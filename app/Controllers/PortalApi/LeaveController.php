<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\ApplyLeave;
use App\Mail\AddLeave;
use App\Mail\AutoLeaveEmail;
use App\Mail\DailyLeaveEmail;
use App\Mail\UpdateLeaveStatusMail;
use App\Models\ActivityLog;
use App\Models\CompensatoryOff;
use App\Models\CompensatoryOffStatus;
use App\Models\DateFormat;
use App\Models\DayDuration;
use App\Models\Employee;
use App\Models\EntityType;
use App\Models\ExceptionalWorkingDay;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceHistory;
use App\Models\LeaveCompensatoryOff;
use App\Models\LeaveDetail;
use App\Models\LeaveResetAction;
use App\Models\LeaveStatus;
use App\Models\LeaveType;
use App\Models\LeaveTypeAllowedDuration;
use App\Models\LeaveTypeType;
use App\Models\LopDetail;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\OrganizationWeekend;
use App\Models\Scopes\OrganizationScope;
use App\Models\Setting;
use App\Models\Timezone;
use App\Models\User;
use App\Traits\ResponseTrait;
use App\Validators\LeaveTypeValidator;
use App\Validators\LeaveValidator;
use Auth;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use DB;
use Illuminate\Http\Request;
use Log;
use PDF;

class LeaveController extends Controller
{
    use ResponseTrait;

    private $leaveTypeValidator;
    private $leaveValidator;

    public function __construct()
    {
        $this->leaveTypeValidator = new LeaveTypeValidator();
        $this->leaveValidator = new LeaveValidator();
    }

