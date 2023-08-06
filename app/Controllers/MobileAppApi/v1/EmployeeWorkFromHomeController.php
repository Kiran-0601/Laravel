<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use App\Jobs\SendEmailJob;
use App\Mail\ApplyWfh;
use App\Mail\UpdateWFHStatusMail;
use App\Models\CompensatoryOff;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\CompOff;
use App\Models\DayDuration;
use App\Models\OrganizationSetting;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserRole;
use App\Models\WFHApplication;
use App\Models\WFHApplicationDetail;
use App\Models\WFHStatus;
use App\Models\WorkFromHomeEmployee;
use App\Validators\WfhValidator;
use Carbon\Carbon;
use DB, Log, Lang, Auth;
use Illuminate\Http\Request;

class EmployeeWorkFromHomeController extends Controller
{
    use ResponseTrait;
    private $wfhValidator;
    private $holidayList;
  
    function __construct()
    {
        $this->wfhValidator = new WfhValidator();
    }

    //Apply work from home request by employee
    public function applyWfh(Request $request){
        try {
            DB::beginTransaction();
            $user= Auth::user();
           
            $organizationId = $this->getCurrentOrganizationId();
        
            $inputs = $request->all();
          
            $employeeId =  $user->entity_id;

            $validation = $this->wfhValidator->validate($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }
            $wfhExist = WFHApplication::join('wfh_application_details', 'wfh_applications.id', 'wfh_application_details.wfh_application_id')->whereBetween('wfh_date', [$inputs['start_date'], $inputs['end_date']])->where('employee_id', $employeeId)->whereIn('wfh_applications.wfh_status_id', [WFHStatus::PENDING, WFHStatus::APPROVE])->first('wfh_applications.id');
           
            if (!empty($wfhExist)) {
                return $this->sendFailResponse(__('messages.already_applied'), 422);
            }
            
            $to = !empty($inputs['to']) ? implode(',', $inputs['to']) : '';
            $cc = !empty($inputs['cc']) ? implode(',', $inputs['cc']) : '';
           
            $wfh = WFHApplication::create(['uuid' => getUuid(),'employee_id' => $employeeId, 'organization_id' => $organizationId, 'from_date' => $inputs['start_date'], 'to_date' => $inputs['end_date'], 'total_working_days' => $inputs['totaldays'],'description' => $inputs['reason'], 'wfh_status_id' => WFHStatus::PENDING, 'to' => $to, 'cc' => $cc]);
            $weekendHoliday = $this->getHolidayAndWeekend($inputs['start_date'],  $inputs['end_date']);

            $getData = [];
            if (!empty($wfh)) {
                foreach ($inputs['wfhDays'] as $wfhData) {

                    if(in_array( $wfhData['date'], $weekendHoliday)){
                        continue;
                    }

                    $wfhDetail = WFHApplicationDetail::create(['wfh_application_id' => $wfh->id, 'wfh_date' => $wfhData['date'], 'day_duration_id' => $wfhData['selectedDuration'], 'wfh_status_id' => WFHStatus::PENDING]);
                    $newData['wfh_date'] = $wfhDetail->wfh_date;
                    $newData['dayDuration'] = DayDuration::FULLDAYNAME;
                    if ($wfhData['selectedDuration'] == DayDuration::FIRSTHALF) {
                        $newData['dayDuration'] = DayDuration::FIRSTHALFNAME;
                    } elseif ($wfhData['selectedDuration'] == DayDuration::SECONDHALF) {
                        $newData['dayDuration'] = DayDuration::SECONDHALFNAME;
                    }

                    $getData[] = $newData;
                }
            }
            $info = ['employee_name' => $user->display_name, 'wfh_data' => $getData, 'from_date' => $wfh->from_date, 'to_date' => $wfh->to_date, 'description' => $wfh->description, 'wfh_id' => $wfh->uuid, 'days' => $wfh->total_working_days];
            DB::commit();
          
            if (!empty($to)) {
                $setting = Setting::where('key', 'default_to_email')->first(['id']);
                $organizationSetting = OrganizationSetting::where('setting_id', $setting->id)->first(['value', 'id']);
                $defaultTo = !empty($organizationSetting) ? $organizationSetting->value : '';

                $to = explode(',', $to);
                $defaultTo = !empty($defaultTo) ?  explode(',', $defaultTo) : [];
                $to = array_merge($to, $defaultTo);

                $userData = User::whereIn('entity_id', $to)->get(['id','entity_id', 'email']);
                $info['cc'] = false;
                $data = new ApplyWfh($info);

                $emailData = ['email' => $userData, 'email_data' => $data];

                SendEmailJob::dispatch($emailData);

            }
            if (!empty($cc)) {
                $userData = User::whereIn('entity_id', explode(',', $cc))->get(['id','entity_id', 'email']);
                $info['cc'] = true;

                $data = new ApplyWfh($info);

                $emailData = ['email' => $userData, 'email_data' => $data];

                SendEmailJob::dispatch($emailData);
            }

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while store wfh";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    
    //List employee work from home request history
    public function getEmployeeWfh(Request $request) {
        try {
            $inputs = $request->all();
            $perPage = $request->perPage ?? 10;
            $fromDate = $request->from_date ? date('Y-m-d', strtotime($request->from_date)) :  '';
            $toDate = $request->to_date  ? date('Y-m-d', strtotime($request->to_date)) :  '';

            $organizationId = $this->getCurrentOrganizationId();
            $user = $request->user();
            $permissions = $user->getAllPermissions()->pluck('name')->toArray();
            $organizationId = $this->getCurrentOrganizationId();

            $allWfhs = [];

            $employeeId = !empty($inputs['employee_id']) ? $inputs['employee_id'] : $user->entity_id;
          
            if (!in_array('manage_wfh', $permissions)) {
                $query = WFHApplication::withoutGlobalScopes([OrganizationScope::class])
                ->join('wfh_application_statuses', 'wfh_applications.wfh_status_id', 'wfh_application_statuses.id')
                ->where('employee_id', $employeeId)
                ->orderBy('wfh_applications.created_at','Desc')->orderBy('wfh_application_statuses.id')
                ->get(['wfh_applications.id', 'wfh_applications.uuid','employee_id', 'from_date', 'to_date', 'total_working_days', 'wfh_applications.created_at', 'wfh_application_statuses.name as wfh_status', 'wfh_application_statuses.color_code']);

                $wfhCount = $query->count();
                $response['upcoming'] = $query;
                $response['total_count'] = $wfhCount;
               
             
            }else{
                $query = WFHApplication::withoutGlobalScopes()->join('wfh_application_statuses', 'wfh_applications.wfh_status_id', 'wfh_application_statuses.id')->join('wfh_application_details', 'wfh_applications.id', 'wfh_application_details.wfh_application_id')->join('employees', function($join) use($organizationId){
                    $join->where('employees.organization_id', $organizationId);
                })->where('wfh_applications.organization_id', $organizationId);

                $query->when($fromDate, function($q) use($fromDate, $toDate){
                    $q->whereBetween('wfh_application_details.wfh_date',[$fromDate, $toDate]);
                });
                $query = $query->groupBy('wfh_applications.id');
                $wfhCount = $query->get()->count();

                $allWfhs = $query->select('wfh_applications.id', 'wfh_applications.uuid','employee_id', 'employees.display_name', 'from_date', 'to_date', 'total_working_days', 'wfh_applications.created_at', 'wfh_application_statuses.name as wfh_status', 'wfh_application_statuses.color_code')->orderBy('wfh_applications.from_date','desc')->orderBy('wfh_application_statuses.id')->simplePaginate($perPage);

                $response['all'] = $allWfhs;
                $response['total_count'] = $wfhCount;
            }
            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while list wfh applications";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    // List all the future work from home requests of employee
    public function getEmployeeFutureWfh(){
        try{
            $user = Auth::user();
    
            $startDate = Carbon::tomorrow()->format('Y-m-d');
            $endDate = Carbon::now()->addWeek()->format('Y-m-d');

            $wfhApplications = WFHApplication::withoutGlobalScopes([OrganizationScope::class])
            ->join('wfh_application_statuses', 'wfh_applications.wfh_status_id', 'wfh_application_statuses.id')
            ->where('employee_id', $user->entity_id)
            ->whereBetween('wfh_applications.from_date', [$startDate, $endDate])
            ->orderBy('wfh_applications.created_at','Desc')->orderBy('wfh_application_statuses.id')
            ->get(['wfh_applications.id', 'wfh_applications.uuid','employee_id', 'from_date', 'to_date', 'total_working_days', 'wfh_applications.created_at', 'wfh_application_statuses.name as wfh_status', 'wfh_application_statuses.color_code']);

            return $this->sendSuccessResponse(__('messages.success'), 200, $wfhApplications);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while fetch upcomming wfh data";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    // Cancel work from home request by employee
    public function cancelEmployeeWfh(Request $request){
        try 
        {
            DB::beginTransaction();
            $inputs = $request->all();
           
            $wfhId = $inputs['id'];
            $status = $inputs['status'] ?? WFHStatus::CANCEL;
            $comment = $inputs['comment'];
            $user = Auth::user();
            $userId = $user->id;
            $currentEmployeeId = $user->employee_id;
            //$permissions = $user->getAllPermissions()->pluck('name')->toArray();

            $wfh = WFHApplication::where('uuid', $wfhId)->first(['employee_id', 'from_date', 'to_date', 'description', 'to', 'cc']);

            if (!empty($status) && $status == WFHStatus::CANCEL) {
                WFHApplication::where('uuid', $wfhId)->update(['wfh_status_id' => WFHStatus::CANCEL,'action_date' => getDateTime(), 'action_by_id' => $userId,'cancel_remarks' => $comment]);
                $info['wfh_action'] = $status;
            }

            $to = $wfh->to ? explode(',', $wfh->to) : [];

            $setting = Setting::where('key', 'default_to_email')->first(['id']);
            $organizationSetting = OrganizationSetting::where('setting_id', $setting->id)->first(['value', 'id']);
            $defaultTo = !empty($organizationSetting) ? $organizationSetting->value : '';

            $defaultTo = !empty($defaultTo) ?  explode(',', $defaultTo) : [];
            $to = array_merge($to, $defaultTo);

            $toUsers = array_filter($to, function ($user) use ($currentEmployeeId) {
                if ($user != $currentEmployeeId) {
                    return $user;
                }
            });
            $toUsers = $toUsers ? $toUsers : [];
            $ccUsers = $wfh->cc ? explode(',', $wfh->cc) : [];

            if (!empty($inputs['status']) && $inputs['status'] == WFHStatus::CANCEL) {
                $employees = array_merge($toUsers, $ccUsers);
            } else {
                $employees = array_merge([$wfh->employee_id], $toUsers, $ccUsers);
            }
            $user = User::whereIn('entity_id', $employees)->get(['entity_id', 'email']);
          
            $employee = Employee::where('id', $wfh->employee_id)->first(['display_name']);

            $details = ['from_date' => $wfh->from_date, 'to_date' => $wfh->to_date, 'display_name' => $employee->display_name];
            $info = array_merge($details, $info);
            $data = new UpdateWFHStatusMail($info);
            $emailData = ['email' => $user, 'email_data' => $data];

            SendEmailJob::dispatch($emailData);
            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update wfh status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

}
