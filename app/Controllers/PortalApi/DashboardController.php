<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Customer;
use App\Models\DayDuration;
use App\Models\Employee;
use App\Models\EmployeementType;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRole;
use App\Models\ProjectStatus;
use App\Models\Scopes\OrganizationScope;
use App\Traits\ResponseTrait;
use DB;
use Illuminate\Http\Request;
use Auth;
use Carbon\Carbon;
use App\Models\Attendance;
use App\Models\EntityType;
use App\Models\WFHApplication;
use App\Models\WFHStatus;

class DashboardController extends Controller
{
    use ResponseTrait;

    //admin dashboard
    public function adminDashboard(Request $request)
    {
        try {
            $organizationId = $this->getCurrentOrganizationId();
            // Total project count in current organization
            $archived = ProjectStatus::where('slug', ProjectStatus::ARCHIVED)->first('id');
            $archived = $archived->id;
            $projectCount = Project::where('status_id', "!=", $archived)->count();
            // Total employee count in current organization  
            $employeeCount = Employee::withoutGlobalScopes([OrganizationScope::class])->active()->where('employees.organization_id', $organizationId);
            //Active employees count
            $activeEmployeeCount = clone $employeeCount;
            $activeEmployeeCount =$activeEmployeeCount->count();
            $employeeCount=$employeeCount->where('employees.employeement_type_id', EmployeementType::PERMANENT)->count();
            // Total customer count in current organization
            $customerCount = Customer::count();

            $user = $request->user();
            $permissions = $user->getAllPermissions()->pluck('name')->toArray();

            $data = $this->getActivityDetails($user, $permissions);
            
            //Total present and absent employees count
            $currentDate=date('Y-m-d');
            $presentEmployeeCount = Attendance::whereRaw('DATE(attendances.created_at) = ' . '"' . $currentDate . '"')
                                                ->where('organization_id', $organizationId)
                                                ->whereNull('punch_out')
                                                ->count();
            $absentEmployeeCount =$activeEmployeeCount-$presentEmployeeCount;

            $data['total_projects']  = $projectCount;
            $data['total_customers'] = $customerCount;
            $data['total_employees'] = $employeeCount;
            $data['present_employee']= $presentEmployeeCount;
            $data['absent_employee'] = $absentEmployeeCount;

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while fetch dashboard data";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    public function employeeAttendance(Request $request)
    {
        try{
            $perPage = $request->perPage ?? '';
            $organizationId = $this->getCurrentOrganizationId();
            $currentDate=date('Y-m-d');
           
            $query = Employee::withoutGlobalScopes([OrganizationScope::class])->active()
            ->leftjoin('attendances', function ($join) use($currentDate) {
                $join->on('employees.id','attendances.employee_id')
                ->whereRaw('DATE(attendances.created_at) = ' . '"' . $currentDate . '"');
            })
            ->select('employees.id','employees.display_name','attendances.punch_in')
            ->where('employees.organization_id', $organizationId)
            ->whereNull('employees.deleted_at')
            ->where('users.entity_type_id', EntityType::Employee)  
            ->where('users.is_active', 1);


            $countQuery = clone $query;

            $totalCount = $countQuery->count();

            $employeeList = $query->orderBy('attendances.id', 'desc')
                                    ->groupBy('employees.id')
                                    ->simplePaginate($perPage);
           
           
            $employeeList->getCollection()->transform(function ($value) {
                if($value->punch_in){
                    $value->punch_in =convertUTCTimeToUserTime($value->punch_in, 'h:i a');
                }
                return $value;
            });

            $response = [
                'employees' => $employeeList,
                'total_count' => $totalCount,

            ];
            
            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while fetch employee attendance data";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }

    }


    public function getActivityDetails($user, $permissions = [])
    {
        // Login user organization id
        $organizationId = $this->getCurrentOrganizationId();

        //Get all employees who have a birthday today
        $todayBirthday = DB::select(DB::raw("SELECT display_name, first_name, last_name, avatar_url
                                            FROM  employees 
                                            JOIN users ON employees.id = users.entity_id AND employees.organization_id = users.organization_id
                                            WHERE  is_active = '1' AND users.organization_id = " . $organizationId . " AND
                                            MONTH(dob) = MONTH(NOW()) AND DAY(dob) = DAY(NOW())"));
        $path = config('constant.avatar');
        foreach ($todayBirthday as $value) {
            $value->avatar = null;
            if(!empty($value->avatar_url)){
                $value->avatar = getFullImagePath($path . '/' . $value->avatar_url);
            }
            
            $value->birth_date = getUtcDate('d M');
        }

        //Get all employees who have a birthday in a next 7 days
        $upcomingBirthday =  DB::select(DB::raw("SELECT display_name, dob, first_name, last_name, avatar_url
                                                FROM  employees 
                                                JOIN users ON employees.id = users.entity_id AND employees.organization_id = users.organization_id
                                                WHERE  is_active = '1' AND users.organization_id = " . $organizationId . " AND
                                                DATE_ADD(dob, INTERVAL YEAR(DATE_ADD(CURDATE(), INTERVAL 1 DAY))-YEAR(dob)+ IF(DAYOFYEAR(CURDATE()) > DAYOFYEAR(dob),1,0)YEAR) BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY MONTH(DOB), DAY(DOB)"));

        foreach ($upcomingBirthday as $value) {
            $value->avatar = null;
            if (!empty($value->avatar_url)) {
                $value->avatar = getFullImagePath($path . '/' . $value->avatar_url);
            }
            $date = date('d-m', strtotime($value->dob)) . '-' . date('Y');
            $value->birth_date = date('d M', strtotime($date));
        }
        //Get all employees who will complete three or five years in current organization

        $threeYearCompletion = Employee::withoutGlobalScopes()->select('display_name', 'avatar_url', 'join_date', 'first_name', 'last_name', DB::raw('join_date As emp_date'), DB::raw('YEAR(join_date) AS jd'), DB::raw('YEAR(DATE_ADD(CURDATE(), INTERVAL -5 YEAR)) AS test'))
            ->join('users', 'employees.id', '=', 'users.entity_id')
            ->where('is_active', '=', '1')
            ->where('employees.organization_id', $organizationId)
            ->whereMonth('join_date', '=', getUtcDate('m'))
            ->whereDay('join_date', '=', getUtcDate('d'))
            ->whereRaw('YEAR(join_date) = YEAR(DATE_ADD(CURDATE(), INTERVAL -3 YEAR))')
            ->groupBy('employees.id')
            ->get();

        $fiveYearCompletion = Employee::withoutGlobalScopes()->select('display_name', 'avatar_url', 'join_date', 'first_name', 'last_name', DB::raw('join_date As emp_date'), DB::raw('YEAR(join_date) AS jd'), DB::raw('YEAR(DATE_ADD(CURDATE(), INTERVAL -5 YEAR)) AS test'))
            ->join('users', 'employees.id', '=', 'users.entity_id')
            ->where('is_active', '=', '1')
            ->where('employees.organization_id', $organizationId)
            ->whereMonth('join_date', '=', getUtcDate('m'))
            ->whereDay('join_date', '=', getUtcDate('d'))
            ->whereRaw('YEAR(join_date) = YEAR(DATE_ADD(CURDATE(), INTERVAL -5 YEAR))')
            ->groupBy('employees.id')
            ->get();

        foreach ($threeYearCompletion as $value) {
            $value->avatar = getFullImagePath($value->avatar_url);
        }

        foreach ($fiveYearCompletion as  $value) {
            $value->avatar = getFullImagePath($value->avatar_url);
        }

        $date = Carbon::today()->subDays(30);
        $announcements = Announcement::withoutGlobalScopes([OrganizationScope::class])
                                    ->leftJoin('announcement_categories', 'announcements.announcement_category_id', 'announcement_categories.id')
                                    ->where('announcements.schedule_date','<=',Carbon::now())
                                    ->where('announcements.organization_id', $organizationId)
                                    ->orderBy('announcements.schedule_date', 'desc')
                                    ->get(['title', 'description', 'name', 'schedule_date', 'image','announcements.created_at','extra_info']);
        $path = config('constant.announcement_attachments');
        foreach($announcements as $announcement){
            if(!empty($announcement->image)){
                $announcement->image = getFullImagePath($path . '/' . $announcement->image);
            }

            if($announcement->name === 'Work anniversary'){
                $info = json_decode($announcement->extra_info);
                if (!empty($info)) {
                    $employeeId = $info->employee_id;
                    $employee = Employee::where('id', $employeeId)->first(['avatar_url']);
                    $path = config('constant.avatar');
                    $announcement->image = null;
                    if (!empty($employee->avatar_url)) {
                        $announcement->image = getFullImagePath($path . '/' . $employee->avatar_url);
                    }
                }
            }
        }

        $leaves = [];
        $wfhApplications = [];
        if(in_array('create_project', $permissions)){

            $roles = $user->roles;
            $allRoles = collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $startDate = Carbon::now()->format('Y-m-d');
            $endDate = Carbon::now()->format('Y-m-d');
            
            $leaves = $this->getLeaveDetails($startDate, $endDate, $organizationId, $allRoles, $user);

            $wfhApplications = $this->getWfhDetail($startDate, $endDate, $organizationId, $allRoles, $user);
        }
      
      
        $data['today_birthday'] =  $todayBirthday;
        $data['upcoming_birthday'] =  $upcomingBirthday;
        $data['three_year_completion'] =  $threeYearCompletion;
        $data['five_year_completion'] =  $fiveYearCompletion;
        $data['announcements'] = $announcements;
        $data['leaves'] = $leaves;
        $data['wfh'] = $wfhApplications;

        return $data;
    }


    //Employee dashboard
    public function userDashboard(Request $request)
    {
        try {
            $user = Auth::user();
            $entityID = $user->entity_id;
            // Login user organization id
            $organizationId = $this->getCurrentOrganizationId();
          
            $permissions = $user->getAllPermissions()->pluck('name')->toArray();

            $data = $this->getActivityDetails($user,$permissions);

            $roles = $user->roles;
            $allRoles = collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $archived = ProjectStatus::where('slug', ProjectStatus::ARCHIVED)->first('id');
            $archived = $archived->id;

            $projectData = Project::withoutGlobalScopes()
                ->leftJoin('user_timesheets', function ($join) use ($user, $organizationId) {
                    $join->on('projects.id', '=', 'user_timesheets.project_id');
                    $join->where('user_timesheets.employee_id', $user->entity_id);
                    $join->where('user_timesheets.organization_id', $organizationId);
                })
                ->whereNotIn('projects.status_id', [$archived])
                ->select('projects.name', 'projects.id', 'projects.uuid', 'projects.logo_url',DB::raw("MAX(user_timesheets.date) as last_worked"))
                ->where('projects.organization_id', $organizationId)
                ->where('projects.default_access_to_all_users',0)
                ->whereNull('projects.deleted_at');

            $projectData = $projectData->orderBy('last_worked', 'desc')->groupBy('projects.id');

            if (!in_array('administrator', $allRoles)) {
                $projectData = $projectData->join('project_employees', function ($join) use ($user, $organizationId) {
                    $join->on('projects.id', '=', 'project_employees.project_id');
                    $join->where('project_employees.project_role_id', ProjectRole::DEVELOPERANDQA);
                    $join->where('project_employees.employee_id', $user->entity_id);
                    $join->where('project_employees.organization_id', $organizationId);
                });
            }

            $projects = $projectData->orderby('projects.id', 'desc')->get();

            $default = Project::withoutGlobalScopes()
            ->leftJoin('user_timesheets', function ($join) use ($user, $organizationId) {
                $join->on('projects.id', '=', 'user_timesheets.project_id');
                $join->where('user_timesheets.employee_id', $user->entity_id);
                $join->where('user_timesheets.organization_id', $organizationId);
            })
            ->select('projects.name', 'projects.id', 'projects.uuid', 'projects.logo_url',DB::raw("MAX(user_timesheets.date) as last_worked"))
            ->where('projects.organization_id', $organizationId)
            ->where('projects.default_access_to_all_users', 1)
            ->whereNull('projects.deleted_at')
            ->orderBy('last_worked', 'desc')->groupBy('projects.id')->get();


            $projects = $projects->merge($default);
            $projects = $projects->sortByDesc('last_worked')->values();

            
            foreach ($projects as  $value) {
                if (!empty($value->logo_url)) {
                    $path = config('constant.project_logo');
                    $value->logo_url = getFullImagePath($path . '/' . $value->logo_url);
                }
            }

            $employee = Employee::where('id', $entityID)->where('organization_id', $organizationId)->first(['join_date']);
            $joinDate = $employee->join_date;
           
            //Display attendance of current user
            $previousWeek = strtotime("-1 week +1 day");
            $startWeek = strtotime("last sunday midnight",$previousWeek);
            $endWeek = strtotime("next saturday",$startWeek);
            $startDate = date("Y-m-d", strtotime('+ 1 day' , $startWeek));
            $endDate = date("Y-m-d",strtotime('+1 day', $endWeek));
           
            $fromDate = $startDate;

          
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
            $holidays = $this->getHoliday($fromDate, $endDate);
            $totalWorkingDays = $noOfDays - count($holidays);
            $WorkingHours = $this->getSettings();
            $totalworkingHours = $totalWorkingDays * $WorkingHours;
             
            $attendanceData = Employee::withoutGlobalScopes([OrganizationScope::class])->leftJoin('attendances','employees.id','attendances.employee_id')
                ->select('employees.id as employee_id','employees.display_name',DB::raw('SUM((time_to_sec(timediff(`punch_out`, `punch_in` )) / 3600)) as employeeTotalHours'))
                ->whereBetween(DB::raw('DATE(attendances.created_at)'),  [$startDate, $endDate])
                ->where('employees.id','=', $entityID)
                ->where('employees.organization_id', $organizationId)
                ->groupBy('attendances.employee_id')
                ->get()
                ->toArray();

            return response()->json($attendanceData);

            $leaves = $this->getLeaveDays($fromDate, $endDate, $entityID);
            

            $leaveOffHours = $leaves * 9.5;
            // foreach($leaves['halfDay'] as $halfLeave){
            //     if($halfLeave->day_duration_id == DayDuration::FIRSTHALF){
            //         $leaveOffHours = $leaveOffHours + 4.5;
            //     }else{
            //         $leaveOffHours = $leaveOffHours + 5;
            //     }
            // }
        
            $leaveHrs = $leaveOffHours;

            $totalHrsData = array();
                
            if(!empty($attendanceData)){
                $totalHrsData['totalRecordedHours'] = $attendanceData[0]['employeeTotalHours'];
            }else{
                $totalHrsData['totalRecordedHours'] = 0;
            }
                
            $recordedHours = ROUND($totalHrsData['totalRecordedHours'],1);
                
            $totalRecordedHours = ROUND($totalHrsData['totalRecordedHours'] + $leaveHrs,1);
            $sortHours = ROUND($totalworkingHours - $totalRecordedHours,1);

            if($sortHours < 0){
                $sortHours = "-";
            }

            $data['projects'] = $projects;
            $data['join_date'] = $joinDate;
            $data['working_hours'] = $totalworkingHours;
            $data['recorded_hours'] = $recordedHours;
            $data['shorts_hours'] = $sortHours;
            $data['start_date'] = $startDate;
            $data['end_date']=$endDate;

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while fetch dashboard data";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
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
     public function getLeaveDays($startDate, $endDate, $userId = 0)
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


     //Get upcoming leaves
     public function getUpcomingLeaves()
     {
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

     public function getLeaveDetails($startDate, $endDate, $organizationId, $allRoles, $user, $upcoming = false)
     {
        $query = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_details', 'leaves.id', 'leave_details.leave_id')->join('employees',function($join) use($organizationId){
            $join->on('leaves.employee_id','=','employees.id');
            $join->where('employees.organization_id', $organizationId);
        })->whereBetween('leave_date', [$startDate, $endDate]);

        if (!in_array('administrator', $allRoles)) {
            $query->join('project_employees', function ($join) use ($organizationId) {
                $join->on('employees.id', '=', 'project_employees.employee_id');
                $join->where('project_employees.organization_id', $organizationId);
            })->join('project_employees as projectManager', function ($join) use ($user, $organizationId) {
                $join->on('projectManager.project_id', '=', 'project_employees.project_id');
                $join->where('projectManager.employee_id', $user->entity_id);
                $join->where('projectManager.organization_id', $organizationId);
            })->groupBy('employees.id')->groupBy('leave_date');
        }
        $leaves = $query->where('leaves.organization_id', $organizationId)
                ->where('leaves.leave_status_id',LeaveStatus::APPROVE)
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

     //Get upcoming wfh
     public function getUpComingWfh()
     {
        try{
            $user = Auth::user();
            $roles = $user->roles;
            $allRoles = collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $organizationId = $this->getCurrentOrganizationId();

            $startDate = Carbon::tomorrow()->format('Y-m-d');
            $endDate = Carbon::now()->addWeek()->format('Y-m-d');
            
            $wfhApplications = $this->getWfhDetail($startDate, $endDate, $organizationId, $allRoles, $user, true);
            
            return $this->sendSuccessResponse(__('messages.success'), 200, $wfhApplications);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while fetch upcomming wfh data";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
     }


     //Get the wfh from database and update the data
     public function getWfhDetail($startDate, $endDate, $organizationId, $allRoles, $user, $upcoming = false)
     {
        $query = WFHApplication::withoutGlobalScopes([OrganizationScope::class])->join('wfh_application_details', 'wfh_applications.id', 'wfh_application_details.wfh_application_id')->join('employees',function($join) use($organizationId){
            $join->on('wfh_applications.employee_id','=','employees.id');
            $join->where('employees.organization_id', $organizationId);
        })->whereBetween('wfh_date', [$startDate, $endDate]);

        if (!in_array('administrator', $allRoles)) {
            $query->join('project_employees', function ($join) use ($organizationId) {
                $join->on('employees.id', '=', 'project_employees.employee_id');
                $join->where('project_employees.organization_id', $organizationId);
            })->join('project_employees as projectManager', function ($join) use ($user, $organizationId) {
                $join->on('projectManager.project_id', '=', 'project_employees.project_id');
                $join->where('projectManager.employee_id', $user->entity_id);
                $join->where('projectManager.organization_id', $organizationId);
            })->groupBy('employees.id')->groupBy('wfh_date');
        }
         $wfhApplications = $query->where('wfh_applications.organization_id',$organizationId)
          ->where('wfh_applications.wfh_status_id',WFHStatus::APPROVE)
          ->whereNull('wfh_application_details.deleted_at')
          ->select('employees.display_name','avatar_url','day_duration_id','wfh_date')
          ->orderBy('wfh_date')
          ->get();

        foreach($wfhApplications as $wfh){
            if(!empty($wfh->avatar_url)){
                $path = config('constant.avatar');
                $wfh->avatar = getFullImagePath($path . '/' . $wfh->avatar_url);
            }

            if ($wfh->day_duration_id == DayDuration::FULLDAY) {
                $wfh->day_duration = 'Full Day';
            } else if ($wfh->day_duration_id == DayDuration::FIRSTHALF) {
                $wfh->day_duration = 'First Half';
            } else{
                $wfh->day_duration = 'Second Half';
            }

            if($upcoming == true){
                if($wfh->wfh_date == $startDate){
                    $wfh->wfh_day = 'Tomorrow';
                }else{
                    $wfh->wfh_day = date('l', strtotime($wfh->wfh_date));
                }
            }else{
                if($wfh->wfh_date == $startDate){	
                    $wfh->wfh_day = 'Today';	
                }else{	
                    $wfh->wfh_day =  'Tomorrow';	
                }
            }
        }

        return $wfhApplications;
    }
}