    public function importLeaveData()
    {
        DB::beginTransaction();
        try {

            $organizationId = 1;

            Leave::where('organization_id', $organizationId)->forceDelete();
            LeaveDetail::whereNull('deleted_at')->forceDelete();
            LeaveBalance::where('organization_id', $organizationId)->forceDelete();
            LeaveBalanceHistory::where('organization_id', $organizationId)->forceDelete();

            $leaves = DB::connection('old_connection')->table('leaves')->get();

            $i = 0;
            $filter = [];
            if (!empty($leaves)) {
                foreach ($leaves as $leave) {

                    $filter[$i][$leave->applied_date][$leave->employee_id][$leave->holiday_type][$leave->duration ?? 0][$leave->status][] = $leave;

                }

                foreach ($filter as $key => $leave) {
                    foreach ($leave as $appliedDate => $employee) foreach ($employee as $holidayType => $duration) {
                            foreach ($duration as $statusKey => $value) foreach ($value as $statusValue => $item) {
                                    foreach ($item as $new => $items) {

                                        if (is_array($items)) {
                                            $fromDate = $items[0]->leave_date;
                                            $toDate = $items[count($items) - 1]->leave_date;

                                            $startDate = Carbon::parse($fromDate);

                                            $endDate = Carbon::parse($toDate);

                                            $holidays = $this->getHolidayAndWeekend($fromDate, $toDate);
                                           
                                            $days = $startDate->diffInDaysFiltered(function (Carbon $date) use ($holidays) {
                                    
                                                if( $date->isWeekday() && !in_array($date, $holidays)){
                                                    return $date;
                                                }
                                    
                                            }, $endDate);
                                            $days = $days + 1;
                                      
                                            $totalWorkingDay = array_sum(array_column($items, 'holiday_type'));

                                            if ($days != $totalWorkingDay) {
                                                foreach ($items as $item) {

                                                    $employee = DB::connection('old_connection')->table('employees')->where('id', $items[0]->employee_id)->first(['id', 'employee_id']);

                                                    if (!empty($employee)) {
                                                        $employeeId = $employee->employee_id;
                                                    }

                                                    $systemLeave = 0;
                                                    if($item->system_leave == 0 && $item->reject_remarks == 'Auto Leave by system'){
                                                        $systemLeave = 2;
                                                    }
                                                    if($item->system_leave == 1 && $item->reject_remarks == 'Auto Leave by system'){
                                                        $systemLeave = 1;
                                                    }

                                                    $leave = Leave::create([
                                                        'uuid' => getUuid(),
                                                        'employee_id' => $employeeId,
                                                        'organization_id' => $organizationId,
                                                        'leave_type_id' => 1,
                                                        'from_date' => $item->leave_date,
                                                        'to_date' => $item->leave_date,
                                                        'total_working_days' => $item->holiday_type,
                                                        'description' => $item->description,
                                                        'applied_date' => $item->applied_date,
                                                        'leave_status_id' => $item->status,
                                                        'action_date' => $item->approve_date,
                                                        'action_by_id' => 2,
                                                        'remarks' => $item->reject_remarks,
                                                        'system_leave' => $systemLeave,
                                                        'created_at' => $item->created_at,
                                                        'deleted_at' => $item->deleted_at
                                                    ]);

                                                    if ($item->holiday_type == 0.5 && $item->duration == 1) {
                                                        $dayDuration = DayDuration::FIRSTHALF;
                                                    } else if ($item->holiday_type == 0.5 && $item->duration == 2) {
                                                        $dayDuration = DayDuration::SECONDHALF;
                                                    } else {
                                                        $dayDuration = DayDuration::FULLDAY;
                                                    }

                                                    LeaveDetail::create([
                                                        'leave_id' => $leave->id,
                                                        'leave_date' => $item->leave_date,
                                                        'day_duration_id' => $dayDuration,
                                                        'deleted_at' => $item->deleted_at
                                                    ]);

                                                }
                                            } else {

                                                $employee = DB::connection('old_connection')->table('employees')->where('id', $items[0]->employee_id)->first(['id', 'employee_id']);

                                                if (!empty($employee)) {
                                                    $employeeId = $employee->employee_id;
                                                }

                                                $systemLeave = 0;
                                                if($items[0]->system_leave == 0 && $items[0]->reject_remarks == 'Auto Leave by system'){
                                                    $systemLeave = 2;
                                                }
                                                if($items[0]->system_leave == 1 && $items[0]->reject_remarks == 'Auto Leave by system'){
                                                    $systemLeave = 1;
                                                }

                                                $leave = Leave::create([
                                                    'uuid' => getUuid(),
                                                    'employee_id' => $employeeId,
                                                    'organization_id' => $organizationId,
                                                    'leave_type_id' => 1,
                                                    'from_date' => $fromDate,
                                                    'to_date' => $toDate,
                                                    'total_working_days' => $totalWorkingDay,
                                                    'description' => $items[0]->description,
                                                    'applied_date' => $items[0]->applied_date,
                                                    'leave_status_id' => $items[0]->status,
                                                    'action_date' => $items[0]->approve_date,
                                                    'action_by_id' => 2,
                                                    'remarks' => $items[0]->reject_remarks,
                                                    'system_leave' => $systemLeave,
                                                    'created_at' => $items[0]->created_at,
                                                    'deleted_at' => $items[0]->deleted_at
                                                ]);
                                                foreach ($items as $item) {
                                                    if ($item->holiday_type == 0.5 && $item->duration == 1) {
                                                        $dayDuration = DayDuration::FIRSTHALF;
                                                    } else if ($item->holiday_type == 0.5 && $item->duration == 2) {
                                                        $dayDuration = DayDuration::SECONDHALF;
                                                    } else {
                                                        $dayDuration = DayDuration::FULLDAY;
                                                    }

                                                    LeaveDetail::create([
                                                        'leave_id' => $leave->id,
                                                        'leave_date' => $item->leave_date,
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


            $leaveType = LeaveType::where('name', 'Casual Leave')->first();
            $leavesDetail = DB::connection('old_connection')->table('leaves_detail')->get();
            $leavesDetailHistory = DB::connection('old_connection')->table('leave_detail_history')->get();
            foreach($leavesDetailHistory as $history){
                LeaveBalanceHistory::create(['employee_id' => $history->employee_id, 'leave_type_id' => $leaveType->id,'action_type' => 'accural', 'balance' => $history->total_credit_leaves,'total_balance' => $history->total_credit_leaves,'organization_id' => $organizationId, 'created_at' => $history->created_at]);
            }

            foreach ($leavesDetail as $leaveDetail) {
                LeaveBalance::create(['employee_id' => $leaveDetail->employee_id, 'leave_type_id' => $leaveType->id, 'balance' => $leaveDetail->total_credit_leaves,'organization_id' => $organizationId]);
              //  LeaveBalanceHistory::create(['employee_id' => $leaveDetail->employee_id, 'leave_type_id' => $leaveType->id,'action_type' => 'accural', 'balance' => $leaveDetail->total_credit_leaves,'total_balance' => $leaveDetail->total_credit_leaves,'organization_id' => $organizationId, 'created_at' => date('2023-06-01 00:00:00')]);
            }

            DB::commit();
            return $this->sendSuccessResponse(__('messages.leave_imported'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while leave imported";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function leaveTypeList(Request $request)
    {
        $leaveTypes = LeaveType::select('uuid', 'leave_types.name','leave_type_type_id', 'code', 'no_of_leaves', 'leaveType.name as leave_type_name', 'is_default', 'is_primary', DB::raw('group_concat(leave_durations.duration_id) as durations'))->leftJoin('leave_type_allowed_durations as leave_durations', 'leave_types.id', 'leave_durations.leave_type_id')->leftJoin('leave_type_types as leaveType', 'leave_types.leave_type_type_id', 'leaveType.id')->groupBy('leave_types.id')->orderBy('leave_types.name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $leaveTypes);
    }

    public function leaveTypeInformation()
    {
        $leaveTypes = LeaveTypeType::select('id', 'name')->where('name', '!=', LeaveTypeType::CompensatoryOff)->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $leaveTypes);
    }

    public function leaveTypeStore(Request $request)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $validation = $this->leaveTypeValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $data = [
                'uuid' => getUuid(),
                'name' => $inputs['name'],
                'organization_id' => $organizationId,
                'code' => $inputs['code'] ?? null,
                'color' => $inputs['color'] ?? null,
                'description' => $inputs['description'] ?? null,
                'no_of_leaves' => $inputs['no_of_leaves'] ?? 0,
                'leave_type_type_id' => $inputs['leave_type_type_id'],
                'accrual' => $inputs['accrual'] ?? 0,
                'accrual_period' => $inputs['accrual_period'] ?? null,
                'accrual_date' => $inputs['accrual_date'] ?? null,
                'accrual_month' => $inputs['accrual_month'] ?? null,
                'reset' => $inputs['reset'] ?? 0,
                'reset_period' => $inputs['reset_period'] ?? null,
                'reset_date' => $inputs['reset_date'] ?? null,
                'reset_month' => $inputs['reset_month'] ?? null,
                'encashment' => $inputs['encashment'] ?? 0,
                'carryforward' => $inputs['carryforward'] ?? 0,

            ];

            $leaveType = LeaveType::create($data);

            $fullDay = $inputs['full_day'];
            if (!empty($fullDay)) {
                LeaveTypeAllowedDuration::create(['leave_type_id' => $leaveType->id, 'duration_id' => DayDuration::FULLDAY]);
            }

            $halfDay = $inputs['half_day'];
            if (!empty($halfDay)) {
                LeaveTypeAllowedDuration::create(['leave_type_id' => $leaveType->id, 'duration_id' => DayDuration::FIRSTHALF]);
                LeaveTypeAllowedDuration::create(['leave_type_id' => $leaveType->id, 'duration_id' => DayDuration::SECONDHALF]);
            }

            $employees = Employee::withoutGlobalScopes([OrganizationScope::class])->active()->where('employees.organization_id', $organizationId)->select('employees.id','employees.join_date', 'employees.display_name')->get();
            if(!empty($employees)){
                foreach($employees as $employee){
                    $data = ['employee_id' => $employee->id, 'organization_id' => $organizationId, 'leave_type_id' => $leaveType->id];
                    LeaveBalance::firstOrCreate($data);
                    LeaveBalanceHistory::firstOrCreate($data);
                }
            }

            $logData = ['organization_id' => $organizationId ,'new_data' => NULL, 'old_data' => NULL, 'action' => 'added leave type '.$leaveType->name, 'table_name' => 'leave_types','updated_by' => $request->user()->id, 'module_id' => $request->user()->id, 'module_name' => 'LMS'];
                    
            $activityLog = new ActivityLog();
            $activityLog->createLog($logData);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.leave_type_store'), 200, $leaveType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add leave type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function leaveTypeDetail($leaveType)
    {
        $data = LeaveType::where('uuid',$leaveType)->leftJoin('leave_type_allowed_durations', 'leave_types.id', 'leave_type_allowed_durations.leave_type_id')
                        ->select('name','code','description','no_of_leaves','leave_type_type_id','accrual','accrual_period','accrual_date','accrual_month','reset','reset_period',
                                 'reset_date','reset_month','encashment','carryforward',DB::raw('GROUP_CONCAT(leave_type_allowed_durations.duration_id) as day_durations'))->first();
        $data->full_day = false;
        $data->half_day = false;
        $duration = explode(',', $data->day_durations);
        if (in_array(DayDuration::FULLDAY, $duration)) {
            $data->full_day = true;
        }

        if (in_array(DayDuration::FIRSTHALF, $duration)) {
            $data->half_day = true;
        }

        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function updateLeaveType(Request $request, $leaveType)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();

            $organizationId = $this->getCurrentOrganizationId();
            $validation = $this->leaveTypeValidator->validateUpdate($request, $leaveType, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

           $leaveTypeData = LeaveType::where('uuid', $leaveType)->first(['id', 'name']);

            $data = [
                'name' => $inputs['name'],
                'code' => $inputs['code'] ?? null,
                'color' => $inputs['color'] ?? null,
                'description' => $inputs['description'] ?? null,
                'no_of_leaves' => $inputs['no_of_leaves'] ?? 0,
                'leave_type_type_id' => $inputs['leave_type_type_id'],
                'accrual' => $inputs['accrual'] ?? 0,
                'accrual_period' => $inputs['accrual_period'] ?? null,
                'accrual_date' => $inputs['accrual_date'] ?? null,
                'accrual_month' => $inputs['accrual_month'] ?? null,
                'reset' => $inputs['reset'] ?? 0,
                'reset_period' => $inputs['reset_period'] ?? null,
                'reset_date' => $inputs['reset_date'] ?? null,
                'reset_month' => $inputs['reset_month'] ?? null,
                'encashment' => $inputs['encashment'] ?? 0,
                'carryforward' => $inputs['carryforward'] ?? 0,
            ];

            $leaveTypeData->update($data);

            if(!empty($inputs['full_day'])){
                $leaveDuration = LeaveTypeAllowedDuration::where('leave_type_id', $leaveTypeData->id)->where('duration_id', DayDuration::FULLDAY)->first();
                
                if(empty($leaveDuration)){
                    LeaveTypeAllowedDuration::firstOrCreate([
                        'leave_type_id' => $leaveTypeData->id,
                        'duration_id' => DayDuration::FULLDAY
                    ]);    
                }
                
            }else{
                LeaveTypeAllowedDuration::where('leave_type_id', $leaveTypeData->id)->where('duration_id', DayDuration::FULLDAY)->delete();
            }

            if(!empty($inputs['half_day'])){
                $leaveDuration = LeaveTypeAllowedDuration::where('leave_type_id', $leaveTypeData->id)->where('duration_id', DayDuration::FIRSTHALF)->first();
                
                if(empty($leaveDuration)){
                    LeaveTypeAllowedDuration::firstOrCreate([
                        'leave_type_id' => $leaveTypeData->id,
                        'duration_id' => DayDuration::FIRSTHALF
                    ]);   
                    LeaveTypeAllowedDuration::firstOrCreate([
                        'leave_type_id' => $leaveTypeData->id,
                        'duration_id' => DayDuration::SECONDHALF
                    ]); 
                }
            }else{
                LeaveTypeAllowedDuration::where('leave_type_id', $leaveTypeData->id)->where('duration_id', DayDuration::FIRSTHALF)->delete();
                LeaveTypeAllowedDuration::where('leave_type_id', $leaveTypeData->id)->where('duration_id', DayDuration::SECONDHALF)->delete();
            }
            
            $logData = ['organization_id' => $organizationId ,'new_data' => NULL, 'old_data' => NULL, 'action' => 'changed leave type '.$leaveTypeData->name, 'table_name' => 'leave_types','updated_by' => $request->user()->id, 'module_id' => $request->user()->id, 'module_name' => 'LMS'];
                    
            $activityLog = new ActivityLog();
            $activityLog->createLog($logData);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.leave_type_update'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update leave type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function deleteLeaveType($leaveType)
    {
        try {
            DB::beginTransaction();

            LeaveType::where('uuid', $leaveType)->delete();

            DB::commit();

            return $this->sendSuccessResponse(__('messages.leave_type_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete leave type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getLMSSettings(Request $request)
    {
        try {

            $organizationId = $this->getCurrentOrganizationId();
            $weekends = OrganizationWeekend::where('organization_id', $organizationId)->get('week_day');
            $settings = Setting::leftJoin('organization_settings', 'settings.id', 'organization_settings.setting_id')->where('organization_id', $organizationId)->get(['settings.key', 'organization_settings.value'])->pluck('value', 'key')->toArray();

            $employees = Employee::withoutGlobalScopes([OrganizationScope::class])->active()->select('employees.id', 'display_name', 'avatar_url')->where('employees.organization_id', $organizationId)->get();

            $settings['week_data'] = $weekends;
            $settings['employees'] = $employees;

            return $this->sendSuccessResponse(__('messages.success'), 200, $settings);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get LMS settings";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function setLMSSettings(Request $request)
    {
        try {
            DB::beginTransaction();

            $organizationId = $this->getCurrentOrganizationId();

            $requestKeys = collect($request->all())->keys();

            $user = Auth::user();

            foreach($requestKeys as $key){

                if (!empty($request->week_day) && $key == 'week_day') {
                    $weekDay = $request->week_day;
                    $weekend = OrganizationWeekend::where('week_day', $weekDay)->first(['week_day']);
                    $newDay = jddayofweek($weekDay-1, 1);
                    if (empty($weekend)) {
                        OrganizationWeekend::create(['organization_id' => $organizationId, 'week_day' => $weekDay]);
                        $action = 'add weekend '.$newDay;
                        
                    } else {
                        OrganizationWeekend::where('week_day', $weekDay)->delete();
                        $action = 'remove weekend '.$newDay;
                    }

                    $logData = ['organization_id' => $organizationId ,'new_data' => NULL, 'old_data' => NULL, 'action' => ' has ' .$action, 'table_name' => 'settings','updated_by' => $user->id, 'module_id' => $user->id, 'module_name' => 'LMS'];
                    
                    $activityLog = new ActivityLog();
                    $activityLog->createLog($logData);

                    continue;
                }

                if($request->has('default_to_email') && empty($request->default_to_email)){
                    $request->{$key} = ' ';
                }

                if($request->has('send_mail_for_auto_leave_to') && empty($request->send_mail_for_auto_leave_to)){
                    $request->{$key} = ' ';
                }

                if($request->has('send_today_leave_details_to') && empty($request->send_today_leave_details_to)){
                    $request->{$key} = ' ';
                }

                $this->updateSettings($key, $request->{$key}, $organizationId, $user);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while store organization LMS settings";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function updateSettings($key, $value, $organizationId, $user)
    {
       
        if (isset($key)) {
            $setting = Setting::where('key', $key)->first(['id','key', 'label', 'type']);
            $organizationSetting = OrganizationSetting::where('setting_id', $setting->id)->first(['value', 'id']);
            if (!empty($organizationSetting)) {
                OrganizationSetting::where('id', $organizationSetting->id)->update(['value' => $value]);
            } else {
                OrganizationSetting::create(['organization_id' => $organizationId, 'setting_id' => $setting->id, 'value' => $value]);
            }

            if($setting->type == 'checkbox'){
                $value = (bool) $value;
                $organizationSetting->value= (bool) $organizationSetting->value;
            }

            $logData = ['organization_id' => $organizationId ,'new_data' => json_encode(["plain" => $value]), 'old_data' => json_encode(["plain" => $organizationSetting->value]), 'action' => ' has changed '.$setting->label, 'table_name' => 'settings','updated_by' => $user->id, 'module_id' => $setting->id, 'module_name' => 'LMS'];
            if (($key =='send_today_leave_details_to' || $key == 'send_mail_for_auto_leave_to' || $key == 'default_to_email') && !empty($value)) {
                $logData = ['organization_id' => $organizationId ,'new_data' => json_encode(["display_name" => $value]), 'old_data' => json_encode(["display_name" => $organizationSetting->value]), 'action' => ' has changed '.$setting->label, 'table_name' => 'employees','updated_by' => $user->id, 'module_id' => $user->id, 'module_name' => 'LMS'];
            }
            
            $activityLog = new ActivityLog();
            $activityLog->createLog($logData);
        }
    }

    public function applyLeaveInformation()
    {
        try {

            $leaveTypes = LeaveType::join('leave_type_types', 'leave_types.leave_type_type_id', 'leave_type_types.id')->select('leave_types.id', 'leave_types.name','leave_type_types.name as leave_type_type_name')->get();

            $user = Auth::user();
            $organizationId = $this->getCurrentOrganizationId();

            $employees = Employee::withoutGlobalScopes([OrganizationScope::class])->active()->select('employees.id', 'display_name', 'avatar_url')->where('employees.organization_id', $organizationId)->get();

            $to = $employees;
            if(!in_array('administrator',$user->roles->pluck('slug')->toArray())){
                $to = $employees->except($user->entity_id);
            }

            $setting = Setting::where('key', 'default_to_email')->first(['id']);
            $organizationSetting = OrganizationSetting::where('setting_id', $setting->id)->first(['value', 'id']);
	    	$defaultTo = !empty($organizationSetting) ? $organizationSetting->value : '';

            $response = ['leave_types' => $leaveTypes, 'employees' => $employees, 'to' => $to, 'defaultTo' => $defaultTo];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get the apply leave information";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getLeaveTypeBalanceSummary(Request $request)
    {
        $fromDate = $request->from_date;
        $toDate = $request->to_date;
        $leaveType = $request->leave_type;
        $leaveId = $request->leave_id;
        $employeeId = $request->employee_id;

        if(!empty($leaveId)){
            $leave = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_statuses', 'leaves.leave_status_id', 'leave_statuses.id')->join('leave_types', 'leaves.leave_type_id', 'leave_types.id')->join('employees', 'leaves.employee_id', 'employees.id')->where('leaves.uuid', $leaveId)->first(['leaves.id','leaves.uuid', 'employee_id', 'employees.display_name', 'leave_type_id', 'from_date', 'to_date', 'total_working_days','leaves.description', 'applied_date', 'to', 'cc' ,'action_date', 'remarks', 'cancel_remarks', 'leave_statuses.name as leave_status', 'leave_types.name as leave_type_name']);
            $leaveId = $leave->id;
            $employeeId = $leave->employee_id;    
        }
 
        $leaveDetails = [];
        if(!empty($leaveId)){
            $leaveDetails  = LeaveDetail::where('leave_id', $leaveId)->get(['day_duration_id', 'leave_date'])->pluck('day_duration_id', 'leave_date');
        }

        $response = $this->getSummary($fromDate, $toDate, $leaveType, $employeeId, $leaveDetails,$leaveId);

        return $this->sendSuccessResponse(__('messages.success'), 200, $response);
    }

    public function getSummary($fromDate, $toDate, $leaveType, $employeeId, $leaveDetails,$leaveId)
    {
        $currentDay = date('N');
        $totalDays = 0;
        $leaveDays = [];
        $allowedDuration = '';
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

            $allowedDuration = LeaveTypeAllowedDuration::join('day_durations', 'leave_type_allowed_durations.duration_id', 'day_durations.id')->where('leave_type_id', $leaveType)->orderBy('day_durations.id')->get(['leave_type_allowed_durations.duration_id as id', 'duration as name'])->toArray();

            $period = CarbonPeriod::create($fromDate, $toDate);

            // Iterate over the period
            foreach ($period as $key => $date) {
                $current = $date->format('Y-m-d');
                $leaveDays[$key]['date'] = $current;
                if(!empty($leaveDetails[$current])){
                    $leaveDays[$key]['selectedDuration'] = $leaveDetails[$current];

                    if($leaveDetails[$current] == DayDuration::FIRSTHALF || $leaveDetails[$current] == DayDuration::SECONDHALF){
                        $totalDays = $totalDays - 0.5;
                    }
                }else{
                    $totalDays = count($allowedDuration) > 0 && $allowedDuration[0]['id'] == 2 ? $totalDays - 0.5 : $totalDays;
                    $leaveDays[$key]['selectedDuration'] = count($allowedDuration) > 0 ? $allowedDuration[0]['id'] : 1;
                }
            
                $leaveDays[$key]['isHoliday'] = in_array($current, $holiday);
                $leaveDays[$key]['isWeekend'] = in_array($current, $filteredWeekend);
            }
        }

        $user = Auth::user();

        $employeeId = !empty($employeeId) ? $employeeId : $user->entity_id;

        $employee = Employee::where('id', $employeeId)->first(['probation_period_end_date']);
        $leaveTypeDetail = LeaveType::withTrashed()->join('leave_type_types', 'leave_types.leave_type_type_id', 'leave_type_types.id')->where('leave_types.id', $leaveType)->first(['leave_types.id','leave_types.organization_id', 'leave_types.name', 'leave_type_types.name as leave_type_type_name','accrual_period','accrual_date','accrual_month', 'reset_period','reset_date','reset_month']);

        // Get current refill period of the leave type to be used in the calculation of the leave balance minus total current period leaves to display available balance when apply leave
        $accrualPeriod = $leaveTypeDetail->accrual_period;
        $accrualDate = $leaveTypeDetail->accrual_date;
        $accrualMonth = $leaveTypeDetail->accrual_month;
        
        $resetPeriod = $leaveTypeDetail->reset_period;
        $resetDate = $leaveTypeDetail->reset_date;
        $resetMonth = $leaveTypeDetail->reset_month;


        $date = date('j');
        $month = date('n');
        $lastDay = config('constant.last_day');
        $periodConfig = config('constant.job_schedule_period');
        if ($accrualPeriod == $periodConfig['Yearly']) {
            if($accrualDate == $lastDay){
                $accrualDate = Carbon::parse(date('Y-'.$accrualMonth.'-t'))->endOfMonth()->format('d');
            }

            $to = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->addYear()->format('Y-m-d');            
        }

        if ($accrualPeriod == $periodConfig['Half yearly']) {
            $monthList = config('constant.half_year_month_list');
            $accrualMonth = $monthList[$leaveTypeDetail->accrual_month];

            if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                $month =  $accrualMonth[0];
                $monthEnd = $accrualMonth[1];
            }else if($month < $accrualMonth[0]){
                $month =  $accrualMonth[1];
                $monthEnd = $accrualMonth[0];
            }else if($month >= $accrualMonth[1]){
                $month =  $accrualMonth[1];
                $monthEnd = $accrualMonth[0];
            }

            if($accrualDate == $lastDay){
                $accrualDate = Carbon::parse(date('Y-'.$month.'-t'))->endOfMonth()->format('d');
            }
            $accrualDate = Carbon::parse(date('Y-'.$month .'-'.$accrualDate))->format('d');
            $accrualMonth = $month;
            $year = date('Y');
            if(date('n') > $monthEnd){
                 $year = Carbon::parse(date('Y'))->addYear()->format('Y');
            }
                
            $to = Carbon::parse(date($year.'-' . $monthEnd . '-' . $accrualDate))->format('Y-m-d');
        }

        if ($accrualPeriod == $periodConfig['Quarterly']) {
            $monthList = config('constant.quartarly_month_list');
            $accrualMonth = $monthList[$leaveTypeDetail->accrual_month];

            if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                $currQuarter = 1;
                $endQuarter = 1;
               
            }

            if ($month >= $accrualMonth[1] && $month < $accrualMonth[2]) {
                $currQuarter = 2;
                $endQuarter = 2;
              
            }

            if ($month >= $accrualMonth[2] && $month < $accrualMonth[3]) {
                $currQuarter = 3;
                $endQuarter = 3;
             
            }

            if ($month < $accrualMonth[0] || $month >= $accrualMonth[3]) {
                $currQuarter = 4;
                $endQuarter = 0;
            }

            $monthCal = $accrualMonth[$currQuarter - 1];               
            $month = $monthCal;
            if ($accrualDate == $lastDay) {
                $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
            }
            $monthEndCal = $accrualMonth[$endQuarter];
            $accrualMonth = $month;

            $year = date('Y');
            if($monthEndCal < date('n')){
              $year = Carbon::parse(date('Y'))->addYear()->format('Y');
            }

            $to = Carbon::parse(date($year.'-' . $monthEndCal . '-' . $accrualDate))->format('Y-m-d');

        }

        if ($accrualPeriod == $periodConfig['Monthly']) {
            if($accrualDate == $lastDay){
                $accrualDate = Carbon::parse(date('Y-'.$month.'-t'))->endOfMonth()->format('d');
            }
            $accrualMonth = $month;
            $to = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->addMonth()->format('Y-m-d');  
            
        }

        if ($resetPeriod == $periodConfig['Yearly']) {
            if ($resetDate == $lastDay) {
                $resetDate = Carbon::parse(date('Y-' . $resetMonth . '-t'))->endOfMonth()->format('d');
            }
        }

        if ($resetPeriod == $periodConfig['Half yearly']) {
            $monthList = config('constant.half_year_month_list');
            $resetMonth = $monthList[$resetMonth];
            if ($month >= $resetMonth[0] && $month < $resetMonth[1]) {
                $month = $resetMonth[0];
                $monthEnd = $resetMonth[1];
                $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
            } else if ($month < $resetMonth[0]) {
                $month = $resetMonth[1];
                $monthEnd = $resetMonth[0];
                $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
            } else if ($month >= $resetMonth[1]) {
                $month = $resetMonth[1];
                $monthEnd = $resetMonth[0];
                $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
            }

            $resetDate = Carbon::parse(date('Y-' . $month . '-' . $resetDate))->addDay()->format('d');
            if ($resetDate == $lastDay) {
                $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
            }

            $resetMonth = $month;

        }

        if ($resetPeriod == $periodConfig['Quarterly']) {
            $month = date('m');
            $monthList = config('constant.quartarly_month_list');
            $resetMonth = $monthList[$resetMonth];
            $currQuarter = round(($month - 1) / 3 + 1);
            $monthCal = 3 * $currQuarter - 2;

            $month = $monthCal;
            if ($resetDate == $lastDay) {
                $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
            }
            $resetMonth = $month;

            $monthEndCal = (3 * $currQuarter) + 1;


        }

        if ($resetPeriod == $periodConfig['Monthly']) {

            if ($resetDate == $lastDay) {
                $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
            }
            $resetMonth = $month;

        }
        $from = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->format('Y-m-d');
        
        $compOffs = [];
        if ($leaveTypeDetail->leave_type_type_name != LeaveTypeType::CompensatoryOff) {
            $balance = LeaveBalance::where('employee_id', $employeeId)->where('organization_id', $user->organization_id)->where('leave_type_id', $leaveType)->first(['balance']);
            if (!empty($balance)) {

                if($employee->probation_period_end_date > $from){
                    $probationLeaves = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('employee_id', $employeeId)->where('organization_id', $leaveTypeDetail->organization_id)->where('leaves.leave_status_id', LeaveStatus::APPROVE)
                    ->select(DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))
                   ->where('leave_type_id', $leaveTypeDetail->id)->whereBetween('leave_date',[$from,$employee->probation_period_end_date])->get()->SUM('total_days');
                }

                $totalLeaves = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('employee_id', $employeeId)->where('organization_id', $leaveTypeDetail->organization_id)->where('leaves.leave_status_id', LeaveStatus::APPROVE)
                ->select(DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))
               ->where('leave_type_id', $leaveTypeDetail->id)->whereBetween('leave_date',[$from,$to])->get()->SUM('total_days');

                $balance = !empty($probationLeaves) ?  $balance->balance - ($totalLeaves - $probationLeaves) : $balance->balance - $totalLeaves;
            }
        } else {

            $totalRequests = CompensatoryOff::withoutGlobalScopes([OrganizationScope::class])
                ->leftJoin('leaves_compensatory_offs', 'compensatory_offs.id', 'leaves_compensatory_offs.compensatory_offs_id')
                ->leftjoin('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')
                ->where(function ($item) {
                    $item->whereNull('leaves_compensatory_offs.compensatory_offs_id');
                    $item->orWhereIn('leaves.leave_status_id', [LeaveStatus::REJECT, LeaveStatus::CANCEL]);
                    $item->orWhere(function ($que) {
                        $que->where('leaves_compensatory_offs.duration', '!=', DayDuration::FULLDAY);
                        $que->where('compensatory_offs.day_duration_id', DayDuration::FULLDAY);
                    });
                })->where('compensatory_offs.employee_id', $employeeId)
                ->where('compensatory_offs.organization_id', $user->organization_id)
                ->where('compensatory_offs.comp_off_date', '<=', getUtcDate())
                ->where('compensatory_off_status_id', CompensatoryOffStatus::APPROVE)
                ->select('leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.uuid', 'compensatory_offs.comp_off_date', 'compensatory_offs.day_duration_id', 'leaves_compensatory_offs.duration', 'leaves_compensatory_offs.leave_id')

                ->get();

            $compOffId = $totalRequests->pluck('compensatory_offs_id')->toArray();

            $existRequest = LeaveCompensatoryOff::join('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')->join('compensatory_offs', 'leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.id')->whereIn('compensatory_offs_id', $compOffId)->whereIn('leave_status_id', [1, 2])->select('leaves_compensatory_offs.compensatory_offs_id')->get()->pluck('compensatory_offs_id')->toArray();

            $totalRequests = array_filter($totalRequests->toArray(), function ($item) use ($existRequest) {
                if (!in_array($item['compensatory_offs_id'], $existRequest) || ($item['day_duration_id'] == 1 && $item['duration'] == '0.5')) {
                    if ($item['duration'] == '0.5') {
                        $halfDays = LeaveCompensatoryOff::join('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')->join('compensatory_offs', 'leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.id')->where('compensatory_offs_id', $item['compensatory_offs_id'])->whereIn('leave_status_id', [1, 2])->select('leaves_compensatory_offs.compensatory_offs_id', 'leaves_compensatory_offs.duration')->get();

                        if ($halfDays->sum('duration') != 1) {
                            return $item;
                        }

                    } else {
                        return $item;
                    }
                }
            });

            if (!empty($leaveId)) {

                $compOff = CompensatoryOff::join('leaves_compensatory_offs', 'compensatory_offs.id', 'leaves_compensatory_offs.compensatory_offs_id')->where('leave_id', $leaveId)->select('uuid', 'comp_off_date', 'leaves_compensatory_offs.duration')->get()->toArray();

                $compOffs['used'] = $compOff;
                $totalRequests = array_merge($totalRequests, $compOffs['used']);
            }

            $compOffs['available'] = !empty($totalRequests) ? array_values($totalRequests) : [];

        }

        $availableBalance = 0;
        $remainingBalance = 0;
        if (!empty($balance)) {
            $availableBalance = $balance >= 0 ? round($balance,2) : 0;
            $remainingBalance = round(($availableBalance - $totalDays),2);
        } else {
            $remainingBalance = round(-$totalDays,2);
        }

        $response = ['available_balance' => $availableBalance, 'currently_booked' => $totalDays, 'remaianing' => $remainingBalance, 'leaveDays' => $leaveDays, 'allowedDuration' => $allowedDuration, 'comp_offs' => $compOffs];

        return $response;
    }

    public function storeExceptionalWorkingDay(Request $request)
    {
        try {
            DB::beginTransaction();

            $organizationId = $this->getCurrentOrganizationId();

            $inputs = $request->all();

            $date = $inputs['date'];
            $name = $inputs['name'] ?? '';
            $description = $inputs['description'] ?? '';

            $fromDate = \Carbon\Carbon::parse($date)->startOfMonth()->toDateString();

            $toDate = \Carbon\Carbon::parse($date)->endOfMonth()->toDateString();

            $weekends = $this->getHolidayAndWeekend($fromDate, $toDate);

            $exist = false;
            if (is_array($weekends)) {
                $exist = !in_array($date, $weekends);
            }

            if (!empty($exist)) {
                return $this->sendFailResponse(__('messages.weekend_date_required'), 422);
            }

            if (!empty($inputs['uuid'])) {
                ExceptionalWorkingDay::where('uuid', $inputs['uuid'])->update(['name' => $name, 'date' => $date, 'description' => $description]);

                $message = __('messages.exceptional_working_day_update');
            } else {

                $dateExist = ExceptionalWorkingDay::where('date', $inputs['date'])->first();
                if(!empty($dateExist)){
                    return $this->sendFailResponse(__('messages.date_exist'), 422);
                }

                ExceptionalWorkingDay::create(['organization_id' => $organizationId, 'uuid' => getUuid(), 'name' => $name, 'date' => $date, 'description' => $description]);
                $message = __('messages.exceptional_working_day_store');
            }

            DB::commit();

            return $this->sendSuccessResponse($message, 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while store exceptional working day";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function listExceptionalWorkingDay(Request $request)
    {
        try {

            $inputs = $request->all();

            $perPage = $request->perPage ?? '';
            $year = $inputs['year'];
            $data = ExceptionalWorkingDay::whereYear('date', $year)->select('uuid', 'name', 'date', 'description')->simplePaginate($perPage);

            $total = ExceptionalWorkingDay::whereYear('date', $year)->count();

            $response = ['data' => $data, 'total_count' => $total];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while list exceptional working day";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function deleteExceptionalWorkingDay($uuid)
    {
        try {
            DB::beginTransaction();

            ExceptionalWorkingDay::where('uuid', $uuid)->delete();

            DB::commit();

            return $this->sendSuccessResponse(__('messages.exceptional_working_day_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete exceptional working day";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function storeLeave(Request $request)
    {
        try {
            DB::beginTransaction();

            $organizationId = $this->getCurrentOrganizationId();

            $inputs = $request->all();

            $validation = $this->leaveValidator->validate($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            if ($inputs['totalDays'] <= 0) {
                return $this->sendFailResponse(__('messages.holiday_leave'), 422);
            }
            $user = Auth::user();
            $currentUserId = $user->entity_id;
           
            $employeeId = !empty($inputs['employee_id']) ? $inputs['employee_id'] : $currentUserId;
            $leaveDays = array_column($inputs['leaveDays'], 'date');
            $selectedDuration = array_column($inputs['leaveDays'], 'selectedDuration');
            $newLeaves = array_combine($leaveDays, $selectedDuration);
            
            $leaveExist = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->whereBetween('leave_date', [$inputs['start_date'], $inputs['end_date']])->where('employee_id', $employeeId)->whereIn('leaves.leave_status_id', [LeaveStatus::PENDING, LeaveStatus::APPROVE])->whereNull('leave_details.deleted_at')->select('leaves.id', 'day_duration_id', 'leave_date')->get();
            foreach ($leaveExist as $val) {
                if ($val->day_duration_id == DayDuration::FULLDAY) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                } elseif ($val->day_duration_id == DayDuration::FIRSTHALF && $newLeaves[$val->leave_date] == DayDuration::FIRSTHALF) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                } elseif ($val->day_duration_id == DayDuration::SECONDHALF && $newLeaves[$val->leave_date] == DayDuration::SECONDHALF) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                } elseif (in_array($val->day_duration_id, [DayDuration::FIRSTHALF, DayDuration::SECONDHALF]) && $newLeaves[$val->leave_date] == DayDuration::FULLDAY) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                }
            }
           
            $leaveTypeDetail = LeaveType::join('leave_type_types', 'leave_types.leave_type_type_id', 'leave_type_types.id')->where('leave_types.id', $inputs['leave_type'])->first(['leave_types.id', 'leave_types.name', 'leave_type_types.name as leave_type_type_name']);
            if ($leaveTypeDetail->leave_type_type_name == LeaveTypeType::CompensatoryOff) {
               
                $applyCompOff = CompensatoryOff::withoutGlobalScopes([OrganizationScope::class])
                    ->leftJoin('leaves_compensatory_offs', 'compensatory_offs.id', 'leaves_compensatory_offs.compensatory_offs_id')
                    ->leftjoin('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')
                    ->where(function ($item) {

                        $item->whereNull('leaves_compensatory_offs.compensatory_offs_id');
                        $item->orWhereIn('leaves.leave_status_id', [LeaveStatus::REJECT, LeaveStatus::CANCEL]);
                        $item->orWhere(function ($que) {
                            $que->where('leaves_compensatory_offs.duration', '!=', DayDuration::FULLDAY);
                            $que->where('compensatory_offs.day_duration_id', DayDuration::FULLDAY);
                        });
                    })->where('compensatory_offs.employee_id', $employeeId)
                    ->where('compensatory_offs.organization_id', $organizationId)
                    ->where('compensatory_offs.comp_off_date', '<=', getUtcDate())
                    ->where('compensatory_off_status_id', CompensatoryOffStatus::APPROVE)
                    ->select('compensatory_offs.id', 'leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.uuid', 'compensatory_offs.comp_off_date', 'compensatory_offs.day_duration_id', 'leaves_compensatory_offs.duration', 'leaves_compensatory_offs.leave_id', DB::raw('(CASE WHEN (day_duration_id = 1 && duration = "1")   THEN 1 WHEN ( duration IS NULL && day_duration_id = 1) THEN  1 WHEN ( duration IS NULL && day_duration_id != 1) THEN 0.5 ELSE "0.5" END) total_days'))
                    ->get();
                    
                $compOffId = $applyCompOff->pluck('compensatory_offs_id')->toArray();
               
                $existRequest = LeaveCompensatoryOff::join('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')
                    ->join('compensatory_offs', 'leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.id')
                    ->whereIn('compensatory_offs_id', $compOffId)
                    ->whereIn('leave_status_id', [LeaveStatus::PENDING, LeaveStatus::APPROVE])
                    ->select('leaves_compensatory_offs.compensatory_offs_id')
                    ->get()->pluck('compensatory_offs_id')->toArray();
                  
                $applyCompOff = array_filter($applyCompOff->toArray(), function ($item) use ($existRequest) {
                
                    if (!in_array($item['compensatory_offs_id'], $existRequest) || ($item['day_duration_id'] == DayDuration::FULLDAY && $item['duration'] == '0.5')) {
                 
                        if ($item['duration'] == '0.5') {
                            $halfDays = LeaveCompensatoryOff::join('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')
                                ->join('compensatory_offs', 'leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.id')
                                ->where('compensatory_offs_id', $item['compensatory_offs_id'])
                                ->whereIn('leave_status_id', [LeaveStatus::PENDING, LeaveStatus::APPROVE])
                                ->select('leaves_compensatory_offs.compensatory_offs_id', 'leaves_compensatory_offs.duration')->get();

                            if ($halfDays->sum('duration') != 1) {
                                return $item;
                            }

                        } else {
                            return $item;
                        }
                    }
                });
             

                $applyCompOff = collect($applyCompOff)->sum('total_days');
                if ($inputs['totalDays'] > $applyCompOff) {
                    return $this->sendFailResponse(__('messages.comp_off_not_available'), 422);
                }
               
                $allCompOff = CompensatoryOff::withoutGlobalScopes([OrganizationScope::class])
                    ->leftJoin('leaves_compensatory_offs', 'compensatory_offs.id', 'leaves_compensatory_offs.compensatory_offs_id')
                    ->leftjoin('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')
                    ->where(function ($item) {

                        $item->whereNull('leaves_compensatory_offs.compensatory_offs_id');
                        $item->orWhereIn('leaves.leave_status_id', [LeaveStatus::REJECT, LeaveStatus::CANCEL]);
                        $item->orWhere(function ($que) {
                            $que->where('leaves_compensatory_offs.duration', '!=', DayDuration::FULLDAY);
                            $que->where('compensatory_offs.day_duration_id', DayDuration::FULLDAY);
                        });
                    })->where('compensatory_offs.employee_id', $employeeId)->where('compensatory_offs.organization_id', $organizationId)
                    ->where('compensatory_offs.comp_off_date', '<=', getUtcDate())
                    ->where('compensatory_off_status_id', CompensatoryOffStatus::APPROVE)
                    ->select('leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.id', 'compensatory_offs.comp_off_date', 'compensatory_offs.day_duration_id', 'leaves_compensatory_offs.duration', 'leaves_compensatory_offs.leave_id')
                    ->groupBy('compensatory_offs.comp_off_date')
                    ->get();

                $compOffId = $allCompOff->pluck('compensatory_offs_id')->toArray();

                $existRequest = LeaveCompensatoryOff::join('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')
                    ->join('compensatory_offs', 'leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.id')
                    ->whereIn('compensatory_offs_id', $compOffId)->whereIn('leave_status_id', [LeaveStatus::PENDING, LeaveStatus::APPROVE])
                    ->select('leaves_compensatory_offs.compensatory_offs_id')
                    ->get()->pluck('compensatory_offs_id')->toArray();

                $allCompOff = array_filter($allCompOff->toArray(), function ($item) use ($existRequest) {
                    if (!in_array($item['compensatory_offs_id'], $existRequest) || ($item['day_duration_id'] == DayDuration::FULLDAY && $item['duration'] == '0.5')) {
                        if ($item['duration'] == '0.5') {
                            $halfDays = LeaveCompensatoryOff::join('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')
                                ->join('compensatory_offs', 'leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.id')
                                ->where('compensatory_offs_id', $item['compensatory_offs_id'])
                                ->whereIn('leave_status_id', [LeaveStatus::PENDING, LeaveStatus::APPROVE])
                                ->select('leaves_compensatory_offs.compensatory_offs_id', 'leaves_compensatory_offs.duration')
                                ->get();

                            if ($halfDays->sum('duration') != 1) {
                                return $item;
                            }

                        } else {
                            return $item;
                        }
                    }
                });
               

                $fullComp = array_filter($allCompOff, function ($comp) {
                    if ($comp['day_duration_id'] == DayDuration::FULLDAY && $comp['day_duration_id'] != 0.5) {
                        return $comp;
                    }
                });
                $halfComp = array_filter($allCompOff, function ($comp) {
                    if ($comp['day_duration_id'] != DayDuration::FULLDAY) {
                        return $comp;
                    }
                });
              
                $fullComp = !empty($fullComp) ? array_values($fullComp) : [];
                $halfComp = !empty($halfComp) ? array_values($halfComp) : [];
            }
          
            $to = !empty($inputs['to']) ? implode(',', $inputs['to']) : '';
            $cc = !empty($inputs['cc']) ? implode(',', $inputs['cc']) : '';
            $leaveStatus = !empty($inputs['employee_id']) ? LeaveStatus::APPROVE : LeaveStatus::PENDING;
            
            $leaveTypeName = LeaveType::where('id',$inputs['leave_type'])->first('name');
           
            $leave = Leave::create(['uuid' => getUuid(), 'employee_id' => $employeeId, 'organization_id' => $organizationId, 'leave_type_id' => $inputs['leave_type'], 'from_date' => $inputs['start_date'], 'to_date' => $inputs['end_date'], 'total_working_days' => $inputs['totalDays'], 'description' => $inputs['leave_reason'], 'applied_date' => getUtcDate(), 'leave_status_id' => $leaveStatus, 'to' => $to, 'cc' => $cc, 'created_by' => $user->id]);
            $weekendHoliday = $this->getHolidayAndWeekend($inputs['start_date'], $inputs['end_date']);

            $getData = [];
            $leaveDays = count($inputs['leaveDays']);

            if (!empty($leave)) {
                foreach ($inputs['leaveDays'] as $leaveData) {
                    
                    if (in_array($leaveData['date'], $weekendHoliday)) {
                  
                        continue;
                       
                    }
                    $leaveDetail = LeaveDetail::create(['leave_id' => $leave->id, 'leave_date' => $leaveData['date'], 'day_duration_id' => $leaveData['selectedDuration']]);
                  
                    $newData['leave_date'] = $leaveDetail->leave_date;
                    $newData['dayDuration'] = DayDuration::FULLDAYNAME;
                  
                    if ($leaveData['selectedDuration'] == DayDuration::FIRSTHALF) {
                        $newData['dayDuration'] = DayDuration::FIRSTHALFNAME;
                    } elseif ($leaveData['selectedDuration'] == DayDuration::SECONDHALF) {
                        $newData['dayDuration'] = DayDuration::SECONDHALFNAME;
                    }
                    $getData[] = $newData;
                  
                    if ($leaveTypeDetail->leave_type_type_name == LeaveTypeType::CompensatoryOff) {
                       
                        $compOffDuration = $leaveData['selectedDuration'] == DayDuration::FULLDAY ? 1 : 0.5;
                        if ($leaveData['selectedDuration'] == DayDuration::FULLDAY && !empty($fullComp) && $leaveDays >= 1) {
                            
                            LeaveCompensatoryOff::create(['leave_id' => $leave->id, 'compensatory_offs_id' => $fullComp[0]['id'], 'duration' => $compOffDuration]);
                            array_shift($fullComp);
                            $leaveDays--;                           

                        } elseif ($leaveData['selectedDuration'] == DayDuration::FULLDAY && empty($fullComp) && !empty($halfComp) && $leaveDays >= 1) {
                          
                            $compApplyExist = LeaveCompensatoryOff::where(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id']])
                                ->where('duration', 0.5)->orderBy('id', 'desc')->first();
                            if (!empty($compApplyExist)) {
                                LeaveCompensatoryOff::where(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id']])->orderBy('id', 'desc')->update(['duration' => 1]);
                            } else {
                                LeaveCompensatoryOff::create(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id'], 'duration' => '0.5']);
                                array_shift($halfComp);
                                LeaveCompensatoryOff::create(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id'], 'duration' => '0.5']);
                                array_shift($halfComp);
                            }
                            $leaveDays--;

                        } else if ($leaveData['selectedDuration'] != DayDuration::FULLDAY && !empty($halfComp) && $leaveDays >= 1) {
                          
                            $compApplyExist = LeaveCompensatoryOff::where(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id']])->where('duration', 0.5)->orderBy('id', 'desc')->first();
                            if (!empty($compApplyExist)) {
                                LeaveCompensatoryOff::where(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id']])->orderBy('id', 'desc')->update(['duration' => 1]);

                            } else {
                                LeaveCompensatoryOff::create(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id'], 'duration' => 0.5]);
                            }
                            array_shift($halfComp);
                            $leaveDays--;
                        } else if ($leaveData['selectedDuration'] != DayDuration::FULLDAY && empty($halfComp) && !empty($fullComp) && $leaveDays >= 1) {
                            $compApplyExist = LeaveCompensatoryOff::where(['leave_id' => $leave->id, 'compensatory_offs_id' => $fullComp[0]['id']])->where('duration', 0.5)->orderBy('id', 'desc')->first();
                            if (!empty($compApplyExist)) {
                                LeaveCompensatoryOff::where(['leave_id' => $leave->id, 'compensatory_offs_id' => $fullComp[0]['id']])->orderBy('id', 'desc')->update(['duration' => 1]);
                            } else {
                                LeaveCompensatoryOff::create(['leave_id' => $leave->id, 'compensatory_offs_id' => $fullComp[0]['id'], 'duration' => 0.5]);
                                array_push($halfComp, $fullComp[0]);
                                array_shift($fullComp);
                            }
                            $leaveDays--;
                        }
                    }
                  
                }
            }

            $employee = User::where('entity_id', $employeeId)->first(['entity_id']);
           
            $info = ['employee_name' => $employee->display_name, 'leave_data' => $getData, 'from_date' => $leave->from_date, 'to_date' => $leave->to_date, 'description' => $leave->description, 'duration' => $leave->day_duration_id, 'leave_id' => $leave->uuid, 'days' => $inputs['totalDays'], 'leave_type' => $leaveTypeName->name, 'created_by' => $request->user()->display_name];
           
            if (!empty($to)) {
               
                $setting = Setting::where('key', 'default_to_email')->first(['id']);
                $organizationSetting = OrganizationSetting::where('setting_id', $setting->id)->first(['value', 'id']);
                $defaultTo = !empty($organizationSetting) ? $organizationSetting->value : '';
                
                $to = explode(',', $to);
                $defaultTo = !empty($defaultTo) ?  explode(',', $defaultTo) : [];
                $to = array_merge($to, $defaultTo);
                $userData = User::whereIn('entity_id', $to)->get(['id', 'entity_id', 'email']);
                $info['cc'] = false;
             
                if($currentUserId != $employeeId){
                   
                    $data = new AddLeave($info);
                }else{
                    
                    $data = new ApplyLeave($info);
                }

                $emailData = ['email' => $userData, 'email_data' => $data];

                SendEmailJob::dispatch($emailData);

            }

            if (!empty($cc)) {
                $userData = User::whereIn('entity_id', explode(',', $cc))->get(['id', 'entity_id', 'email']);
                $info['cc'] = true;

                if($currentUserId != $employeeId){
                    $data = new AddLeave($info);
                }else{
                    $data = new ApplyLeave($info);
                }
            
                $emailData = ['email' => $userData, 'email_data' => $data];

                SendEmailJob::dispatch($emailData);

            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.leave_store'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while store leave";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function updateLeave(Request $request, $leave)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();

            $validation = $this->leaveValidator->validate($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            if ($inputs['totalDays'] <= 0) {
                return $this->sendFailResponse(__('messages.holiday_leave'), 422);
            }

            $leaveId = $leave;
            $organizationId = $this->getCurrentOrganizationId();
            $leave = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leaves.uuid', $leaveId)->whereIn('leaves.leave_status_id', [LeaveStatus::PENDING, LeaveStatus::APPROVE])->first(['leaves.id', 'leaves.organization_id', 'leaves.employee_id', 'from_date','to_date','total_working_days', 'description']);

            if (empty($leave)) {
                return $this->sendFailResponse(__('messages.can_not_update'), 422);
            }

            $leaveDays = array_column($inputs['leaveDays'], 'date');
            $selectedDuration = array_column($inputs['leaveDays'], 'selectedDuration');
            $newLeaves = array_combine($leaveDays, $selectedDuration);

            $leaveExist = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->whereBetween('leave_date', [$inputs['start_date'], $inputs['end_date']])->where('employee_id', $leave->employee_id)->whereIn('leaves.leave_status_id', [LeaveStatus::PENDING, LeaveStatus::APPROVE])->where('leaves.id', '!=', $leave->id)->select('leaves.id', 'day_duration_id', 'leave_date')->get();

            foreach ($leaveExist as $val) {
                if ($val->day_duration_id == DayDuration::FULLDAY) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                } elseif ($val->day_duration_id == DayDuration::FIRSTHALF && $newLeaves[$val->leave_date] == DayDuration::FIRSTHALF) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                } elseif ($val->day_duration_id == DayDuration::SECONDHALF && $newLeaves[$val->leave_date] == DayDuration::SECONDHALF) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                } elseif (in_array($val->day_duration_id, [DayDuration::FIRSTHALF, DayDuration::SECONDHALF]) && $newLeaves[$val->leave_date] == DayDuration::FULLDAY) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                }
            }

            $leaveTypeDetail = LeaveType::join('leave_type_types', 'leave_types.leave_type_type_id', 'leave_type_types.id')->where('leave_types.id', $inputs['leave_type'])->first(['leave_types.id', 'leave_types.name', 'leave_type_types.name as leave_type_type_name']);
            if ($leaveTypeDetail->leave_type_type_name == LeaveTypeType::CompensatoryOff) {
                LeaveCompensatoryOff::where('leave_id', $leave->id)->delete();
                /* if(!empty($compOffs)){
                   foreach($compOffs as $compOff){
                       $exist = LeaveCompensatoryOff::join('compensatory_offs', 'leaves_compensatory_offs.compensatory_offs_id','compensatory_offs.id')->join('leaves', 'leaves.id', 'leaves_compensatory_offs.leave_id')->where('leaves.leave_status_id', LeaveStatus::APPROVE)->where('compensatory_offs.uuid', $compOff)->where('leave_id','!=' ,$leaveId)->select('day_duration_id','comp_off_date')->get();
                       if(!empty($exist) && count($exist) > 0){
                            if(count($exist) > 1 || $exist->duration == DayDuration::FULLDAY ){
                               return $this->sendFailResponse(__('messages.comp_off_expired'), 422);
                   }	
                       }else{
                           $addLatests[] = $compOff;
                       }
                   }
               }*/

                $applyCompOff = CompensatoryOff::withoutGlobalScopes([OrganizationScope::class])
                    ->leftJoin('leaves_compensatory_offs', 'compensatory_offs.id', 'leaves_compensatory_offs.compensatory_offs_id')
                    ->leftjoin('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')
                    ->where(function ($item) {

                        $item->whereNull('leaves_compensatory_offs.compensatory_offs_id');
                        $item->orWhereIn('leaves.leave_status_id', [LeaveStatus::REJECT, LeaveStatus::CANCEL]);
                        $item->orWhere(function ($que) {
                            $que->where('leaves_compensatory_offs.duration', '!=', 1);
                            $que->where('compensatory_offs.day_duration_id', 1);
                        });
                    })->where('compensatory_offs.employee_id', $leave->employee_id)
                    ->where('compensatory_offs.organization_id', $organizationId)
                    ->where('compensatory_offs.comp_off_date', '<=', getUtcDate())
                    ->where('compensatory_off_status_id', CompensatoryOffStatus::APPROVE)
                    ->select('compensatory_offs.id', 'leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.uuid', 'compensatory_offs.comp_off_date', 'compensatory_offs.day_duration_id', 'leaves_compensatory_offs.duration', 'leaves_compensatory_offs.leave_id', DB::raw('(CASE WHEN (day_duration_id = 1 && duration = "1")   THEN 1 WHEN ( duration IS NULL && day_duration_id = 1) THEN  1 WHEN ( duration IS NULL && day_duration_id != 1) THEN 0.5 ELSE "0.5" END) total_days'))
                    ->get();

                $compOffId = $applyCompOff->pluck('compensatory_offs_id')->toArray();

                $existRequest = LeaveCompensatoryOff::join('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')
                    ->join('compensatory_offs', 'leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.id')
                    ->whereIn('compensatory_offs_id', $compOffId)
                    ->whereIn('leave_status_id', [LeaveStatus::PENDING, LeaveStatus::APPROVE])
                    ->select('leaves_compensatory_offs.compensatory_offs_id')
                    ->get()->pluck('compensatory_offs_id')->toArray();

                $applyCompOff = array_filter($applyCompOff->toArray(), function ($item) use ($existRequest) {
                    if (!in_array($item['compensatory_offs_id'], $existRequest) || ($item['day_duration_id'] == DayDuration::FULLDAY && $item['duration'] == '0.5')) {
                        if ($item['duration'] == '0.5') {
                            $halfDays = LeaveCompensatoryOff::join('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')
                                ->join('compensatory_offs', 'leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.id')
                                ->where('compensatory_offs_id', $item['compensatory_offs_id'])
                                ->whereIn('leave_status_id', [LeaveStatus::PENDING, LeaveStatus::APPROVE])
                                ->select('leaves_compensatory_offs.compensatory_offs_id', 'leaves_compensatory_offs.duration')->get();

                            if ($halfDays->sum('duration') != 1) {
                                return $item;
                            }

                        } else {
                            return $item;
                        }
                    }
                });

                $applyCompOff = collect($applyCompOff)->sum('total_days');
                if ($inputs['totalDays'] > $applyCompOff) {
                    return $this->sendFailResponse(__('messages.comp_off_not_available'), 422);
                }
                $allCompOff = CompensatoryOff::withoutGlobalScopes([OrganizationScope::class])
                    ->leftJoin('leaves_compensatory_offs', 'compensatory_offs.id', 'leaves_compensatory_offs.compensatory_offs_id')
                    ->leftjoin('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')
                    ->where(function ($item) {

                        $item->whereNull('leaves_compensatory_offs.compensatory_offs_id');
                        $item->orWhereIn('leaves.leave_status_id', [LeaveStatus::REJECT, LeaveStatus::CANCEL]);
                        $item->orWhere(function ($que) {
                            $que->where('leaves_compensatory_offs.duration', '!=', 1);
                            $que->where('compensatory_offs.day_duration_id', 1);
                        });
                    })->where('compensatory_offs.employee_id', $leave->employee_id)->where('compensatory_offs.organization_id', $organizationId)
                    ->where('compensatory_offs.comp_off_date', '<=', getUtcDate())
                    ->where('compensatory_off_status_id', CompensatoryOffStatus::APPROVE)
                    ->select('leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.id', 'compensatory_offs.comp_off_date', 'compensatory_offs.day_duration_id', 'leaves_compensatory_offs.duration', 'leaves_compensatory_offs.leave_id')
                    ->groupBy('compensatory_offs.comp_off_date')
                    ->get();

                $compOffId = $allCompOff->pluck('compensatory_offs_id')->toArray();

                $existRequest = LeaveCompensatoryOff::join('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')
                    ->join('compensatory_offs', 'leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.id')
                    ->whereIn('compensatory_offs_id', $compOffId)->whereIn('leave_status_id', [LeaveStatus::PENDING, LeaveStatus::APPROVE])
                    ->select('leaves_compensatory_offs.compensatory_offs_id')
                    ->get()->pluck('compensatory_offs_id')->toArray();

                $allCompOff = array_filter($allCompOff->toArray(), function ($item) use ($existRequest) {
                    if (!in_array($item['compensatory_offs_id'], $existRequest) || ($item['day_duration_id'] == DayDuration::FULLDAY && $item['duration'] == '0.5')) {
                        if ($item['duration'] == '0.5') {
                            $halfDays = LeaveCompensatoryOff::join('leaves', 'leaves_compensatory_offs.leave_id', 'leaves.id')
                                ->join('compensatory_offs', 'leaves_compensatory_offs.compensatory_offs_id', 'compensatory_offs.id')
                                ->where('compensatory_offs_id', $item['compensatory_offs_id'])
                                ->whereIn('leave_status_id', [LeaveStatus::PENDING, LeaveStatus::APPROVE])
                                ->select('leaves_compensatory_offs.compensatory_offs_id', 'leaves_compensatory_offs.duration')
                                ->get();

                            if ($halfDays->sum('duration') != 1) {
                                return $item;
                            }

                        } else {
                            return $item;
                        }
                    }
                });

                $fullComp = array_filter($allCompOff, function ($comp) {
                    if ($comp['day_duration_id'] == DayDuration::FULLDAY && $comp['duration'] != 0.5) {
                        return $comp;
                    }
                });
                $halfComp = array_filter($allCompOff, function ($comp) {
                    if ($comp['day_duration_id'] != DayDuration::FULLDAY || $comp['duration'] == 0.5) {
                        return $comp;
                    }
                });
                $fullComp = !empty($fullComp) ? array_values($fullComp) : [];
                $halfComp = !empty($halfComp) ? array_values($halfComp) : [];

            }

            $exists = [];
            $leaveDays = count($inputs['leaveDays']);
            $weekendHoliday = $this->getHolidayAndWeekend($inputs['start_date'], $inputs['end_date']);
            if (!empty($leave)) {
                $to = !empty($inputs['to']) ? implode(',', $inputs['to']) : '';
                $cc = !empty($inputs['cc']) ? implode(',', $inputs['cc']) : '';
                $oldData = NULL;
                $updatedData = '';
                $plain = NULL;
                if($leave->from_date != $inputs['start_date']){
                    
                    $plain .= 'From date: ' .$leave->from_date. ' To '. $inputs['start_date'];
                }
                
                if($leave->to_date != $inputs['end_date']){

                    if(!empty($plain)){
                        $plain .= ',';
                    }
                    
                    $plain .= '   To date: ' .$leave->to_date. ' To '. $inputs['end_date'];
                }

                if($leave->total_working_days != $inputs['totalDays']){

                    if(!empty($plain)){
                        $plain .= ',';
                    }

                    $plain .= '   Total leave days: ' .$leave->total_working_days. ' To '. number_format($inputs['totalDays'],1);
                }

                if($leave->description != $inputs['leave_reason']){
                    
                    if(!empty($plain)){
                        $plain .= ',';
                    }

                    $plain .= '   Reason: ' .$leave->description. ' To '. $inputs['leave_reason'];
                }

                $updatedData = !empty($plain) ? ['plain' => $plain] : NULL;

                $leave->update(['leave_type_id' => $inputs['leave_type'], 'employee_id' => $inputs['employee_id'], 'from_date' => $inputs['start_date'], 'to_date' => $inputs['end_date'], 'description' => $inputs['leave_reason'], 'total_working_days' => $inputs['totalDays'], 'to' => $to, 'cc' => $cc]);
                foreach ($inputs['leaveDays'] as $leaveData) {

                    if (in_array($leaveData['date'], $weekendHoliday)) {
                        continue;
                    }

                    $leaveDetail = LeaveDetail::where('leave_date', $leaveData['date'])->where('leave_id', $leave->id)->first(['id', 'leave_date']);
                    if (!empty($leaveDetail)) {
                        $exists[] = $leaveDetail->id;
                        $leaveDetail->update(['day_duration_id' => $leaveData['selectedDuration']]);
                    } else {
                        $leaveDetail = LeaveDetail::create(['leave_id' => $leave->id, 'leave_date' => $leaveData['date'], 'day_duration_id' => $leaveData['selectedDuration'], 'leave_status_id' => LeaveStatus::APPROVE]);
                        $exists[] = $leaveDetail->id;
                    }

                    /*if($leaveTypeDetail->leave_type_type_name == LeaveTypeType::CompensatoryOff){
                                       if(!empty($addLatests)){
                                           foreach($addLatests as $latest){
                                               $compOffData = CompensatoryOff::where('uuid', $latest)->select('id')->first();

                                             $currentCompOff = LeaveCompensatoryOff::join('compensatory_offs', 'leaves_compensatory_offs.compensatory_offs_id','compensatory_offs.id')->where('compensatory_offs.uuid', $latest)->where('leave_id',$leaveId)
               ->select('day_duration_id','comp_off_date')->first();
                                               if(empty($currentCompOff)){
               $compOffDuration = $leaveData['selectedDuration'] == DayDuration::FULLDAY ? 1 : 0.5;
                                                   LeaveCompensatoryOff::create(['leave_id' => $leave->id, 'compensatory_offs_id' => $compOffData->id,'duration' => $compOffDuration]);
                                              }
                                           }
                                       }
                                   }*/

                    if ($leaveTypeDetail->leave_type_type_name == LeaveTypeType::CompensatoryOff) {
                        $compOffDuration = $leaveData['selectedDuration'] == DayDuration::FULLDAY ? 1 : 0.5;

                        if ($leaveData['selectedDuration'] == DayDuration::FULLDAY && !empty($fullComp) && $leaveDays >= 1) {
                            LeaveCompensatoryOff::create(['leave_id' => $leave->id, 'compensatory_offs_id' => $fullComp[0]['id'], 'duration' => $compOffDuration]);
                            array_shift($fullComp);
                            $leaveDays--;

                        } elseif ($leaveData['selectedDuration'] == DayDuration::FULLDAY && empty($fullComp) && !empty($halfComp) && $leaveDays >= 1) {
                            $compApplyExist = LeaveCompensatoryOff::where(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id']])->where('duration', 0.5)->orderBy('id', 'desc')->first();
                            if (!empty($compApplyExist)) {
                                LeaveCompensatoryOff::where(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id']])->orderBy('id', 'desc')->update(['duration' => 1]);
                            } else {
                                LeaveCompensatoryOff::create(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id'], 'duration' => '0.5']);
                                array_shift($halfComp);
                                LeaveCompensatoryOff::create(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id'], 'duration' => '0.5']);
                                array_shift($halfComp);
                            }
                            $leaveDays--;

                        } else if ($leaveData['selectedDuration'] != DayDuration::FULLDAY && !empty($halfComp) && $leaveDays >= 1) {
                            $compApplyExist = LeaveCompensatoryOff::where(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id']])->where('duration', 0.5)->orderBy('id', 'desc')->first();
                            if (!empty($compApplyExist)) {
                                LeaveCompensatoryOff::where(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id']])->orderBy('id', 'desc')->update(['duration' => 1]);

                            } else {
                                LeaveCompensatoryOff::create(['leave_id' => $leave->id, 'compensatory_offs_id' => $halfComp[0]['id'], 'duration' => 0.5]);
                            }
                            array_shift($halfComp);
                            $leaveDays--;
                        } else if ($leaveData['selectedDuration'] != DayDuration::FULLDAY && empty($halfComp) && !empty($fullComp) && $leaveDays >= 1) {
                            $compApplyExist = LeaveCompensatoryOff::where(['leave_id' => $leave->id, 'compensatory_offs_id' => $fullComp[0]['id']])->where('duration', 0.5)->orderBy('id', 'desc')->first();
                            if (!empty($compApplyExist)) {
                                LeaveCompensatoryOff::where(['leave_id' => $leave->id, 'compensatory_offs_id' => $fullComp[0]['id']])->orderBy('id', 'desc')->update(['duration' => 1]);
                            } else {
                                LeaveCompensatoryOff::create(['leave_id' => $leave->id, 'compensatory_offs_id' => $fullComp[0]['id'], 'duration' => 0.5]);
                                array_push($halfComp, $fullComp[0]);
                                array_shift($fullComp);
                            }
                            $leaveDays--;
                        }
                    }

                    $newData['leave_date'] = $leaveData['date'];
                    $newData['dayDuration'] = DayDuration::FULLDAYNAME;
                    if ($leaveData['selectedDuration'] == DayDuration::FIRSTHALF) {
                        $newData['dayDuration'] = DayDuration::FIRSTHALFNAME;
                    } elseif ($leaveData['selectedDuration'] == DayDuration::SECONDHALF) {
                        $newData['dayDuration'] = DayDuration::SECONDHALFNAME;
                    }

                    $getData[] = $newData;
                }

                LeaveDetail::where('leave_id', $leave->id)->whereNotIn('id', $exists)->delete();
                $employee = User::where('entity_id', $leave->employee_id)->first(['entity_id']);


                if(!empty($updatedData) && count($updatedData) > 0){
                    
                    $logData = ['organization_id' => $leave->organization_id, 'new_data' => !empty($updatedData) ? json_encode($updatedData) : null, 'old_data' => !empty($oldData) ? json_encode($oldData) : NULL, 'action' => 'has updated leave of '.$employee->display_name, 'table_name' => 'employees', 'updated_by' => $request->user()->id, 'module_id' => $leave->employee_id, 'module_name' => 'LMS'];

                    $activityLog = new ActivityLog();
                    $activityLog->createLog($logData);
             
                    $info = ['employee_name' => $employee->display_name, 'leave_data' => $getData, 'from_date' => $leave->from_date, 'to_date' => $leave->to_date, 'description' => $leave->description, 'duration' => $leave->day_duration_id, 'leave_id' => $leave->id, 'days' => $inputs['totalDays'], 'leave_type' => $leaveTypeDetail->name];
                    $info['edit'] = true;
                    $info['note'] = $plain;
                    if (!empty($to)) {
                        $userData = User::whereIn('entity_id', explode(',', $to))->get(['id', 'entity_id', 'email']);
                        $info['cc'] = false;

                        $data = new ApplyLeave($info);

                        $emailData = ['email' => $userData, 'email_data' => $data];

                        SendEmailJob::dispatch($emailData);

                    }

                    if (!empty($cc)) {
                        $userData = User::whereIn('entity_id', explode(',', $cc))->get(['id', 'entity_id', 'email']);
                        $info['cc'] = true;

                        $data = new ApplyLeave($info);

                        $emailData = ['email' => $userData, 'email_data' => $data];

                        SendEmailJob::dispatch($emailData);

                    }
                }
            }
            DB::commit();

            return $this->sendSuccessResponse(__('messages.leave_update'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update leave";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getLeaveDashboard(Request $request)
    {
        try {

            $inputs = $request->all();
            $perPage = $request->perPage ?? 10;
            $fromDate = $request->from_date ? date('Y-m-d', strtotime($request->from_date)) : '';
            $toDate = $request->to_date ? date('Y-m-d', strtotime($request->to_date)) : '';

            $user = $request->user();
            $permissions = $user->getAllPermissions()->pluck('name')->toArray();
            $organizationId = $this->getCurrentOrganizationId();

            $leaveTypes = LeaveType::where('leave_type_type_id', '!=', LeaveTypeType::CompensatoryOffID)->select('id', 'accrual_period', 'accrual_date', 'accrual_month', 'reset_period', 'reset_date', 'reset_month')->get();
            $leaveTypeFrom = $leaveTypeTo = [];
            if (!empty($leaveTypes)) {
                foreach ($leaveTypes as $leaveType) {

                    // Get current refill period for display current balance for all leave type
                    $accrualPeriod = $leaveType->accrual_period;
                    $accrualDate = $leaveType->accrual_date;
                    $accrualMonth = $leaveType->accrual_month;

                    $resetPeriod = $leaveType->reset_period;
                    $resetDate = $leaveType->reset_date;
                    $resetMonth = $leaveType->reset_month;

                    $date = date('j');
                    $month = date('n');
                    $lastDay = config('constant.last_day');
                    $periodConfig = config('constant.job_schedule_period');
                    if ($accrualPeriod == $periodConfig['Yearly']) {

                        if ($accrualDate == $lastDay) {
                            $accrualDate = Carbon::parse(date('Y-' . $accrualMonth . '-t'))->endOfMonth()->format('d');
                        }
                      
                        $to = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->addYear()->format('Y-m-d');
                    }

                    if ($accrualPeriod == $periodConfig['Half yearly']) {
                        $monthList = config('constant.half_year_month_list');
                        $accrualMonth = $monthList[$accrualMonth];

                        if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                            $month = $accrualMonth[0];
                            $monthEnd = $accrualMonth[1];
                        } else if ($month < $accrualMonth[0]) {
                            $month = $accrualMonth[1];
                            $monthEnd = $accrualMonth[0];
                        } else if ($month >= $accrualMonth[1]) {
                            $month = $accrualMonth[1];
                            $monthEnd = $accrualMonth[0];
                        }
                       
                        if ($accrualDate == $lastDay) {
                            $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                        }
                        $accrualDate = Carbon::parse(date('Y-' . $month . '-' . $accrualDate))->format('d');

                        $accrualMonth = $month;

                        $year = date('Y');
                        if(date('n') > $monthEnd){
                            $year = Carbon::parse(date('Y'))->addYear()->format('Y');
                        }
                        $to = Carbon::parse(date($year.'-' . $monthEnd . '-' . $accrualDate))->format('Y-m-d');
                    }

                    if ($accrualPeriod == $periodConfig['Quarterly']) {
                        $monthList = config('constant.quartarly_month_list');
                        $accrualMonth = $monthList[$accrualMonth];

                        if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                            $currQuarter = 1;
                            $endQuarter = 1;
                           
                        }
    
                        if ($month >= $accrualMonth[1] && $month < $accrualMonth[2]) {
                            $currQuarter = 2;
                            $endQuarter = 2;
                          
                        }
    
                        if ($month >= $accrualMonth[2] && $month < $accrualMonth[3]) {
                            $currQuarter = 3;
                            $endQuarter = 3;
                         
                        }

                        if ($month < $accrualMonth[0] || $month >= $accrualMonth[3]) {
                            $currQuarter = 4;
                            $endQuarter = 0;
                        }

                        $monthCal = $accrualMonth[$currQuarter - 1];
                        $month = $monthCal;
                        if ($accrualDate == $lastDay) {
                            $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                        }

                        $monthEndCal = $accrualMonth[$endQuarter];
                        $year = date('Y');
                        if($monthEndCal < date('n')){
                            $year = Carbon::parse(date('Y'))->addYear()->format('Y');
                        }
                        $to = Carbon::parse(date($year.'-' . $monthEndCal . '-' . $accrualDate))->format('Y-m-d');
                        $accrualMonth = $month;
                    }

                    if ($accrualPeriod == $periodConfig['Monthly']) {
                        if ($accrualDate == $lastDay) {
                            $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                        }
                        $accrualMonth = $month;

                        $to = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->addMonth()->format('Y-m-d');
                    }


                    if ($resetPeriod == $periodConfig['Yearly']) {
                        if ($resetDate == $lastDay) {
                            $resetDate = Carbon::parse(date('Y-' . $resetMonth . '-t'))->endOfMonth()->format('d');
                        }

                    }

                    if ($resetPeriod == $periodConfig['Half yearly']) {
                        $monthList = config('constant.half_year_month_list');
                        $resetMonth = $monthList[$resetMonth];
                        if ($month >= $resetMonth[0] && $month < $resetMonth[1]) {
                            $month = $resetMonth[0];
                            $monthEnd = $resetMonth[1];
                            $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
                        } else if ($month < $resetMonth[0]) {
                            $month = $resetMonth[1];
                            $monthEnd = $resetMonth[0];
                            $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
                        } else if ($month >= $resetMonth[1]) {
                            $month = $resetMonth[1];
                            $monthEnd = $resetMonth[0];
                            $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
                        }

                        $resetDate = Carbon::parse(date('Y-' . $month . '-' . $resetDate))->addDay()->format('d');
                        if ($resetDate == $lastDay) {
                            $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                        }

                        $resetMonth = $month;

                    }

                    if ($resetPeriod == $periodConfig['Quarterly']) {
                        $month = date('m');
                        $monthList = config('constant.quartarly_month_list');
                        $resetMonth = $monthList[$resetMonth];
                        $currQuarter = round(($month - 1) / 3 + 1);
                        $monthCal = 3 * $currQuarter - 2;

                        $month = $monthCal;
                        if ($resetDate == $lastDay) {
                            $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                        }
                        $resetMonth = $month;

                        $monthEndCal = (3 * $currQuarter) + 1;

                    }

                    if ($resetPeriod == $periodConfig['Monthly']) {

                        if ($resetDate == $lastDay) {
                            $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                        }
                        $resetMonth = $month;

                    }

                    $from = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->format('Y-m-d');

                    $leaveTypeFrom[$leaveType->id] = $from;
                    $leaveTypeTo[$leaveType->id] = $to;

                }
            }

            $leaveBalance = [];
            $upcoming = [];
            $history = [];
            $allLeaves = [];
            $historyCount = 0;
            $totalBalance = 0;
            $totalBooked = 0;

            $employeeId = !empty($inputs['employee_id']) ? $inputs['employee_id'] : $user->entity_id;
            $date = getUtcDate();

            if (!in_array('manage_leaves', $permissions)) {
                $upcoming = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_statuses', 'leaves.leave_status_id', 'leave_statuses.id')->join('leave_types', 'leaves.leave_type_id', 'leave_types.id')->where('employee_id', $employeeId)->whereDate('from_date', '>=', $date)->orderBy('leaves.from_date')->orderBy('leave_statuses.id')
                    ->select(
                        'leaves.id',
                        'leaves.uuid',
                        'employee_id',
                        'leave_type_id',
                        'from_date',
                        'to_date',
                        'total_working_days',
                        'applied_date',
                        'leave_statuses.name as leave_status',
                        'leave_types.name as leave_type_name',
                        DB::raw('(CASE WHEN leaves.system_leave IN( ' . Leave::PENDINGSYSTEMLEAVE .','. Leave::SYSTEMLEAVEWITHYES .','. Leave::SYSTEMLEAVEWITHNO . ') THEN "' . Leave::AUTOLEAVECOLOR . '" ELSE leave_statuses.color_code END) AS color_code'),
                        DB::raw('(CASE WHEN leaves.system_leave IN( ' . Leave::PENDINGSYSTEMLEAVE .','. Leave::SYSTEMLEAVEWITHYES .','. Leave::SYSTEMLEAVEWITHNO . ') THEN "auto" ELSE "" END) AS auto_leave')
                    )->where('leaves.organization_id', $organizationId)->get();

                $history = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_statuses', 'leaves.leave_status_id', 'leave_statuses.id')->join('leave_types', 'leaves.leave_type_id', 'leave_types.id')->whereDate('to_date', '<=', $date)->where('employee_id', $employeeId)
                    ->select(
                        'leaves.id',
                        'leaves.uuid',
                        'employee_id',
                        'leave_type_id',
                        'from_date',
                        'to_date',
                        'total_working_days',
                        'applied_date',
                        'leave_statuses.name as leave_status',
                        'leave_types.name as leave_type_name', DB::raw('(CASE WHEN leaves.system_leave IN( ' . Leave::PENDINGSYSTEMLEAVE .','. Leave::SYSTEMLEAVEWITHYES .','. Leave::SYSTEMLEAVEWITHNO . ')  THEN "' . Leave::AUTOLEAVECOLOR . '" ELSE leave_statuses.color_code END) AS color_code'),
                        DB::raw('(CASE WHEN leaves.system_leave IN( ' . Leave::PENDINGSYSTEMLEAVE .','. Leave::SYSTEMLEAVEWITHYES .','. Leave::SYSTEMLEAVEWITHNO . ')  THEN "auto" ELSE "" END) AS auto_leave')
                    )
                    ->where('leaves.organization_id', $organizationId)->orderBy('leaves.from_date', 'desc')->orderBy('leave_statuses.id')->simplePaginate($perPage);
                $historyCount = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_statuses', 'leaves.leave_status_id', 'leave_statuses.id')->join('leave_types', 'leaves.leave_type_id', 'leave_types.id')->whereDate('to_date', '<=', $date)->where('employee_id', $employeeId)->count();

                $response['upcoming'] = $upcoming;
                $response['history'] = $history;
                $response['total_count'] = $historyCount;

            } else {
                $query = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_details', 'leaves.id', 'leave_details.leave_id')->join('leave_statuses', 'leaves.leave_status_id', 'leave_statuses.id')->join('leave_types', 'leaves.leave_type_id', 'leave_types.id')->join('employees', function ($join) use ($organizationId) {
                    $join->on('leaves.employee_id', '=', 'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                });

                $query->when($fromDate, function ($q) use ($fromDate, $toDate) {
                    $q->whereBetween('leave_details.leave_date', [$fromDate, $toDate]);
                });

                if (!empty($inputs['employee_id'])) {
                    $query->where('employee_id', $inputs['employee_id']);
                }

                $query = $query->where('leaves.organization_id', $organizationId)->groupBy('leaves.id');

                $countQuery = clone $query;
                $leaveCount = $countQuery->get()->count();

                $allLeaves = $query->select(
                    'leaves.id',
                    'leaves.uuid',
                    'employee_id',
                    'employees.display_name',
                    'leave_type_id',
                    'from_date',
                    'to_date',
                    'total_working_days',
                    'applied_date',
                    'leave_statuses.name as leave_status',
                    'leave_types.name as leave_type_name',
                    DB::raw('(CASE WHEN leaves.system_leave = ' . Leave::SYSTEMLEAVEWITHNO . ' OR leaves.system_leave = ' . Leave::SYSTEMLEAVEWITHYES . ' OR leaves.system_leave IN( ' . Leave::PENDINGSYSTEMLEAVE .','. Leave::SYSTEMLEAVEWITHYES .','. Leave::SYSTEMLEAVEWITHNO . ') THEN "' . Leave::AUTOLEAVECOLOR . '" ELSE leave_statuses.color_code END) AS color_code'),
                    DB::raw('(CASE WHEN leaves.system_leave = ' . Leave::SYSTEMLEAVEWITHNO . ' OR leaves.system_leave = ' . Leave::SYSTEMLEAVEWITHYES . ' OR leaves.system_leave IN( ' . Leave::PENDINGSYSTEMLEAVE .','. Leave::SYSTEMLEAVEWITHYES .','. Leave::SYSTEMLEAVEWITHNO . ') THEN "auto" ELSE "" END) AS auto_leave')
                )->orderBy('leave_details.leave_date','desc')->orderBy('leave_statuses.id')->simplePaginate($perPage);

                $response['all'] = $allLeaves;
                $response['total_count'] = $leaveCount;
            }

            if (!empty($inputs['employee_id']) || !in_array('manage_leaves', $permissions)) {

                $leaveBalance = Employee::withoutGlobalScopes([OrganizationScope::class])->active()
                    ->leftJoin('leave_balance', function ($que) use ($organizationId) {
                        $que->on('leave_balance.employee_id', 'employees.id');
                        $que->where('leave_balance.organization_id', $organizationId);
                    })
                    ->leftJoin('leave_types', function ($join) use ($organizationId) {
                        $join->on('leave_types.id', 'leave_balance.leave_type_id');
                    })
                    ->where('leave_types.leave_type_type_id', '!=', LeaveTypeType::CompensatoryOffID)
                    ->where('leave_types.organization_id', $organizationId)
                    ->whereNull('leave_types.deleted_at')
                    ->where('employees.organization_id', $organizationId)
                    ->where('employees.id', $employeeId)
                    ->select('leave_types.uuid','leave_types.name', 'leave_balance.balance', 'leave_type_id', 'employees.id', 'employees.probation_period_end_date','employees.resign_date')->get();

                $response['leaveBalance'] = $leaveBalance;

                $totalLeaves = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('employee_id', $employeeId)->where('organization_id', $organizationId)->where('leaves.leave_status_id', LeaveStatus::APPROVE)
                    ->select('total_working_days', 'leave_type_id', 'from_date', 'to_date', 'leave_date', DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))
                    ->orderBy('leave_details.leave_date', 'desc')->get();

                $setting = OrganizationSetting::with('setting')->whereHas('setting', function ($que) {
                    $que->where('settings.key','LIKE','lop_during_notice_period');
                })->first(['value']);
                $lopDuringNoticePeriod = false;
                if(!empty($setting)){
                    $lopDuringNoticePeriod = $setting->value;
                }

                foreach ($leaveBalance as $entry) {
                    $startDate = $leaveTypeFrom[$entry->leave_type_id];
                    $endDate = $leaveTypeTo[$entry->leave_type_id];

                    if(($leaveTypeFrom[$entry->leave_type_id] < $entry->probation_period_end_date)){
                        $probationLeaves = $totalLeaves->where('leave_type_id', $entry->leave_type_id)->whereBetween('leave_date', [$startDate, $entry->probation_period_end_date])->SUM('total_days');    
                    }
                  
                    if($lopDuringNoticePeriod == true){
                        if($endDate > $entry->resign_date){
                            $endDate = $entry->resign_date;
                        }
                    }

                    $total = $totalLeaves->where('leave_type_id', $entry->leave_type_id)->whereBetween('leave_date', [$startDate, $endDate])->SUM('total_days');
                    $entry->booked = !empty($total) ? round($total, 2) : 0;
                    $entry->balance = !empty($probationLeaves) ?  $entry->balance - ($entry->booked - $probationLeaves) : $entry->balance - $entry->booked;
                    $entry->balance = $entry->balance > 0 ? round($entry->balance, 2) : 0;
                    $totalBalance += $entry->balance;
                    $totalBooked += $entry->booked;
                }

                $response['total_balance'] = round($totalBalance, 2);
                $response['total_booked'] = round($totalBooked, 2);
            }

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while list leaves";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function pendingLeaves(Request $request)
    {
        try {

            $perPage = $request->perPage ?? 10;
            $employeeId = $request->employee ?? '';
            $organizationId = $this->getCurrentOrganizationId();

            $query = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_statuses', 'leaves.leave_status_id', 'leave_statuses.id')->join('leave_types', 'leaves.leave_type_id', 'leave_types.id')
                                    ->join('employees', function ($join) use($organizationId) {
                                        $join->on('leaves.employee_id', '=',  'employees.id');
                                        $join->where('employees.organization_id', $organizationId);
                                    })                        
                                    ->where('leave_status_id', LeaveStatus::PENDING)
                                    ->where('leaves.organization_id', $organizationId);
            $query = $query->when($employeeId,function($q) use($employeeId){
                $q->where('employee_id', $employeeId);
            });

            $pendingLeaves = $query->orderBy('leaves.from_date')
                           ->orderBy('leave_status_id')
                           ->select('leaves.id','leaves.uuid', 'employee_id', 'employees.display_name', 'leave_type_id', 'from_date', 'to_date', 'total_working_days', 'applied_date', 'leave_statuses.name as leave_status', 'leave_types.name as leave_type_name')->simplePaginate($perPage);

            $total = Leave::where('leave_status_id', LeaveStatus::PENDING)->count();

            $response = ['pending_leaves' => $pendingLeaves, 'total_count' => $total];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while list pending leaves";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function leaveDetails(Request $request)
    {
        try {
            $leaveId = $request->id;
            $organizationId = $this->getCurrentOrganizationId();
            $leave = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_statuses', 'leaves.leave_status_id', 'leave_statuses.id')->join('leave_types', 'leaves.leave_type_id', 'leave_types.id')->join(
                'employees',
                function ($join) use ($organizationId) {
                    $join->on('leaves.employee_id', 'employees.id');
                    $join->where('employees.organization_id', '=', $organizationId);
                }
            )->where('leaves.uuid', $leaveId)->first(['leaves.id', 'leaves.uuid', 'employee_id', 'employees.display_name', 'leave_type_id', 'from_date', 'to_date', 'total_working_days', 'leaves.description', 'applied_date', 'leaves.created_at', 'to', 'cc', 'action_date', 'action_by_id', 'remarks', 'cancel_remarks', 'leave_statuses.name as leave_status', 'leave_types.name as leave_type_name']);
            $leave->to = !empty($leave->to) ? explode(',', $leave->to) : [];
            $leave->cc = !empty($leave->cc) ? explode(',', $leave->cc) : [];
            $leaveId = $leave->id;

            $actionBy = User::where('id', $leave->action_by_id)->first(['id', 'email', 'entity_id']);
            $actionByUser = '';
            if (!empty($actionBy)) {
                $actionByUser = $actionBy->display_name;
            }
            $leave->actionBy = $actionByUser;

            $user = User::where('organization_id', $organizationId)->where('entity_id', $leave->employee_id)->where('entity_type_id', EntityType::Employee)->first('timezone_id');

            if (!empty($user)) {
                $timezone = Timezone::find($user->timezone_id);

                if (!empty($timezone)) {
                    $timezone = $timezone->value;
                    if (!empty($leave->action_date)) {
                        $utcDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $leave->action_date, 'UTC');
                        $utcDateTime->setTimezone($timezone)->toDateTimeString();
                        $leave->action_date = $utcDateTime;
                    }
                }
            }

            $leaveDetails = LeaveDetail::where('leave_id', $leaveId)->get(['day_duration_id', 'leave_date'])->pluck('day_duration_id', 'leave_date');

            $leaveTypes = LeaveType::join('leave_type_types', 'leave_types.leave_type_type_id', 'leave_type_types.id')->where('leave_types.id', $leave->leave_type_id)->select('leave_types.id', 'leave_types.name', 'leave_type_types.name as leave_type_type_name')->get();

            $empIds = array_merge([$leave->employee_id], $leave->to, $leave->cc);

            $employees = Employee::select('employees.id', 'display_name', 'avatar_url')->whereIn('employees.id', $empIds)->get();

            $summary = $this->getSummary($leave->from_date, $leave->to_date, $leave->leave_type_id, $leave->employee_id, $leaveDetails, $leaveId);

            $response = ['leaveDetails' => $leave, 'employees' => $employees, 'summary' => $summary['leaveDays'], 'leaveTypes' => $leaveTypes, 'allowedDuration' => $summary['allowedDuration'], 'available_balance' => $summary['available_balance'], 'currently_booked' => $summary['currently_booked'], 'remaianing' => $summary['remaianing'], 'compOffs' => $summary['comp_offs']];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while detail leave";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function updateLeaveStatus(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();

            $leaveId = $inputs['id'];
            $status = $inputs['status'];
            $comment = $inputs['comment'] ?? '';

            $user = Auth::user();
            $userId = $user->id;
            $currentEmployeeId = $user->employee_id;
            $permissions = $user->getAllPermissions()->pluck('name')->toArray();
            $organizationId = $this->getCurrentOrganizationId();

            $leave = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_types', 'leaves.leave_type_id', 'leave_types.id')->where('leaves.organization_id', $organizationId)->where('leaves.uuid', $leaveId)->first(['leaves.id','employee_id', 'from_date', 'to_date', 'leaves.description', 'to', 'cc', 'leaves.system_leave', 'leave_types.name as leave_type_name']);

            if(in_array('manage_leaves', $permissions)){

                if (!empty($status) && $status == 'approved') {

                    Leave::where('uuid', $leaveId)->update(['leave_status_id' => LeaveStatus::APPROVE,'remarks' => $comment, 'action_date' => getDateTime(), 'action_by_id' => $userId]);
                   // LeaveDetail::where('leave_id', $leave->id)->update(['leave_status_id' => LeaveStatus::APPROVE]);
    
                    $info['leave_action'] = $status;
    
                }
    
                if (!empty($status) && $status == 'rejected') {
    
                    Leave::where('uuid', $leaveId)->update(['leave_status_id' => LeaveStatus::REJECT, 'remarks' => $comment,'action_date' => getDateTime(), 'action_by_id' => $userId]);
                 //   LeaveDetail::where('leave_id', $leave->id)->update(['leave_status_id' => LeaveStatus::REJECT]);
    
                    $info['leave_action'] = $status;
                }
            }

            if (!empty($status) && $status == 'cancelled') {
                Leave::where('uuid', $leaveId)->update(['leave_status_id' => LeaveStatus::CANCEL,'action_date' => getDateTime(), 'action_by_id' => $userId,'cancel_remarks' => $comment]);
               // LeaveDetail::where('leave_id', $leave->id)->update(['leave_status_id' => LeaveStatus::CANCEL]);

                $info['leave_action'] = $status;

            }

            $to = $leave->to ? explode(',', $leave->to) : [];
            $setting = Setting::where('key', 'default_to_email')->first(['id']);
            $organizationSetting = OrganizationSetting::where('setting_id', $setting->id)->first(['value', 'id']);
            $defaultTo = !empty($organizationSetting) ? $organizationSetting->value : '';

            $defaultTo = !empty($defaultTo) ?  explode(',', $defaultTo) : [];
            $to = array_merge($to, $defaultTo);
            $toUsers = array_filter($to, function ($currentUser) use ($currentEmployeeId) {
                if ($currentUser != $currentEmployeeId) {
                    return $currentUser;
                }
            });
            $toUsers = $toUsers ? $toUsers : [];
            $ccUsers = $leave->cc ? explode(',', $leave->cc) : [];

            if (!empty($inputs['status']) && $inputs['status'] == 'cancelled' && $user->entity_id == $leave->employee_id) {

                $employees = array_merge($toUsers, $ccUsers);
            } else {
                $employees = array_merge([$leave->employee_id], $toUsers, $ccUsers);
            }

            $emailUsers = User::whereIn('entity_id', $employees)->get(['entity_id', 'email']);

            $employee = Employee::where('id', $leave->employee_id)->first('display_name');

            $dateFormat = DateFormat::where('id', $user->date_format_id)->first('format');
            $currentDateFormat = $dateFormat->format;
    
            $details = ['from_date' => convertUTCTimeToUserTime($leave->from_date, $currentDateFormat), 'to_date' => convertUTCTimeToUserTime($leave->to_date, $currentDateFormat), 'display_name' => $employee->display_name, 'system_leave' => $leave->system_leave, 'leave_type' => $leave->leave_type_name];
            $info = array_merge($details, $info);

            $data = new UpdateLeaveStatusMail($info);

            $emailData = ['email' => $emailUsers, 'email_data' => $data];

            SendEmailJob::dispatch($emailData);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.update_leave_status'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update leave status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function updatePrimaryLeaveType(Request $request)
    {
        try {
            DB::beginTransaction();

            $leaveType = $request->leave_type;
            if(!empty($leaveType)){
                LeaveType::where('is_primary', 1)->update(['is_primary'=> 0]);
                LeaveType::where('uuid', $leaveType)->update(['is_primary' => 1]);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update leave status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function accureLeave()
    {
        try {

            DB::beginTransaction();

            $organizations = Organization::select('id')->get();

            foreach ($organizations as $organization) {

                $leaveTypes = LeaveType::withoutGlobalScopes([OrganizationScope::class])->where('leave_type_type_id', '!=', LeaveTypeType::CompensatoryOffID)->where('organization_id', $organization->id)->whereNull('leave_types.deleted_at')->select('id', 'name','accrual', 'accrual_period', 'accrual_date', 'accrual_month', 'no_of_leaves')->get();

                $employees = Employee::withoutGlobalScopes([OrganizationScope::class])->active()->where('employees.organization_id', $organization->id)->select('employees.id','employees.join_date', 'employees.display_name')->get();

                $days = Setting::join('organization_settings', 'settings.id', 'organization_settings.setting_id')->where('key', 'probation_period_days')->where('organization_id', $organization->id)->first('organization_settings.value');

                $settings['probation_days'] = $days->value;

                $allowLeaveDuringProbation = Setting::join('organization_settings', 'settings.id', 'organization_settings.setting_id')->where('key', 'allow_leave_during_probation_period')->where('organization_id', $organization->id)->first('organization_settings.value');
                $settings['allow_leave_during_probation'] = $allowLeaveDuringProbation->value;

                $allowLeaveDuringNoticePeriod = Setting::join('organization_settings', 'settings.id', 'organization_settings.setting_id')->where('key', 'allow_leave_during_notice_period')->where('organization_id', $organization->id)->first('organization_settings.value');
                $settings['allow_leave_during_notice_period'] = $allowLeaveDuringNoticePeriod->value;

                foreach ($leaveTypes as $type) {
                    if (!empty($type->accrual)) {
                        foreach($employees as $employee){
                            $data = ['employee_id' => $employee->id, 'organization_id' => $organization->id, 'leave_type_id' => $type->id];
                            LeaveBalance::firstOrCreate($data);
                        }
                        // Get last refill period for minus total leave of previous refill period from leave balance and add refill balance after it
                        $accuredLeave = $type->no_of_leaves ?? 0;
                        $accrualPeriod = $type->accrual_period;
                        $accrualDate = $type->accrual_date;
                        $accrualMonth = $type->accrual_month;
                        $date = date('j');
                        $month = date('n');
                        $lastDay = config('constant.last_day');
                        $periodConfig = config('constant.job_schedule_period');
                        if ($accrualPeriod == $periodConfig['Yearly']) {
                            if($accrualDate == $lastDay){
                                $accrualDate = Carbon::parse(date('Y-'.$accrualMonth.'-t'))->endOfMonth()->format('d');
                            }
                            if ($date == $accrualDate && $month == $accrualMonth) {
                               $from = Carbon::parse(date('Y-' . $month . '-' . $accrualDate))->subYear()->format('Y-m-d');
                               $to = Carbon::parse(date('Y-' . $month . '-' . $accrualDate))->subDay()->format('Y-m-d');
                               $this->accuredEmployeeLeaves($employees, $type, $organization, $accuredLeave,$settings, $from, $to);
                            }
                        }

                        if ($accrualPeriod == $periodConfig['Half yearly']) {
                            $monthList = config('constant.half_year_month_list');
                            $accrualMonth = $monthList[$type->accrual_month];
                            if($accrualDate == $lastDay && in_array($month, $accrualMonth)){
                                $accrualDate = Carbon::parse(date('Y-'.$month.'-t'))->endOfMonth()->format('d');
                            }
                            if ($date == $accrualDate && in_array($month, $accrualMonth)) {
                                $from = Carbon::parse(date('Y-' . $month . '-' . $accrualDate))->subMonths(6)->format('Y-m-d');
                                $to = Carbon::parse(date('Y-' . $month . '-' . $accrualDate))->subDay()->format('Y-m-d');
                                $this->accuredEmployeeLeaves($employees, $type, $organization, $accuredLeave, $settings, $from, $to);
                            }
                        }

                        if ($accrualPeriod == $periodConfig['Quarterly']) {
                            $monthList = config('constant.quartarly_month_list');
                            $accrualMonth = $monthList[$type->accrual_month];
                            if($accrualDate == $lastDay && in_array($month, $accrualMonth)){
                                $accrualDate = Carbon::parse(date('Y-'.$month.'-t'))->endOfMonth()->format('d');
                            }

                            if ($date == $accrualDate && in_array($month, $accrualMonth)) {
                                $from = Carbon::parse(date('Y-' . $month . '-' . $accrualDate))->subMonths(3)->format('Y-m-d');
                                $to = Carbon::parse(date('Y-' . $month . '-' . $accrualDate))->subDay()->format('Y-m-d');
                                $this->accuredEmployeeLeaves($employees, $type, $organization, $accuredLeave, $settings, $from, $to);
                            }
                        }

                        if ($accrualPeriod == $periodConfig['Monthly']) {
                            if($accrualDate == $lastDay){
                                $accrualDate = Carbon::parse(date('Y-'.$month.'-t'))->endOfMonth()->format('d');
                            }
                            if ($date == $accrualDate) {
                                $from = Carbon::parse(date('Y-' . $month . '-' . $accrualDate))->subMonth()->format('Y-m-d');
                                $to = Carbon::parse(date('Y-' . $month . '-' . $accrualDate))->subDay()->format('Y-m-d');
                                $this->accuredEmployeeLeaves($employees, $type, $organization, $accuredLeave, $settings, $from, $to);
                            }
                        }
                    }
                }
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while accure leaves";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Common code for getting last refill period for minus leave to reset the balance upto this period
    public function getLeaveTypeDate()
    {
        $leaveTypes = LeaveType::select('id', 'accrual_period', 'accrual_date', 'accrual_month', 'reset_period', 'reset_date', 'reset_month')->get();
        $leaveTypeLeave = [];
        if (!empty($leaveTypes)) {
            foreach ($leaveTypes as $leaveType) {
                $accrualPeriod = $leaveType->accrual_period;
                $accrualDate = $leaveType->accrual_date;
                $accrualMonth = $leaveType->accrual_month;

                $resetPeriod = $leaveType->reset_period;
                $resetDate = $leaveType->reset_date;
                $resetMonth = $leaveType->reset_month;

                $date = date('j');
                $month = date('n');
                $lastDay = config('constant.last_day');
                $periodConfig = config('constant.job_schedule_period');
                $year = date('Y');
                if ($accrualPeriod == $periodConfig['Yearly']) {
                   
                    if($accrualMonth >= $month){
                        $year = Carbon::parse($year)->subYear()->format('Y');
                    }

                    if ($accrualDate == $lastDay) {
                        $accrualDate = Carbon::parse(date('Y-' . $accrualMonth . '-t'))->endOfMonth()->format('d');
                    }
                   
                    $to = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->format('Y-m-d');
                }

                if ($accrualPeriod == $periodConfig['Half yearly']) {
                    $monthList = config('constant.half_year_month_list');
                    $accrualMonth = $monthList[$accrualMonth];

                    if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                        $month = $accrualMonth[0];
                        $monthEnd = $accrualMonth[1];
                    } else if ($month < $accrualMonth[0]) {
                        $month = $accrualMonth[1];
                        $monthEnd = $accrualMonth[0];
                    } else if ($month > $accrualMonth[1]) {
                        $month = $accrualMonth[1];
                        $monthEnd = $accrualMonth[0];
                    }

                    if ($accrualDate == $lastDay) {
                        $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                    }

                    $accrualMonth = $month;

                    if(date('n') > $monthEnd){
                        $to = Carbon::parse(date('Y-' . $monthEnd . '-' . $accrualDate))->addYear()->format('Y-m-d');
                    }else{
                        $to = Carbon::parse(date('Y-' . $monthEnd . '-' . $accrualDate))->format('Y-m-d');
                    }
                }

                if ($accrualPeriod == $periodConfig['Quarterly']) {
                    $monthList = config('constant.quartarly_month_list');
                    $accrualMonth = $monthList[$accrualMonth];
                    
                    if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                        $currQuarter = 1;
                        $endQuarter = 1;
                       
                    }
    
                    if ($month >= $accrualMonth[1] && $month < $accrualMonth[2]) {
                        $currQuarter = 2;
                        $endQuarter = 2;
                      
                    }
    
                    if ($month >= $accrualMonth[2] && $month < $accrualMonth[3]) {
                        $currQuarter = 3;
                        $endQuarter = 3;
                     
                    }
    
                    if ($month < $accrualMonth[0] || $month >= $accrualMonth[3]) {
                        $currQuarter = 4;
                        $endQuarter = 0;
                    }
                    $monthCal = $accrualMonth[$currQuarter - 1];               
                    $month = $monthCal;
                    if ($accrualDate == $lastDay) {
                        $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                    }
       
                    $monthEndCal = $accrualMonth[$endQuarter];
                    $accrualMonth = $month;

    
                    if($monthEndCal < date('n')){
                        $to = Carbon::parse(date('Y-' . $monthEndCal . '-' . $accrualDate))->addYear()->format('Y-m-d');
                    }else{
                        $to = Carbon::parse(date($year.'-' . $monthEndCal . '-' . $accrualDate))->format('Y-m-d');
                    }
    
                }

                if ($accrualPeriod == $periodConfig['Monthly']) {
                    if ($accrualDate == $lastDay) {
                        $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                    }
                    $accrualMonth = $month;

                    $to = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->addMonth()->format('Y-m-d');
                }


                if ($resetPeriod == $periodConfig['Yearly']) {
                    if ($resetDate == $lastDay) {
                        $resetDate = Carbon::parse(date('Y-' . $resetMonth . '-t'))->endOfMonth()->format('d');
                    }

                }

                if ($resetPeriod == $periodConfig['Half yearly']) {
                    $monthList = config('constant.half_year_month_list');
                    $resetMonth = $monthList[$resetMonth];
                    if ($month >= $resetMonth[0] && $month < $resetMonth[1]) {
                        $month = $resetMonth[0];
                        $monthEnd = $resetMonth[1];
                        $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
                    } else if ($month < $resetMonth[0]) {
                        $month = $resetMonth[1];
                        $monthEnd = $resetMonth[0];
                        $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
                    } else if ($month >= $resetMonth[1]) {
                        $month = $resetMonth[1];
                        $monthEnd = $resetMonth[0];
                        $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
                    }

                    $resetDate = Carbon::parse(date('Y-' . $month . '-' . $resetDate))->addDay()->format('d');
                    if ($resetDate == $lastDay) {
                        $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                    }

                    $resetMonth = $month;

                }

                if ($resetPeriod == $periodConfig['Quarterly']) {
                    $month = date('m');
                    $monthList = config('constant.quartarly_month_list');
                    $resetMonth = $monthList[$resetMonth];
                    $currQuarter = round(($month - 1) / 3 + 1);
                    $monthCal = 3 * $currQuarter - 2;

                    $month = $monthCal;
                    if ($resetDate == $lastDay) {
                        $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                    }
                    $resetMonth = $month;

                    $monthEndCal = (3 * $currQuarter) + 1;
                }

                if ($resetPeriod == $periodConfig['Monthly']) {

                    if ($resetDate == $lastDay) {
                        $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                    }
                    $resetMonth = $month;

                }
                $from = Carbon::parse(date($year.'-' . $accrualMonth . '-' . $accrualDate))->format('Y-m-d');

                $leaveTypeLeave[$leaveType->id]['start'] = $from;
                $leaveTypeLeave[$leaveType->id]['end'] = $to;
            }
        }

        return $leaveTypeLeave;
    }

    public function resetLeave()
    {
        try {

            DB::beginTransaction();

            $organizations = Organization::select('id')->get();

            foreach ($organizations as $organization) {

                $leaveTypes = LeaveType::withoutGlobalScopes([OrganizationScope::class])->where('organization_id', $organization->id)->whereNull('leave_types.deleted_at')->select('id','name', 'reset', 'reset_period', 'reset_date', 'reset_month', 'encashment', 'carryforward')->get();

                $employees = Employee::withoutGlobalScopes([OrganizationScope::class])->active()->where('employees.organization_id', $organization->id)->select('employees.id','employees.display_name')->get();

                $leaveTypeLeaves = $this->getLeaveTypeDate();
                foreach ($leaveTypes as $type) {
                    if (!empty($type->reset)) {
                        $resetPeriod = $type->reset_period;
                        $resetDate = $type->reset_date;
                        $resetMonth = $type->reset_month;
                        $encashment = $type->encashment;
                        $carryforward = $type->carryforward;
                        $date = date('j');
                        $month = date('n');
                        $lastDay = config('constant.last_day');
                        $periodConfig = config('constant.job_schedule_period');
                        if ($resetPeriod == $periodConfig['Yearly']) {
                            if($resetDate == $lastDay){
                                $resetDate = Carbon::parse(date('Y-'.$resetMonth.'-t'))->endOfMonth()->format('d');
                            }
                            if ($date == $resetDate && $month == $resetMonth) {
                                foreach ($employees as $employee) {
                                    $this->updateLeaveBalance($employee, $organization,$type, $encashment, $carryforward, $leaveTypeLeaves);
                                }
                            }
                        }

                        if ($resetPeriod == $periodConfig['Half yearly']) {
                            $monthList = config('constant.half_year_month_list');
                            $resetMonth = $monthList[$type->reset_month];
                            if($resetDate == $lastDay && in_array($month, $resetMonth)){
                                $resetDate = Carbon::parse(date('Y-'.$month.'-t'))->endOfMonth()->format('d');
                            }
                            if ($date == $resetDate && in_array($month, $resetMonth)) {
                                foreach ($employees as $employee) {
                                    $this->updateLeaveBalance($employee,$organization,$type, $encashment, $carryforward, $leaveTypeLeaves);
                                }
                            }
                        }

                        if ($resetPeriod == $periodConfig['Quarterly']) {
                            $monthList = config('constant.quartarly_month_list');
                            $resetMonth = $monthList[$type->reset_month];
                            if($resetDate == $lastDay && in_array($month, $resetMonth)){
                                $resetDate = Carbon::parse(date('Y-'.$month.'-t'))->endOfMonth()->format('d');
                            }
                            if ($date == $resetDate && in_array($month, $resetMonth)) {
                                foreach ($employees as $employee) {
                                    $this->updateLeaveBalance($employee,$organization,$type, $encashment, $carryforward, $leaveTypeLeaves);
                                }
                            }
                        }

                        if ($resetPeriod == $periodConfig['Monthly']) {
                            if($resetDate == $lastDay){
                                $resetDate = Carbon::parse(date('Y-'.$month.'-t'))->endOfMonth()->format('d');
                            }
                            if ($date == $resetDate) {
                                foreach ($employees as $employee) {
                                    $this->updateLeaveBalance($employee,$organization,$type, $encashment, $carryforward, $leaveTypeLeaves);
                                }
                            }
                        }
                    }
                }
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while accure leaves";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function updateLeaveBalance($employee, $organization,$type, $encashment, $carryforward, $leaveTypeLeaves)
    {
        $leaveBalance = LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('organization_id', $organization->id)->orderBy('id','desc')->first(['balance','id', 'leave_type_id', 'organization_id']);
        
        if(!empty($leaveBalance->balance)){
            $from = $leaveTypeLeaves[$type->id]['start'];
            $to = $leaveTypeLeaves[$type->id]['end'];

            $total = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leave_type_id', $type->id)->where('employee_id', $employee->id)->whereBetween('leave_date', [$from, $to])->where('leaves.leave_status_id', LeaveStatus::APPROVE)->select('employee_id', 'leave_type_id', 'leave_date', DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->sum('total_days');

            $balance = ($leaveBalance->balance - $total) >= 0 ? ($leaveBalance->balance - $total) : 0;
            $carryforwardLeave = $encash = 0;
            if (!empty($encashment) && $balance > 0) {

                $encash = $balance >= $encashment ?  $encashment : $balance;
               
                $balance = $balance >= $encashment ?  $balance - $encashment : 0;

                $logData = ['organization_id' => $organization->id ,'new_data' =>  NULL, 'old_data' => NULL, 'action' => $encashment. ' '.$type->name.' encash for '.$employee->display_name, 'table_name' => 'employees','updated_by' => '', 'module_id' => $employee->id, 'module_name' => 'LMS'];
                        
                $activityLog = new ActivityLog();
                $activityLog->createLog($logData);
            }
    
            if (!empty($carryforward) && $balance > 0) {
                if($carryforward != '-1' && $carryforward != 0){
                    $carryforwardLeave = $balance >= $carryforward ?  $carryforward :  $balance;
                    $balance = $balance >= $carryforward ?  $balance - ($balance - $carryforward) :  $balance;
                }else{
                    $carryforwardLeave = $balance;
                    if($carryforward == 0){
                        $carryforwardLeave = 0;
                    }
                }

                $logData = ['organization_id' => $organization->id ,'new_data' =>  NULL, 'old_data' => NULL, 'action' => $balance. ' '.$type->name.'  carry forward for '.$employee->display_name, 'table_name' => 'employees','updated_by' => '', 'module_id' => $employee->id, 'module_name' => 'LMS'];
                        
                $activityLog = new ActivityLog();
                $activityLog->createLog($logData);
            }

            if($carryforward == 0){
                $balance = 0;
            }

            $leaveBalance->update(['balance' => $balance]);

            $logData = ['organization_id' => $organization->id ,'new_data' =>  NULL, 'old_data' => NULL, 'action' => $type->name.' reset done for '.$employee->display_name, 'table_name' => 'employees','updated_by' => '', 'module_id' => $employee->id, 'module_name' => 'LMS'];
                        
            $activityLog = new ActivityLog();
            $activityLog->createLog($logData);

            $this->addLeaveBalanceHistory($leaveBalance->leave_type_id, $employee->id, $leaveBalance->organization_id, $balance, 'reset');

            LeaveResetAction::create(['employee_id' => $employee->id, 'leave_type_id'=> $type->id, 'organization_id' => $organization->id, 'carry_forward' => $carryforwardLeave, 'encash' => $encash]);
        }

        return true;
    }

    public function accuredEmployeeLeaves($employees, $type, $organization, $accuredLeave, $settings, $from, $to)
    {
        foreach ($employees as $employee) {

            $futureDate = Carbon::parse($employee->join_date)->addDays($settings['probation_days']);

            //Do not accrual leave due to probation period
            if($futureDate > Carbon::now() && $settings['allow_leave_during_probation'] == false){
                continue;
            }

            //Do not accrual leave due to serving notice period
            if($employee->on_notice_period == true && $settings['allow_leave_during_notice_period'] == false){

                $logData = ['organization_id' => $organization->id ,'new_data' =>  NULL, 'old_data' => NULL, 'action' => 'Leave refill has not done due to serving notice period by '.$employee->display_name, 'table_name' => 'employees','updated_by' => '', 'module_id' => $employee->id, 'module_name' => 'LMS'];
                        
                $activityLog = new ActivityLog();
                $activityLog->createLog($logData);
                continue;
            }

            //For probation period employee leave should be accrual once from this cron or probation cron
 
            $total = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leave_type_id', $type->id)->where('employee_id', $employee->id)->whereBetween('leave_date', [$from, $to])->where('leaves.leave_status_id', LeaveStatus::APPROVE)->select('employee_id', 'leave_type_id', 'leave_date', DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->sum('total_days');
    
            $balance = LeaveBalance::where('employee_id', $employee->id)->where('organization_id', $organization->id)->where('leave_type_id' , $type->id)->first('balance');

            $balance =  $balance->balance;

            $resetLeaveTypeDate = $this->resetFromToDate($organization);

            if($resetLeaveTypeDate[$type->id]['start'] > date('Y-m-d') && $from < $resetLeaveTypeDate[$type->id]['start'] && $to < $resetLeaveTypeDate[$type->id]['start']){

                $balance = ($balance - $total) >=0 ? ($balance - $total) : 0;
            }
            
            $balance =  $balance + $accuredLeave;

            LeaveBalance::where('employee_id', $employee->id)->where('organization_id', $organization->id)->where('leave_type_id' , $type->id)->update(['balance' =>  $balance]);

            $logData = ['organization_id' => $organization->id ,'new_data' =>  NULL, 'old_data' => NULL, 'action' => $accuredLeave. ' '. $type->name . ' added for '. $employee->display_name. ' during leave refill', 'table_name' => 'employees','updated_by' => '', 'module_id' => $employee->id, 'module_name' => 'LMS'];
                        
            $activityLog = new ActivityLog();
            $activityLog->createLog($logData);
           
            $this->addLeaveBalanceHistory($type->id, $employee->id, $organization->id, $accuredLeave, 'accural');
        }
    }

    public function resetFromToDate($organization)
    {
        $leaveTypes = LeaveType::withoutGlobalScopes([OrganizationScope::class])->where('organization_id', $organization->id)->whereNull('leave_types.deleted_at')->select('id','reset', 'reset_period', 'reset_date', 'reset_month', 'encashment', 'carryforward')->get();

        foreach ($leaveTypes as $type) {
            if (!empty($type->reset)) {
                $resetPeriod = $type->reset_period;
                $resetDate = $type->reset_date;
                $resetMonth = $type->reset_month;

                $month = date('n');
                $lastDay = config('constant.last_day');
                $periodConfig = config('constant.job_schedule_period');
                if ($resetPeriod == $periodConfig['Yearly']) {
                    if ($resetDate == $lastDay) {
                        $resetDate = Carbon::parse(date('Y-' . $resetMonth . '-t'))->endOfMonth()->format('d');
                    }

                }

                if ($resetPeriod == $periodConfig['Half yearly']) {
                    $monthList = config('constant.half_year_month_list');
                    $resetMonth = $monthList[$resetMonth];
                    if ($month >= $resetMonth[0] && $month < $resetMonth[1]) {
                        $month = $resetMonth[0];
                        $monthEnd = $resetMonth[1];
                       
                    } else if ($month < $resetMonth[0]) {
                        $month = $resetMonth[1];
                        $monthEnd = $resetMonth[0];
                       
                    } else if ($month >= $resetMonth[1]) {
                        $month = $resetMonth[1];
                        $monthEnd = $resetMonth[0];
                       
                    }

                    $resetDate = Carbon::parse(date('Y-' . $month . '-' . $resetDate))->addDay()->format('d');
                    if ($resetDate == $lastDay) {
                        $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                    }

                    $resetMonth = $month;

                }

                if ($resetPeriod == $periodConfig['Quarterly']) {
                    
                    $monthList = config('constant.quartarly_month_list');
                    $resetMonth = $monthList[$resetMonth];
                  
                    if ($month >= $resetMonth[0] && $month < $resetMonth[1]) {
                        $currQuarter = 1;
                        $endQuarter = 1;
                    
                    }

                    if ($month >= $resetMonth[1] && $month < $resetMonth[2]) {
                        $currQuarter = 2;
                        $endQuarter = 2;
                    
                    }

                    if ($month >= $resetMonth[2] && $month < $resetMonth[3]) {
                        $currQuarter = 3;
                        $endQuarter = 3;
                    
                    }

                    if ($month < $resetMonth[0] || $month >= $resetMonth[3]) {
                        $currQuarter = 4;
                        $endQuarter = 0;
                    }

                    $monthCal = $resetMonth[$currQuarter - 1];               
                    $month = $monthCal;
                    if ($resetDate == $lastDay) {
                        $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                    }
                    $monthEndCal = $resetMonth[$endQuarter];
                    $resetMonth = $month;
                }

                if ($resetPeriod == $periodConfig['Monthly']) {

                    if ($resetDate == $lastDay) {
                        $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                    }
                    $resetMonth = $month;

                }
                $from = Carbon::parse(date('Y-' . $resetMonth . '-' . $resetDate))->format('Y-m-d');
            }

            $leaveTypeResetDate[$type->id]['start'] = $from;
          
            return $leaveTypeResetDate;
        }
    }

    public function addLeaveBalanceHistory($leaveType, $employeeId, $organizationId , $balance, $actionType = 'manual correction')
    {
        $totalBalance = 0;
        $leaveBalance = LeaveBalance::where('employee_id', $employeeId)->where('organization_id', $organizationId)->where('leave_type_id' , $leaveType)->first('balance');
        if(!empty($leaveBalance)){
            $totalBalance = $leaveBalance->balance;
        }
        LeaveBalanceHistory::create([
            'employee_id' => $employeeId,
            'organization_id' => $organizationId,
            'leave_type_id' => $leaveType,
            'balance' => $balance,
            'total_balance' => $totalBalance,
            'action_type' => $actionType
        ]);
    }

    public function probationPeriodNewCreditLeave()
    {
        
        DB::beginTransaction();
        try {

            $organizations = Organization::select('id')->get();

            foreach ($organizations as $organization) {

                // $days = Setting::join('organization_settings', 'settings.id', 'organization_settings.setting_id')->where('key', 'probation_period_days')->where('organization_id', $organization->id)->first('organization_settings.value');
              
                // $previousDateTime = Carbon::now()->subDays($days->value);
      
                // $compareDate = date('Y-m-d', strtotime($previousDateTime->toDateString()));

                $currentDate = getUtcDate();

                //Not allow leave during probation period then compare date after probation compelete 
                $probationPeriodLeave = Setting::join('organization_settings', 'settings.id', 'organization_settings.setting_id')->where('key', 'allow_leave_during_probation_period')->where('organization_id', $organization->id)->first('organization_settings.value');
                $probationPeriodLeave = $probationPeriodLeave->value;

                //If allow leave during probation period then compare join date
                // if($probationPeriodLeave == true){
                //     $compareDate = $currentDate;
                // }

                $employees = Employee::active()->leftJoin('leave_balance', 'employees.id', 'leave_balance.employee_id')->where('employees.organization_id', $organization->id)->whereDate('probation_period_end_date', $currentDate)->where('leave_balance.balance', '0.00')->groupBy('employees.id')->get(['employees.id', 'employees.display_name']);
                $leaveTypes = LeaveType::withoutGlobalScopes([OrganizationScope::class])->where('leave_type_type_id', '!=' , LeaveTypeType::CompensatoryOffID)->where('organization_id', $organization->id)->whereNull('leave_types.deleted_at')->select('id', 'name', 'accrual', 'accrual_period', 'accrual_date', 'accrual_month', 'encashment', 'carryforward','no_of_leaves')->get();

                foreach ($employees as $employee) {

                    foreach ($leaveTypes as $type) {
                        if (!empty($type->accrual)) {
                            $accrualPeriod = $type->accrual_period;
                            $periodConfig = config('constant.job_schedule_period');
                            $totalLeaves = $type->no_of_leaves;
                            $accrualDay = $type->accrual_date;
                            $accrualMonth = $type->accrual_month;
                            $month = date('n');
                            $day = date('j');
                            $currentDate = Carbon::now();
                            $lastDay = config('constant.last_day');
                            if($accrualDay == $lastDay){
                                $accrualDay = Carbon::parse(date('Y-'.$accrualMonth.'-t'))->endOfMonth()->format('d');
                            }
                            if($month == $accrualMonth && $day == $accrualDay){
                                continue;
                            }
                      
                            if ($accrualPeriod == $periodConfig['Yearly']) {
                                $month = date('m');

                                $nextYear = Carbon::parse(date('Y'))->addYear()->format('Y');

                                $lastDate = Carbon::parse(date($nextYear.'-' . $accrualMonth . '-'.$accrualDay))->format('Y-m-d');
                                $remainingDays = $currentDate->diffInDays($lastDate);

                                $firstDate = Carbon::parse(date('Y-' . $accrualMonth . '-'.$accrualDay));
                                $totalDays = $firstDate->diffInDays($lastDate);
                                $accuredLeave = $totalLeaves > 0 ? round((($totalLeaves / $totalDays) * $remainingDays),2) : 0;

                                $data = ['employee_id' => $employee->id, 'organization_id' => $organization->id, 'leave_type_id' => $type->id, 'balance' => $accuredLeave];
                               
                                $leaveBalance = LeaveBalance::where('leave_type_id', $type->id)->where('employee_id', $employee->id)->where('organization_id', $organization->id)->first('id');
                                if(!empty($leaveBalance->id)){
                                    $leaveBalance->update(['balance' => $accuredLeave]);
                                }else{
                                    LeaveBalance::create($data);
                                }

                                $logData = ['organization_id' => $organization->id ,'new_data' => NULL, 'old_data' => NULL, 'action' => $accuredLeave . ' Leave of '. $type->name.' added for '. $employee->display_name . ' as probation period got over', 'table_name' => 'employees','updated_by' => '', 'module_id' => $employee->id, 'module_name' => 'LMS'];
                        
                                $activityLog = new ActivityLog();
                                $activityLog->createLog($logData);
                            }

                            if ($accrualPeriod == $periodConfig['Half yearly']) {
                                $month = date('m');
                                $monthList = config('constant.half_year_month_list');
                                $accrualMonth = $monthList[$type->accrual_month];
                                if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                                    $month = $month - $accrualMonth[0];
                                    $firstDate = Carbon::parse(date('Y-' . $accrualMonth[0] . '-'.$accrualDay));
                                    $lastDate = Carbon::parse(date('Y-' . $accrualMonth[1] . '-'.$accrualDay))->format('Y-m-d');
                                    $totalDays = $firstDate->diffInDays($lastDate);
                                    $remainingDays = $currentDate->diffInDays($lastDate);
                                    $accuredLeave = $totalLeaves > 0 ? round((($totalLeaves / $totalDays) * $remainingDays),2) : 0;
                                }else if($month < $accrualMonth[0]){
                                    $month = $accrualMonth[0] - $month;
                                    $firstDate = Carbon::parse(date('Y-' . $accrualMonth[1] . '-'.$accrualDay));
                                    $lastDate = Carbon::parse(date('Y-' . $accrualMonth[0] . '-'.$accrualDay))->format('Y-m-d');
                                    $totalDays = $firstDate->diffInDays($lastDate);
                                    $remainingDays = $currentDate->diffInDays($lastDate);
                                    $accuredLeave = $totalLeaves > 0 ? round((($totalLeaves / $totalDays) * $remainingDays),2) : 0;
                                }else if($month > $accrualMonth[1]){
                                    $month =  $month - $accrualMonth[1];
                                    $nextYear = Carbon::parse(date('Y'))->addYear()->format('Y');
                                    $firstDate = Carbon::parse(date('Y-' . $accrualMonth[0] . '-'.$accrualDay));
                                    $lastDate = Carbon::parse(date($nextYear.'-' . $accrualMonth[1] . '-'.$accrualDay))->format('Y-m-d');
                                    $totalDays = $firstDate->diffInDays($lastDate);
                                    $remainingDays = $currentDate->diffInDays($lastDate);
                                    $accuredLeave = $totalLeaves > 0 ? round((($totalLeaves / $totalDays) * $remainingDays),2) : 0;
                                }
                                $data = ['employee_id' => $employee->id, 'organization_id' => $organization->id, 'leave_type_id' => $type->id, 'balance' => $accuredLeave];
                                
                                $leaveBalance = LeaveBalance::where('leave_type_id', $type->id)->where('employee_id', $employee->id)->where('organization_id', $organization->id)->first('id');
                                if(!empty($leaveBalance->id)){
                                    $leaveBalance->update(['balance' => $accuredLeave]);
                                }else{
                                    LeaveBalance::create($data);
                                }

                                $logData = ['organization_id' => $organization->id ,'new_data' => NULL, 'old_data' => NULL, 'action' => $accuredLeave . ' Leave of '. $type->name.' added for '. $employee->display_name . ' as probation period got over', 'table_name' => 'employees','updated_by' => '', 'module_id' => $employee->id, 'module_name' => 'LMS'];
                                $activityLog = new ActivityLog();
                                $activityLog->createLog($logData);

                            }

                            if ($accrualPeriod == $periodConfig['Quarterly']) {
                                $month = date('m');
                                $monthList = config('constant.quartarly_month_list');
                                $accrualMonth = $monthList[$type->accrual_month];

                                if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                                    $month = $month - $accrualMonth[0];
                                    $firstDate = Carbon::parse(date('Y-' . $accrualMonth[0] . '-'.$accrualDay));
                                    $lastDate = Carbon::parse(date('Y-' . $accrualMonth[1] . '-'.$accrualDay))->format('Y-m-d');
                                    $totalDays = $firstDate->diffInDays($lastDate);
                                    $remainingDays = $currentDate->diffInDays($lastDate);
                                    $accuredLeave = $totalLeaves > 0 ? round((($totalLeaves / $totalDays) * $remainingDays),2) : 0;
                                    $data = ['employee_id' => $employee->id, 'organization_id' => $organization->id, 'leave_type_id' => $type->id, 'balance' => $accuredLeave];
                                    $leaveBalance = LeaveBalance::where('leave_type_id', $type->id)->where('employee_id', $employee->id)->where('organization_id', $organization->id)->first('id');
                                    if(!empty($leaveBalance->id)){
                                        $leaveBalance->update(['balance' => $accuredLeave]);
                                    }else{
                                        LeaveBalance::create($data);
                                    }

                                    $logData = ['organization_id' => $organization->id ,'new_data' => NULL, 'old_data' => NULL, 'action' => $accuredLeave . ' Leave of '. $type->name.' added for '. $employee->display_name . ' as probation period got over', 'table_name' => 'employees','updated_by' => '', 'module_id' => $employee->id, 'module_name' => 'LMS'];
                                    $activityLog = new ActivityLog();
                                    $activityLog->createLog($logData);
                                }
                            
                                if ($month >= $accrualMonth[1] && $month < $accrualMonth[2]) {
                                    $month = $month - $accrualMonth[1];
                                    $firstDate = Carbon::parse(date('Y-' . $accrualMonth[1] . '-'.$accrualDay));
                                    $lastDate = Carbon::parse(date('Y-' . $accrualMonth[2] . '-'.$accrualDay))->format('Y-m-d');
                                    $totalDays = $firstDate->diffInDays($lastDate);
                                    $remainingDays = $currentDate->diffInDays($lastDate);

                                    $accuredLeave = $totalLeaves > 0 ? round((($totalLeaves / $totalDays) * $remainingDays),2) : 0;

                                    $data = ['employee_id' => $employee->id, 'organization_id' => $organization->id,'leave_type_id' => $type->id, 'balance' => $accuredLeave];
                                    $leaveBalance = LeaveBalance::where('leave_type_id', $type->id)->where('employee_id', $employee->id)->where('organization_id', $organization->id)->first('id');
                                    if(!empty($leaveBalance->id)){
                                        $leaveBalance->update(['balance' => $accuredLeave]);
                                    }else{
                                        LeaveBalance::create($data);
                                    }

                                    $logData = ['organization_id' => $organization->id ,'new_data' => NULL, 'old_data' => NULL, 'action' => $accuredLeave . ' Leave of '. $type->name.' added for '. $employee->display_name . ' as probation period got over', 'table_name' => 'employees','updated_by' => '', 'module_id' => $employee->id, 'module_name' => 'LMS'];
                                    $activityLog = new ActivityLog();
                                    $activityLog->createLog($logData);
                                }

                                if ($month >= $accrualMonth[2] && $month < $accrualMonth[3]) {
                                    $month = $month - $accrualMonth[2];
                                    $firstDate = Carbon::parse(date('Y-' . $accrualMonth[2] . '-'.$accrualDay));
                                    $lastDate = Carbon::parse(date('Y-' . $accrualMonth[3] . '-'.$accrualDay))->format('Y-m-d');
                                    $totalDays = $firstDate->diffInDays($lastDate);
                                    $remainingDays = $currentDate->diffInDays($lastDate);
                                    $accuredLeave = $totalLeaves > 0 ? round((($totalLeaves / $totalDays) * $remainingDays),2) : 0;
                                    $data = ['employee_id' => $employee->id, 'organization_id' => $organization->id, 'leave_type_id' => $type->id, 'balance' => $accuredLeave];
                                    $leaveBalance = LeaveBalance::where('leave_type_id', $type->id)->where('employee_id', $employee->id)->where('organization_id', $organization->id)->first('id');
                                    if(!empty($leaveBalance->id)){
                                        $leaveBalance->update(['balance' => $accuredLeave]);
                                    }else{
                                        LeaveBalance::create($data);
                                    }
                                    $logData = ['organization_id' => $organization->id ,'new_data' => NULL, 'old_data' => NULL, 'action' => $accuredLeave . ' Leave of '. $type->name.' added for '. $employee->display_name . ' as probation period got over', 'table_name' => 'employees','updated_by' => '', 'module_id' => $employee->id, 'module_name' => 'LMS'];
                                    $activityLog = new ActivityLog();
                                    $activityLog->createLog($logData);
                                }

                                if ($month >= $accrualMonth[3]) {
                                    $month = $month - $accrualMonth[3];
                                    
                                    $nextYear = Carbon::parse(date('Y'))->addYear()->format('Y');
                                    $firstDate = Carbon::parse(date('Y-' . $accrualMonth[2] . '-'.$accrualDay));
                                    $lastDate = Carbon::parse(date($nextYear.'-' . $accrualMonth[3] . '-'.$accrualDay))->format('Y-m-d');
                                    $remainingDays = $currentDate->diffInDays($lastDate);
                                    $totalDays = $firstDate->diffInDays($lastDate);
                                    $accuredLeave = $totalLeaves > 0 ? round((($totalLeaves / $totalDays) * $remainingDays),2) : 0;
                                    $data = ['employee_id' => $employee->id, 'organization_id' => $organization->id, 'leave_type_id' => $type->id, 'balance' => $accuredLeave];
                                    $leaveBalance = LeaveBalance::where('leave_type_id', $type->id)->where('employee_id', $employee->id)->where('organization_id', $organization->id)->first('id');
                                    if(!empty($leaveBalance->id)){
                                        $leaveBalance->update(['balance' => $accuredLeave]);
                                    }else{
                                        LeaveBalance::create($data);
                                    }
                                    $logData = ['organization_id' => $organization->id ,'new_data' => NULL, 'old_data' => NULL, 'action' => $accuredLeave . ' Leave of '. $type->name.' added for '. $employee->display_name . ' as probation period got over', 'table_name' => 'employees','updated_by' => '', 'module_id' => $employee->id, 'module_name' => 'LMS'];
                                    $activityLog = new ActivityLog();
                                    $activityLog->createLog($logData);
                                }

                                if ($month < $accrualMonth[0]) {
                                    $month =  $accrualMonth[0] - $month;
                                    $firstDate = Carbon::parse(date('Y-' . $accrualMonth[3] . '-'.$accrualDay));
                                    $lastDate = Carbon::parse(date('Y-' . $accrualMonth[0] . '-'.$accrualDay))->format('Y-m-d');
                                    $totalDays = $firstDate->diffInDays($lastDate);
                                    $remainingDays = $currentDate->diffInDays($lastDate);

                                    $accuredLeave = $totalLeaves > 0 ? round((($totalLeaves / $totalDays) * $remainingDays),2) : 0;
                                    $data = ['employee_id' => $employee->id, 'organization_id' => $organization->id, 'leave_type_id' => $type->id, 'balance' => $accuredLeave];
                                    $leaveBalance = LeaveBalance::where('leave_type_id', $type->id)->where('employee_id', $employee->id)->where('organization_id', $organization->id)->first('id');
                                    if(!empty($leaveBalance->id)){
                                        $leaveBalance->update(['balance' => $accuredLeave]);
                                    }else{
                                        LeaveBalance::create($data);
                                    }
                                    $logData = ['organization_id' => $organization->id ,'new_data' => NULL, 'old_data' => NULL, 'action' => $accuredLeave . ' Leave of '. $type->name.' added for '. $employee->display_name . ' as probation period got over', 'table_name' => 'employees','updated_by' => '', 'module_id' => $employee->id, 'module_name' => 'LMS'];
                                    $activityLog = new ActivityLog();
                                    $activityLog->createLog($logData);
                                }
                            }

                            if ($accrualPeriod == $periodConfig['Monthly']) {
                                $firstDay = Carbon::now()->startOfMonth();
                                $lastDay = Carbon::now()->endOfMonth();
                                $diff = $firstDay->diffInDays($lastDay->addDay()->startOfDay());
                                $today = date('d');
                                $accuredLeave = $totalLeaves > 0 ? round((($diff - $today)  * ($totalLeaves / $diff)),2) : 0;
                                $data = ['employee_id' => $employee->id, 'organization_id' => $organization->id, 'leave_type_id' => $type->id, 'balance' => $accuredLeave];
                                $leaveBalance = LeaveBalance::where('leave_type_id', $type->id)->where('employee_id', $employee->id)->where('organization_id', $organization->id)->first('id');
                                if(!empty($leaveBalance->id)){
                                    $leaveBalance->update(['balance' => $accuredLeave]);
                                }else{
                                    LeaveBalance::create($data);
                                }

                                $logData = ['organization_id' => $organization->id ,'new_data' => NULL, 'old_data' => NULL, 'action' => $accuredLeave . ' Leave of '. $type->name.' added for '. $employee->display_name . ' as probation period got over', 'table_name' => 'employees','updated_by' => '', 'module_id' => $employee->id, 'module_name' => 'LMS'];
                                $activityLog = new ActivityLog();
                                $activityLog->createLog($logData);
                            }

                            LeaveBalanceHistory::create([
                                'employee_id' => $employee->id,
                                'organization_id' => $organization->id,
                                'leave_type_id' => $type->id,
                                'balance' => $accuredLeave,
                                'total_balance' => $accuredLeave,
                                'description' => 'New leave credit after probation period',
                            ]);
                        }
                    }
                }
            }

            

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while accure leaves for new joinee after complete probation period";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function dailyLeaveReminder()
    {
        DB::beginTransaction();
        try {
            $currentDate = getUtcDate();
            $currentTime = getHour();

            $organizations = Organization::select('id')->get();

            foreach ($organizations as $organization) {
                $organizationId = $organization->id;
                $user = User::where('organization_id', $organizationId)->where('entity_id', 1)->where('entity_type_id', EntityType::Employee)->first('timezone_id');
                $holiday = Holiday::withoutGlobalScopes([OrganizationScope::class])->where('organization_id', $organizationId)->whereDate('date', $currentDate)->first(['id']);
                $weekends = OrganizationWeekend::where('organization_id', $organizationId)->get('week_day')->pluck('week_day')->toArray();
                $currentDay = date('N');
                if (empty($holiday) && !in_array($currentDay, $weekends)) {

                    $settings = Setting::leftJoin('organization_settings', 'settings.id', 'organization_settings.setting_id')->where('organization_id', $organizationId)->get(['settings.key', 'organization_settings.value', 'organization_id'])->pluck('value', 'key')->toArray();

                    $sendTodayLeaveDetails = $settings['send_today_leave_details'];
                    $sendTodayLeaveDetailTime = $settings['send_today_leave_details_time'];
                    $sendTodayLeaveDetailTo = $settings['send_today_leave_details_to'];
                    if (!empty($user)) {
                        $timezone = Timezone::find($user->timezone_id);
                        $utc_date = '12:00';
                        if (!empty($timezone)) {
                            $timezone = $timezone->value;
                            $utc_date = Carbon::createFromFormat('H:i', $sendTodayLeaveDetailTime, $timezone);
                            $utc_date->setTimezone('UTC')->toDateTimeString();
                            $utc_date = Carbon::parse($utc_date)->format('H:i');
                        }
                    }

                    if ($sendTodayLeaveDetails == 1 && $currentTime == $utc_date) {
                        $leaves = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->join('employees', function ($join) use ($organizationId) {
                            $join->on('leaves.employee_id', '=', 'employees.id');
                            $join->where('employees.organization_id', $organizationId);
                        })->whereDate('leave_date', $currentDate)
                            ->select('leave_details.day_duration_id', 'leaves.applied_date', 'employees.display_name')
                            ->where('leaves.leave_status_id', LeaveStatus::APPROVE)->get();

                        $info = array();
                        foreach ($leaves as $key => $value) {
                            $value['leave_type'] = 'Planned Leave';
                            if ($value['applied_date'] == $currentDate) {
                                $value['leave_type'] = 'Adhoc Leave';
                            }

                            $value['duration'] = DayDuration::FULLDAYNAME;
                            if ($value['day_duration_id'] == DayDuration::FIRSTHALF) {
                                $value['duration'] = DayDuration::FIRSTHALFNAME;
                            } else if ($value['day_duration_id'] == DayDuration::SECONDHALF) {
                                $value['duration'] = DayDuration::SECONDHALFNAME;
                            }

                            $info[$key]['employee_name'] = $value['display_name'];
                            $info[$key]['duration'] = $value['duration'];
                            $info[$key]['leave_type'] = $value['leave_type'];
                        }

                        if (!empty($sendTodayLeaveDetailTo)) {
                            $userData = User::whereIn('entity_id', explode(',', $sendTodayLeaveDetailTo))->where('organization_id', $organizationId)->get(['id', 'entity_id', 'email']);
                            $data = new DailyLeaveEmail($info);

                            $emailData = ['email' => $userData, 'email_data' => $data];

                            SendEmailJob::dispatch($emailData);

                        }
                    }
                }
            }
            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while daily leave reminder";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function autoLeave()
    {
        DB::beginTransaction();
        try {
            $currentDate = getUtcDate();

            $organizations = Organization::select('id')->get();

            foreach ($organizations as $organization) {
                $organizationId = $organization->id;
                $holiday = Holiday::withoutGlobalScopes([OrganizationScope::class])->where('organization_id', $organizationId)->whereDate('date', $currentDate)->first(['id']);
                $weekends = OrganizationWeekend::where('organization_id', $organizationId)->get('week_day')->pluck('week_day')->toArray();
                $currentDay = date('N');
                if (empty($holiday) && !in_array($currentDay, $weekends)) {

                    $settings = Setting::leftJoin('organization_settings', 'settings.id', 'organization_settings.setting_id')->where('organization_id', $organizationId)->get(['settings.key', 'organization_settings.value', 'organization_id'])->pluck('value', 'key')->toArray();

                    $autoLeaveForPunchInOut = $settings['auto_leave_for_punch_in_out'];

                    $sendMailForAutoLeaveTo = $settings['send_mail_for_auto_leave_to'];

                    if ($autoLeaveForPunchInOut == 1) {

                        $defaultLeaveType = LeaveType::where('is_primary', 1)->where('organization_id', $organizationId)->first('id');

                        if (!empty($defaultLeaveType)) {

                            $query = Employee::withoutGlobalScopes([OrganizationScope::class])->active()
                                ->leftJoin('attendances', function ($join) use ($organizationId, $currentDate) {
                                    $join->on('employees.id', '=', 'attendances.employee_id');
                                    $join->where('employees.organization_id', $organizationId);
                                    $join->whereDate(DB::raw('DATE(attendances.created_at)'), $currentDate);
                                })
                                ->leftJoin('leaves', function ($join) use ($organizationId, $currentDate) {
                                    $join->on('leaves.employee_id', '=', 'employees.id');
                                    $join->where('leaves.organization_id', $organizationId);
                                    $join->whereNull('leaves.deleted_at');
                                })
                                ->leftJoin('leave_details', function ($join) use ($currentDate) {
                                    $join->on('leave_details.leave_id', '=', 'leaves.id');
                                    $join->where('leave_details.leave_date', $currentDate);
                                    $join->whereNull('leave_details.deleted_at');
                                })
                                ->whereNull('leave_details.id')
                                ->where('employees.do_not_required_punchinout',0)
                                ->select('employees.id as employee_id', 'employees.display_name', 'users.id as user_id', 'users.timezone_id', 'attendances.punch_in', 'attendances.punch_out')
                                ->where('employees.organization_id', $organization->id)
                                ->groupBy('attendances.employee_id');

                            $autoLeaveForForgotPunchinout = $settings['auto_leave_for_forgot_punchinout'];

                            $autoLeaveForForgotPunchout = $settings['auto_leave_for_forgot_punchout'];

                            $autoFullLeaveForLogLessHour = $settings['auto_full_leave_for_log_less_hour'];
                            $logHoursForAutoFullLeave = $settings['log_hours_for_auto_full_leave'];
                            $autoHalfLeaveForLogLessHour = $settings['auto_half_leave_for_log_less_hour'];
                            $logHoursForAutoHalfLeave = $settings['log_hours_for_auto_half_leave'];
                            $leaveStatus = LeaveStatus::APPROVE;
                            $leaveData = [
                                'organization_id' => $organization->id,
                                'from_date' => $currentDate,
                                'to_date' => $currentDate,
                                'applied_date' => getUtcDate(),
                                'leave_status_id' => $leaveStatus,
                                'to' => $sendMailForAutoLeaveTo,
                                'leave_type_id' => $defaultLeaveType->id,
                                'system_leave' => Leave::PENDINGSYSTEMLEAVE
                            ];

                            $leaveDetail = ['leave_date' => $currentDate];

                            //Missing punch in and punch out add full auto leave
                            if (!empty($autoLeaveForForgotPunchinout)) {
                                $fullLeaveEmployee = clone $query;
                                $employees = $fullLeaveEmployee->whereNull('attendances.id')->get();

                                $message = "You missed Punch In & Out yesterday. So the system added it as a leave for " . $currentDate;
                                $subject = "Auto Leave Added on " . $currentDate . " Due to Missing Punch In & Out";
                                $leaveType = 'Full Leave';
                                $leaveData['description'] = 'Forgot Punch';
                                $leaveData['remarks'] = Leave::AUTOLEAVEREMARK;
                                $leaveData['total_working_days'] = 1;

                                $leaveDetail['day_duration_id'] = DayDuration::FULLDAY;

                                $this->autoLeaveEmailData($employees, $leaveData, $leaveDetail, $leaveType, $subject, $message, $settings, $organizationId, 'punchin-out');

                            }

                            //Missing punch out add half auto leave
                            if (!empty($autoLeaveForForgotPunchout)) {
                                $halfLeaveEmployee = clone $query;
                                $employees = $halfLeaveEmployee->whereNull('punch_out')->whereNotNull('punch_in')->get();

                                $message = "You did not punch out yesterday. So the system added it as a second half leave for " . $currentDate;
                                $subject = "Half Leave: You did not punch out on " . $currentDate;
                                $leaveType = 'Half Leave';

                                $leaveData['description'] = 'Forgot Punch Out';
                                $leaveData['remarks'] = Leave::AUTOLEAVEREMARK;
                                $leaveData['total_working_days'] = 0.5;

                                $leaveDetail['day_duration_id'] = DayDuration::SECONDHALF;

                                $this->autoLeaveEmailData($employees, $leaveData, $leaveDetail, $leaveType, $subject, $message, $settings, $organizationId, 'punch-out');

                            }

                            //Log less hours then configure for auto leave
                            if (!empty($autoHalfLeaveForLogLessHour) || !empty($autoFullLeaveForLogLessHour)) {
                                $autoLeaveEmployee = clone $query;
                                $autoLeaveEmployee->whereNull('leaves.id');
                                $employees = $autoLeaveEmployee->whereRaw("TIMESTAMPDIFF(HOUR,`punch_in`,`punch_out`) <= " . $logHoursForAutoHalfLeave)->get();
                                $leaveType = 'Half Leave';
                                $leaveData['description'] = 'Late Punch In or Early Punch Out';
                                $leaveData['remarks'] = Leave::AUTOLEAVEREMARK;
                                $leaveDetail['day_duration_id'] = DayDuration::SECONDHALF;
                                $subject = "Half Leave: Half Day Leave registered in FOVERO";
                                $message = '';

                                $this->autoLeaveEmailData($employees, $leaveData, $leaveDetail, $leaveType, $subject, $message, $settings, $organizationId, 'less-half-hours', $logHoursForAutoFullLeave, true);
                            }
                        }
                    }
                }
            }
            DB::commit();
            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while store auto leave";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Send email for auto leave based on permission
    public function autoLeaveEmailData($employees, $leaveData, $leaveDetail, $leaveType, $subject, $message, $settings, $organizationId, $action, $logHoursForAutoFullLeave = 2, $logHoursBaseAutoLeave = false)
    {
        if (!empty($employees)) {

            foreach ($employees as $employee) {
                $leaveData['uuid'] = getUuid();
                $leaveData['employee_id'] = $employee->employee_id;

                $punchin = '';
                $punchout = '';
                if ($logHoursBaseAutoLeave) {

                    if (!empty($employee->punch_in) && !empty($employee->punch_out)) {

                        $timezone = Timezone::find($employee->timezone_id);
                        $timezone = $timezone->value;
                        $userPunchInDate = date('Y-m-d H:i:s', strtotime($employee->punch_in));
                        $userPunchInDate = Carbon::createFromFormat('Y-m-d H:i:s', $userPunchInDate, 'UTC');
                        $userPunchInDate->setTimezone($timezone)->toDateTimeString();
                        $userPunchIn = Carbon::parse($userPunchInDate)->format('Y-m-d H:i:s');
                        
                        $userPunchOutDate = date('Y-m-d H:i:s', strtotime($employee->punch_out));
                        $userPunchOutDate = Carbon::createFromFormat('Y-m-d H:i:s', $userPunchOutDate, 'UTC');
                        $userPunchOutDate->setTimezone($timezone)->toDateTimeString();
                        $userPunchOut = Carbon::parse($userPunchOutDate)->format('Y-m-d H:i:s');

                        $punchin = $userPunchIn;
                        $punchout = $userPunchOut;
                        $carbonPunchIn = Carbon::parse($employee->punch_in);
                        $carbonPunchOut = Carbon::parse($employee->punch_out);
                        $diff = number_format(($carbonPunchIn->diffInMinutes($carbonPunchOut) / 60), 2);

                        $message = "";
                        $subject = "Half Leave: Half Day Leave registered in FOVERO";
                        $leaveType = 'Half Leave';

                        $leaveData['total_working_days'] = 0.5;

                        $leaveDetail['day_duration_id'] = DayDuration::SECONDHALF;

                        if ($diff < $logHoursForAutoFullLeave) {

                            $leaveData['total_working_days'] = 1;
                            $leaveDetail['day_duration_id'] = 1;
                            $leaveType = 'Full Leave';
                            $subject = 'Full Leave: Full Day Leave registered in FOVERO';
                            $action = 'less-full-hours';
                        }
                    }
                }


                $leaveExist = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->whereBetween('leave_date', [$leaveData['from_date'], $leaveData['to_date']])->where('employee_id', $leaveData['employee_id'])->whereIn('leaves.leave_status_id', [LeaveStatus::PENDING, LeaveStatus::APPROVE])->first(['leaves.id', 'leaves.leave_status_id', 'leave_details.day_duration_id']);

                if (!empty($leaveExist)) {
                    if ($leaveExist->leave_status_id == LeaveStatus::PENDING) {
                        Leave::where('id', $leaveExist->id)->update(['leave_status_id' => LeaveStatus::APPROVE, 'system_leave' => 1, 'remarks' => Leave::AUTOLEAVEREMARK]);

                        if ($leaveExist->day_duration_id != DayDuration::FULLDAY && $leaveData['total_working_days'] == 1) {
                            $leaveData['description'] = 'Forgot Punch Out';
                            $leaveData['remarks'] = Leave::AUTOLEAVEREMARK;
                            $leaveData['total_working_days'] = 0.5;
                            $leaveDetail['day_duration_id'] = DayDuration::SECONDHALF;
                            $leave = Leave::create($leaveData);

                            $leaveDetail['leave_id'] = $leave->id;

                            LeaveDetail::create($leaveDetail);
                        }
                    }
                    continue;
                }

                $leave = Leave::create($leaveData);

                $leaveDetail['leave_id'] = $leave->id;

                LeaveDetail::create($leaveDetail);
                $info = ['employee' => $employee->display_name, 'message' => $message, 'subject' => $subject, 'leave_type' => $leaveType, 'leave_id' => $leave->uuid, 'punch_in' => $punchin, 'punch_out' => $punchout];

                $sendMailForForgotPunchinout = $settings['send_mail_for_forgot_punchinout'];
                $sendMailForForgotPunchout = $settings['send_mail_for_forgot_punchout'];
                $sendMailForAutoLeave = $settings['send_mail_for_auto_leave'];
                $autoFullLeaveForLogLessHour = $settings['auto_full_leave_for_log_less_hour'];
                $autoHalfLeaveForLogLessHour = $settings['auto_half_leave_for_log_less_hour'];
                $sendMailForAutoLeaveTo = $settings['send_mail_for_auto_leave_to'];

                $info['forgot_punch'] = false;
                if ($logHoursBaseAutoLeave == false) {
                    $info['forgot_punch'] = true;
                }

                if (!empty($sendMailForAutoLeave) && !empty($sendMailForAutoLeaveTo)) {
                    if ($action == 'punchin-out' && $sendMailForForgotPunchinout == true) {
                        $this->autoLeaveEmail($sendMailForAutoLeaveTo, $organizationId, $info, $employee);
                    } elseif ($action == 'punch-out' && $sendMailForForgotPunchout == true) {
                        $this->autoLeaveEmail($sendMailForAutoLeaveTo, $organizationId, $info, $employee);
                    } elseif ($action == 'less-half-hours' && $autoHalfLeaveForLogLessHour == true) {
                        $this->autoLeaveEmail($sendMailForAutoLeaveTo, $organizationId, $info, $employee);
                    } elseif ($action == 'less-full-hours' && $autoFullLeaveForLogLessHour == true) {
                        $this->autoLeaveEmail($sendMailForAutoLeaveTo, $organizationId, $info, $employee);
                    }

                }
            }
        }

        return;
    }
    public function autoLeaveEmail($sendMailForAutoLeaveTo, $organizationId, $info, $employee)
    {
        $sendMailForAutoLeaveTo = explode(',', $sendMailForAutoLeaveTo);

        $sendMailForAutoLeaveTo = array_merge([$employee->employee_id], $sendMailForAutoLeaveTo);

        $userData = User::whereIn('entity_id', $sendMailForAutoLeaveTo)->where('organization_id', $organizationId)->get(['id', 'entity_id', 'email']);

        $data = new AutoLeaveEmail($info);

        $emailData = ['email' => $userData, 'email_data' => $data];

        SendEmailJob::dispatch($emailData);

        return;
    }

    public function adminLeaveBalance(Request $request)
    {
        try {
            $organizationId = $this->getCurrentOrganizationId();
           
            $employee = $request->employee;
            $leaveType = $request->leave_type;
            $perPage = $request->perPage ?? 50;
     
            $response = $this->leaveBalanceDetail($organizationId, $employee, $leaveType, $perPage);

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while leave balance";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function leaveBalanceDetail($organizationId, $employee, $leaveType, $perPage = 1)
    {
        $query = LeaveType::leftJoin('leave_type_types', 'leave_types.leave_type_type_id', 'leave_type_types.id')->where('leave_type_type_id', '!=' , LeaveTypeType::CompensatoryOffID)->select('leave_types.id', 'leave_types.name', 'leave_type_types.name as leave_type', 'leave_type_type_id', 'accrual_period', 'accrual_date', 'accrual_month', 'reset_period', 'reset_date', 'reset_month');

        $leaveTypes = $query->get();
        $leaveTypeList = [];
        foreach($leaveTypes as $item){
            //Get current refill period to minus balance to display current balance after deduct leave from current period
            $accrualPeriod = $item->accrual_period;
            $accrualDate = $item->accrual_date;
            $accrualMonth = $item->accrual_month;

            $resetPeriod = $item->reset_period;
            $resetDate = $item->reset_date;
            $resetMonth = $item->reset_month;

            $date = date('j');
            $month = date('n');
            $year = date('Y');
            $currentMonth = date('n');
            $lastDay = config('constant.last_day');
            $periodConfig = config('constant.job_schedule_period');
            if ($accrualPeriod == $periodConfig['Yearly']) {
                if ($accrualDate == $lastDay) {
                    $accrualDate = Carbon::parse(date('Y-' . $accrualMonth . '-t'))->endOfMonth()->format('d');
                }

                $to = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->addYear()->format('Y-m-d');
            }

            if ($accrualPeriod == $periodConfig['Half yearly']) {
                $monthList = config('constant.half_year_month_list');
                $accrualMonth = $monthList[$accrualMonth];
                if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                    $month = $accrualMonth[0];
                    $monthEnd = $accrualMonth[1];
                } else if ($month < $accrualMonth[0]) {
                    $month = $accrualMonth[1];
                    $monthEnd = $accrualMonth[0];
                } else if ($month >= $accrualMonth[1]) {
                    $month = $accrualMonth[1];
                    $monthEnd = $accrualMonth[0];
                }

                if ($accrualDate == $lastDay) {
                    $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                }

                if($month > $currentMonth){
                    $year = Carbon::parse(date('Y'))->subYear()->format('Y');
                }

                $accrualMonth = $month;
                $to = Carbon::parse(date('Y-' . $monthEnd . '-' . $accrualDate))->format('Y-m-d');
            }

            if ($accrualPeriod == $periodConfig['Quarterly']) {
                $monthList = config('constant.quartarly_month_list');
                $accrualMonth = $monthList[$accrualMonth];

                if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                    $currQuarter = 1;
                    $endQuarter = 1;
                   
                }

                if ($month >= $accrualMonth[1] && $month < $accrualMonth[2]) {
                    $currQuarter = 2;
                    $endQuarter = 2;
                  
                }

                if ($month >= $accrualMonth[2] && $month < $accrualMonth[3]) {
                    $currQuarter = 3;
                    $endQuarter = 3;
                 
                }

                if ($month < $accrualMonth[0] || $month >= $accrualMonth[3]) {
                    $currQuarter = 4;
                    $endQuarter = 0;
                   
                }

                $monthCal = $accrualMonth[$currQuarter - 1];               
                $month = $monthCal;
                if ($accrualDate == $lastDay) {
                    $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                }
   
                $monthEndCal = $accrualMonth[$endQuarter];
                $accrualMonth = $month;

                if($month > $currentMonth){
                    $year = Carbon::parse(date('Y'))->subYear()->format('Y');
                }

                if( $monthEndCal < $month){
                    $to = Carbon::parse(date('Y-' . $monthEndCal . '-' . $accrualDate))->addYear()->format('Y-m-d');
                }else{
                    $to = Carbon::parse(date('Y-' . $monthEndCal . '-' . $accrualDate))->format('Y-m-d');
                }
               
            }

            if ($accrualPeriod == $periodConfig['Monthly']) {

                if ($accrualDate == $lastDay) {
                    $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                }
                $accrualMonth = $month;


                $to = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->addMonth()->format('Y-m-d');
            }

            if ($resetPeriod == $periodConfig['Yearly']) {
                if ($resetDate == $lastDay) {
                    $resetDate = Carbon::parse(date('Y-' . $resetMonth . '-t'))->endOfMonth()->format('d');
                }
            }

            if ($resetPeriod == $periodConfig['Half yearly']) {
                $monthList = config('constant.half_year_month_list');
                $resetMonth = $monthList[$resetMonth];
                if ($month >= $resetMonth[0] && $month < $resetMonth[1]) {
                    $month = $resetMonth[0];
                    $monthEnd = $resetMonth[1];
                    $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
                } else if ($month < $resetMonth[0]) {
                    $month = $resetMonth[1];
                    $monthEnd = $resetMonth[0];
                    $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
                } else if ($month > $resetMonth[1]) {
                    $month = $resetMonth[1];
                    $monthEnd = $resetMonth[0];
                    $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
                }

                $resetDate = Carbon::parse(date('Y-' . $month . '-' . $resetDate))->addDay()->format('d');
                if ($resetDate == $lastDay) {
                    $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                }

                $resetMonth = $month;

            }

            if ($resetPeriod == $periodConfig['Quarterly']) {
                $month = date('m');
                $monthList = config('constant.quartarly_month_list');
                $resetMonth = $monthList[$resetMonth];
                $currQuarter = ($month - 1) / 3 + 1;
                $monthCal = 3 * $currQuarter - 2;

                $month = date('m', strtotime('Y-' . $monthCal . '-1'));
                if ($resetDate == $lastDay) {
                    $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                }
                $resetMonth = $month;

                $monthEndCal = (3 * $currQuarter) + 1;

            }

            if ($resetPeriod == $periodConfig['Monthly']) {

                if ($resetDate == $lastDay) {
                    $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                }
                $resetMonth = $month;

            }
            $resetDate = Carbon::parse(date($year.'-' . $accrualMonth . '-' . $accrualDate))->format('Y-m-d');

            $startDate = $resetDate;
            $endDate = $to;

            $leaveTypeList[$item->id]['start'] = $startDate;
            $leaveTypeList[$item->id]['end'] = $endDate;
            
        }

        $query->when($leaveType, function ($que) use ($leaveType) {
            $que->where('leave_types.id', $leaveType);
        });

        $header = $query->where('leave_type_types.name', '!=', LeaveTypeType::CompensatoryOff)->groupBy('leave_types.leave_type_type_id')->groupBy('leave_types.id')->get()->groupBy('leave_type_type_id');
        $employeeQuery =  Employee::withoutGlobalScopes()->join('leave_balance', 'employees.id', 'leave_balance.employee_id')->where('leave_balance.organization_id', $organizationId)->where('employees.organization_id', $organizationId);
        if($employee != 'inactive'){
                $employeeQuery = $employeeQuery->active()->select('employees.id', 'display_name');
        } else {
                $employeeQuery = $employeeQuery->join('users', function ($join) {
                    $join->on('users.entity_id', '=',  'employees.id');
                    $join->on('users.organization_id', '=', 'employees.organization_id');
                })->where('is_active',false)->select('employees.id', 'display_name');
        }

        if(!empty($employee) && $employee != 'inactive'){

            $employeeQuery->where('employees.id', $employee);
        }
        $employeeQuery->whereNull('employees.deleted_at')->groupBy('employees.id');

        if($perPage == 'export'){
            $employees = $employeeQuery->get();
            $employeeIds = $employees->pluck('id')->toArray();
        }else{
            $employees = $employeeQuery->simplePaginate($perPage);

            $employeeIds = collect($employees->items())->pluck('id')->toArray();
        }
      

        $query = LeaveType::withoutGlobalScopes([OrganizationScope::class])->leftJoin('leave_balance', 'leave_balance.leave_type_id', 'leave_types.id')
            ->leftJoin('employees', function ($join) use ($organizationId) {
                $join->on('leave_balance.employee_id', 'employees.id');
                $join->where('employees.organization_id', $organizationId);
            })->where('leave_balance.organization_id', $organizationId)
            ->whereIn('employee_id',$employeeIds)
            ->where('leave_balance.organization_id',$organizationId)
            ->whereNull('employees.deleted_at')
            ->where('leave_types.leave_type_type_id', '!=', LeaveTypeType::CompensatoryOffID)
            ->where('leave_types.organization_id', $organizationId)
            ->select('leave_types.name', 'leave_balance.employee_id', 'leave_balance.balance', 'leave_balance.leave_type_id', 'employees.display_name', 'employees.avatar_url','employees.probation_period_end_date', 'accrual_period', 'accrual_month', 'accrual_date', 'reset_period', 'reset_month', 'reset_date');

        $query = $query->groupBy('leave_balance.leave_type_id');
        // if (!empty($balanceHistoryDate)) { //uncomment when datefilter add
        //     $latestRecords = DB::table('leave_balance_history')
        //         ->selectRaw("max(id) max_id,leave_type_id")
        //         ->where(DB::raw('DATE(`leave_balance_history` . `created_at`)'), '<=', $date)
        //         ->where(function ($que) use ($organizationId) {
        //             $que->where('leave_balance_history.organization_id', $organizationId);
        //         })
        //         ->groupBy('leave_type_id', 'employee_id');
        //     $query = LeaveType::withoutGlobalScopes([OrganizationScope::class])

        //         ->leftJoinSub($latestRecords, 'leave_balance_history_latest', function ($join) {
        //             $join->on('leave_types.id', '=', 'leave_balance_history_latest.leave_type_id');
        //         })
        //         ->leftJoin('leave_balance_history', 'leave_balance_history_latest.max_id', 'leave_balance_history.id')
        //         ->leftJoin('employees', function ($join) use ($organizationId) {
        //             $join->on('leave_balance_history.employee_id', 'employees.id');
        //             $join->where('employees.organization_id', $organizationId);
        //         })->where('leave_balance_history.organization_id', $organizationId)
        //         ->whereDate('leave_balance_history.created_at', '<=', $date)
        //         ->select('leave_types.name', 'leave_balance_history.employee_id', 'leave_balance_history.total_balance as balance', 'leave_balance_history.leave_type_id', 'employees.display_name', 'employees.avatar_url', 'accrual_period', 'accrual_month', 'accrual_date', 'reset_period', 'reset_month', 'reset_date');

        //     $query = $query->groupBy('leave_balance_history.leave_type_id');
        // }

        if(!empty($employee) && $employee != 'inactive'){

            $query->where('employees.id', $employee);
        }

        $query->when($leaveType, function ($que) use ($leaveType) {
            $que->where('leave_types.id', $leaveType);
        });

        $leaveBalance = $query->groupBy('employee_id')->orderBy('employee_id')->get()->groupBy('employee_id');


        $setting = OrganizationSetting::with('setting')->whereHas('setting', function ($que) {
            $que->where('settings.key','LIKE','lop_during_notice_period');
        })->first(['value']);
        $lopDuringNoticePeriod = false;
        if(!empty($setting)){
            $lopDuringNoticePeriod = $setting->value;
        }

        $balance = [];
        foreach ($leaveBalance as $employeesData) {
            $entry = [];
          
            foreach ($employeesData as $employee) {
                $startDate = ($leaveTypeList[$employee->leave_type_id]['start'] > $employee->probation_period_end_date) ? $leaveTypeList[$employee->leave_type_id]['start'] : $employee->probation_period_end_date;
                $endDate = $leaveTypeList[$employee->leave_type_id]['end'];

                //Check if the consider lop during the notice period then balance should not deduct after the resign date added
                if($lopDuringNoticePeriod == true){
                    $endDate = (!empty($employee->resign_date) && ($leaveTypeList[$employee->leave_type_id]['end'] > $employee->resign_date)) ?  $employee->resign_date : $leaveTypeList[$employee->leave_type_id]['end'];
                }

                $total = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leave_type_id', $employee->leave_type_id)->where('employee_id', $employee->employee_id)->whereBetween('leave_date', [$startDate, $endDate])->where('leaves.leave_status_id', LeaveStatus::APPROVE)->select('employee_id', 'leave_type_id', 'leave_date', DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->sum('total_days');

                $entry[$employee->leave_type_id] = round(($employee->balance - $total), 2) >= 0 ? round(($employee->balance - $total), 2) : 0;
            }
            $balance[$employeesData[0]->employee_id] = $entry;
        }

        foreach($employees as $employee){
            $employee->balance = $balance[$employee->id];
        }

        $response = ['leave_types' => $leaveTypes, 'header' => $header, 'balance' => $employees];

        return $response;
    }

    public function exportLeaveBalancePdf(Request $request)
    {
        try {
            $organizationId = $this->getCurrentOrganizationId();
            $employee = $request->employee;
            $leaveType = $request->leave_type;

            $response = $this->leaveBalanceDetail($organizationId, $employee, $leaveType, 'export');

            $html = view('pdf.leave-balance.export', ['headers' => $response['header'], 'details' => $response['balance']])->render();

            $pdf = PDF::setOptions(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => true])->loadHTML($html)->setPaper('a4', 'landscape')->setWarnings(false);

            $pdf->getDomPDF()->setHttpContext(
                stream_context_create([
                    'ssl' => [
                        'allow_self_signed' => TRUE,
                        'verify_peer' => FALSE,
                        'verify_peer_name' => FALSE,
                    ]
                ])
            );

            return $pdf->download('leave-balance-report.pdf');
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while export leave balance";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function editLeaveBalance(Request $request)
    {
        try {
            DB::beginTransaction();
            $employeeId = $request->employee;
            $leaveTypeList = $request->leave_types;
            $organizationId = $this->getCurrentOrganizationId();

            $employee = Employee::where('id', $employeeId)->where('organization_id', $organizationId)->first(['display_name', 'probation_period_end_date']);

            $leaveTypes = LeaveType::select('id', 'accrual_period', 'accrual_date', 'accrual_month', 'reset_period', 'reset_date', 'reset_month')->get();

            if (!empty($leaveTypes)) {
                foreach ($leaveTypes as $leaveType) {
                    //Get current refill period to add the total leave in current refill period
                    $accrualPeriod = $leaveType->accrual_period;
                    $accrualDate = $leaveType->accrual_date;
                    $accrualMonth = $leaveType->accrual_month;

                    $resetPeriod = $leaveType->reset_period;
                    $resetDate = $leaveType->reset_date;
                    $resetMonth = $leaveType->reset_month;

                    $date = date('j');
                    $month = date('n');
                    $lastDay = config('constant.last_day');
                    $periodConfig = config('constant.job_schedule_period');
                    if ($accrualPeriod == $periodConfig['Yearly']) {

                        if ($accrualDate == $lastDay) {
                            $accrualDate = Carbon::parse(date('Y-' . $accrualMonth . '-t'))->endOfMonth()->format('d');
                        }
                        $accrualDate = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->addDay()->format('d');
                        $to = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->addYear()->format('Y-m-d');
                    }

                    if ($accrualPeriod == $periodConfig['Half yearly']) {
                        $monthList = config('constant.half_year_month_list');
                        $accrualMonth = $monthList[$accrualMonth];

                        if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                            $month = $accrualMonth[0];
                            $monthEnd = $accrualMonth[1];
                        } else if ($month < $accrualMonth[0]) {
                            $month = $accrualMonth[1];
                            $monthEnd = $accrualMonth[0];
                        } else if ($month >= $accrualMonth[1]) {
                            $month = $accrualMonth[1];
                            $monthEnd = $accrualMonth[0];
                        }

                     
                        if ($accrualDate == $lastDay) {
                            $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                        }

                        $accrualDate = Carbon::parse(date('Y-' . $month . '-' . $accrualDate))->addDay()->format('d');

                        $accrualMonth = $month;

                        $to = Carbon::parse(date('Y-' . $monthEnd . '-' . $accrualDate))->format('Y-m-d');
                    }

                    if ($accrualPeriod == $periodConfig['Quarterly']) {
                        $monthList = config('constant.quartarly_month_list');
                        $accrualMonth = $monthList[$accrualMonth];
                        
                        if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                            $currQuarter = 1;
                            $endQuarter = 1;
                           
                        }
        
                        if ($month >= $accrualMonth[1] && $month < $accrualMonth[2]) {
                            $currQuarter = 2;
                            $endQuarter = 2;
                          
                        }
        
                        if ($month >= $accrualMonth[2] && $month < $accrualMonth[3]) {
                            $currQuarter = 3;
                            $endQuarter = 3;
                         
                        }
        
                        if ($month < $accrualMonth[0] || $month >= $accrualMonth[3]) {
                            $currQuarter = 4;
                            $endQuarter = 0;
                           
                        }
                        $monthCal = $accrualMonth[$currQuarter - 1];               
                        $month = $monthCal;
                        if ($accrualDate == $lastDay) {
                            $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                        }
           
                        $monthEndCal = $accrualMonth[$endQuarter];
                        $accrualMonth = $month;
        
                        $to = Carbon::parse(date('Y-' . $monthEndCal . '-' . $accrualDate))->format('Y-m-d');
                    }

                    if ($accrualPeriod == $periodConfig['Monthly']) {
                        if ($accrualDate == $lastDay) {
                            $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                        }
                        $accrualMonth = $month;

                        $to = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->addMonth()->format('Y-m-d');
                    }


                    if ($resetPeriod == $periodConfig['Yearly']) {
                        if ($resetDate == $lastDay) {
                            $resetDate = Carbon::parse(date('Y-' . $resetMonth . '-t'))->endOfMonth()->format('d');
                        }

                    }

                    if ($resetPeriod == $periodConfig['Half yearly']) {
                        $monthList = config('constant.half_year_month_list');
                        $resetMonth = $monthList[$resetMonth];
                        if ($month >= $resetMonth[0] && $month < $resetMonth[1]) {
                            $month = $resetMonth[0];
                            $monthEnd = $resetMonth[1];
                            $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
                        } else if ($month < $resetMonth[0]) {
                            $month = $resetMonth[1];
                            $monthEnd = $resetMonth[0];
                            $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
                        } else if ($month > $resetMonth[1]) {
                            $month = $resetMonth[1];
                            $monthEnd = $resetMonth[0];
                            $resetDate = Carbon::parse(date('Y-' . $month . '-1'))->format('d');
                        }

                        $resetDate = Carbon::parse(date('Y-' . $month . '-' . $resetDate))->addDay()->format('d');
                        if ($resetDate == $lastDay) {
                            $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                        }

                        $resetMonth = $month;

                    }

                    if ($resetPeriod == $periodConfig['Quarterly']) {
                        $month = date('m');
                        $monthList = config('constant.quartarly_month_list');
                        $resetMonth = $monthList[$resetMonth];
                        $currQuarter = ($month - 1) / 3 + 1;
                        $monthCal = 3 * $currQuarter - 2;

                        $month = date('m', strtotime('Y-' . $monthCal . '-1'));
                        if ($resetDate == $lastDay) {
                            $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                        }
                        $resetMonth = $month;

                        $monthEndCal = (3 * $currQuarter) + 1;
                    }

                    if ($resetPeriod == $periodConfig['Monthly']) {

                        if ($resetDate == $lastDay) {
                            $resetDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                        }
                        $resetMonth = $month;

                    }
                    $from = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->format('Y-m-d');

                    if($from < $employee->probation_period_end_date){
                        $from = $employee->probation_period_end_date;
                    }

                    $total = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereBetween('leave_date', [$from, $to])->where('leaves.leave_status_id', LeaveStatus::APPROVE)->select('employee_id', 'leave_type_id', 'leave_date', DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->sum('total_days');

                    $leaveTypeLeave[$leaveType->id] = $total;
                }
            }

            foreach ($leaveTypeList as $leaveType) {
                $balance = $leaveType['balance'];
                $description = $leaveType['description'] ?? 'manual correction required';
                $leaveTypeId = $leaveType['leave_type_id'];

                if (isset($balance)) {
                    $type = LeaveType::where('id', $leaveTypeId)->first('name');

                    $leaveBalance = LeaveBalance::where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->where('organization_id', $organizationId)->first(['id', 'balance']);
                    $oldBalance = $leaveBalance->balance - $leaveTypeLeave[$leaveTypeId];

                    $balance = $balance + $leaveTypeLeave[$leaveTypeId];

                    $leaveBalance->update(['balance' => $balance]);

                    LeaveBalanceHistory::create([
                        'employee_id' => $employeeId,
                        'organization_id' => $organizationId,
                        'leave_type_id' => $leaveTypeId,
                        'balance' => $balance,
                        'total_balance' => $balance,
                        'description' => $description
                    ]);

                    $logData = ['organization_id' => $organizationId, 'new_data' => json_encode(['plain' => $balance]), 'old_data' => json_encode(['plain' => $oldBalance]), 'action' => 'modify leave balance of ' . $type->name . ' for ' . $employee->display_name . ' due to <b>' . $description . '</b>', 'table_name' => 'employees', 'updated_by' => $request->user()->id, 'module_id' => $employeeId, 'module_name' => 'LMS'];

                    $activityLog = new ActivityLog();
                    $activityLog->createLog($logData);
                }
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while edit leave balance";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getLeaveTypeHistory(Request $request)
    {
        try {
            $leaveType = $request->leave_type;
            $employeeId = $request->employee;
            $organizationId = $this->getCurrentOrganizationId();

            $leaveType = LeaveType::where('uuid', $leaveType)->first('id');
            $leaveBalance = LeaveBalanceHistory::where('organization_id', $organizationId)->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->select('employee_id', 'leave_type_id', 'balance', 'action_type', 'created_at')->get();

            foreach ($leaveBalance as $index => $entry) {
                if (!empty($leaveBalance[$index - 1])) {
                    $addedBalance = $entry->balance - $leaveBalance[$index - 1]->balance;
                    if ($addedBalance >= 0) {
                        $entry->added = $addedBalance;
                    } else {
                        $entry->removed = -$addedBalance;
                    }
                } else {
                    $entry->added = 0;
                }
            }

            return $this->sendSuccessResponse(__('messages.success'), 200, $leaveBalance);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while leave balance";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function lopReportDetail(Request $request)
    {
        try {
            $organizationId = $this->getCurrentOrganizationId();
            $lopMonth = $request->month;

            $lopYear = $request->year;
            $lopMonthDate = \Carbon\Carbon::parse(date($lopYear . '-' . $lopMonth . '-1'))->endOfMonth()->toDateString();
            $monthName = date('F', strtotime($lopMonthDate));
            $year = date('Y', strtotime($lopMonthDate));

            $lopDetails = LopDetail::join('employees', function($join) use($organizationId){
                $join->on('employees.id', 'lop_details.employee_id');
                $join->where('employees.organization_id', $organizationId);
            })->where('month' , $lopMonth)
            ->where('year' , $lopYear)
            ->where('lop_details.organization_id', $organizationId)
            ->select('employee_id', 'employees.display_name as employee_name', 'lop')
            ->get();

            if(!empty($lopDetails) && count($lopDetails) > 0) {
                $response = $lopDetails;
                $lockedFile = true;
            }else{
                $response = $this->calculateLop($lopMonth, $year, $lopYear, $organizationId, $lopMonthDate);
                $lockedFile = false;
            }

            $organizationDetail = Organization::where('id',$organizationId)->first();

            $path = config('constant.avatar');
            $logo = !empty($organizationDetail->organization_logo) ? public_path('storage/'.$path.'/'.$organizationDetail->organization_logo) : '';      
            $type = pathinfo($logo, PATHINFO_EXTENSION);
    
            $companyName = $organizationDetail?->organization_billing?->billing_name;

            $html = view('pdf.lop-report.export', ['details' => $response, 'month' => $monthName, 'year' => $year, 'logo' => $logo, 'type' =>  $type, 'company_name' => $companyName, 'locked_file' => $lockedFile])->render();

            $pdf = PDF::setOptions(['isHtml5ParserEnabled' => true, 'isRemoteEnabled' => true])->loadHTML($html)->setPaper('a4', 'portrait')->setWarnings(false);

            $pdf->getDomPDF()->setHttpContext(
                stream_context_create([
                    'ssl' => [
                        'allow_self_signed' => TRUE,
                        'verify_peer' => FALSE,
                        'verify_peer_name' => FALSE,
                    ]
                ])
            );

            return $pdf->download('lop-report.pdf');

        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while lop report detail";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function calculateLop($lopMonth, $lopYear, $year, $organizationId, $lopMonthDate, $employee=null, $leaveType=null) {

        $allowLeaveDuringProbation = Setting::join('organization_settings', 'settings.id', 'organization_settings.setting_id')->where('key', 'allow_leave_during_probation_period')->where('organization_id', $organizationId)->first('organization_settings.value');
        $allowLeaveDuringProbation = $allowLeaveDuringProbation->value;

        $lopDuringNoticePeriod = Setting::join('organization_settings', 'settings.id', 'organization_settings.setting_id')->where('key', 'lop_during_notice_period')->where('organization_id', $organizationId)->first('organization_settings.value');
        $lopDuringNoticePeriod = $lopDuringNoticePeriod->value;

        $latestRecords = DB::table('leave_balance_history')
            ->selectRaw("max(id) max_id,employee_id,organization_id")
            ->where(DB::raw('DATE(`leave_balance_history` . `created_at`)'), '<=', $lopMonthDate)
            ->groupBy('leave_type_id', 'employee_id');

        $query = Employee::withoutGlobalScopes([OrganizationScope::class])
            ->leftJoinSub($latestRecords, 'leave_balance_history_latest', function ($join) use ($organizationId) {
                $join->on('employees.id', '=', 'leave_balance_history_latest.employee_id');
                $join->where('leave_balance_history_latest.organization_id', $organizationId);
            })
            ->leftJoin('leave_balance_history', 'leave_balance_history_latest.max_id', 'leave_balance_history.id')
            ->leftJoin('leave_types', function ($join) use ($organizationId) {
                $join->on('leave_types.id', 'leave_balance_history.leave_type_id');
                $join->where('leave_types.organization_id', $organizationId);
            })
            ->where(function($q) use ($lopMonth){
                $q->whereNull('reliving_date');
                $q->orWhereMonth('reliving_date', $lopMonth);
            });

            if(!empty($employee)){
                $query->where('employees.id', $employee);
            }

            if(!empty($leaveType)){
                $query->where('leave_types.id', $leaveType);
            }

            $leaveBalance =  $query->where('employees.organization_id', $organizationId)
            ->select('leave_types.name', 'employees.id as employee_id', 'leave_balance_history.total_balance as balance', 'leave_balance_history.leave_type_id', 'employees.display_name', 'employees.probation_period_end_date', 'employees.resign_date', 'employees.join_date', 'accrual_period', 'accrual_month', 'accrual_date')
            ->groupBy('leave_balance_history.leave_type_id')
            ->groupBy('leave_balance_history.employee_id')->get();

        foreach ($leaveBalance as $entry) {
            $lop = 0;
            $lopYear = $year;
            $startDate = date($lopYear . '-' . $lopMonth . '-1');
            $endDate = $lopMonthDate;
            $accrualPeriod = $entry->accrual_period;
            $accrualDate = $entry->accrual_date;
            $accrualMonth = $entry->accrual_month;
            $refillDate = date($lopYear . '-' . $lopMonth . '-1');

            $lastDay = config('constant.last_day');
            $periodConfig = config('constant.job_schedule_period');
            $current = '';
            $employeement = '';
            if ($accrualPeriod == $periodConfig['Yearly']) {
                //Get the last refill date for calculate leave
                if($accrualMonth > $lopMonth){
                    $lopYear = Carbon::parse($lopYear)->subYear()->format('Y');
                }
                    
                if ($accrualDate == $lastDay) {
                    $accrualDate = Carbon::parse(date($lopYear . '-' . $lopMonth . '-t'))->endOfMonth()->format('Y-m-d');
                } else {
                    $accrualDate = Carbon::parse(date($lopYear . '-' . $lopMonth . '-' . $accrualDate))->format('Y-m-d');
                }

                $current = $lopYear;
                $employeement = ceil(date('Y', strtotime($entry->probation_period_end_date)));
            }

            if ($accrualPeriod == $periodConfig['Half yearly']) {
                $monthList = config('constant.half_year_month_list');
                $accrualMonth = $monthList[$entry->accrual_month];
               
                if ($accrualDate == $lastDay) {
                    $accrualDate = Carbon::parse(date($lopYear . '-' . $lopMonth . '-t'))->endOfMonth()->format('Y-m-d');
                }
                if ($lopMonth >= $accrualMonth[0] && $lopMonth < $accrualMonth[1]) {
                    $month = $accrualMonth[0];
                  
                } else if ($lopMonth < $accrualMonth[0]) {
                    $month = $accrualMonth[1];
                   
                } else if ($lopMonth >= $accrualMonth[1]) {
                    $month = $accrualMonth[1];
                    
                }
                //Get the last refill date for calculate leave
                if($month > $lopMonth){
                    $lopYear = Carbon::parse($lopYear)->subYear()->format('Y');
                }

                $accrualDate = Carbon::parse(date($lopYear . '-' . $month . '-' . $accrualDate))->format('Y-m-d');

                $current = ceil($lopMonth / 6);
                $employeement = ceil(date('m', strtotime($entry->probation_period_end_date)) / 6);
            }

            if ($accrualPeriod == $periodConfig['Quarterly']) {

                $monthList = config('constant.quartarly_month_list');
                $accrualMonth = $monthList[$entry->accrual_month];
                if ($accrualDate == $lastDay) {
                    $accrualDate = Carbon::parse(date($lopYear . '-' . $lopMonth . '-t'))->endOfMonth()->format('Y-m-d');
                }
                if ($lopMonth >= $accrualMonth[0] && $lopMonth < $accrualMonth[1]) {
                    $month = $accrualMonth[0];
                }

                if ($lopMonth >= $accrualMonth[1] && $lopMonth < $accrualMonth[2]) {
                    $month = $accrualMonth[1];
                }

                if ($lopMonth >= $accrualMonth[2] && $lopMonth < $accrualMonth[3]) {
                    $month = $accrualMonth[2];
                }

                if ($lopMonth < $accrualMonth[0] || $lopMonth >= $accrualMonth[3]) {
                    $month = $accrualMonth[3];
                }
                //Get the last refill date for calculate leave
                if($month > $lopMonth){
                    $lopYear = Carbon::parse($lopYear)->subYear()->format('Y');
                }

                $accrualDate = Carbon::parse(date($lopYear . '-' . $month . '-' . $accrualDate))->format('Y-m-d');

                $current = ceil($lopMonth / 3);
                $employeement = ceil(date('m', strtotime($entry->probation_period_end_date)) / 3);
            }

            if ($accrualPeriod == $periodConfig['Monthly']) {
                if ($accrualDate == $lastDay) {
                    $accrualDate = Carbon::parse(date($lopYear . '-' . $lopMonth . '-t'))->endOfMonth()->format('Y-m-d');
                }

                $accrualDate = Carbon::parse(date($lopYear . '-' . $lopMonth . '-' . $accrualDate))->format('Y-m-d');

                $current = $lopMonth;
                $employeement = ceil(date('m', strtotime($entry->probation_period_end_date)));
            }
            //$refillDate = $accrualDate;

            if ($accrualDate < $startDate) {
                $refillDate = $accrualDate;
            }

            //Current LOP month total leave
            $total = Leave::leftJoin('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leaves.leave_status_id', LeaveStatus::APPROVE)
                ->whereBetween('leave_date', [$startDate, $endDate])
                ->where('employee_id', $entry->employee_id)->where('leave_type_id', $entry->leave_type_id)->select(DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->SUM('total_days');

            if ($entry->probation_period_end_date >= $endDate) {
                $lop += $total;
            } else {
                $probationLeave = 0;
                if (!$allowLeaveDuringProbation) {
                    if(($current == $employeement) && date('Y', strtotime($entry->probation_period_end_date)) == $lopYear){
                        $probationEndDate = $entry->probation_period_end_date;
                        $refillDate = $probationEndDate;
                        $probationLeave = Leave::leftJoin('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leaves.leave_status_id', LeaveStatus::APPROVE)
                            ->whereBetween('leave_date', [$startDate, $probationEndDate])
                            ->where('employee_id', $entry->employee_id)->where('leave_type_id', $entry->leave_type_id)
                            ->select(DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->SUM('total_days');

                         $startDate = $entry->probation_period_end_date;
                    }
                }
                $noticePeriodLeave = 0;

                if (!empty($entry->resign_date)) {
                    if ($lopDuringNoticePeriod) {
                        if ($startDate < $entry->resign_date) {
                            $startDate = $entry->resign_date;
                        }

                        $noticePeriodLeave = Leave::leftJoin('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leaves.leave_status_id', LeaveStatus::APPROVE)
                            ->whereBetween('leave_date', [$startDate, $endDate])
                            ->where('employee_id', $entry->employee_id)->where('leave_type_id', $entry->leave_type_id)
                            ->select(DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->SUM('total_days');
                    }
                }

                //Last refill date to current LOP month total leave
                $restLeaves = Leave::leftJoin('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leaves.leave_status_id', LeaveStatus::APPROVE)
                    ->whereBetween('leave_date', [$refillDate, $endDate])
                    ->where('employee_id', $entry->employee_id)->where('leave_type_id', $entry->leave_type_id)->select(DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->SUM('total_days');

                if ($entry->balance >= $restLeaves && $probationLeave == 0 && $noticePeriodLeave == 0) {
                    $lop += 0;

                } elseif ($probationLeave > 0) { // Case for half month probation and half month credit apply for new joinee
                    $lop += $probationLeave;

                    if (!empty($restLeaves)) {
                        if ($restLeaves - $entry->balance < $total) {
                            $lop += $restLeaves - $entry->balance;

                        } else {
                            $lop += $total;
                        }
                    }

                } elseif ($noticePeriodLeave > 0) {
                    $lop += $noticePeriodLeave;
                    if (!empty($restLeaves) && $noticePeriodLeave < $total) {
                        if (($restLeaves - $entry->balance) > 0 && ($restLeaves - $entry->balance < $total)) {
                            $lop += $restLeaves - $entry->balance;
                        } else {
                            $lop += $total;
                        }
                    }
                } else {
                    if ($restLeaves - $entry->balance < $total) {
                        $lop += $restLeaves - $entry->balance;
                    } else {
                        $lop += $total;
                    }
                }
            }

            $response[$entry->employee_id]['employee_name'] = $entry->display_name;
            $response[$entry->employee_id]['employee_id'] = $entry->employee_id;
            $previous = $response[$entry->employee_id]['lop'] ?? 0;
            $response[$entry->employee_id]['lop'] = $previous + $lop;
        }

        $response = array_values($response);

        return $response;
    }

    public function getPendingAutoLeaves(Request $request)
    {
        try {

            $fromDate = $request->from_date;
            $toDate = $request->to_date;
            $perPage = $request->perPage ?? 10;
            $organizationId = $this->getCurrentOrganizationId();

            $query = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_details', 'leaves.id', 'leave_details.leave_id')
                ->join('employees', function ($join) use ($organizationId) {
                    $join->on('leaves.employee_id', 'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })->leftJoin('day_durations', 'leave_details.day_duration_id', 'day_durations.id')
                ->select('leaves.uuid', 'leave_details.leave_date', 'day_durations.duration', 'employees.display_name')
                ->where('leaves.system_leave', Leave::PENDINGSYSTEMLEAVE)
                ->where('leaves.organization_id', $organizationId)
                ->whereNull('leaves.deleted_at')
                ->where('leaves.leave_status_id', LeaveStatus::APPROVE)
                ->whereBetween(DB::raw('DATE(leave_details.leave_date)'), [$fromDate, $toDate])
                ->groupBy('leaves.id')
                ->orderBy('leaves.created_at', 'desc');


            $countQuery = clone $query;
            $count = $countQuery->get();
            $count = count($count);

            $pendingResponse = $query->simplePaginate($perPage);

            $response = ['total_count' => $count, 'pending_response' => $pendingResponse];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get pending auto leaves";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getNoAcknowledgeAutoLeaves(Request $request)
    {
        try {

            $fromDate = $request->from_date;
            $toDate = $request->to_date;
            $perPage = $request->perPage ?? 10;
            $organizationId = $this->getCurrentOrganizationId();

            $query = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_details', 'leaves.id', 'leave_details.leave_id')
                ->join('employees', function ($join) use ($organizationId) {
                    $join->on('leaves.employee_id', 'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })->leftJoin('day_durations', 'leave_details.day_duration_id', 'day_durations.id')
                ->select('leaves.uuid', 'leave_details.leave_date', 'day_durations.duration', 'employees.display_name')
                ->where('leaves.system_leave', Leave::SYSTEMLEAVEWITHNO)
                ->where('leaves.organization_id', $organizationId)
                ->where('leaves.leave_status_id', LeaveStatus::APPROVE)
                ->whereNull('leaves.deleted_at')
                ->whereBetween(DB::raw('DATE(leaves.created_at)'), [$fromDate, $toDate])
                ->groupBy('leaves.id')
                ->orderBy('leaves.created_at', 'desc');

            $countQuery = clone $query;
            $count = $countQuery->get();
            $count = count($count);

            $noResponse = $query->simplePaginate($perPage);

            $response = ['total_count' => $count, 'no_response' => $noResponse];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get auto leaves with no acknowledgement";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function updateSystemLeaveStatus(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();

            $leaveId = $inputs['id'];
            $status = $inputs['status'];

            $leave = Leave::where('uuid', $leaveId)->first(['employee_id', 'from_date', 'to_date']);

            if (!empty($status) && $status == 'yes') {

                Leave::where('uuid', $leaveId)->update(['system_leave' => Leave::SYSTEMLEAVEWITHYES, 'action_date' => getDateTime()]);
               // LeaveDetail::where('leave_id', $leave->id)->update(['system_leave' => Leave::SYSTEMLEAVEWITHYES]);
            }

            if (!empty($status) && $status == 'no') {

                Leave::where('uuid', $leaveId)->update(['system_leave' => Leave::SYSTEMLEAVEWITHNO, 'action_date' => getDateTime()]);
               // LeaveDetail::where('leave_id', $leave->id)->update(['system_leave' => Leave::SYSTEMLEAVEWITHNO]);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.auto_leave_update_success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update system leave status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getLopReports(Request $request) {
        try {
            
            $inputs = $request->all();

            $year = $inputs['year'] ? $inputs['year'] : date('Y');
            $organizationId = $this->getCurrentOrganizationId();

            $lopReports = LopDetail::where('organization_id', $organizationId)->where('year', $year)->orderBy('id','desc')->groupBy('month')->select('month', 'year',DB::raw('"locked" as status'))->get();

            if(!empty($lopReports) && count($lopReports) > 0){
                // if($lopReports[0]->month != date('n') && $lopReports[0]->year == date('Y')) {
                //     $lopReports->prepend(['month' => date('n'), 'year' => date('Y'), 'status' => '' ]);
                // }

                $monthArray = clone $lopReports;
                $months = $monthArray->pluck('year', 'month')->toArray();
    
                $monthList = $year == date('Y') ? date('m') - 1 : 12;
                for($i = $monthList; $i >= 1; $i--){
                    if(empty($months[$i]) || (!empty($months[$i]) && $months[$i] != $year)) {
                       $lopReports =  $lopReports->push(collect(['month' => $i, 'year' => $year, 'status' => '' ]));
                    }
                }
            }else{

                $monthList = $year == date('Y') ? date('m')-1 : 12;
                
                for($i = $monthList; $i >= 1; $i--){
                   
                       $lopReports =  $lopReports->push(collect(['month' => $i, 'year' => $year, 'status' => '' ]));
                    
                }
            }

            return $this->sendSuccessResponse(__('messages.success'), 200, $lopReports);
        } catch (\Throwable $ex) {
    
            $logMessage = "Something went wrong while fetch lop records";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    function getLeaveBalanceDetail(Request $request) {
        try {
            $leaveType = $request->leave_type;
            
            if (!empty($leaveType)) {

                $leaveType = $request->leave_type;
                $employeeId = !empty($request->employee) ? $request->employee : $request->user()->entity_id;
                $organizationId = $this->getCurrentOrganizationId();
                $year = $request->year;
                $leaveType = LeaveType::where('uuid', $leaveType)->first(['id', 'accrual_period', 'accrual', 'accrual_date', 'accrual_month']);
              
                if (!empty($leaveType->accrual)) {
                    // Get last refill period for minus total leave of previous refill period from leave balance and add refill balance after it

                    $accrualPeriod = $leaveType->accrual_period;
                    $accrualDate = $leaveType->accrual_date;
                    $accrualMonth = $leaveType->accrual_month;
                    $date = date('j');
                    $month = date('n');
                    $lastDay = config('constant.last_day');
                    $lastAvailable = 0;
                    $periodConfig = config('constant.job_schedule_period');
                    if ($accrualPeriod == $periodConfig['Yearly']) {

                        if ($accrualDate == $lastDay) {
                            $accrualDate = Carbon::parse(date('Y-' . $accrualMonth . '-t'))->endOfMonth()->format('d');
                        }

                        $leaveBalance = LeaveBalanceHistory::where('organization_id', $organizationId)->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereYear('created_at', $year)->where('action_type', 'accural')->whereMonth('created_at', '>=', $accrualMonth)->select('id', 'employee_id', 'leave_type_id', 'balance', 'action_type', 'total_balance', 'created_at')->first();

                        $availableChange = 0;
                        $balance = 0;
                        $totalBooked = 0;
                        $manualChange = 0;
                        $refill = 0;
                        $cf = 0;
                        $nextYear = Carbon::parse(date('Y'))->addYear()->format('Y');
                        if (!empty($leaveBalance)) {
                            $manualChange = LeaveBalanceHistory::where('organization_id', $organizationId)->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereYear('created_at', $year)->where('action_type', 'manual correction')->whereMonth('created_at', '>=', $accrualMonth)->select('employee_id', 'leave_type_id', 'balance', 'action_type', 'total_balance', 'created_at')->orderBy('id', 'desc')->first();
                            $refill = $leaveBalance->balance;
                            $from = Carbon::parse(date('Y-' . $accrualMonth . '-' . $accrualDate))->format('Y-m-d');
                            $to = Carbon::parse(date($nextYear . '-' . $accrualMonth . '-' . $accrualDate))->subDay()->format('Y-m-d');

                            $totalBooked = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereBetween('leave_date', [$from, $to])->where('leaves.leave_status_id', LeaveStatus::APPROVE)->select('employee_id', 'leave_type_id', 'leave_date', DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->sum('total_days');

                            $availableChange = !empty($manualChange->total_balance) ? $manualChange->total_balance : $leaveBalance->total_balance;
                            $balance = $leaveBalance->total_balance;
                            $manualChange = !empty($manualChange->total_balance) ? $manualChange->total_balance : 0;

                            $availableChange = $availableChange - $totalBooked > 0 ? $availableChange - $totalBooked : 0;
                            $lastAvailable = $availableChange;

                            //Check leaves apply and approve before new quarter start
                            $totalBookedBefore = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereBetween('leave_date', [$from, $to])->where('leaves.leave_status_id', LeaveStatus::APPROVE)->whereDate('action_date', '<=', $from)->select('employee_id', 'leave_type_id', 'leave_date', DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->sum('total_days');

                            $cf = $balance - $leaveBalance->balance > 0 ? ($balance - $totalBookedBefore) - $leaveBalance->balance : 0;
                        }

                        $lop = 0;
                        $currentMonth = $accrualMonth;
                        while ($currentMonth <= $accrualMonth) {

                            $lopMonthDate = \Carbon\Carbon::parse(date($year . '-' . $currentMonth . '-1'))->endOfMonth()->toDateString();

                            $lopDetail = $this->calculateLop($currentMonth, $year, $year, $organizationId, $lopMonthDate, $employeeId, $leaveType->id);

                            $lop += $lopDetail[0]['lop'];

                            $currentMonth = $currentMonth + 1;
                        }

                        $info[] = ['carry_farward' => $cf, 'refill' => $refill, 'booked' => $totalBooked, 'lop' => $lop, 'manual_change' => $manualChange, 'available' => $availableChange];
                    }

                    if ($accrualPeriod == $periodConfig['Half yearly']) {
                        $monthList = config('constant.half_year_month_list');
                        $accrualMonth = $monthList[$leaveType->accrual_month];

                        foreach ($accrualMonth as $key => $currentHalfYear) {

                            if ($accrualDate == $lastDay) {
                                $accrualDate = Carbon::parse(date('Y-' . $currentHalfYear . '-t'))->endOfMonth()->format('d');
                            }
                            $halfMonth = $currentHalfYear;
                            $nextMonth = (!empty($accrualMonth[$key + 1])) ? $accrualMonth[$key + 1] : $accrualMonth[0];
                            $leaveBalance = LeaveBalanceHistory::where('organization_id', $organizationId)->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereYear('created_at', $year)->where('action_type', 'accural')->whereMonth('created_at', '>=', $halfMonth)->whereMonth('created_at', '<', $nextMonth)->select('id', 'employee_id', 'leave_type_id', 'balance', 'action_type', 'total_balance', 'created_at')->first();

                            $availableChange = 0;
                            $balance = 0;
                            $totalBooked = 0;
                            $refill = 0;
                            $manualChange = 0;
                            $cf = 0;
                            if (!empty($leaveBalance)) {
                                $manualChange = LeaveBalanceHistory::where('organization_id', $organizationId)->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereYear('created_at', $year)->where('action_type', 'manual correction')->whereMonth('created_at', '>=', $halfMonth)->whereMonth('created_at', '<', $nextMonth)->select('employee_id', 'leave_type_id', 'balance', 'action_type', 'total_balance', 'created_at')->orderBy('id', 'desc')->first();
                                $refill = $leaveBalance->balance;
                                $from = Carbon::parse(date('Y-' . $halfMonth . '-' . $accrualDate))->format('Y-m-d');
                                $to = Carbon::parse(date('Y-' . $nextMonth . '-' . $accrualDate))->subDay()->format('Y-m-d');

                                $totalBooked = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereBetween('leave_date', [$from, $to])->where('leaves.leave_status_id', LeaveStatus::APPROVE)->select('employee_id', 'leave_type_id', 'leave_date', DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->sum('total_days');

                                $availableChange = !empty($manualChange->total_balance) ? $manualChange->total_balance : $leaveBalance->total_balance;
                                $balance = $leaveBalance->total_balance;
                                $manualChange = !empty($manualChange->total_balance) ? $manualChange->total_balance : 0;

                                $availableChange = $availableChange - $totalBooked > 0 ? $availableChange - $totalBooked : 0;
                                $lastAvailable = $availableChange;

                                //Check leaves apply and approve before new quarter start
                                $totalBookedBefore = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereBetween('leave_date', [$from, $to])->where('leaves.leave_status_id', LeaveStatus::APPROVE)->whereDate('action_date', '<=', $from)->select('employee_id', 'leave_type_id', 'leave_date', DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->sum('total_days');

                                $cf = $balance - $leaveBalance->balance > 0 ? ($balance - $totalBookedBefore) - $leaveBalance->balance : 0;
                            }


                            $lop = 0;
                            while ($halfMonth <= $nextMonth) {

                                $lopMonthDate = \Carbon\Carbon::parse(date($year . '-' . $halfMonth . '-1'))->endOfMonth()->toDateString();

                                $lopDetail = $this->calculateLop($halfMonth, $year, $year, $organizationId, $lopMonthDate, $employeeId, $leaveType->id);

                                $lop += $lopDetail[0]['lop'];

                                $halfMonth = $halfMonth + 1;
                            }

                            $info[] = ['carry_farward' => $cf, 'refill' => $refill, 'booked' => $totalBooked, 'lop' => $lop, 'manual_change' => $manualChange, 'available' => $availableChange];
                        }
                    }

                    if ($accrualPeriod == $periodConfig['Quarterly']) {
                        $monthList = config('constant.quartarly_month_list');
                        $accrualMonth = $monthList[$leaveType->accrual_month];

                        foreach ($accrualMonth as $key => $currentQuater) {
                            if ($accrualDate == $lastDay) {
                                $accrualDate = Carbon::parse(date('Y-' . $currentQuater . '-t'))->endOfMonth()->format('d');
                            }
                            $quarterMonth = $currentQuater;
                            $nextMonth = (!empty($accrualMonth[$key + 1])) ? $accrualMonth[$key + 1] : $accrualMonth[0];
                            $leaveBalance = LeaveBalanceHistory::where('organization_id', $organizationId)->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereYear('created_at', $year)->where('action_type', 'accural')->whereMonth('created_at', '>=', $quarterMonth)->whereMonth('created_at', '<', $nextMonth)->select('id', 'employee_id', 'leave_type_id', 'balance', 'action_type', 'total_balance', 'created_at')->first();

                            $availableChange = 0;
                            $balance = 0;
                            $totalBooked = 0;
                            $refill = 0;
                            $manualChange = 0;
                            $cf = 0;
                            if (!empty($leaveBalance)) {
                                $manualChange = LeaveBalanceHistory::where('organization_id', $organizationId)->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereYear('created_at', $year)->where('action_type', 'manual correction')->whereMonth('created_at', '>=', $quarterMonth)->whereMonth('created_at', '<', $nextMonth)->select('employee_id', 'leave_type_id', 'balance', 'action_type', 'total_balance', 'created_at')->orderBy('id', 'desc')->first();
                                $refill = $leaveBalance->balance;
                                $from = Carbon::parse(date('Y-' . $quarterMonth . '-' . $accrualDate))->format('Y-m-d');
                                $to = Carbon::parse(date('Y-' . $nextMonth . '-' . $accrualDate))->subDay()->format('Y-m-d');

                                $totalBooked = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereBetween('leave_date', [$from, $to])->where('leaves.leave_status_id', LeaveStatus::APPROVE)->select('employee_id', 'leave_type_id', 'leave_date', DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->sum('total_days');

                                $availableChange = !empty($manualChange->total_balance) ? $manualChange->total_balance : $leaveBalance->total_balance;
                                $balance = $leaveBalance->total_balance;
                                $manualChange = !empty($manualChange->total_balance) ? $manualChange->total_balance : 0;

                                $availableChange = $availableChange - $totalBooked > 0 ? $availableChange - $totalBooked : 0;
                                $lastAvailable = $availableChange;

                                //Check leaves apply and approve before new quarter start
                                $totalBookedBefore = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereBetween('leave_date', [$from, $to])->where('leaves.leave_status_id', LeaveStatus::APPROVE)->whereDate('action_date', '<=', $from)->select('employee_id', 'leave_type_id', 'leave_date', DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->sum('total_days');

                                $cf = $balance - $leaveBalance->balance > 0 ? ($balance) - $leaveBalance->balance : 0;
                            }


                            $lop = 0;
                            while ($currentQuater <= $nextMonth) {

                                $lopMonthDate = \Carbon\Carbon::parse(date($year . '-' . $currentQuater . '-1'))->endOfMonth()->toDateString();

                                $lopDetail = $this->calculateLop($currentQuater, $year, $year, $organizationId, $lopMonthDate, $employeeId, $leaveType->id);

                                $lop += $lopDetail[0]['lop'];

                                $currentQuater = $currentQuater + 1;
                            }

                            $info[] = ['carry_farward' => $cf, 'refill' => $refill, 'booked' => $totalBooked, 'lop' => $lop, 'manual_change' => $manualChange, 'available' => $availableChange];
                        }


                    }

                    if ($accrualPeriod == $periodConfig['Monthly']) {
                        if ($accrualDate == $lastDay) {
                            $accrualDate = Carbon::parse(date('Y-' . $month . '-t'))->endOfMonth()->format('d');
                        }

                        for ($i = 1; $i <= 12; $i++) {

                            $month = $i;
                            $nextMonth = $i + 1;

                            $leaveBalance = LeaveBalanceHistory::where('organization_id', $organizationId)->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereYear('created_at', $year)->where('action_type', 'accural')->whereMonth('created_at', '>=', $month)->whereMonth('created_at', '<', $nextMonth)->select('id', 'employee_id', 'leave_type_id', 'balance', 'action_type', 'total_balance', 'created_at')->first();

                            $availableChange = 0;
                            $balance = 0;
                            $totalBooked = 0;
                            $manualChange = 0;
                            $refill = 0;
                            $cf = 0;
                            if (!empty($leaveBalance)) {
                                $manualChange = LeaveBalanceHistory::where('organization_id', $organizationId)->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereYear('created_at', $year)->where('action_type', 'manual correction')->whereMonth('created_at', '>=', $month)->whereMonth('created_at', '<', $nextMonth)->select('employee_id', 'leave_type_id', 'balance', 'action_type', 'total_balance', 'created_at')->orderBy('id', 'desc')->first();
                                $refill = $leaveBalance->balance;
                                $from = Carbon::parse(date('Y-' . $month . '-' . $accrualDate))->format('Y-m-d');
                                $to = Carbon::parse(date('Y-' . $nextMonth . '-' . $accrualDate))->subDay()->format('Y-m-d');

                                $totalBooked = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereBetween('leave_date', [$from, $to])->where('leaves.leave_status_id', LeaveStatus::APPROVE)->select('employee_id', 'leave_type_id', 'leave_date', DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->sum('total_days');

                                $availableChange = !empty($manualChange->total_balance) ? $manualChange->total_balance : $leaveBalance->total_balance;
                                $balance = $leaveBalance->total_balance;
                                $manualChange = !empty($manualChange->total_balance) ? $manualChange->total_balance : '';

                                $availableChange = $availableChange - $totalBooked > 0 ? $availableChange - $totalBooked : 0;
                                $lastAvailable = $availableChange;

                                //Check leaves apply and approve before new quarter start
                                $totalBookedBefore = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('leave_type_id', $leaveType->id)->where('employee_id', $employeeId)->whereBetween('leave_date', [$from, $to])->where('leaves.leave_status_id', LeaveStatus::APPROVE)->whereDate('action_date', '<=', $from)->select('employee_id', 'leave_type_id', 'leave_date', DB::raw('(CASE WHEN day_duration_id = "1" THEN "1" ELSE "0.5" END) total_days'))->get()->sum('total_days');

                                $cf = $balance - $leaveBalance->balance > 0 ? ($balance - $totalBookedBefore) - $leaveBalance->balance : 0;
                            }

                            $lopMonthDate = \Carbon\Carbon::parse(date($year . '-' . $month . '-1'))->endOfMonth()->toDateString();

                            $lopDetail = $this->calculateLop($month, $year, $year, $organizationId, $lopMonthDate, $employeeId, $leaveType->id);

                            $lop = $lopDetail[0]['lop'];

                            $info[] = ['carry_farward' => $cf, 'refill' => $refill, 'booked' => $totalBooked, 'lop' => $lop, 'manual_change' => $manualChange, 'available' => $availableChange];
                        }
                    }
                }
            }

            $total[] = ['refillTotal' => array_sum(array_column($info, 'refill')), 'bookedTotal' => array_sum(array_column($info, 'booked')), 'lopTotal' => array_sum(array_column($info, 'lop')), 'available' => $lastAvailable];

            $response = ['data' => $info, 'total' => $total, 'refill_cycle' => $accrualPeriod];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {

            $logMessage = "Something went wrong while fetch leave type balance detail";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}