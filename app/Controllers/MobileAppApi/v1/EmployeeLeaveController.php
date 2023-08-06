<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use App\Models\Leave;
use App\Models\LeaveDeatil;
use App\Models\LeaveType;
use Illuminate\Http\Request;
use App\Validators\LeaveValidator;
use Log, Lang, DB, Auth;
use Carbon\Carbon;
use App\Models\Employee;
use App\Models\User;
use App\Mail\AddLeave;
use App\Mail\ApplyLeave;
use App\Jobs\SendEmailJob;
use App\Models\CompensatoryOff;
use App\Models\CompensatoryOffStatus;
use App\Models\CompOff;
use App\Models\DayDuration;
use App\Models\LeaveCompensatoryOff;
use App\Models\LeaveDetail;
use App\Models\LeaveStatus;
use App\Models\LeaveTypeType;
use App\Models\OrganizationSetting;
use App\Models\Scopes\OrganizationScope;
use App\Models\Setting;

class EmployeeLeaveController extends Controller
{
    use ResponseTrait;
    private $leaveValidator;
  
    function __construct()
    {
        $this->leaveValidator = new LeaveValidator();
    }

    /**
     * Get All Leave Type
     *
     * @return void
     */
    public function getHolidayList(Request $request)
    {
        try {
            $startDate = $request->start_date;
            $endDate = $request->end_date;
            $holidays = $this->getHoliday($startDate,  $endDate);
            return $this->sendSuccessResponse(__('messages.success'), 200, $holidays);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get holidays list";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    public function getLeaveType() {
        try{
            $leaveTypes = LeaveType::select('uuid', 'leave_types.name','leave_type_type_id', 'code', 'no_of_leaves', 'leaveType.name as leave_type_name', 'is_default', 'is_primary', DB::raw('group_concat(leave_durations.duration_id) as durations'))->leftJoin('leave_type_allowed_durations as leave_durations', 'leave_types.id', 'leave_durations.leave_type_id')->leftJoin('leave_type_types as leaveType', 'leave_types.leave_type_type_id', 'leaveType.id')->groupBy('leave_types.id')->orderBy('leave_types.name')->get();
            return $this->sendSuccessResponse(__('messages.success'),200,$leaveTypes);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while update leave type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    /**
     * Get All Project Manager List.
     *
     * @return \Illuminate\Http\Response
     * 
     */
    public function getProjectManager()
    {
        try{
            $projectManager = Employee::join('users','employees.id', '=', 'users.id')
                            ->join('user_role','users.id', '=', 'user_role.user_id')
                            ->join('roles','user_role.role_id', '=', 'roles.id')
                            ->where('roles.name', '=', 'Project Manager')
                            ->select('employees.id','employees.employee_id','users.email','employees.display_name')->get();

            return $this->sendSuccessResponse(Lang::get('messages.leave.project_manager'),200,$projectManager);
        } catch (\Exception $e) {           
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    /**
     * Get Employee Leave  BY  Employee ID, Month, Year
     *
     * @param Request $request
     * @return void
     */
    public function getEmployeeLeave(Request $request) {
        try
        {
            $inputs = $request->all();
            $user = Auth::user();
            $employeeID = $user->entity_id;
            $organizationId = $this->getCurrentOrganizationId();

            $dateFull = new Carbon();

            $year =  isset($inputs['year']) && $inputs['year'] != null ? $inputs['year'] : $dateFull->year;
            $month = isset($inputs['month']) && $inputs['month'] != null ? $inputs['month'] : null;
            
            $status = isset($inputs['status']) && $inputs['status'] != null ? $inputs['status'] : 0;
          
            $data = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_statuses', 'leaves.leave_status_id','leave_statuses.id')
            ->join('leave_types', 'leaves.leave_type_id','leave_types.id')
            ->join('employees', function ($join) use($organizationId) {
                $join->on('leaves.employee_id', '=',  'employees.id');
                $join->where('employees.organization_id', $organizationId);
            })
            ->select('employees.display_name','leaves.id','leaves.from_date',DB::raw('Date(leaves.applied_date) as applied_date'), 'leaves.to_date', 'total_working_days', 'leave_statuses.name as leave_status','leave_types.name as leave_type')->where('leaves.employee_id', $employeeID )->where('leaves.organization_id', $organizationId )
            ->orderBy('leaves.created_at','desc');
            
            if ($year) {
                $data = $data->where(DB::raw('YEAR(leaves.applied_date)'), $year);
            }
            if ($month) {
                $data = $data->where(DB::raw('MONTH(leaves.applied_date)'), $month);
            }
            if ($status == 0) {
                $data = $data->whereIn('leave_statuses.id', [LeaveStatus::PENDING, LeaveStatus::APPROVE, LeaveStatus::REJECT,LeaveStatus::CANCEL]);
            }
            else{
               $data = $data->where('leave_statuses.id', $status);
            }
            $data = $data->get();
            return $this->sendSuccessResponse(Lang::get('messages.success'),200,$data);
        }
        catch (\Throwable $ex) {
            $logMessage = "Something went wrong while leave imported";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    /**
     * Store a leave applied by employee
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function applyLeave(Request $request)
    {       
        try {
            DB::beginTransaction();
            $organizationId = $this->getCurrentOrganizationId();
            $inputs = $request->all();
            $user = Auth::user();
            $employeeId = $user->entity_id;
            $currentUserId = !empty($inputs['employee_id']) ? $inputs['employee_id'] : $employeeId;            

            $validation = $this->leaveValidator->validate($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }
            $leaveDays = array_column($inputs['leaveDays'], 'date');
            $selectedDuration = array_column($inputs['leaveDays'], 'selectedDuration');
            $newLeaves = array_combine($leaveDays, $selectedDuration);

            $leaveExist = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->whereBetween('leave_date', [$inputs['start_date'], $inputs['end_date']])->where('employee_id', $employeeId)->whereIn('leaves.leave_status_id', [LeaveStatus::PENDING, LeaveStatus::APPROVE])->whereNull('leave_details.deleted_at')->select('leaves.id', 'day_duration_id', 'leave_date')->get();

            foreach ($leaveExist as $val) {
                if ($val->day_duration_id == DayDuration::FULLDAY || ($val->day_duration_id == DayDuration::FIRSTHALF && $newLeaves[$val->leave_date] == DayDuration::FIRSTHALF) || ($val->day_duration_id == DayDuration::SECONDHALF && $newLeaves[$val->leave_date] == DayDuration::SECONDHALF) || (in_array($val->day_duration_id, [DayDuration::FIRSTHALF, DayDuration::SECONDHALF]) && $newLeaves[$val->leave_date] == DayDuration::FULLDAY)) {
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

    /**
     * Get employee future leaves
     *
     * 
     */
    public function getEmployeeFutureLeave(){

        try{
            $user = Auth::user();
            $roles = $user->roles;
            $allRoles = collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $organizationId = $this->getCurrentOrganizationId();
            $startDate = Carbon::tomorrow()->format('Y-m-d');
            $endDate = Carbon::now()->addWeek()->format('Y-m-d');
          
            $leaves = $this->getLeaveDetails($startDate, $endDate, $organizationId, $allRoles, $user, true);
            return $this->sendSuccessResponse(__('messages.success'), 200, $leaves);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while fetch upcomming leave data";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }   

    /**
     * Cancel leave by employee
     *
     * @param  $employee_id
     * 
     */
     public function getLeaveDetails($startDate, $endDate, $organizationId, $allRoles, $user, $upcoming = false)
     {
        $query = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_details', 'leaves.id', 'leave_details.leave_id')->join('employees',function($join) use($organizationId){
            $join->on('leaves.employee_id','=','employees.id');
            $join->where('employees.organization_id', $organizationId);
        })->whereBetween('leave_date', [$startDate, $endDate]);
        $leaves = $query->where('leaves.organization_id', $organizationId)
                ->whereIn('leaves.leave_status_id',[LeaveStatus::PENDING, LeaveStatus::APPROVE])
                ->whereNull('leave_details.deleted_at')
                ->select('employees.display_name','avatar_url','day_duration_id','leave_date')
                ->orderBy('leave_date')
                ->get();

        foreach($leaves as $leave){
            if(!empty($leave->avatar_url)){
                $path = config('constant.avatar');
                $leave->avatar = getFullImagePath($path . '/' . $leave->avatar_url);
            }
            if ($leave->day_duration_id == DayDuration::FULLDAY) {
                $leave->day_duration = 'Full Day';
            } else if ($leave->day_duration_id == DayDuration::FIRSTHALF) {
                $leave->day_duration = 'First Half';
            } else{
                $leave->day_duration = 'Second Half';
            }

            if($upcoming == true){
                if($leave->leave_date == $startDate){
                    $leave->leave_day = 'Tomorrow';
                }else{
                    $leave->leave_day =  date('l', strtotime($leave->leave_date));
                }
            }else{
                if($leave->leave_date == $startDate){
                    $leave->leave_day = 'Today';
                }else{
                    $leave->leave_day = 'Tomorrow';
                }
            }
        }
        return $leaves;
    }
    public function cancelEmployeeLeave(Request $request)
    {
        try 
        {
            DB::beginTransaction();
            $data = $request->all();
            $user = Auth::user();
            $cancel_leave = $data['cancel_leaves'];
            $employee_id = $user->entity_id;
            $project_manager = $data['project_manager'];
            $updated = false;
            $cancel_leave_date = [];
            foreach ($cancel_leave as $key => $leave) {
                $leave_id = $leave;
                $leave = Leave::where('id', $leave_id)->first(['leave_date','duration','holiday_type']);
                $leave_date = $leave['leave_date'];
                $cancel_leave_date[] =  date('d-m-Y',strtotime($leave_date));
                if ($leave_date >= date('Y-m-d')) {
                    $data = ['status' => config('constants.cancel_status')];
                    Leave::where('id', $leave_id)->update($data);
                    $updated = true;
                }

                if (!empty($leave['duration']) && $leave['duration'] == Leave::FIRSTHALF) {
                    $duration = Leave::first_half;
                } elseif (!empty($leave['duration']) && $leave['duration'] == Leave::SECONDHALF) {
                    $duration = Leave::second_half;
                } else {
                    $duration = '';
                }
                $leave_data[$key]['leave_date'] = date('d-m-Y',strtotime($leave['leave_date']));
                $leave_data[$key]['holiday_type'] = $leave['holiday_type'] == Leave::FULLLEAVE ? Leave::full_leave : Leave::half_leave;
                $leave_data[$key]['duration'] = $duration;
                $leave_data[$key]['leave_id'] = $leave_id;
                $leaves[] = $leave_id;
            }

            if ($updated == true) {
                $cancel_leave_date = implode(',',$cancel_leave_date);
                $employee = Employee::whereId($employee_id)->first(['display_name']);
                $employee_name = $employee['display_name'];
                $employee_email = User::whereEntityTypeId('3')->whereIn('entity_id',$project_manager)->get()->pluck('email')->toArray();
                $manager_email = $employee_email;
                $base_url = config('app.base_url');
                $app_url = config('app.url'); 
                $leaves = implode(',',$leaves);
                $leaves = base64_encode($leaves);
                $email_data = [ 'type' => 'EmployeeCancelAction', 'cancel_leave_date' => $cancel_leave_date,'employee_name' => $employee_name,'base_url' => $base_url,'app_url' => $app_url,'leave_data' => $leave_data,'leaves' => $leaves, 'email_bcc' => $manager_email];

                // foreach (config('constants.admin_mail') as $recipient) {
                //         $email_data['email'] = $recipient;  
                //         SendEmailJob::dispatch($email_data);
                // }

                $email_data['email'] = config('constants.admin_mail');
                $recipients = collect($email_data['email'] );
                $email_data['email'] = $recipients ;
                SendEmailJob::dispatch($email_data); 
            }

            DB::commit();
            $data = Leave::with('leaveType')->whereEmployeeId($employee_id)->whereDate('leave_date', '>=', date('Y-m-d'))
            ->whereIn('status',[config('constants.pending_status'),config('constants.approve_status'),config('constants.reject_status')])
            ->get();
            return $this->sendSuccessResponse(Lang::get('messages.leave.success-cancel'),200,$data);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Cancel leave by employee Data Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    } 

    /**
     * Get Employee Imported Leave Detail.
     *
     * @return \Illuminate\Http\Response
     * 
     */
    public function getEmpImportedLeave(){
        try{
            $user = Auth::user();
            $employee = Employee::where('id', $user->id)->select('employee_id','join_date','id')->first();
            $uId = $employee['employee_id'];
            $data = LeaveDetail::where('employee_id', $uId)
                    ->first(); 

            if(!empty($data)){
                $employeeData = $this->getEmployeementDetail($employee);

                $start_date = getQuarterStartDate();
                $end_date = getQuarterEndDate();

                $comp_off = CompensatoryOff::where('employee_id',$employee->id)->whereDate('applied_date','>=',$start_date)->whereDate('applied_date','<=',$end_date)->where('status',2)->sum('type');
                $data->comp_off = $comp_off;
                $data->total_credit_leaves = $data->carry_forwarded_leaves + $data->new_quarter_credit + $data->comp_off;

                if($employeeData['probation_period'] == false || (($employeeData['currentQuarter'] == $employeeData['employeementQuarter']) && $employeeData['employeementYear'] == date('Y'))){
                    $probation_leave_used = Leave::where('probation_leave',1)->whereDate('leave_date','>=',$start_date)->whereDate('leave_date','<=',$end_date)->where('employee_id',$employee->id)->whereStatus(config('constants.approve_status'))->sum('holiday_type');
                    $start_date = $employeeData['employeement_date'];
                    $data->probation_period = true;
                    $data->probation_leave = $probation_leave_used;
                }
                $rest_leave_used = Leave::whereDate('leave_date','>=',$start_date)->whereDate('leave_date','<=',$end_date)->where('probation_leave',0)->where('employee_id',$employee->id)->whereStatus(config('constants.approve_status'))->sum('holiday_type');

                $available_leaves = $data->total_credit_leaves - $rest_leave_used > 0 ? $data->total_credit_leaves - $rest_leave_used : 0;
                $data->total_leave_used = $rest_leave_used;
                $data->available_leaves = $available_leaves;
            }

            return $this->sendSuccessResponse(Lang::get('messages.leave.leave_balance_get'),200,$data);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Employee Imported Leave Detail Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    private function getEmployeementDetail($employeeData){
        $join_date = $employeeData['join_date'];
        $now_date = Carbon::now();
        $employeement_date = date('Y-m-d', strtotime("+3 months", strtotime($join_date)));

        $employeementMonth = date('m',strtotime($employeement_date));
        $employeementYear = date('Y',strtotime($employeement_date));

        $probation_period = $now_date->gt($employeement_date);

        $month = date('n');
        $currentQuarter = ceil($month / 3);
        $employeementQuarter = ceil($employeementMonth / 3);

        $data = ['employeement_date' => $employeement_date,'employeementMonth' => $employeementMonth, 'employeementYear' => $employeementYear, 'probation_period' => $probation_period, 'currentQuarter' => $currentQuarter, 'employeementQuarter' => $employeementQuarter];
        return $data;
    }

}
