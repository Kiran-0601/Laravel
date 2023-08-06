<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\ApplyWfh;
use App\Mail\UpdateWFHStatusMail;
use App\Models\ActivityLog;
use App\Models\DayDuration;
use App\Models\Employee;
use App\Models\ExceptionalWorkingDay;
use App\Models\Holiday;
use App\Models\LeaveTypeAllowedDuration;
use App\Models\OrganizationSetting;
use App\Models\Setting;
use App\Models\User;
use App\Models\WFHApplication;
use App\Models\WFHApplicationDetail;
use App\Models\WFHStatus;
use App\Traits\ResponseTrait;
use App\Validators\WFHValidator;
use Auth;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use DB;
use Illuminate\Http\Request;

class WFHController extends Controller
{
    use ResponseTrait;
    protected $wfhValidator;

    public function __construct()
    {
        $this->wfhValidator = new WFHValidator();
    }

    public function importWFHData()
    {
        DB::beginTransaction();
        try {

            $organizationId = 1;

            WFHApplication::where('organization_id', $organizationId)->forceDelete();
            WFHApplicationDetail::whereNull('deleted_at')->forceDelete();

            $wfhs = DB::connection('old_connection')->table('work_from_home_employees')->get();

            $i = 0;
            $filter = [];
            if (!empty($wfhs)) {
                foreach ($wfhs as $wfh) {

                    $filter[$i][$wfh->applied_date][$wfh->employee_id][$wfh->wfh_type][$wfh->duration ?? 0][$wfh->status][] = $wfh;

                }

                foreach ($filter as $key => $wfh) {
                    foreach ($wfh as $appliedDate => $employee) foreach ($employee as $holidayType => $duration) {
                            foreach ($duration as $statusKey => $value) foreach ($value as $statusValue => $item) {
                                    foreach ($item as $new => $items) {

                                        if (is_array($items)) {
                                            $fromDate = $items[0]->wfh_date;
                                            $toDate = $items[count($items) - 1]->wfh_date;

                                            $startDate = Carbon::parse($fromDate);

                                            $endDate = Carbon::parse($toDate);

                                            $holidays = $this->getHolidayAndWeekend($fromDate, $toDate);
                                           
                                            $days = $startDate->diffInDaysFiltered(function (Carbon $date) use ($holidays) {
                                    
                                                if( $date->isWeekday() && !in_array($date, $holidays)){
                                                    return $date;
                                                }
                                    
                                            }, $endDate);
                                            $days = $days + 1;

                                            $totalWorkingDay = array_sum(array_column($items, 'wfh_type'));

                                            if ($days != $totalWorkingDay) {
                                                foreach ($items as $item) {

                                                    $employee = DB::connection('old_connection')->table('employees')->where('id', $items[0]->employee_id)->first(['id', 'employee_id']);

                                                    if (!empty($employee)) {
                                                        $employeeId = $employee->employee_id;
                                                    }

                                                    $wfhEntry = WFHApplication::create([
                                                        'uuid' => getUuid(),
                                                        'employee_id' => $employeeId,
                                                        'organization_id' => $organizationId,
                                                        'from_date' => $item->wfh_date,
                                                        'to_date' => $item->wfh_date,
                                                        'total_working_days' => $item->wfh_type,
                                                        'description' => $item->description,
                                                        'applied_date' => $item->applied_date,
                                                        'wfh_status_id' => $item->status,
                                                        'action_date' => $item->approve_date,
                                                        'action_by_id' => 2,
                                                        'remarks' => $item->reject_remarks,
                                                        'created_at' => $item->created_at,
                                                        'deleted_at' => $item->deleted_at
                                                    ]);

                                                    if ($item->wfh_type == 0.5 && $item->duration == 1) {
                                                        $dayDuration = DayDuration::FIRSTHALF;
                                                    } else if ($item->wfh_type == 0.5 && $item->duration == 2) {
                                                        $dayDuration = DayDuration::SECONDHALF;
                                                    } else {
                                                        $dayDuration = DayDuration::FULLDAY;
                                                    }

                                                    WFHApplicationDetail::create([
                                                        'wfh_application_id' => $wfhEntry->id,
                                                        'wfh_date' => $item->wfh_date,
                                                        'day_duration_id' => $dayDuration,
                                                        'deleted_at' => $item->deleted_at
                                                    ]);

                                                }
                                            } else {
                                                $employee = DB::connection('old_connection')->table('employees')->where('id', $items[0]->employee_id)->first(['id', 'employee_id']);

                                                if (!empty($employee)) {
                                                    $employeeId = $employee->employee_id;
                                                }

                                                $wfhEntry = WFHApplication::create([
                                                    'uuid' => getUuid(),
                                                    'employee_id' => $employeeId,
                                                    'organization_id' => $organizationId,
                                                    'from_date' => $fromDate,
                                                    'to_date' => $toDate,
                                                    'total_working_days' => $totalWorkingDay,
                                                    'description' => $items[0]->description,
                                                    'applied_date' => $items[0]->applied_date,
                                                    'wfh_status_id' => $items[0]->status,
                                                    'action_date' => $items[0]->approve_date,
                                                    'action_by_id' => 2,
                                                    'remarks' => $items[0]->reject_remarks,
                                                    'created_at' => $items[0]->created_at,
                                                    'deleted_at' => $items[0]->deleted_at
                                                ]);
                                                foreach ($items as $item) {
                                                    if ($item->wfh_type == 0.5 && $item->duration == 1) {
                                                        $dayDuration = DayDuration::FIRSTHALF;
                                                    } else if ($item->wfh_type == 0.5 && $item->duration == 2) {
                                                        $dayDuration = DayDuration::SECONDHALF;
                                                    } else {
                                                        $dayDuration = DayDuration::FULLDAY;
                                                    }

                                                    WFHApplicationDetail::create([
                                                        'wfh_application_id' => $wfhEntry->id,
                                                        'wfh_date' => $item->wfh_date,
                                                        'day_duration_id' => $dayDuration,
                                                        'deleted_at' => $item->deleted_at
                                                    ]);
                                                }
                                            }
                                        }
                                    }
                                }
                        }
                }
            }

            DB::commit();
            return $this->sendSuccessResponse(__('messages.wfh_imported'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while wfh imported";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function applyWfhInformation()
    {
        try {
            $user = Auth::user();
            $organizationId = $this->getCurrentOrganizationId();

            $employees = Employee::withoutGlobalScopes()->active()->select('employees.id', 'display_name', 'avatar_url')->where('employees.organization_id', $organizationId)->whereNull('employees.deleted_at')->get();

            $to = $employees;
            if(!in_array('administrator',$user->roles->pluck('slug')->toArray())){
                $to = $employees->except($user->entity_id);
            }

            $setting = Setting::where('key', 'default_to_email')->first(['id']);
            $organizationSetting = OrganizationSetting::where('setting_id', $setting->id)->first(['value', 'id']);
		    $defaultTo = !empty($organizationSetting) ? $organizationSetting->value : '';

            $response = ['employees' => $employees, 'to' => $to, 'defaultTo' => $defaultTo];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get the apply wfh information";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getDaySummary(Request $request, $internal = false)
    {
        try{
            $inputs = $request->all();
            $totalDays = 0;
            $wfhDays = [];
            $allowedDuration = '';
            $fromDate = $inputs['from_date'] ?? '';
            $toDate = $inputs['to_date'] ?? '';
            if(!empty($fromDate) && !empty($toDate)){
                $query = Holiday::whereBetween('date', [$fromDate, $toDate]);
                $holiday = $query->select('date')->pluck('date')->toArray();
                $weekends = $this->getWeekendDays($fromDate, $toDate);
                $holidayWeekend = $holiday;
                $weekDays = [];
                $filteredWeekend = [];
                if (is_array($weekends)) {

                    $dates = ExceptionalWorkingDay::whereBetween('date', [$fromDate, $toDate])->get(['date'])->pluck('date')->toArray();
                    $holidayWeekend = array_unique(array_merge($holiday, $weekends));
                    $holidayWeekend = array_values($holidayWeekend);

                    foreach ($holidayWeekend as $weekend) {
                        if (!in_array($weekend, $dates)) {
                            $weekDays[] = $weekend;
                        }
                    }

                    foreach ($weekends as $current) {
                        if (!in_array($current, $dates)) {
                            $filteredWeekend[] = $current;
                        }
                    }
                }

                $startDate = Carbon::parse($fromDate);
                $endDate = Carbon::parse($toDate);

                $diffInDays = $startDate->diffInDays($endDate->addDay()->startOfDay());

                $totalDays = $diffInDays - count($weekDays);

                $period = CarbonPeriod::create($fromDate, $toDate);

                if(!empty($inputs['wfh_id'])){
                    $wfh = WFHApplication::join('wfh_application_details', 'wfh_applications.id','wfh_application_details.wfh_application_id')->where('wfh_applications.id', $inputs['wfh_id'])->get()->pluck('day_duration_id', 'wfh_date');
                }


                $allowedDuration = DayDuration::select('id','duration as name')->get();

                // Iterate over the period
                foreach ($period as $key => $date) {
                    $current = $date->format('Y-m-d');
                    $wfhDays[$key]['date'] = $current;
                    $wfhDays[$key]['selectedDuration'] = !empty($wfh[$current]) ? $wfh[$current] : DayDuration::FULLDAY;
                    $wfhDays[$key]['isHoliday'] = in_array($current, $holiday);
                    $wfhDays[$key]['isWeekend'] = in_array($current, $filteredWeekend);
                }
            }

            $response = ['wfhDays' => $wfhDays, 'allowedDuration' => $allowedDuration, 'totalDays' => $totalDays];

            if($internal == true) {
                return $response;
            }

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get the day summary";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $organizationId = $this->getCurrentOrganizationId();

            $inputs = $request->all();

            $validation = $this->wfhValidator->validate($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            if($inputs['totalDays'] <= 0){	
                return $this->sendFailResponse(__('messages.holiday_leave'), 422);	
            }

            $currentUserId =  $request->user()->entity_id;

            $employeeId = !empty($inputs['employee_id']) ? $inputs['employee_id'] :  $currentUserId;

            $wfhExist = WFHApplication::join('wfh_application_details', 'wfh_applications.id', 'wfh_application_details.wfh_application_id')->whereBetween('wfh_date', [$inputs['start_date'], $inputs['end_date']])->where('employee_id', $employeeId)->whereIn('wfh_applications.wfh_status_id', [WFHStatus::PENDING, WFHStatus::APPROVE])->first('wfh_applications.id');

            if (!empty($wfhExist)) {
                return $this->sendFailResponse(__('messages.already_applied'), 422);
            }

            $to = !empty($inputs['to']) ? implode(',', $inputs['to']) : '';
            $cc = !empty($inputs['cc']) ? implode(',', $inputs['cc']) : '';
            $wfhStatus = WFHStatus::PENDING;

            $wfh = WFHApplication::create(['uuid' => getUuid(),'employee_id' => $employeeId, 'organization_id' => $organizationId, 'from_date' => $inputs['start_date'], 'to_date' => $inputs['end_date'], 'total_working_days' => $inputs['totalDays'],'description' => $inputs['reason'], 'wfh_status_id' => $wfhStatus, 'to' => $to, 'cc' => $cc]);

            $weekendHoliday = $this->getHolidayAndWeekend($inputs['start_date'],  $inputs['end_date']);

            $getData = [];
            if (!empty($wfh)) {
                foreach ($inputs['wfhDays'] as $wfhData) {

                    if(in_array( $wfhData['date'], $weekendHoliday)){
                        continue;
                    }

                    $wfhDetail = WFHApplicationDetail::create(['wfh_application_id' => $wfh->id, 'wfh_date' => $wfhData['date'], 'day_duration_id' => $wfhData['selectedDuration'], 'wfh_status_id' => $wfhStatus]);
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

            $employee = User::where('entity_id', $employeeId)->first(['entity_id']);
            $info = ['employee_name' => $employee->display_name, 'wfh_data' => $getData, 'from_date' => $wfh->from_date, 'to_date' => $wfh->to_date, 'description' => $wfh->description, 'duration' => $wfh->day_duration_id, 'wfh_id' => $wfh->uuid, 'days' => $inputs['totalDays']];

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

            DB::commit();

            return $this->sendSuccessResponse(__('messages.wfh_store'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while store wfh";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function list(Request $request)
    {
        try {
            $inputs = $request->all();
            $perPage = $request->perPage ?? 10;
            $fromDate = $request->from_date ? date('Y-m-d', strtotime($request->from_date)) :  '';
            $toDate = $request->to_date  ? date('Y-m-d', strtotime($request->to_date)) :  '';

            $organizationId = $this->getCurrentOrganizationId();

            $user = $request->user();
            $permissions = $user->getAllPermissions()->pluck('name')->toArray();
            $organizationId = $this->getCurrentOrganizationId();

            $upcoming = [];
            $history = [];
            $allWfhs = [];
            $historyCount = 0;

            $employeeId = !empty($inputs['employee_id']) ? $inputs['employee_id'] : $user->entity_id;
            $date = getUtcDate();

            if (!in_array('manage_wfh', $permissions)) {
                $upcoming = WFHApplication::withoutGlobalScopes([OrganizationScope::class])->join('wfh_application_statuses', 'wfh_applications.wfh_status_id', 'wfh_application_statuses.id')->where('employee_id', $employeeId)->whereDate('to_date', '>=', $date)->orderBy('wfh_applications.from_date')->orderBy('wfh_application_statuses.id')->get(['wfh_applications.id', 'wfh_applications.uuid','employee_id', 'from_date', 'to_date', 'total_working_days', 'wfh_applications.created_at', 'wfh_application_statuses.name as wfh_status', 'wfh_application_statuses.color_code']);
                $history = WFHApplication::withoutGlobalScopes([OrganizationScope::class])->join('wfh_application_statuses', 'wfh_applications.wfh_status_id', 'wfh_application_statuses.id')->whereDate('to_date', '<=', $date)->where('employee_id', $employeeId)->select('wfh_applications.id', 'wfh_applications.uuid','employee_id', 'from_date', 'to_date', 'total_working_days', 'wfh_applications.created_at', 'wfh_application_statuses.name as wfh_status', 'wfh_application_statuses.color_code')->orderBy('wfh_applications.from_date','desc')->orderBy('wfh_application_statuses.id')->simplePaginate($perPage);
                $historyCount = WFHApplication::withoutGlobalScopes([OrganizationScope::class])->join('wfh_application_statuses', 'wfh_applications.wfh_status_id', 'wfh_application_statuses.id')->whereDate('to_date', '<=', $date)->where('employee_id', $employeeId)->count();

                $response['upcoming'] = $upcoming;
                $response['history'] = $history;
                $response['total_count'] = $historyCount;
            

            }else{
                $query = WFHApplication::withoutGlobalScopes()->join('wfh_application_statuses', 'wfh_applications.wfh_status_id', 'wfh_application_statuses.id')->join('wfh_application_details', 'wfh_applications.id', 'wfh_application_details.wfh_application_id')->join('employees', function($join) use($organizationId){
                    $join->on('wfh_applications.employee_id', '=', 'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })->where('wfh_applications.organization_id', $organizationId)
                ->whereNull('wfh_applications.deleted_at');

                $query->when($fromDate, function($q) use($fromDate, $toDate){
                    $q->whereBetween('wfh_application_details.wfh_date',[$fromDate, $toDate]);
                });

               if(!empty($inputs['employee_id'])){
                 $query->where('employee_id', $inputs['employee_id']);
               }

               $query = $query->groupBy('wfh_applications.id');
               
               $countQuery = clone $query;
               $wfhCount = $countQuery->get()->count();

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

    public function pendingWfhApplications(Request $request)
    {
        try {

            $perPage = $request->perPage ?? 10;
            $employeeId = $request->employee ?? '';
            $organizationId = $this->getCurrentOrganizationId();

            $query = WFHApplication::withoutGlobalScopes()->join('wfh_application_statuses', 'wfh_applications.wfh_status_id', 'wfh_application_statuses.id')
                                    ->join('employees', function ($join) use($organizationId) {
                                        $join->on('wfh_applications.employee_id', '=',  'employees.id');
                                        $join->where('employees.organization_id', $organizationId);
                                    })                        
                                    ->where('wfh_status_id', WFHStatus::PENDING)
                                    ->where('wfh_applications.organization_id', $organizationId)
                                    ->whereNull('wfh_applications.deleted_at');
            $query = $query->when($employeeId,function($q) use($employeeId){
                $q->where('employee_id', $employeeId);
            });

            $pendingWfhApplications = $query->orderBy('wfh_applications.from_date')
                           ->orderBy('wfh_status_id')
                           ->select('wfh_applications.id','wfh_applications.uuid', 'employee_id', 'employees.display_name', 'from_date', 'to_date', 'total_working_days', 'wfh_applications.created_at', 'wfh_application_statuses.name as wfh_status')->simplePaginate($perPage);

            $countQuery = WFHApplication::where('wfh_status_id', WFHStatus::PENDING);
            $countQuery = $countQuery->when($employeeId,function($q) use($employeeId){
                $q->where('employee_id', $employeeId);
            });
            $total = $countQuery->count();

            $response = ['pending_wfh_applications' => $pendingWfhApplications, 'total_count' => $total];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while list pending wfh applications";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function wfhDetails(Request $request)
    {
        try {
            $wfhId = $request->id;
            $organizationId = $this->getCurrentOrganizationId();
            $wfh = WFHApplication::withoutGlobalScopes()->join('wfh_application_statuses', 'wfh_applications.wfh_status_id', 'wfh_application_statuses.id')
                                                        ->join('employees',function($join) use($organizationId){
                                                            $join->on('wfh_applications.employee_id', 'employees.id');
                                                            $join->where('employees.organization_id', $organizationId);
                                                        })->where('wfh_applications.uuid', $wfhId)
                                                        ->where('wfh_applications.organization_id', $organizationId)
                                                        ->whereNull('wfh_applications.deleted_at')
                                                        ->first(['wfh_applications.id','wfh_applications.uuid', 'employee_id', 'employees.display_name', 'from_date', 'to_date', 'total_working_days','wfh_applications.description', 'wfh_applications.created_at', 'to', 'cc' ,'action_date', 'remarks', 'cancel_remarks', 'wfh_application_statuses.name as wfh_status']);
            $wfh->to = !empty($wfh->to) ? explode(',',$wfh->to) : [];
            $wfh->cc = !empty($wfh->cc) ? explode(',',$wfh->cc) : [];
            $wfhId = $wfh->id;

            $organizationId = $this->getCurrentOrganizationId();

            $request = new Request(['from_date' => $wfh->from_date, 'to_date' => $wfh->to_date, 'wfh_id' => $wfh->id]);
            $summary = $this->getDaySummary($request, true);

            $empIds = array_merge([$wfh->employee_id], $wfh->to, $wfh->cc);

            $employees = Employee::select('employees.id', 'display_name','avatar_url')->whereIn('employees.id', $empIds)->get();

            $response = ['wfhDetails' => $wfh,'employees' => $employees, 'summary' => $summary['wfhDays'], 'allowedDuration' => $summary['allowedDuration']];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while detail wfh";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function updateWFHApplicationStatus(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();

            $wfhId = $inputs['id'];
            $status = $inputs['status'];
            $comment = $inputs['comment'];

            $user = Auth::user();
            $userId = $user->id;
            $currentEmployeeId = $user->employee_id;
            $permissions = $user->getAllPermissions()->pluck('name')->toArray();

            $wfh = WFHApplication::where('uuid', $wfhId)->first(['employee_id', 'from_date', 'to_date', 'description', 'to', 'cc']);

            if(in_array('manage_wfh',$permissions)){

                if (!empty($status) && $status == 'approved') {

                    WFHApplication::where('uuid', $wfhId)->update(['wfh_status_id' => WFHStatus::APPROVE,'remarks' => $comment, 'action_date' => getDateTime(), 'action_by_id' => $userId]);
    
                    $info['wfh_action'] = $status;
    
                }
    
                if (!empty($status) && $status == 'rejected') {
    
                    WFHApplication::where('uuid', $wfhId)->update(['wfh_status_id' => WFHStatus::REJECT, 'remarks' => $comment,'action_date' => getDateTime(), 'action_by_id' => $userId]);
    
                    $info['wfh_action'] = $status;
                }
            }

            if (!empty($status) && $status == 'cancelled') {
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

            if (!empty($inputs['status']) && $inputs['status'] == 'cancelled') {

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

    public function update(Request $request, $wfh)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();

            $validation = $this->wfhValidator->validate($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $wfhId = $wfh;
            $wfh = WFHApplication::join('wfh_application_details', 'wfh_applications.id', 'wfh_application_details.wfh_application_id')->where('wfh_applications.uuid', $wfhId)->whereIn('wfh_applications.wfh_status_id', [WFHStatus::PENDING, WFHStatus::APPROVE])->first(['wfh_applications.id' ,'wfh_applications.organization_id', 'wfh_applications.employee_id']);

            if (empty($wfh)) {
                return $this->sendFailResponse(__('messages.can_not_update'), 422);
            }

            $exists = [];
            if (!empty($wfh)) {
                $to = !empty($inputs['to']) ? implode(',', $inputs['to']) : '';
                $cc = !empty($inputs['cc']) ? implode(',', $inputs['cc']) : '';
                $wfh->update(['employee_id' => $inputs['employee_id'], 'from_date' => $inputs['start_date'], 'to_date' => $inputs['end_date'], 'description' => $inputs['reason'], 'total_working_days' => $inputs['totalDays'], 'to' => $to, 'cc' => $cc]);
                foreach ($inputs['wfhDays'] as $wfhData) {

                    $wfhDetail = WFHApplicationDetail::where('wfh_date', $wfhData['date'])->where('wfh_application_id', $wfh->id)->first(['id', 'wfh_date']);
                    if(!empty($wfhDetail)){
                        $exists[] = $wfhDetail->id;
                        $wfhDetail->update(['day_duration_id' => $wfhData['selectedDuration']]);                      
                    }else{

                        $wfhExist = WFHApplication::join('wfh_application_details', 'wfh_applications.id', 'wfh_application_details.wfh_application_id')->where('wfh_date', $wfhData['date'])->where('employee_id', $inputs['employee_id'])->whereNull('wfh_application_details.deleted_at')->whereIn('wfh_applications.wfh_status_id', [WFHStatus::PENDING, WFHStatus::APPROVE])->first('wfh_applications.id');

                        if (!empty($wfhExist)) {
                            return $this->sendFailResponse(__('messages.already_applied'), 422);
                        }
                        
                        $wfhDetail = WFHApplicationDetail::create(['wfh_application_id' => $wfh->id, 'wfh_date' => $wfhData['date'], 'day_duration_id' => $wfhData['selectedDuration'], 'wfh_status_id' => WFHStatus::APPROVE]);
                        $exists[] = $wfhDetail->id;
                    }

                    $newData['wfh_date'] = $wfhDetail->wfh_date;
                    $newData['duration'] = $wfhData['selectedDuration'] == DayDuration::FULLDAY ? DayDuration::FULLDAYNAME : 'Half Day';
                    $newData['dayDuration'] =  DayDuration::FULLDAYNAME;
                    if ($wfhData['selectedDuration'] == DayDuration::FIRSTHALF) {
                        $newData['dayDuration'] = DayDuration::FIRSTHALFNAME;
                    } elseif ($wfhData['selectedDuration'] == DayDuration::SECONDHALF) {
                        $newData['dayDuration'] = DayDuration::SECONDHALFNAME;
                    }
    
                    $getData[] = $newData;
                }

                WFHApplicationDetail::where('wfh_application_id', $wfh->id)->whereNotIn('id', $exists)->delete();

                $logData = ['organization_id' => $wfh->organization_id ,'new_data' => NULL, 'old_data' => json_encode(['display_name' => $wfh->employee_id]), 'action' => 'has updated wfh', 'table_name' => 'employees','updated_by' => $request->user()->id, 'module_id' => $wfh->employee_id, 'module_name' => 'WFH'];
                        
                $activityLog = new ActivityLog();
                $activityLog->createLog($logData);

                $employee = User::where('entity_id', $wfh->employee_id)->first(['entity_id']);
                $info = ['employee_name' => $employee->display_name, 'wfh_data' => $getData, 'from_date' => $wfh->from_date, 'to_date' => $wfh->to_date, 'description' => $wfh->description, 'duration' => $wfh->day_duration_id, 'wfh_id' => $wfh->id, 'days' => $inputs['totalDays']];
                $info['edit'] = true;
                if (!empty($to)) {
                    $userData = User::whereIn('entity_id', explode(',', $to))->get(['id','entity_id', 'email']);
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
            }
            DB::commit();

            return $this->sendSuccessResponse(__('messages.wfh_update'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update wfh";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }


}
