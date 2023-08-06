<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\TimesheetMissingHrs;
use App\Models\CompensatoryOff;
use App\Models\CompensatoryOffStatus;
use App\Models\DayDuration;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveStatus;
use App\Models\OrganizationSetting;
use App\Models\Project;
use App\Models\ProjectEmployee;
use App\Models\ProjectRole;
use App\Models\Scopes\OrganizationScope;
use App\Models\Task;
use App\Models\TimesheetStatus;
use App\Models\User;
use App\Models\UserTimesheet;
use App\Traits\ResponseTrait;
use App\Validators\UserTimesheetValidator;
use Auth;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;

class UsertimesheetController extends Controller
{

    use ResponseTrait;

    private $userTimesheetValidator;
    private $workingHoursPerDay;

    function __construct()
    {
        $this->userTimesheetValidator = new UserTimesheetValidator();

        $setting = OrganizationSetting::with('setting')->whereHas('setting', function ($subQuery) {
            $subQuery->where("settings.key", "working_hours");
        })->first();

        $this->workingHoursPerDay = $setting->value;
    }

    /* User Filled Hours */
    public function getUserWorkingHours($startDate, $endDate,$employeeId = null)
    {
        $employeeId = $employeeId ?? Auth::user()->entity_id;

        return UserTimesheet::select('id', 'project_id', 'working_hours', 'note', 'date', DB::raw('
            SUM(working_hours) AS working'))
            ->whereEmployeeId($employeeId)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNull('deleted_at')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    //Get percentage for the number
    public function get_percentage($total, $number)
    {

        if ($total > 0) {
            return ($number * 100) / $total;
        } else {
            return 0;
        }
    }

    //Compare color code
    public function compareColorCode($a, $b)
    {
        return strcmp($a['color_order'], $b['color_order']);
    }

    /* User Total Working Hours */
    public function getTotalHours($startDate, $endDate, $organizationId, $userId = 0, $isCurrentUser = 0)
    {
        $holidays = $this->getHoliday($startDate, $endDate, $isCurrentUser);

        $leaveOffDays = $this->getLeaveDays($startDate, $endDate, $organizationId, $userId);
       // $leaveOffDays = count($leaves['fullDay']) + (count($leaves['halfDay']) / 2);

        $noOfDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;

        $totalWorkingDays = $noOfDays - (count($holidays) + $leaveOffDays);

        return $totalWorkingDays * $this->workingHoursPerDay;
    }

    //Get list of holidays
    public function getHoliday($startDate, $endDate, $isCurrentUser = 0)
    {
        $employeeId = Auth::user()->entity_id;
        $employeeJoinDate = Employee::where('id', $employeeId)->select('join_date')->first();
        $query = Holiday::whereBetween('date', [$startDate, $endDate]);
        if ((isset($employeeJoinDate->join_date)) && ($isCurrentUser == 1)) {
            $query = $query->whereDate('date', '>=', $employeeJoinDate->join_date);
        }
        $holiday = $query->select('date')->pluck('date')->toArray();
        $weekends = $this->getWeekendDays($startDate, $endDate);

        if (is_array($weekends)) {
            $holiday = array_unique(array_merge($holiday, $weekends));
        }

        $holiday = array_values($holiday);

        return $holiday;
    }

    //Get user's leave day
    public function getLeaveDays($startDate, $endDate, $organizationId, $userId = 0)
    {

        $employeeId = $userId == 0 ? Auth::user()->entity_id : $userId;

        $leaves = Leave::join('leave_details','leaves.id', 'leave_details.leave_id')
            ->whereBetween('leave_date', [$startDate, $endDate])
            ->where('employee_id', $employeeId)
            ->where('leaves.leave_status_id', LeaveStatus::APPROVE)->whereNull('leave_details.deleted_at')->get(['leave_details.leave_date','leave_details.day_duration_id']);
  
        $totalLeaveDays = 0;
        foreach($leaves as $leave){
            $totalLeaveDays += $leave->day_duration_id == DayDuration::FULLDAY ? 1 : 0.5;
        }
        

        return $totalLeaveDays;
    }

      //Get user's Compoff days
      public function getCompOffDays($startDate, $endDate, $userId = 0)
      {
  
          $employeeId = $userId == 0 ? Auth::user()->entity_id : $userId;
   
          $compOffs = CompensatoryOff::whereBetween('comp_off_date', [$startDate, $endDate])
          ->where('employee_id', $employeeId)
          ->where('compensatory_off_status_id', CompensatoryOffStatus::APPROVE)->get(['comp_off_date','day_duration_id']);
  
          return $compOffs;
      }


    //Convert decimal to time format
    public function decimalToHoursMin($totalHours)
    {
        $hours = intval($totalHours);
        $minutes = round($totalHours - $hours, 2);
        return array('hours' => $hours, 'minutes' => round($minutes * 60));
    }

    //Get user timesheet dashboard
    public function timesheetDashboard(Request $request)
    {
        DB::beginTransaction();
        try {
            $user = $request->user();
            $roles = $user->roles;
            $allRoles =  collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();
            $permissions = $user->getAllPermissions()->pluck('name')->toArray();
            $organizationId = $this->getCurrentOrganizationId();

            $responseData = [];
            //Admin dashboard
            if (in_array('create_manage_timesheet', $permissions)) {

                $department = [];

                $lastMonthDay = new Carbon('first day of last month');
                $lastMonthStartDate = $lastMonthDay->toDateString();
                $lastMonthEndDay = new Carbon('last day of last month');
                $lastMonthEndDate = $lastMonthEndDay->toDateString();
                //Last month missed hours
                $lastMonthMissedHours  = $this->getMissedHoursEmployeeData($lastMonthStartDate, $lastMonthEndDate, $department,false, false,$allRoles);
                
                // $previousWeek = strtotime("-1 week +1 day");
                // $startWeek = strtotime("last monday", $previousWeek);
                // $endWeek = strtotime("next friday", $startWeek);
                // $startWeek = date("Y-m-d", $startWeek);
                // $endWeek = date("Y-m-d", $endWeek);
                // //Last week missed hours
                // $lastWeekMissedHours  = $this->getMissedHoursEmployeeData($startWeek, $endWeek, $department,false, false,$allRoles);

                $monthStartDate = Carbon::now("UTC")->firstOfMonth()->format('Y-m-d');
                $monthCurrentDate = getUtcDate();
                $yesterDayDate = Carbon::now("UTC")->yesterday()->format('Y-m-d');
                //This month missed hours
                $thisMonthMissedHours  = $this->getMissedHoursEmployeeData($monthStartDate, $yesterDayDate, $department,false, false,$allRoles);

                //Pending submission
                $startDate = $lastMonthStartDate;
                $endDate = $lastMonthEndDate;

                $query = Project::withoutGlobalScopes([OrganizationScope::class])->leftJoin('user_timesheets', 'projects.id', 'user_timesheets.project_id')
                    ->whereBetween('user_timesheets.date', [$startDate, $endDate])
                    ->where('projects.organization_id', $organizationId)
                    ->where('projects.billable', 1)
                    ->where('user_timesheets.timesheet_status_id', TimesheetStatus::PENDING)
                    ->whereNull('user_timesheets.deleted_at')
                    ->select('projects.id');
                if (!in_array('administrator', $allRoles)) {
                    $query =  $query->join('project_employees', 'projects.id', 'project_employees.project_id')
                        ->where('project_employees.employee_id', $user->entity_id)
                        ->where('project_employees.organization_id', $organizationId);
                }

                $pendingExport = $query->groupBy('projects.id')->get();

                $query = Project::withoutGlobalScopes([OrganizationScope::class])->join('timesheet_exports', 'projects.id', 'timesheet_exports.project_id')
                    ->where('projects.organization_id', $organizationId)
                    ->whereNULL('timesheet_exports.deleted_at')
                    ->select('projects.id','timesheet_exports.timesheet_status_id');
                if (!in_array('administrator', $allRoles)) {
                    $query =  $query->join('project_employees', 'projects.id', 'project_employees.project_id')
                                    ->where('project_employees.id', '=', function($q) use ($user)
                                        {
                                        $q->from('project_employees')
                                            ->selectRaw('id')
                                            ->whereRaw('project_id  = `projects`.`id`')
                                            ->where('employee_id', $user->entity_id)->limit(1);
                                        })
                                        ->where('project_employees.organization_id', $organizationId);
                }

                $exportedEntries = $query;
                $submittedExport = [];
                $approvedExport = [];
                $invoicedExport = [];
                $rejectedExport = [];

                //Invoice count is only for this month
                $invoiced = clone $exportedEntries;

                $invoicedExport = $invoiced->where(function ($q) use ($monthStartDate, $monthCurrentDate) {
                    $q->whereBetween(DB::raw('DATE(timesheet_exports.created_at)'), [$monthStartDate, $monthCurrentDate]);

                })->where('timesheet_status_id', TimesheetStatus::INVOICED)->get();
             
                //submitted, rejected and approved counts are without any date filter
                $exportedEntries = $exportedEntries->get();
                foreach($exportedEntries as $entries){
                    
                    if($entries->timesheet_status_id == TimesheetStatus::SUBMITTED){
                        $submittedExport[] = $entries;
                    }  

                    if($entries->timesheet_status_id == TimesheetStatus::REJECTED){
                        $rejectedExport[] = $entries;
                    }

                    if($entries->timesheet_status_id == TimesheetStatus::APPROVED){
                        $approvedExport[] = $entries;
                    }
                }

                $responseData['lastMonthMissedHours'] = $lastMonthMissedHours;
              //  $responseData['lastWeekMissedHours'] = $lastWeekMissedHours;
                $responseData['thisMonthMissedHours'] = $monthStartDate == getUtcDate() ? 0 : $thisMonthMissedHours;
                $responseData['pendingExports'] = count($pendingExport);
                $responseData['submittedExports'] = count($submittedExport);
                $responseData['approvedExports'] = count($submittedExport);
                $responseData['invoicedExports'] = count($invoicedExport);
                $responseData['rejectedExports'] = count($rejectedExport);
            }

            //Employee dashboard
           // if ((!in_array('administrator', $allRoles)) && (in_array('missed_hours_report', $permissions) || in_array('add_hours', $permissions) || in_array('view_timesheet_dashboard', $permissions) || in_array('timesheet_report', $permissions))) {

                $employeeId = $user->entity_id;
                $today = Carbon::now("UTC");
               

                $employee = Employee::where('id', $employeeId)->select('join_date')->first();

                if (isset($employee->join_date)) {
                    $employeeJoinDate = $employee->join_date;
                    $joinDate = \Carbon\Carbon::parse($employeeJoinDate);
                }
                $daysMonth = cal_days_in_month(CAL_GREGORIAN, $today->month, $today->year);
                if (isset($employee->join_date) && ($joinDate->month == $today->month) && ($joinDate->year == $today->year)) {
                    $daysMonth = $daysMonth - ($joinDate->day - 1);
                }

                $monthStartDate = Carbon::now("UTC")->firstOfMonth()->format('Y-m-d');
                $monthCurrentDate = $monthStartDate == getUtcDate() ?  getUtcDate() : Carbon::yesterday()->format('Y-m-d');
                $monthEndDate = Carbon::now("UTC")->lastOfMonth()->format('Y-m-d');
                //join_date
                if (isset($joinDate) && Carbon::parse($joinDate)->between(Carbon::parse($monthStartDate), getUtcDate())) {
                    $monthStartDate = $joinDate;
                }

                $previousWeek = strtotime("-1 week +1 day");
                $startWeek = strtotime("last monday", $previousWeek);
                $endWeek = strtotime("next friday", $startWeek);
                $startWeek = date("Y-m-d", $startWeek);
                $endWeek = date("Y-m-d", $endWeek);


                $year = date('Y');
                $month = date('m');

                // $query  = Holiday::query();

                // if (isset($employee->join_date)) {
                //     $query = $query->whereDate('date', '>=', $employee->join_date);
                // }
              //  $holidays = $query->whereYear('date', $year)->whereMonth('date', $month)->count();

             //   $totalHolidayHrs = $holidays * $this->workingHoursPerDay;
                // $halfDay = Leave::join('leave_details','leaves.id', 'leave_details.leave_id')->whereIn('day_duration_id', [DayDuration::FIRSTHALF, DayDuration::SECONDHALF])
                //          ->whereMonth('leave_date',$month)
                //          ->whereYear('leave_date', $year)
                //          ->where('employee_id', $employeeId)->where('leave_status_id', LeaveStatus::APPROVE)->count() * 4;

                // $fullDay = Leave::join('leave_details','leaves.id', 'leave_details.leave_id')->whereIn('day_duration_id', [DayDuration::FULLDAY])
                //         ->whereMonth('leave_date',$month)
                //         ->whereYear('leave_date', $year)
                //         ->where('employee_id', $employeeId)->where('leave_status_id', LeaveStatus::APPROVE)->count() * $this->workingHoursPerDay;

              //  $leaveHours = $fullDay + $halfDay;
              //  $leaveHours = 0;
              //  $nonWorkingHours = $totalHolidayHrs + $leaveHours;

                // This month working Hours
                $thisMonthData = $this->getUserWorkingHours($monthStartDate, $monthCurrentDate);
                $thisMonthFilledTotal = UserTimesheet::whereBetween('date', [$monthStartDate, $monthCurrentDate])
                    ->where('employee_id', $employeeId)
                    ->sum('working_hours');
                $totalWorkingHours = $this->getTotalHours($monthStartDate, $monthCurrentDate,$organizationId, 0, 1);
                //End This month working Hours

                $totalHours = $this->getTotalHours($monthStartDate, $monthEndDate,$organizationId, 0, 1);

                // Last Week Missing Hours
                $prevWeekDay = Carbon::now("UTC")->subWeek()->startOfWeek()->format('Y-m-d');
                $prevWeekEndDay = Carbon::now("UTC")->subWeek()->endOfWeek()->format('Y-m-d');
                if (isset($employeeJoinDate) && Carbon::parse($employeeJoinDate)->between(Carbon::parse($prevWeekDay), Carbon::parse($prevWeekEndDay))) {
                    $prevWeekDay = $employeeJoinDate;
                }
                $prevWeekData = $this->getUserWorkingHours($prevWeekDay, $prevWeekEndDay);

                $prevWeekTotalWorkingHours = $this->getTotalHours($prevWeekDay, $prevWeekEndDay,$organizationId, 0, 1);

                //End Last Week Missing Hours

                // Last Month Missing Hours
                $prevMonthDay = new Carbon('first day of last month');
                $prevMonthDay = $prevMonthDay->toDateString();
                $prevMonthEndDay = new Carbon('last day of last month');
                $prevMonthEndDay = $prevMonthEndDay->toDateString();
                if (isset($employeeJoinDate) && Carbon::parse($employeeJoinDate)->between(Carbon::parse($prevMonthDay), Carbon::parse($prevMonthEndDay))) {
                    $prevMonthDay = $employeeJoinDate;
                }
                $prevMonthData = $this->getUserWorkingHours($prevMonthDay, $prevMonthEndDay);
                $prevMonthTotalWorkingHours = $this->getTotalHours($prevMonthDay, $prevMonthEndDay, $organizationId,0, 1);

                //End Last Month Missing Hours

                $thisMonthCompOffHours = $this->getCompOffTotalMissedHours($monthStartDate, $monthCurrentDate,$employeeId);
                $lastWeekCompOffHours = $this->getCompOffTotalMissedHours($prevWeekDay, $prevWeekEndDay,$employeeId);
                $lastMonthCompOffHours = $this->getCompOffTotalMissedHours($prevMonthDay, $prevMonthEndDay,$employeeId);

                $thisMissedMonth = $totalWorkingHours - $thisMonthData->sum('working') > 0 ? $totalWorkingHours - $thisMonthData->sum('working') : 0;

                $thisMissedMonth = ($thisMissedMonth <= 0) ? 0 : round($thisMissedMonth, 2);
              
                if(!empty($thisMonthCompOffHours['total_hours'])){
                    $compOffFillHours = $thisMonthCompOffHours['fill_hours'];
    
                    $thisMissedMonth = $totalWorkingHours - ($thisMonthData->sum('working') -  $compOffFillHours);
                    $thisMissedMonth = ($thisMissedMonth <= 0) ? 0 : round($thisMissedMonth, 2);

                    if(!empty($thisMonthCompOffHours['missed_hours'])){
                        $thisMissedMonth = $thisMissedMonth .'+'. $thisMonthCompOffHours['missed_hours'];
                    }
		        }

                $lastMissedWeek = $prevWeekTotalWorkingHours - $prevWeekData->sum('working') > 0 && $thisMissedMonth > 0  ? $prevWeekTotalWorkingHours - $prevWeekData->sum('working') : 0;
                if (isset($employeeJoinDate) && Carbon::parse($employeeJoinDate)->greaterThan(Carbon::parse($prevWeekEndDay))) {
                    $lastMissedWeek = 0;
                }

                $lastMissedWeek = ($lastMissedWeek <= 0) ? 0 : round($lastMissedWeek, 2);

                if(!empty($lastWeekCompOffHours['total_hours'])){
                    $compOffFillHours = $lastWeekCompOffHours['fill_hours'];
    
                    $lastMissedWeek = $prevWeekTotalWorkingHours - ($prevWeekData->sum('working') -  $compOffFillHours);
                    $lastMissedWeek = ($lastMissedWeek <= 0) ? 0 : round($lastMissedWeek, 2);

                    if(!empty($lastWeekCompOffHours['missed_hours'])){
                        $lastMissedWeek = $lastMissedWeek .'+'. $lastWeekCompOffHours['missed_hours'];
                    }
		        }

                if (isset($employeeJoinDate) && Carbon::parse($employeeJoinDate)->greaterThan(Carbon::parse($prevWeekEndDay))) {
                    $lastMissedWeek = 0;
                }

                $lastMissedMonth = $prevMonthTotalWorkingHours - $prevMonthData->sum('working') > 0 ? $prevMonthTotalWorkingHours - $prevMonthData->sum('working') : 0;
                if (isset($employeeJoinDate) && Carbon::parse($employeeJoinDate)->greaterThan(Carbon::parse($prevMonthEndDay))) {
                    $lastMissedMonth = 0;
                }
                $lastMissedMonth = ($lastMissedMonth <= 0) ? 0 : round($lastMissedMonth, 2);
                if(!empty($lastMonthCompOffHours['total_hours'])){
                    $compOffFillHours = $lastMonthCompOffHours['fill_hours'];
    
                    $lastMissedMonth = $prevMonthTotalWorkingHours - ($prevMonthData->sum('working') -  $compOffFillHours);
                    $lastMissedMonth = ($lastMissedMonth <= 0) ? 0 : round($lastMissedMonth, 2);

                    if(!empty($lastMonthCompOffHours['missed_hours'])){
                        $lastMissedMonth = $lastMissedMonth .'+'. $lastMonthCompOffHours['missed_hours'];
                    }
		        }

                if (isset($employeeJoinDate) && Carbon::parse($employeeJoinDate)->greaterThan(Carbon::parse($prevMonthEndDay))) {
                    $lastMissedMonth = 0;
                }

                $responseData['thisMonthWorking'] = round($totalHours, 2);
                $responseData['totalMonthWorking'] = round($thisMonthFilledTotal, 2);
                $responseData['thisMissedMonth'] = date('Y-m-d',strtotime($monthStartDate)) == getUtcDate() ? 0 : $thisMissedMonth;
                $responseData['lastMissedWeek'] = $lastMissedWeek;
                $responseData['lastMissedMonth'] = $lastMissedMonth;
           // }

            return $this->sendSuccessResponse(__('messages.success'), 200, $responseData);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while fetch user timesheet dashboard";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    private function getCompOffTotalMissedHours($startDate, $endDate, $employeeId){
        $compOffs = $this->getCompOffDays($startDate, $endDate, $employeeId);
        $compOffFillHour = 0;
        $compOffTotalHours = 0;
        $compOffMissedHours = 0;
         if (!empty($compOffs)) {

             foreach ($compOffs as $day) {
                $fillHour = 0;
                $compOffHour = $this->getUserWorkingHours($day['comp_off_date'], $day['comp_off_date'],$employeeId);
                $compOffFillHour += $compOffHour->sum('working');
                $fillHour = $compOffHour->sum('working');

                 if($day->day_duration_id == DayDuration::FULLDAY){
                    $compOffTotalHours += $this->workingHoursPerDay;
                    $compOffMissedHours += ($fillHour < ($this->workingHoursPerDay / 2 )) ? $this->workingHoursPerDay - $fillHour : 0;
                 }else{
                    $compOffTotalHours += ($this->workingHoursPerDay / 2);
                    $compOffMissedHours += empty($fillHour) ? (( $this->workingHoursPerDay / 2)- $fillHour) : 0;
                 }
             }

             $compOffTotalHours = ['total_hours' => $compOffTotalHours, 'missed_hours' => $compOffMissedHours, 'fill_hours' => $compOffFillHour];
         }

        return $compOffTotalHours;
    }

    //Get the total filled hours 
    private function getWorkingHours($startDate, $endDate)
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;

        //join_date
        if (isset($employeeJoinDate) && Carbon::parse($employeeJoinDate)->between(Carbon::parse($startDate), Carbon::parse($endDate))) {
            $startDate = $employeeJoinDate;
        }

        $thisMonthData = $this->getUserWorkingHours($startDate, $endDate);
        $thisMonthFilledTotal = UserTimesheet::whereBetween('date', [$startDate, $endDate])
            ->where('employee_id', $user->entity_id)
            ->sum('working_hours');

        $totalWorkingHours = $this->getTotalHours($startDate, $endDate,$organizationId, 0, 1);

        $data['total_working_hours'] = $thisMonthFilledTotal;
        $data['total_missed_hours'] = $totalWorkingHours - $thisMonthData->sum('working');

        return $data;
    }

    //Save single timesheet entry
    public function addSingleTimesheetEntry(Request $request)
    {
        try {

            $inputs = $request->all();

            DB::beginTransaction();

            $validation = $this->userTimesheetValidator->validateStore($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $organizationId = $this->getCurrentOrganizationId();
            $user = Auth::user();

            if (!empty($inputs['hours'])) {
                $date = convertUserTimeToUTC($inputs['date']);

                UserTimesheet::create(
                    ['employee_id' => $user->entity_id, 'organization_id' => $organizationId, 'date' => $date, 'project_id' => $inputs['project'], 'note' => $inputs['note'], 'pm_note' => $inputs['note'], 'working_hours' => $inputs['hours'], 'timesheet_status_id' => TimesheetStatus::PENDING, 'billing_hours' => $inputs['hours'], 'task_id' =>  $inputs['task_id'] ?? null]
                );
            }
            $response = [];
            if(!empty($inputs['task_id'])){
                $task = Task::where('id', $inputs['task_id'])->first();
                $timesheetQuery = UserTimesheet::where('task_id', $inputs['task_id']);
                $hours = $timesheetQuery
                    ->select('working_hours')
                    ->get();
    
                $loggedHours = 0;
                if (!empty($hours)) {
                    $total = collect($hours)->sum('working_hours');
                    $loggedHours = $total;
                }
                $estimatedHours = $task->estimated_hours;
                if ($estimatedHours == 0) {
                    $progress = 0;
                } else {
                    $progress = (($loggedHours * 100) / $estimatedHours);
                    if ($progress > 100) {
                        $progress = 100;
                    }
                }
                $percentage = round($progress, 2);

                $response = ['percentage' => $percentage, 'logged_hours' => $loggedHours];
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.timesheet_added'), 200, $response);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while save user timesheet";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    // Update single timesheet entry
    public function updateSingleTimesheetEntry(Request $request, $timesheetId)
    {
        try {

            $inputs = $request->all();

            DB::beginTransaction();

            $validation = $this->userTimesheetValidator->validateStore($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $useTimesheet = UserTimesheet::where('id', $timesheetId)->first();

            if (!empty($inputs['hours']) && !empty($useTimesheet) && $useTimesheet->timesheet_status_id == TimesheetStatus::PENDING) {
                $date = convertUserTimeToUTC($inputs['date']);

                $useTimesheet->update(
                    ['date' => $date, 'project_id' => $inputs['project'], 'note' => $inputs['note'], 'pm_note' => $inputs['note'], 'working_hours' => $inputs['hours'], 'billing_hours' => $inputs['hours']]
                );
            }

            $response = [];
            if(!empty($inputs['task_id'])){
                $task = Task::where('id', $inputs['task_id'])->first();
                $timesheetQuery = UserTimesheet::where('task_id', $inputs['task_id']);
                $hours = $timesheetQuery
                    ->select('working_hours')
                    ->get();
    
                $loggedHours = 0;
                if (!empty($hours)) {
                    $total = collect($hours)->sum('working_hours');
                    $loggedHours = $total;
                }
                $estimatedHours = $task->estimated_hours;
                if ($estimatedHours == 0) {
                    $progress = 0;
                } else {
                    $progress = (($loggedHours * 100) / $estimatedHours);
                    if ($progress > 100) {
                        $progress = 100;
                    }
                }
                $percentage = round($progress, 2);

                $response = ['percentage' => $percentage, 'logged_hours' => $loggedHours];

            }


            DB::commit();

            return $this->sendSuccessResponse(__('messages.timesheet_added'), 200, $response);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while update user timesheet";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Save all timesheets entry
    public function saveAllTimesheetDetails(Request $request)
    {
        try {

            $inputs = $request->all();

            DB::beginTransaction();

            $user = Auth::user();
            $organizationId = $this->getCurrentOrganizationId();
            $data = $inputs['weekdates'];
            $startDate = !empty($inputs['start_date']) ? $inputs['start_date'] : null;
            $endDate = !empty($inputs['end_date']) ? $inputs['end_date'] : null;
            $updated = [];
            $weekTotal = 0;
            $totalHours = 0;

            foreach ($data as $key => $details) {
                $weekTotal = 0;
                $updated[] = $details;
                if (!empty($details['weekdates']) && is_array($details['weekdates'])) {
                    foreach ($details['weekdates'] as $dayKey => $dayDetail) {
                        $time = array();

                        if (isset($dayDetail['timesheet']) && is_array($dayDetail['timesheet'])) {
                            foreach ($dayDetail['timesheet'] as $timesheet) {
                                if(!empty($timesheet['working_hours'])){

                                    if((!empty($timesheet['timesheet_status_id']) &&  $timesheet['timesheet_status_id'] == TimesheetStatus::PENDING) || empty($timesheet['timesheet_status_id']) ){
                                        $time[] = UserTimesheet::updateOrCreate(
                                            ['id' =>  $timesheet['id'] ?? null],
                                            ['employee_id' => $user->entity_id, 'organization_id' => $organizationId,  'date' => $dayDetail['date'], 'project_id' => $timesheet['project_id'], 'timesheet_status_id' => $timesheet['timesheet_status_id'] ?? TimesheetStatus::PENDING, 'note' => $timesheet['note'],  'pm_note' => $timesheet['note'], 'working_hours' => $timesheet['working_hours'], 'billing_hours' => $timesheet['working_hours']]
                                        );
                                    }else{
                                        $time[] = $timesheet;
                                    }

                                }else{
                                    $time[] = [
                                        "project_id"=> "",
                                        "note"=> "",
                                        "working_hours"=> 0,
                                        "id"=> 0,
                                        "timesheet_status_id"=> 1,
                                        "date"=> "2022-11-07"
                                    ];
                                }
                             
                            }
                        }
                        $dayTotal = collect($time)->sum('working_hours');
                        $weekTotal += $dayTotal;
                        $updated[$key]['weekdates'][$dayKey]['totalDayHours'] = $dayTotal;
                        $updated[$key]['weekdates'][$dayKey]['timesheet'] = $time;
                        $updated[$key]['totalHours'] = $weekTotal;
                    }
                }
            }

            $totalWorkingHours = 0;
            if (!empty($inputs['totalWorkingHours'])) {
                $totalWorkingHours = $inputs['totalWorkingHours'];
                $updated[0]['totalWorkingHours'] = $inputs['totalWorkingHours'];
            }

            if (!empty($inputs['join_date'])) {
                $updated[0]['join_date'] = $inputs['join_date'];
            }

            $data = $this->getWorkingHours($startDate, $endDate);

            $totalHours = $data['total_working_hours'];

            $startDate = Carbon::now("UTC")->firstOfMonth()->format('Y-m-d');
            $endDate = getUtcDate();

            $data = $this->getWorkingHours($startDate, $endDate);

            $updated[0]['totalMonthWorking'] = $data['total_working_hours'];
            $updated[0]['thisMissedMonth'] = $data['total_missed_hours'];

            $response = ['data' =>  $updated, 'totalHours' => $totalHours, 'totalWorkingHours' => $totalWorkingHours, 'join_date' => $inputs['join_date']];

            DB::commit();

            return $this->sendSuccessResponse(__('messages.timesheet_added'), 200, $response);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while save user timesheet";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get list of holidays between given dates
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

    //Get timesheet details
    public function timesheetDetail(Request $request)
    {
        try {
            $inputs = $request->all();
            $weekStart = $loopStart = $start = isset($inputs['start']) ?  date("Y-m-d", strtotime($inputs['start'])) : '';
            $weekEnd = $loopEnd = $end = isset($inputs['end']) ? date("Y-m-d", strtotime($inputs['end'])) : '';
            $isMissing = isset($inputs['is_missing']) ? $inputs['is_missing'] : false;

            $currentWeek = Carbon::now("UTC")->weekOfYear;
            $workingWeekend = array();
            $user = Auth::user();
            $employeeId = $user->entity_id;
            $organizationId = $this->getCurrentOrganizationId();
            $employee = Employee::where('id', $employeeId)->select('join_date')->first();

            if (strtotime($start) < strtotime($employee->join_date)) {
                $weekStart = $loopStart = $start = $employee->join_date;
            }
            $holidays = $this->getHoliday($weekStart,  $weekEnd);

            $compOffs = $this->getCompOffDays($weekStart, $weekEnd, $employeeId);
            $compOffDate = [];
            $compOffDuration = [];
            if(!empty($compOffs)){
             
                foreach ($compOffs as $day) {
                    $compOffDate[] = $day['comp_off_date'];
                    $compOffDuration[] = [$day['comp_off_date'] => $day['day_duration_id']];
                }

                $compOffDuration = array_merge(...$compOffDuration);
                
            }

            $timesheetDatas = UserTimesheet::select('id', 'project_id', 'working_hours', 'note', 'date', 'timesheet_status_id')
                ->whereEmployeeId($employeeId)
                ->whereBetween('date', [$start, $end])
                ->orderBy('date', 'ASC')
                ->get();

            $totalAddedHours = $timesheetDatas->sum('working_hours');

            $totalWorkedHours = $this->getTotalHours($start, $end, $organizationId,$employeeId);

            $timesheetData = array();
            $emptyObject = array();
            $compOffFillHour = 0;
            $offHours = 0;
            while (strtotime($loopStart) <= strtotime($loopEnd)) {

                $data = $timesheetDatas->where('date', $loopStart)->toArray();
                if (!empty($data)) {
                    $timesheetData = array_merge($timesheetData, $data);
                } else if (!Carbon::parse($loopStart)->isWeekend() && !in_array($loopStart, $holidays)) {
                    $emptyObject =  new UserTimesheet();
                    $emptyObject->project_id = '';
                    $emptyObject->note = '';
                    $emptyObject->working_hours = 0;
                    $emptyObject->id = 0;
                    $emptyObject->timesheet_status_id = TimesheetStatus::PENDING;
                    $emptyObject->date = $loopStart;

                    $timesheetData[] = $emptyObject;
                } 
                
                if(in_array($loopStart, $compOffDate)){

                    if (empty($data)) {
                        $emptyObject = new UserTimesheet();
                        $emptyObject->project_id = '';
                        $emptyObject->note = '';
                        $emptyObject->working_hours = 0;
                        $emptyObject->id = 0;
                        $emptyObject->timesheet_status_id = TimesheetStatus::PENDING;
                        $emptyObject->date = $loopStart;
                        $emptyObject->is_holiday = true;

                        $timesheetData[] = $emptyObject;
                    }
                }

                $loopStart = date("Y-m-d", strtotime("+1 days", strtotime($loopStart)));
            }

            $timesheetData = collect($timesheetData)->groupBy(
                [function ($date) {
                    return Carbon::parse($date['date'])->format('W');
                }, 'date']
            );

            $leaves = Leave::join('leave_details','leaves.id', 'leave_details.leave_id')
            ->whereBetween('leave_date', [$start,$end])
            ->where('employee_id', $employeeId)->where('leaves.leave_status_id', LeaveStatus::APPROVE)
            ->whereNull('leave_details.deleted_at')->get(['leave_details.leave_date','leave_details.day_duration_id']);

            $responseData = array();
            $count = 0;
            $totalHours = 0;
            $totalWorkingHours = 0;

            foreach ($timesheetData as $key => $week) {
                $innerCount = 0;
                $date = Carbon::now("UTC");
                $date->setISODate($date->year, $key);
                // $startDate = $date->startOfWeek()->format('Y-m-d');
                // $endDate =  $date->endOfWeek()->format('Y-m-d');

                $startDate = $weekStart;
                $endDate = $weekEnd;

                if (Carbon::parse($startDate)->lt(Carbon::parse($start))) {
                    $startDate = $start;
                }

                $responseData[$count]['isDisable'] = ($key == $currentWeek) ? false : true;
                $responseData[$count]['totalWorkingHours'] = $this->getTotalHours($startDate, $endDate, $organizationId);
                $responseData[$count]['weekName'] =  Carbon::parse($startDate)->weekNumberInMonth;
                $responseData[$count]['join_date'] = $employee->join_date;

                $totalWorkingHours += $responseData[$count]['totalWorkingHours'];

               
                foreach ($week as $date => $dateData) {
                    $total = collect($dateData)->sum('working_hours');
                    $responseData[$count]['totalHours'] = (isset($responseData[$count]['totalHours'])) ? $responseData[$count]['totalHours'] + $dateData->sum('working_hours') : $dateData->sum('working_hours');
                    $totalHours = $responseData[$count]['totalHours'];
                    $leave = $leaves->where('leave_date', $date)->count();
                    $fullDay = false;
                    $checkHours =  $this->workingHoursPerDay;
                    if($leave == 1){
                        $leave = $leaves->firstWhere('leave_date', $date);
                        $checkHours = (!empty($leave) && $leave->day_duration_id != DayDuration::FULLDAY) ? 4 : $this->workingHoursPerDay;
                        $fullDay =  ($leave->day_duration_id == DayDuration::FULLDAY) ? true  : false;
                    }elseif($leave == 2){
                        $fullDay = true;
                    }

                    $isHoliday = Carbon::parse($date)->isWeekend() || in_array($date, $holidays);

                    if (in_array($date, $compOffDate)) {

                        $compOffHour = $this->getUserWorkingHours($date, $date);
                        $compOffFillHour = $compOffHour->sum('working');

                        $totalAddedHours = $totalAddedHours - $compOffFillHour;

                        if (!empty($compOffDuration[$date])) {
                            $off = $compOffDuration[$date];
                            $offHours = (!empty($off) && $off != DayDuration::FULLDAY) ? 4 : $this->workingHoursPerDay;
                        }
                    }
                  

                    if (in_array($date, $holidays)) {
                        $holidays = array_diff($holidays, array($date));
                    }

                    $isMissed = false;
                    if($total == 0 && empty($leave)){
                        $isMissed = true;
                    }else if($totalAddedHours < $totalWorkedHours && !in_array($date, $compOffDate)){
                        $isMissed = true;
                    }else if($compOffFillHour < $offHours && in_array($date, $compOffDate)){
                        if($offHours == $this->workingHoursPerDay && $compOffFillHour <=  ($this->workingHoursPerDay / 2)){
                            $isMissed = true;
                        }
                        if($offHours == 4 && empty($compOffFillHour)){
                            $isMissed = true;
                        }
                    }
                    
                    if (($isMissing == false || (($isMissed == true && $total < $checkHours)  && $isMissing == true))) {
                        $responseData[$count]['weekdates'][$innerCount]['totalDayHours'] = $dateData->sum('working_hours');
                        $responseData[$count]['weekdates'][$innerCount]['date'] = $date;
                        $responseData[$count]['weekdates'][$innerCount]['day'] = Carbon::parse($date)->format('l');
                        $responseData[$count]['weekdates'][$innerCount]['on_leave'] = (!empty($leave)) ? true : false;
                        $responseData[$count]['weekdates'][$innerCount]['is_full_leave'] = $fullDay;
                        $responseData[$count]['weekdates'][$innerCount]['is_holiday'] = $isHoliday;

                        if (empty($leave) || $fullDay == false) {
                            $responseData[$count]['weekdates'][$innerCount]['timesheet'] = $dateData;
                        }

                        $innerCount++;
                    }
                }

                $count++;
            }

            // Weekend Entry
            $weekend = array();
            $weekendCount = 0;
            $weekendStartDate = Carbon::parse('this saturday')->format("Y-m-d");
            $weekendEndDate = Carbon::parse('this sunday')->format("Y-m-d");

            if (Carbon::parse($end)->weekOfYear == $currentWeek && Carbon::now()->gte(Carbon::parse('this saturday'))) {
                while (strtotime($weekendStartDate) <= strtotime($weekendEndDate)) {

                    $weekend[$weekendCount]['totalDayHours'] = 0;
                    $weekend[$weekendCount]['date'] = $weekendStartDate;
                    $weekend[$weekendCount]['day'] = Carbon::parse($weekendStartDate)->format('l');
                    $weekend[$weekendCount]['on_leave'] = false;
                    $weekend[$weekendCount]['disabled'] = in_array($weekendStartDate, $workingWeekend);
                    $weekend[$weekendCount]['timesheet'] = [$emptyObject];


                    $weekendCount++;
                    $weekendStartDate = date("Y-m-d", strtotime("+1 days", strtotime($weekendStartDate)));
                }
            }
            //Get user's project
            $projects = Project::withoutGlobalScopes()->join(
                'project_employees', function ($join) use ($employeeId, $organizationId) {
                    $join->on('projects.id', '=', 'project_employees.project_id');
                    $join->where('project_employees.project_role_id', '=', ProjectRole::DEVELOPERANDQA);
                    $join->where('project_employees.employee_id', $employeeId);
                    $join->where('project_employees.organization_id', $organizationId);
                    $join->where('projects.organization_id', $organizationId);
                    $join->orWhere(function ($join) use ($organizationId) {
                                $join->where('projects.default_access_to_all_users', 1);
                                $join->where('projects.organization_id', $organizationId);
                    });
                })->whereNull('projects.deleted_at')->groupBy('projects.id');

            $projects = $projects->get(['projects.name', 'projects.id', 'projects.uuid']);

            $holidays = array_values($holidays);

            $response = [
                'data' =>  $responseData,
                'weekend' => $weekend,
                'projects' => $projects,
                'totalHours' => $totalHours,
                'totalWorkingHours' => $totalWorkingHours,
                'join_date' => $employee->join_date,
                'holidays' => $holidays
            ];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get timesheet details";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Delete timesheet entry
    public function deleteTimesheetEntry(Request $request)
    {
        DB::beginTransaction();
        try {

            $inputs = $request->all();
            $id = $inputs['id'];

            $timesheet = UserTimesheet::where('id', $id)->first();

            if (!empty($timesheet)) {
                $timesheet->delete();
            }

            $startDate = Carbon::now("UTC")->firstOfMonth()->format('Y-m-d');
            $endDate = getUtcDate();

            $data = $this->getWorkingHours($startDate, $endDate);

            $data['totalMonthWorking'] = $data['total_working_hours'];
            $data['thisMissedMonth'] = $data['total_missed_hours'];

            if(!empty($inputs['task_id'])){
                $task = Task::where('id', $inputs['task_id'])->first();
                $timesheetQuery = UserTimesheet::where('task_id', $inputs['task_id']);
                $hours = $timesheetQuery
                    ->select('working_hours')
                    ->get();
    
                $loggedHours = 0;
                if (!empty($hours)) {
                    $total = collect($hours)->sum('working_hours');
                    $loggedHours = $total;
                }
                $estimatedHours = $task->estimated_hours;
                if ($estimatedHours == 0) {
                    $progress = 0;
                } else {
                    $progress = (($loggedHours * 100) / $estimatedHours);
                    if ($progress > 100) {
                        $progress = 100;
                    }
                }
                $percentage = round($progress, 2);

                $data['percentage'] = $percentage;
                $data['logged_hours'] = $loggedHours;
            }

            DB::commit();
            return $this->sendSuccessResponse(__('messages.timesheet_deleted'), 200, $data);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while delete timesheet entry";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Employee timesheet report
    public function timesheetReport(Request $request)
    {
        try {
            $user = Auth::user();

            $employeeId = $user->entity_id;

            $organizationId = $this->getCurrentOrganizationId();

            $validation = $this->userTimesheetValidator->validateTimesheet($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $projectId = $request->project;
            $fromDate = date('Y-m-d', strtotime($request->start_date));
            $toDate = date('Y-m-d', strtotime($request->end_date));

            $timesheetQuery = UserTimesheet::withoutGlobalScopes([OrganizationScope::class])->join('projects', 'user_timesheets.project_id', 'projects.id')
                ->join('employees', function ($join) {
                    $join->on('user_timesheets.employee_id', '=',  'employees.id');
                    $join->on('employees.organization_id', '=', 'user_timesheets.organization_id');
                })
                ->whereBetween('user_timesheets.date', [$fromDate, $toDate]);

            if (!empty($projectId)) {
                $timesheetQuery->where('user_timesheets.project_id', $projectId);
            }
            if (!empty($employeeId)) {
                $timesheetQuery->where('user_timesheets.employee_id', $employeeId);
            }

            $timesheetQuery->where('user_timesheets.organization_id', $organizationId);

            $timesheetQuery->orderBy('user_timesheets.date', 'desc')
                ->select(
                    'projects.name as project_name',
                    'user_timesheets.note as notes',
                    'user_timesheets.working_hours as hours',
                    'user_timesheets.date'
                );

            $timesheetList = $timesheetQuery->get();

            foreach ($timesheetList as $key => $value) {
                $name = $value->project_name;

                if (empty($value->notes)) {
                    $value->notes = "";
                }
                $timesheetList[$key]->name = $name;
                $entryDate = date('d M, Y', strtotime($value->date));
                $timesheetList[$key]->date = $entryDate;

                $hoursMin = $this->decimalToHoursMin($value->hours);
                $hours = $hoursMin['hours'] . ' H ' . $hoursMin['minutes'] . ' M';
                $timesheetList[$key]->hours = $hours;
            }

            //Timesheet summary
            $timesheetQuery->select(
                'projects.name as project_name',
                DB::raw('ROUND(SUM(user_timesheets.working_hours),2)  AS hours')
            )
                ->groupBy('user_timesheets.project_id');

            $summaryList = $timesheetQuery->get();

            $response = [
                'summary' => $summaryList,
                'timesheet' => $timesheetList
            ];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while get timesheet report";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get employee data of missed hours
    private function getMissedHoursEmployeeData($startDate, $endDate, $department = [], $details = false, $allEmployees = false, $allRoles = [])
    {
        $count = 0;
        $responseData = [];
        $organizationId = $this->getCurrentOrganizationId();

        $user = Auth::user();

        $activeEmployee = Employee::withoutGlobalScopes([OrganizationScope::class])->active()->select('employees.id AS employee_id', 'employees.display_name','join_date')
            ->where('employees.do_not_required_punchinout', 0)
            ->where('employees.timesheet_filling_not_required', 0)
            ->where('employees.organization_id', $organizationId);

        if ($department) {
            $activeEmployee = $activeEmployee->whereNotIn('department_id', $department);
        }

        if (!in_array('administrator', $allRoles)) {
            $projectIds = ProjectEmployee::where('employee_id', $user->entity_id)->get('project_id')->pluck('project_id');
            $activeEmployee->leftJoin('project_employees', 'employees.id', 'project_employees.employee_id')
                ->where('project_employees.project_role_id', ProjectRole::DEVELOPERANDQA)
                ->where('project_employees.organization_id', $organizationId)
                ->whereIn('project_employees.project_id', $projectIds);
        }else{
            $activeEmployee = $activeEmployee->where('employees.id', '!=', $user->entity_id);
        }

        $activeEmployee = $activeEmployee->groupBy('employees.id')->get();

        $holidays = $this->getHoliday($startDate, $endDate);

        $timesheetData = UserTimesheet::withoutGlobalScopes([OrganizationScope::class])->join('projects', 'user_timesheets.project_id', 'projects.id')
        ->where('user_timesheets.organization_id', $organizationId)
        ->whereBetween('date', [$startDate, $endDate])
        ->get(['working_hours','billing_hours','billable','date','timesheet_status_id', 'user_timesheets.employee_id']);

        foreach ($activeEmployee as $employee) {

            $fromDate = $startDate;

            $joinDate = $employee->join_date;
            if (!empty($joinDate) && strtotime($fromDate) < strtotime($joinDate)) {
                $fromDate = Carbon::parse($joinDate)->format('Y-m-d');
               
                if ($fromDate > $endDate) {
                    $noOfDays = 0;
                } else {
                    $noOfDays = Carbon::parse($fromDate)->diffInDays(Carbon::parse($endDate)) + 1;
                }
            } else {
                $fromDate = Carbon::parse($fromDate)->format('Y-m-d');
                $noOfDays = Carbon::parse($fromDate)->diffInDays(Carbon::parse($endDate)) + 1;
            }

            $holidayList = array_filter($holidays, function ($holiday) use($fromDate) {
                return strtotime($holiday) >= strtotime($fromDate);
            });
           

            $totalWorkingDays = $noOfDays - count($holidayList);

            $totalWorkingHours = $totalWorkingDays * $this->workingHoursPerDay;


            $filledHours = $timesheetData->filter(function ($value) use($employee, $fromDate) {
                if($value->employee_id == $employee->employee_id && $value->date >= $fromDate){
                    return $value;
                }
            });
          

            $filledHoursData =  $filledHours->sum('working_hours');
            
            $leaveOffDays = $this->getLeaveDays($fromDate, $endDate,$organizationId, $employee->employee_id);
          //  $leaveOffDays = count($leaves['fullDay']) + (count($leaves['halfDay']) / 2);
            $leaveHours = $leaveOffDays * $this->workingHoursPerDay;
           
            $compOffHours = $this->getCompOffTotalMissedHours($fromDate, $endDate,$employee->employee_id);
          
            $totalMissedHours = $totalWorkingHours - ($filledHoursData + $leaveHours);
            $totalMissedHours = ($totalMissedHours < 0) ? 0 : round($totalMissedHours, 2);

            if(!empty($compOffHours)){

                $compOffFillHours = $compOffHours['fill_hours'];

                $totalMissedHours = $totalWorkingHours - ($filledHoursData + $leaveHours -  $compOffFillHours);
                
                $totalMissedHours = ($totalMissedHours < 0) ? 0 : round($totalMissedHours, 2);

                if(!empty($compOffHours['missed_hours']) && $compOffHours['missed_hours'] > 0){
                    $totalMissedHours = $totalMissedHours .'+'. $compOffHours['missed_hours'];
                }
              
                $totalWorkingHours = $totalWorkingHours + $compOffHours['total_hours'];
                $filledHoursData = $filledHoursData - $compOffFillHours;
            }
           
            if (($allEmployees) || $totalMissedHours > 0 && ($totalWorkingHours > round($filledHoursData, 2))) {
                $count++;
                if ($details == true) {

                    // $exportedHrs = UserTimesheet::select(DB::raw('SUM(billing_hours) as working_hours'))
                    // ->whereEmployeeId($employee->employee_id)
                    // ->whereBetween('date', [$fromDate, $endDate])
                    // ->where('timesheet_status_id', TimesheetStatus::INVOICED)
                    // ->groupBy('employee_id')
                    // ->orderBy('employee_id')
                    // ->first();

                    $exportedHours = $timesheetData->filter(function ($value) use($employee, $fromDate) {
                        if($value->employee_id == $employee->employee_id && $value->date >= $fromDate && $value->timesheet_status_id == TimesheetStatus::INVOICED){
                            return $value;
                        }
                    });
    
                    if (empty($exportedHours)) {
                        $exportedHrs['working_hours'] = 0;
                    }else{
                        $exportedHrs['working_hours'] = $exportedHours->sum('billing_hours');
                    }
                
                    $billableHours = $timesheetData->filter(function ($value) use($employee, $fromDate) {
                        if($value->employee_id == $employee->employee_id && $value->date >= $fromDate && $value->billable == 1){
                            return $value;
                        }
                    });

                    $billableHours =  $billableHours->sum('working_hours');
        
                    $nonBillableHours = $timesheetData->filter(function ($value) use($employee, $fromDate) {
                        if($value->employee_id == $employee->employee_id && $value->date >= $fromDate && $value->billable == 0){
                            return $value;
                        }
                    });
                    $nonBillableHours =  $nonBillableHours->sum('working_hours');

                    $responseData[$employee->employee_id] = $employee;
                    $responseData[$employee->employee_id]['filled_hours'] = round($filledHoursData, 2);
                    $responseData[$employee->employee_id]['leave_hours'] = round($leaveHours, 2);
                    $responseData[$employee->employee_id]['missed_hours'] = $startDate == getUtcDate() && $endDate == getUtcDate() ? 0 : $totalMissedHours;
                    $responseData[$employee->employee_id]['exported_hours'] = round($exportedHrs['working_hours'], 2);
                    $responseData[$employee->employee_id]['billable_hours'] = (!empty($billableHours)) ? round($billableHours, 2) : 0;
                    $responseData[$employee->employee_id]['non_billable_hours'] = (!empty($nonBillableHours)) ? round($nonBillableHours, 2) : 0;
                }
            }
        }

        if ($details == true) {
            $responseData = collect($responseData)->sortByDesc('missed_hours')->values();
            return $responseData;
        }

        return $count;
    }

    //Get admin dashboard detail report
    public function getAdminDashboardReport(Request $request)
    {
        try {
            $inputs = $request->all();

            $user = Auth::user();
            $roles = $user->roles;
            $allRoles =  collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $department = $inputs['excluded_department'];
            $startDate = Carbon::parse($inputs['start_date'])->format('Y-m-d');
            $endDate = Carbon::parse($inputs['end_date'])->format('Y-m-d');            
            $allEmployees = isset($inputs['allEmployee']) ? $inputs['allEmployee']  : 0;

            $holidays = $this->getHoliday($startDate, $endDate);

            $noOfDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
            $totalWorkingDays = $noOfDays - count($holidays);
            $totalWorkingHours = $totalWorkingDays * $this->workingHoursPerDay;
            $totalWorkingHoursAll = $totalWorkingHours;

            $responseData = $this->getMissedHoursEmployeeData($startDate, $endDate, $department, true, $allEmployees, $allRoles);

            $data = collect($responseData)->sortByDesc('missed_hours')->values();

            $response = [
                'data' => $data,
                'total_hours' => $totalWorkingHoursAll
            ];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while get admin timesheet dashboard report";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get missed hours detail for employee to send email
    public function missedHoursDetails(Request $request)
    {
        try {
            $inputs = $request->all();
            $validation = $this->userTimesheetValidator->validateMissedHoursDetail($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $loopStart = $startDate = Carbon::parse($inputs['start_date'])->format('Y-m-d');
            $loopEnd = $endDate = Carbon::parse($inputs['end_date'])->format('Y-m-d');
            $employeeId = $inputs['employee_id'];

            $responseData = array();
            $count = 0;

            $timesheetData = UserTimesheet::select('date', DB::raw('SUM(working_hours) as working_hours'))
                ->whereEmployeeId($employeeId)
                ->whereBetween('date', [$startDate, $endDate])
                ->groupBy('date')
                ->orderBy('date')
                ->get();


            $joinDate = Employee::where('id', $employeeId)->select('join_date')->first();

            if (!empty($joinDate) && strtotime($loopStart) < strtotime($joinDate['join_date'])) {
                $loopStart = Carbon::parse($joinDate['join_date'])->format('Y-m-d');
            }

            $holidays = $this->getHoliday($loopStart, $loopEnd);
            $leaves = Leave::join('leave_details','leaves.id', 'leave_details.leave_id')
            ->whereBetween('leave_date', [$loopStart,$loopEnd,$employeeId])
            ->where('employee_id', $employeeId)->where('leaves.leave_status_id', LeaveStatus::APPROVE)
            ->whereNull('leave_details.deleted_at')->select('leave_details.leave_date','leave_details.day_duration_id',DB::raw('SUM(CASE WHEN leave_details.day_duration_id = '.DayDuration::FULLDAY . ' THEN 8 else 4 END) as leave_hours'))->groupBy('leave_details.leave_date')->get()->toArray();
            $compOffs = $this->getCompOffDays($loopStart, $loopEnd, $employeeId);
            $compOffDays = $compOffs->pluck('comp_off_date')->toArray();

            while (strtotime($loopStart) <= strtotime($loopEnd)) {
                $details = $timesheetData->firstWhere('date', $loopStart);

                $leave = array_filter($leaves, function ($leave) use ($loopStart) {
                    if ($leave['leave_date'] == $loopStart) {
                        return $leave;
                    }
                });

                $leaveHours = 0;
                if(!empty($leave) && count($leave) > 0){
                    $leave = array_values($leave);
                    $leaveHours = $leave[0]['leave_hours'];
                }
               
                if ((in_array($loopStart, $compOffDays) || !Carbon::parse($loopStart)->isWeekend()) || !empty($details)) {
            
                    $filledHours = !empty($details) ? $details->working_hours : 0;
                    $maxFilledHours = !empty($details) ? $details->working_hours : 0;
                    $extraFilledHours = $filledHours - $maxFilledHours;
                   

                    $totalMissedHours = ($this->workingHoursPerDay - ($maxFilledHours + $leaveHours) >= 0) ? $this->workingHoursPerDay - ($maxFilledHours + $leaveHours) : 0;

                    $compOffHours = $this->getCompOffTotalMissedHours($loopStart, $loopStart,$employeeId);
                    if(!empty($compOffHours['total_hours'])){
        
                        if($compOffHours['missed_hours'] >= 0){
                       
                            $totalMissedHours = $compOffHours['missed_hours'];
                         }
                    }

                    $responseData[$count]['date'] = $loopStart;
                    $responseData[$count]['filled_hours'] = $filledHours;
                    $responseData[$count]['extra_filled_hours'] = $extraFilledHours;
                    $responseData[$count]['leave_hours'] = $leaveHours;
                    $responseData[$count]['missed_hours'] = $totalMissedHours;
                }

                $count++;
                $loopStart = date("Y-m-d", strtotime("+1 days", strtotime($loopStart)));
            }

            foreach ($responseData as $key => $value) {
                if ($value['missed_hours'] == 0 || ((!in_array($value['date'], $compOffDays) && in_array($value['date'], $holidays)))) {
                    unset($responseData[$key]);
                }
            }

            $responseData = collect($responseData)->values();

            return $this->sendSuccessResponse(__('messages.success'), 200, $responseData);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while get admin timesheet missed hours report";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Send email for missed hours
    public function timesheetDetailMail(Request $request)
    {
        try {
            $inputs = $request->all();
            $empId = $inputs['employee'];

            $employee = Employee::select('id', 'display_name')->where('id', $empId)->first();

            $mailData = array();
            foreach ($inputs['dates'] as $key => $record) {

                $date = $record['date'];

                $getUserData = UserTimesheet::select(DB::raw('SUM(working_hours) as working_hours'))->where('employee_id', $empId)->where('date', $date)->groupBy('date')->first();

                $dateList = date('d-m-Y', strtotime($date));

                $mailData[$key]['date'] = $dateList;
                if ($getUserData == null || $getUserData == '') {
                    $mailData[$key]['hrs'] = 0;
                } else {
                    $mailData[$key]['hrs'] = $getUserData['working_hours'];
                }
            }

            $user = User::where('entity_id', $empId)->first(['email']);

            $info = ['employee_name' => $employee->display_name, 'data' => $mailData];

            $data = new TimesheetMissingHrs($info);

            $emailData = ['email' => $user['email'], 'email_data' => $data];

            SendEmailJob::dispatch($emailData);

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while missed hours send email to employee";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Admin timesheet report
    public function adminTimesheetReport(Request $request)
    {
        try {
            $user = Auth::user();
            $roles = $user->roles;
            $allRoles =  collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $employeeId = $request->employee_id;
            if (empty($employeeId) && !in_array('administrator', $allRoles)) {
                $employeeId = $user->entity_id;
            }

            $organizationId = $this->getCurrentOrganizationId();

            $validation = $this->userTimesheetValidator->validateTimesheet($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $projectId = $request->project;
            $customerId = $request->customer_id;
            $fromDate = date('Y-m-d', strtotime($request->start_date));
            $toDate = date('Y-m-d', strtotime($request->end_date));

            $timesheetQuery = UserTimesheet::withoutGlobalScopes([OrganizationScope::class])->join('projects', 'user_timesheets.project_id', 'projects.id')
                ->join('employees', function ($join) {
                    $join->on('user_timesheets.employee_id', '=',  'employees.id');
                    $join->on('employees.organization_id', '=', 'user_timesheets.organization_id');
                })
                ->whereBetween('user_timesheets.date', [$fromDate, $toDate]);

            if (!empty($projectId)) {
                $project = Project::where('uuid', $projectId)->first('id');
                $projectId = $project->id;
                $timesheetQuery->where('user_timesheets.project_id', $projectId);
            }
            if (!empty($request->employee_id)) {
                $timesheetQuery->where('user_timesheets.employee_id', $employeeId);
            }

            if(!empty($customerId)) {
                $timesheetQuery->where('projects.customer_id', $customerId);
            }

            $timesheetQuery->where('user_timesheets.organization_id', $organizationId);

            $timesheetQuery->select(
                    'projects.name as project_name',
                    'user_timesheets.note as notes',
                    'user_timesheets.working_hours as hours',
                    'user_timesheets.date',
                    'user_timesheets.employee_id'
                );

            if (!in_array('administrator', $allRoles)) {
                $timesheetQuery->join('project_employees', 'projects.id', 'project_employees.project_id')
                    ->where('project_employees.organization_id', $organizationId)
                    ->where('project_employees.id', '=', function($q) use ($employeeId)
                    {
                    $q->from('project_employees')
                        ->selectRaw('id')
                        ->whereRaw('project_id  = `projects`.`id`')
                        ->where('employee_id', $employeeId)->limit(1);
                    });
            }
           
            $summaryList = clone $timesheetQuery;
            $employeeSummaryList = clone $timesheetQuery;
            
            $timesheetList = $timesheetQuery->orderBy('user_timesheets.date','desc')->get();
            
            $entries = collect($timesheetList)->groupBy('employee_id');
         
            $emp = 0;
            $count = 0;
            $timesheetData = [];
            foreach ($entries as $employee => $entry) {
                $employeeData = Employee::where('id',$employee)->select('display_name')->first();
                $timesheetData[$count]['employee'] = $employeeData->display_name;

                foreach($entry as $record){
                   
                    $name = $record->project_name;
    
                    if (empty($record->notes)) {
                        $record->notes = "";
                    }
                
                    $record->name = $name;
                    // $entryDate = date('d M, Y', strtotime($record->date));
    
                    $hoursMin = $this->decimalToHoursMin($record->hours);
                    $hours = $hoursMin['hours'] . ' H ' . $hoursMin['minutes'] . ' M';
                    $record->hours = $hours;
                   
                    $timesheetData[$count]['data'][] = $record;
    
                    $emp++;
                }
               
               $count ++;
            }

            $summaryList->select(
                'projects.name as project_name',
                DB::raw('ROUND(SUM(user_timesheets.working_hours),2)  AS hours')
            );

            $summaryList = $summaryList->groupBy('user_timesheets.project_id')->orderBy('hours', 'desc')->get();


            //Employee summary list
            $employeeSummaryList->select(
                'employees.display_name',
                'employees.id as employee_id',
                DB::raw('ROUND(SUM(user_timesheets.working_hours),2)  AS spent_total_hours')
            )
                ->groupBy('user_timesheets.employee_id')->orderBy('spent_total_hours', 'desc');

            $employeeSummaryList = $employeeSummaryList->get();

            $response = [
                'summary' => $summaryList,
                'timesheet' => $timesheetData,
                'employee_summary' => $employeeSummaryList
            ];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while get timesheet report";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get hours report for employee when customer report filter
    public function getHoursReportByEmployee(Request $request)
    {
        try {
            $user = Auth::user();
            $roles = $user->roles;
            $allRoles =  collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $employeeId = $request->employee_id;
            
            $customerId = $request->customer_id;

            $organizationId = $this->getCurrentOrganizationId();
            $fromDate = date('Y-m-d', strtotime($request->start_date));
            $toDate = date('Y-m-d', strtotime($request->end_date));

            $timesheetQuery = UserTimesheet::withoutGlobalScopes([OrganizationScope::class])->join('projects', 'user_timesheets.project_id', 'projects.id')
                ->join('employees', function ($join) {
                    $join->on('user_timesheets.employee_id', '=',  'employees.id');
                    $join->on('employees.organization_id', '=', 'user_timesheets.organization_id');
                })
                ->whereBetween('user_timesheets.date', [$fromDate, $toDate]);


            if (!empty($employeeId)) {
                $timesheetQuery->where('user_timesheets.employee_id', $employeeId);
            }

            if(!empty($customerId)) {
                $timesheetQuery->where('projects.customer_id', $customerId);
            }

            if (!in_array('administrator', $allRoles)) {
                $timesheetQuery->join('project_employees', 'projects.id', 'project_employees.project_id')
                    ->where('project_employees.organization_id', $organizationId)
                    ->where('project_employees.id', '=', function($q) use ($user)
                    {
                    $q->from('project_employees')
                        ->selectRaw('id')
                        ->whereRaw('project_id  = `projects`.`id`')
                        ->where('employee_id', $user->entity_id)->limit(1);
                    });
            }

            $timesheetQuery->where('user_timesheets.organization_id', $organizationId);

            $timesheetQuery->orderBy('user_timesheets.created_at', 'desc');
      
            //Timesheet summary
            $timesheetQuery->select(
                'projects.name as project_name',
                DB::raw('ROUND(SUM(user_timesheets.working_hours),2)  AS hours'),
                'employees.display_name'
            );

            $summaryList = $timesheetQuery->groupBy('user_timesheets.project_id')->get();

            $response = [
                'project_summary' => $summaryList
            ];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while get timesheet report project summary";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Resource report for work
    public function employeeWorkReport(Request $request)
    {
        try {
            $departmentId = $request->department;
            $startDate = date('Y-m-d', strtotime($request->start_date));
            $endDate = date('Y-m-d', strtotime($request->end_date));
            $type = !empty($request->type) ? $request->type : '';
            $organizationId = $this->getCurrentOrganizationId();

            $holidays = $this->getHoliday($startDate, $endDate);

            $noOfDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
            $totalWorkingDays = $noOfDays - count($holidays);
            $totalWorkingHours = $totalWorkingDays * $this->workingHoursPerDay;

            $employeesQuery = Employee::withoutGlobalScopes([OrganizationScope::class])->active()->where('employees.do_not_required_punchinout', false);

            if (!empty($departmentId)) {
                $employeesQuery->where('employees.department_id', $departmentId);
            }
   
            if (!empty($type) && $type == "2") { //Check project basis type
                $employeesQuery->where('employees.working_on_dedicated_project' ,'!=', 1);
            }else if(!empty($type) && $type == "3"){ //Check dedicated emloyees
                $employeesQuery->where('employees.working_on_dedicated_project' , 1);
            }

            $employees = $employeesQuery->where('employees.organization_id', $organizationId)->select('employees.id', 'employees.on_bench', 'employees.working_on_dedicated_project', 'employees.display_name', 'employees.join_date', 'employees.department_id','employees.availability_comments','employees.first_name', 'employees.last_name','employees.avatar_url')->get();

            $hoursData = UserTimesheet::withoutGlobalScopes([OrganizationScope::class])->join('projects', 'user_timesheets.project_id', 'projects.id')
              ->where('user_timesheets.organization_id', $organizationId)
              ->whereBetween('date', [$startDate, $endDate])
              ->get(['working_hours','billing_hours','billable','date','timesheet_status_id', 'user_timesheets.employee_id']);

            $firstDate = $startDate;
            //$employees = $employees->map(function ($employee) use ($startDate, $endDate, $totalWorkingHours, $organizationId) {
            foreach($employees as $employee){
                $startDate = $firstDate;
                if (strtotime($startDate) < strtotime($employee['join_date'])) {
                    $startDate = Carbon::parse($employee['join_date'])->format('Y-m-d');
                }

                $userHoliday = array_filter($holidays, function ($holiday) use($startDate) {
                    return strtotime($holiday) > strtotime($startDate);
                });

                $leaveOffDays = $this->getLeaveDays($startDate, $endDate, $organizationId, $employee['id']);
               // $leaveOffDays = count($leaves['fullDay']) + (count($leaves['halfDay']) / 2);
        
                $noOfDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;
        
                $totalWorkingDays = $noOfDays - (count($userHoliday) + $leaveOffDays);
        
                $userTotalWorkingHour =   $totalWorkingDays * $this->workingHoursPerDay;

               // $userTotalWorkingHour = $this->getTotalHours($startDate, $endDate,$organizationId, $employee['id']);

               $billableHours = $hoursData->filter(function ($value) use($employee, $startDate) {
                    if($value->employee_id == $employee->id && $value->date >= $startDate && $value->billable == 1){
                        return $value;
                    }
                });

                $billableHoursData =  $billableHours->sum('working_hours');
                
                $nonBillableHours = $hoursData->filter(function ($value) use($employee, $startDate) {
                    if($value->employee_id == $employee->id && $value->date >= $startDate && $value->billable == 0){
                        return $value;
                    }
                });
                $nonBillableHoursData =  $nonBillableHours->sum('working_hours');

                // $subQuery = "user_timesheets.id IN ( select max(user_timesheets.id) FROM user_timesheets WHERE user_timesheets.employee_id = " . $employee['id'] . " and (user_timesheets.date between '" . $startDate . "' and '" . $endDate . "') group by user_timesheets.project_id )";
                $projectsData = UserTimesheet::withoutGlobalScopes([OrganizationScope::class])->join('projects', 'user_timesheets.project_id', 'projects.id')
                    ->whereBetween('date',[$startDate, $endDate])
                    ->where('employee_id', $employee['id'])
                    ->where('user_timesheets.organization_id', $organizationId)
                    ->groupBy('user_timesheets.project_id')
                    ->orderBy('user_timesheets.date', 'desc')
                    ->pluck('projects.name')
                    ->toArray();

                $billableHours = (!empty($billableHoursData)) ? $billableHoursData : 0;
                $nonBillableHours = (!empty($nonBillableHoursData)) ? $nonBillableHoursData : 0;
                $notFilledHours = $userTotalWorkingHour - ($billableHours + $nonBillableHours);

                $leaveHours = $leaveOffDays * $this->workingHoursPerDay;

                $leavePercentage = $this->get_percentage($totalWorkingHours, $leaveHours);
                $billablePercentage = $this->get_percentage(($totalWorkingHours - $leaveHours), $billableHours);

                $titleColor = "";
                if ($employee['on_bench']) {
                    $titleColor = "FFE5E5";
                    $color = "Available";
                    $color_order = 4;
                } else if ($employee['working_on_dedicated_project']) {
                    $titleColor = "EAF3FF";
                    $color = "Dedicated";
                    $color_order = 1;
                } elseif ($leavePercentage >= 50) {
                    $titleColor = "ff69b4";
                    $color = "On Leave";
                    $color_order = 5;
                } elseif ($billablePercentage >= 80) {
                    $titleColor = "E8FAF2";
                    $color = "Occupied";
                    $color_order = 2;
                } elseif ($billablePercentage >= 40 && $billablePercentage < 80) {
                    $titleColor = "f2f4d3";
                    $color = "Partially Available";
                    $color_order = 3;
                } else {
                    $titleColor = "FFE5E5";
                    $color = "Available";
                    $color_order = 4;
                }

                $employee['title_color'] = $titleColor;
                $employee['color'] = $color;
                $employee['color_order'] = $color_order;
                $employee['total_user_working_hours'] = $userTotalWorkingHour;
                $employee['billable_hours'] = $billableHours;
                $employee['non_billable_hours'] = $nonBillableHours;
                $employee['not_filled_hours'] = $notFilledHours > 0 ? $notFilledHours : 0;
                $employee['total_leaves'] = $leaveHours;
                $employee['worked_projects'] = implode(", ", $projectsData);
                
            }
            $employeeData = array_values(array_filter($employees->toArray()));
            usort($employeeData, array($this, "compareColorCode"));
            $data = collect($employeeData)->groupBy('department_id');
            $count = 0;
            $responseData = [];
            $departments = Department::where('organization_id',$organizationId)->pluck('name','id')->toArray();

            foreach ($data as $department => $entries) {
                if(!empty($departments[$department])){
                    $departmentData = $departments[$department];

                    if (!empty($departmentData)) {
                        $responseData[$count]['department'] = $departmentData;
                        $responseData[$count]['value'] = $entries;
    
                        $count++;
                        
                    }
                }
            }

            $response = [
                'data' => $responseData,
                'total_working_hours' => $totalWorkingHours
            ];
            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while get timesheet report";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Update employee status from resource report
    public function updateEmployeeData(Request $request)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();

            if (isset($inputs['on_bench'])) {
                Employee::where('id', $inputs['id'])->update(['on_bench' => $inputs['on_bench']]);
            }

            if (isset($inputs['working_on_dedicated_project'])) {
                Employee::where('id', $inputs['id'])->update(['working_on_dedicated_project' => $inputs['working_on_dedicated_project']]);
            }

            if (!empty($inputs['comment'])) {
                Employee::where('id', $inputs['id'])->update(['availability_comments' => $inputs['comment']]);
            }

            if(!empty($inputs['delete_comment'])) {
                Employee::where('id', $inputs['id'])->update(['availability_comments' => ""]);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while get timesheet report";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function importTimesheetData(Request $request)
    {
        DB::beginTransaction();
        try {

            $organizationId = $request->organization_id;

            $userTimesheets = DB::connection('old_connection')->table('user_timesheets')->get();

            if(!empty($userTimesheets)){
                foreach($userTimesheets as $timesheet){

                    $employee = DB::connection('old_connection')->table('employees')->where('id', $timesheet->employee_id)->first(['employee_id']);
                    $employeeId = $employee->employee_id;

                    $project = DB::connection('old_connection')->table('projects')->where('id', $timesheet->project_id)->first(['name']);
                    // print_r($project->name);
                    // echo '<br/>';
                    if (!empty($project)) {
                        $project = Project::where('name', 'LIKE', $project->name)->where('organization_id',$organizationId)->withTrashed()->first(['id']);
                    }
                   
                    $userTimesheet = UserTimesheet::where(['project_id' =>  $project , 'employee_id' =>  $employeeId, 'working_hours' => $timesheet->working_hours,'billing_hours' => $timesheet->billing_hours,'note' => $timesheet->note, 'organization_id' => $organizationId])->first();
                    if(!empty($userTimesheet)){
                        if(end($userTimesheets) == $timesheet) {
                            // last iteration
                            DB::commit();
                            return $this->sendSuccessResponse(__('messages.timesheet_imported'), 200);
                        }
                        continue;
                    }

                    $timesheetStatus = $timesheet->is_exported == 1 ? 3 : 1;
                    // echo '<pre>';
                    // print_r($project);
                    if(!empty($employeeId)){
                        UserTimesheet::create(
                            ['employee_id' => $employeeId, 'organization_id' => $organizationId, 'date' => $timesheet->date, 'project_id' => $project->id, 'note' => $timesheet->note, 'pm_note' => $timesheet->pm_note, 'working_hours' => $timesheet->working_hours, 'timesheet_status_id' => $timesheetStatus, 'billing_hours' => $timesheet->billing_hours, 'task_id' => $timesheet->task_id, 'deleted_at' => $timesheet->deleted_at]
                        );
                    }
                    
                }
            }
            
            ENDLoop:

            DB::commit();
            return $this->sendSuccessResponse(__('messages.timesheet_imported'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while timesheet imported";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
