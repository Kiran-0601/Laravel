<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\ApplyCompOff;
use App\Mail\UpdateCompOffStatusMail;
use App\Models\ActivityLog;
use App\Models\CompensatoryOff;
use App\Models\CompensatoryOffStatus;
use App\Models\DayDuration;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveCompensatoryOff;
use App\Models\LeaveStatus;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Scopes\OrganizationScope;
use App\Models\Setting;
use App\Models\User;
use App\Traits\ResponseTrait;
use App\Validators\CompoffValidator;
use Auth;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;

class CompensatoryOffController extends Controller
{
    use ResponseTrait;

    private $compoffValidator;

    function __construct()
    {
        $this->compoffValidator = new CompoffValidator();
    }

    public function importCompOffData()
    {
        DB::beginTransaction();
        try {

            $organizationId = 1;

            CompensatoryOff::where('organization_id', $organizationId)->forceDelete();

            $compOffs = DB::connection('old_connection')->table('comp_off')->get();
         
            $i = 0;
            $filter = [];
            if(!empty($compOffs)){

                foreach($compOffs as $compOff){

                    if($compOff->type == 0.5 && $compOff->duration == 1){
                            $dayDuration = DayDuration::FIRSTHALF;
                    }
                    else if($compOff->type == 0.5 && $compOff->duration == 2){
                            $dayDuration = DayDuration::SECONDHALF;
                    }
                    else{
                            $dayDuration = DayDuration::FULLDAY;
                    }

                    $employee = DB::connection('old_connection')->table('employees')->where('id', $compOff->employee_id)->first(['employee_id']);
                    if(!empty($employee)){
                        $employeeId = $employee->employee_id;
                    }

                    CompensatoryOff::create([
                            'uuid' => getUuid(),
                            'employee_id' => $employeeId,
                            'organization_id' => $organizationId,
                            'comp_off_date' => $compOff->applied_date,
                            'day_duration_id' => $dayDuration,
                            'description' => $compOff->description,
                            'action_date' => $compOff->approve_date,
                            'action_by_id' => 2,
                            'compensatory_off_status_id' => $compOff->status,
                            'remarks' => $compOff->reject_remarks,
                            'created_at' => $compOff->created_at,
                            'deleted_at' => $compOff->deleted_at
                        ]);

                }
                // foreach($compOffs as $compOff){

                //     $filter[$i][$compOff->applied_date][$compOff->employee_id][$compOff->type][$compOff->duration??0][$compOff->status][] = $compOff;

                // }
             
                // foreach($filter as $key => $compOff){
                //     foreach($compOff as $appliedDate => $employee)
                //         foreach($employee as $type => $duration){
                //           foreach($duration as $statusKey => $value)
                //             foreach($value as $statusValue => $item){
                //                 foreach($item as $new => $items){
                                       
                //                   if(is_array($items)){
                //                     $fromDate = $items[0]->applied_date;
                //                     $toDate = $items[count($items) - 1]->applied_date;
                //                     $totalWorkingDay = array_sum(array_column($items, 'type'));
                //                     $items = $items[0];    
                //                   }else{
                //                     $fromDate = $items->applied_date;
                //                     $toDate = $items->applied_date;
                //                   }

                //                    if($items->type == 0.5 && $items->duration == 1){
                //                             $dayDuration = DayDuration::FIRSTHALF;
                //                    }
                //                    else if($items->type == 0.5 && $items->duration == 2){
                //                             $dayDuration = DayDuration::SECONDHALF;
                //                    }
                //                    else{
                //                             $dayDuration = DayDuration::FULLDAY;
                //                    }

                //                    $employee = DB::connection('old_connection')->table('employees')->where('id', $items->employee_id)->first(['employee_id']);
                //                   if(!empty($employee)){
                //                     $employeeId = $employee->employee_id;
                //                   }
                                  
       
                //                    $compOffStatus = $items->status;

                //                    if(!empty($employeeId)){
                //                     CompensatoryOff::create([
                //                             'employee_id' => $employeeId,
                //                             'organization_id' => $organizationId,
                //                             'from_date' => $fromDate,
                //                             'to_date' => $toDate,
                //                             'total_working_days' => $totalWorkingDay,
                //                             'day_duration_id' => $dayDuration,
                //                             'description' => $items->description,
                //                             'approve_date' => $items->approve_date,
                //                             'approve_by_id' => 1,
                //                             'compensatory_off_status_id' => $compOffStatus,
                //                             'reject_remarks' => $items->reject_remarks,
                //                             'created_at' => $items->created_at  
                //                         ]);
                //                    }
                //             }
                //         }
                //     }
                // }
            }
           
            DB::commit();
            return $this->sendSuccessResponse(__('messages.compoff_imported'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while comp off imported";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();

            $organizationId = $this->getCurrentOrganizationId();

            $validation = $this->compoffValidator->validateStore($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $currentUserId = $request->user()->entity_id;

            $employeeId = !empty($inputs['employee_id']) ? $inputs['employee_id'] : $currentUserId;

            $holidayWeekends = $this->getHolidayAndWeekend($inputs['date'], $inputs['date']);

            if (!in_array($inputs['date'], $holidayWeekends)) {
                return $this->sendFailResponse(__('messages.weekend_holiday_date_required'), 422);
            }

            if ($inputs['duration'] === DayDuration::FULLDAY) {
                $durationName = DayDuration::FULLDAYNAME;
            } else {
                $durationName = DayDuration::HALFDAYNAME;
            }

            $compOffExist = CompensatoryOff::whereDate('comp_off_date', $inputs['date'])->where('employee_id', $employeeId)->whereIn('compensatory_off_status_id', [CompensatoryOffStatus::PENDING, CompensatoryOffStatus::APPROVE])->get(['compensatory_offs.id', 'day_duration_id']);

            foreach ($compOffExist as $val) {
                if ($val->day_duration_id == DayDuration::FULLDAY) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                } elseif ($val->day_duration_id == DayDuration::FIRSTHALF && $inputs['duration'] == DayDuration::FIRSTHALF) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                } elseif ($val->day_duration_id == DayDuration::SECONDHALF && $inputs['duration'] == DayDuration::SECONDHALF) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                } elseif (in_array($val->day_duration_id, [DayDuration::FIRSTHALF, DayDuration::SECONDHALF]) && $inputs['duration'] == DayDuration::FULLDAY) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                }
            }

            $compoff = CompensatoryOff::create([
                'uuid' => getUuid(),
                'employee_id' => $employeeId,
                'organization_id' => $organizationId,
                'day_duration_id' => $inputs['duration'],
                'description' => $inputs['description'],
                'compensatory_off_status_id' => CompensatoryOffStatus::PENDING,
                'comp_off_date' => $inputs['date']
            ]);

            $adminUsers = $this->getAdminUser();
            $employee = User::where('entity_id', $employeeId)->first(['entity_id']);
            $info = ['employee_name' => $employee->display_name, 'date' => $compoff->comp_off_date, 'description' => $compoff->description, 'duration' => $durationName, 'compoff_id' => $compoff->uuid];

            $data = new ApplyCompOff($info);

            $emailData = ['email' => $adminUsers, 'email_data' => $data];

            SendEmailJob::dispatch($emailData);

            $hrUsers = $this->getHRUser();

            $emailData = ['email' => $hrUsers, 'email_data' => $data];

            DB::commit();

            return $this->sendSuccessResponse(__('messages.compoff_success'), 200, $compoff);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add comp off";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function listCompensatoryRequest(Request $request)
    {
        try {

            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $year = !empty($inputs['year']) ? $inputs['year'] : date('Y');

            $perPage = $request->perPage ?? 10;
            $user = $request->user();
            $permissions = $user->getAllPermissions()->pluck('name')->toArray();

            $employeeId = !empty($inputs['employee_id']) ? $inputs['employee_id'] : $user->entity_id;

            $query = CompensatoryOff::withoutGlobalScopes([OrganizationScope::class])->join('employees', function ($join) use ($organizationId) {
                $join->on('compensatory_offs.employee_id', '=', 'employees.id');
                $join->where('employees.organization_id', $organizationId);
            })->join('compensatory_off_statuses', 'compensatory_offs.compensatory_off_status_id', 'compensatory_off_statuses.id')
                ->leftJoin('leaves_compensatory_offs', 'compensatory_offs.id', 'leaves_compensatory_offs.compensatory_offs_id')
                ->leftJoin('leaves', 'leaves.id', 'leaves_compensatory_offs.leave_id')
                ->where('compensatory_offs.organization_id', $organizationId)
                ->whereYear('compensatory_offs.comp_off_date', $year)
                ->select(
                    'compensatory_offs.id',
                    'compensatory_offs.uuid',
                    'employees.display_name',
                    'comp_off_date',
                    'compensatory_off_status_id',
                    'compensatory_offs.description',
                    'compensatory_off_statuses.name',
                    'compensatory_offs.created_at',
                    'compensatory_offs.day_duration_id',
                    DB::raw('CASE WHEN (`leaves_compensatory_offs`.`compensatory_offs_id` IS NOT NULL  && leaves.leave_status_id IN (1,2) && (((day_duration_id = 1 && duration =1) || (day_duration_id IN (2,3) && duration = 0.5)) || `leaves_compensatory_offs`.duration = 1 || (day_duration_id != duration && count(`leaves_compensatory_offs`.compensatory_offs_id) > 1)) ) THEN  "Booked"
                          WHEN  (compensatory_offs.compensatory_off_status_id = 2 && (`leaves_compensatory_offs`.`compensatory_offs_id` IS  NULL || leaves.leave_status_id IN (3,4) || (day_duration_id != duration && count(`leaves_compensatory_offs`.compensatory_offs_id) = 1))) THEN  "Available"  ELSE "" END compoff_status'), DB::raw('CASE 
                          WHEN  (compensatory_offs.compensatory_off_status_id = 2 && (`leaves_compensatory_offs`.`compensatory_offs_id` IS  NULL ) && day_duration_id = 1) THEN day_duration_id  WHEN  (compensatory_offs.compensatory_off_status_id = 2 && (`leaves_compensatory_offs`.`compensatory_offs_id` IS  NULL ) && day_duration_id != 1) THEN 0.5  WHEN  leaves.leave_status_id IN (3,4) && day_duration_id = 1 && duration = 1 THEN 1 WHEN  leaves.leave_status_id IN (3,4) && day_duration_id != 1 THEN 0.5  WHEN  leaves.leave_status_id IN (3,4) && day_duration_id = 1 && (day_duration_id != duration && count(`leaves_compensatory_offs`.compensatory_offs_id) > 1) THEN 1 WHEN  (day_duration_id != duration && count(`leaves_compensatory_offs`.compensatory_offs_id) = 1) THEN 0.5 ELSE 0.5 END balance')
                );

            if (!in_array('manage_comp_off', $permissions)) {
                $query->where('compensatory_offs.employee_id', $employeeId);
            }

            if (!empty($inputs['employee'])) {
                $query->where('compensatory_offs.employee_id', $inputs['employee']);
            }
            $query = $query->where('compensatory_offs.organization_id', $organizationId)->groupBy('compensatory_offs.id');
            $countQuery = clone $query;
            $total = $countQuery->get()->count();

            $data = $query->orderBy('compensatory_offs.comp_off_date')->orderBy('compensatory_off_statuses.id')->simplePaginate($perPage);

            $response = ['data' => $data, 'total_count' => $total];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while list compensatory request";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function show($compOff)
    {
        $data = CompensatoryOff::where('uuid',$compOff)->join('compensatory_off_statuses', 'compensatory_offs.compensatory_off_status_id', 'compensatory_off_statuses.id')->first(['compensatory_offs.id','uuid','employee_id','day_duration_id','description','compensatory_off_status_id','comp_off_date', 'remarks', 'cancel_remarks', 'compensatory_off_statuses.name']);
        $leaves = Leave::join('leaves_compensatory_offs', 'leaves.id', 'leaves_compensatory_offs.leave_id')->join('leave_statuses', 'leaves.leave_status_id', 'leave_statuses.id')->select('leaves.from_date', 'leaves.to_date', 'total_working_days', 'leave_statuses.name as leave_status')->where('leaves_compensatory_offs.compensatory_offs_id', $data->id)->get();
        
        $data->leaves = $leaves;
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function update(Request $request,$compOff)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();

            $validation = $this->compoffValidator->validateStore($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $currentUserId =  $request->user()->entity_id;

            $employeeId = !empty($inputs['employee_id']) ? $inputs['employee_id'] :  $currentUserId;

            $holidayWeekends = $this->getHolidayAndWeekend($inputs['date'], $inputs['date']);

            if (!in_array($inputs['date'], $holidayWeekends)) {
                return $this->sendFailResponse(__('messages.weekend_holiday_date_required'), 422);
            }

            if($inputs['duration']===DayDuration::FULLDAY){
                $durationName = DayDuration::FULLDAYNAME;           
            }else{             
                $durationName = DayDuration::HALFDAYNAME;
            }

            $compOffExist = CompensatoryOff::whereDate('comp_off_date', $inputs['date'])->where('employee_id', $employeeId)->where('uuid','!=', $compOff)->whereIn('compensatory_off_status_id', [CompensatoryOffStatus::PENDING, CompensatoryOffStatus::APPROVE])->first('compensatory_offs.id');

            if (!empty($compOffExist)) {
                return $this->sendFailResponse(__('messages.already_applied'), 422);
            }

            $compoffDetail = CompensatoryOff::where('uuid' , $compOff)->first(['id', 'uuid','employee_id','description','comp_off_date']);
            $compoffDetail->update([
                'employee_id' => $employeeId,
                'day_duration_id' => $inputs['duration'],
                'description' => $inputs['description'],
                'comp_off_date' => $inputs['date']
            ]);

            $adminUsers = $this->getAdminUser();
            $employee = User::where('entity_id', $employeeId)->first(['entity_id']);
            
            $info = ['employee_name' => $employee->display_name, 'date' => $compoffDetail->comp_off_date, 'description' => $compoffDetail->description, 'duration' => $durationName, 'compoff_id' => $compoffDetail->uuid];

            $data = new ApplyCompOff($info);

            $emailData = ['email' => $adminUsers, 'email_data' => $data];

            SendEmailJob::dispatch($emailData);

            $hrUsers = $this->getHRUser();

            $emailData = ['email' => $hrUsers, 'email_data' => $data];

            DB::commit();

            return $this->sendSuccessResponse(__('messages.compoff_update'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update comp off";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function destroy($compOff)
    {
        try {
            DB::beginTransaction();

            CompensatoryOff::where('uuid', $compOff)->delete();

            DB::commit();

            return $this->sendSuccessResponse(__('messages.delete_compensatory_request'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete compensatory request";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function pendingCompensatoryOff(Request $request)
    {
        try {

            $perPage = $request->perPage ?? 10;
            $employeeId = $request->employee ?? '';
            $organizationId = $this->getCurrentOrganizationId();

            $query = CompensatoryOff::withoutGlobalScopes([OrganizationScope::class])->join('compensatory_off_statuses', 'compensatory_offs.compensatory_off_status_id', 'compensatory_off_statuses.id')
                                    ->join('employees', function ($join) use($organizationId) {
                                        $join->on('compensatory_offs.employee_id', '=',  'employees.id');
                                        $join->where('employees.organization_id', $organizationId);
                                    })       
                                    
                                    ->where('compensatory_offs.organization_id', $organizationId)
                                    ->where('compensatory_off_status_id', CompensatoryOffStatus::PENDING);
            $query = $query->when($employeeId,function($q) use($employeeId){
                $q->where('employee_id', $employeeId);
            });

            $countQuery = clone $query;

            $pendingCompOffs = $query->orderBy('compensatory_offs.comp_off_date')
                           ->orderBy('compensatory_off_status_id')
                           ->select('compensatory_offs.id','compensatory_offs.uuid', 'compensatory_offs.created_at', 'compensatory_offs.comp_off_date','employee_id', 'employees.display_name', 'compensatory_off_statuses.name as status', 'day_duration_id')->simplePaginate($perPage);

            $total = $countQuery->count();

            $response = ['pending_compoff' => $pendingCompOffs, 'total_count' => $total];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while list pending compoff";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function updateCompensatoryOffStatus(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();

            $compOffId = $inputs['id'];
            $status = $inputs['status'];
            $comment = $inputs['comment'];

            $userId = Auth::user()->id;
            $organizationId = $this->getCurrentOrganizationId();

            $compOff = CompensatoryOff::where('uuid', $compOffId)->first(['id', 'employee_id', 'description', 'comp_off_date', 'day_duration_id']);

            if (!empty($status) && $status == 'approved') {

                CompensatoryOff::where('uuid', $compOffId)->update(['compensatory_off_status_id' => CompensatoryOffStatus::APPROVE, 'remarks' => $comment, 'action_date' => getDateTime(), 'action_by_id' => $userId]);
                $info['comp_off_action'] = $status;

                if ($compOff->day_duration_id == DayDuration::FULLDAY) {
                    $duration = 1;
                } else {
                    $duration = 0.5;
                }

                $employee = Employee::where('id', $compOff->employee_id)->where('organization_id', $organizationId)->first(['display_name', 'id']);

                $logData = ['organization_id' => $organizationId, 'new_data' => NULL, 'old_data' => NULL, 'action' => 'added ' . $duration . ' comp off for ' . $employee->display_name, 'table_name' => 'employees', 'updated_by' => $request->user()->id, 'module_id' => $employee->id, 'module_name' => 'COMPOFF'];

                $activityLog = new ActivityLog();
                $activityLog->createLog($logData);

            }

            if (!empty($status) && $status == 'rejected') {

                CompensatoryOff::where('uuid', $compOffId)->update(['compensatory_off_status_id' => CompensatoryOffStatus::REJECT, 'remarks' => $comment, 'action_date' => getDateTime(), 'action_by_id' => $userId]);
                $info['comp_off_action'] = $status;
            }

            if (!empty($status) && $status == 'cancelled') {
                CompensatoryOff::where('uuid', $compOffId)->update(['compensatory_off_status_id' => CompensatoryOffStatus::CANCEL, 'cancel_remarks' => $comment, 'action_date' => getDateTime(), 'action_by_id' => $userId]);
                $info['comp_off_action'] = $status;
            }

            $adminUsers = $this->getAdminUser();

            $info['comp_off_date'] = $compOff->comp_off_date;

            $data = new UpdateCompOffStatusMail($info);

            $emailData = ['email' => $adminUsers, 'email_data' => $data];

            SendEmailJob::dispatch($emailData);

            $hrUsers = $this->getHRUser();

            if (!empty($hrUsers)) {
                $emailData = ['email' => $hrUsers, 'email_data' => $data];

                SendEmailJob::dispatch($emailData);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update comp off status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
