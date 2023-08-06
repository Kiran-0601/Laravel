<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use App\Models\WorkFromHomeEmployee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\User;
use App\Models\Project;
use App\Models\Customer;
use App\Models\Attendance;
use App\Models\DeviceDetail;
use App\Models\UserRole;
use App\Models\Employee;
use App\Models\Role;
use Carbon\Carbon;
use DB, Log, Lang, Auth;
use Storage;
use DateTime;
use Exception;
use Illuminate\Http\Request;
use App\Jobs\SendEmailJob;
use App\Models\DayDuration;
use App\Models\EntityType;
use App\Models\Permission;
use App\Models\ProjectEmployee;
use App\Models\ProjectRole;
use App\Models\Scopes\OrganizationScope;
use App\Models\WFHApplication;
use App\Models\WFHStatus;

class AdminDashboardController extends Controller
{
    use ResponseTrait;

    public function index()
    {
        try {
            $user = Auth::user();
            $today = Carbon::now('Asia/Kolkata');
            $currentDate = $today->format('Y-m-d');

            $entityID = $user->entity_id;
            $organizationId = $this->getCurrentOrganizationId();

            $userInfo = Employee::select('avatar_url','join_date',DB::raw('CONCAT(employees.first_name," ", IFNULL(employees.last_name, "")) as display_name'))
            ->where('id', $entityID)
            ->first();

            $joinDate = $userInfo->join_date;

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
 
            $leaves = $this->getLeaveDays($fromDate, $endDate, $entityID);
          
            $leaveHrs = $leaves * $WorkingHours;

            $totalHrsData = array();
            if(!empty($attendanceData)){
                $totalHrsData['totalRecordedHours'] = $attendanceData[0]['employeeTotalHours'];
            }else{
                $totalHrsData['totalRecordedHours'] = 0;
            }
                
            $totalRecordedHours = ROUND($totalHrsData['totalRecordedHours'] + $leaveHrs,1);
            $sortHours = ROUND($totalworkingHours - $totalRecordedHours,1);
         
            if($sortHours < 0){
                $sortHours = "-";
            }
            $totalHrsData['totalSortHours'] = $sortHours;
            $totalHrsData['totalWorkingHours'] = $totalworkingHours;
            $totalHrsData['startDate'] = $fromDate;
            $totalHrsData['endDate'] = $endDate;
            $data['inOutSummary'] = $totalHrsData;

            //Birthday List

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

            //Year Completion
            $threeYearCompletion = Employee::withoutGlobalScopes()->select('display_name', 'avatar_url', 'join_date', 'first_name', 'last_name', DB::raw('join_date As emp_date'), DB::raw('YEAR(join_date) AS jd'), DB::raw('YEAR(DATE_ADD(CURDATE(), INTERVAL -5 YEAR)) AS test'))
                ->join('users', 'employees.id', '=', 'users.entity_id')
                ->where('is_active', '=', '1')
                ->where('employees.organization_id', $organizationId)
                ->whereMonth('join_date', '=', getUtcDate('m'))
                ->whereDay('join_date', '=', getUtcDate('d'))
                ->whereRaw('YEAR(join_date) = YEAR(DATE_ADD(CURDATE(), INTERVAL -3 YEAR))')
                ->groupBy('employees.id')
                ->get();

            foreach ($threeYearCompletion as $value) {
                $value->avatar = getFullImagePath($value->avatar_url);
            }
            
             $fiveYearCompletion = Employee::withoutGlobalScopes()->select('display_name', 'avatar_url', 'join_date', 'first_name', 'last_name', DB::raw('join_date As emp_date'), DB::raw('YEAR(join_date) AS jd'), DB::raw('YEAR(DATE_ADD(CURDATE(), INTERVAL -5 YEAR)) AS test'))
                ->join('users', 'employees.id', '=', 'users.entity_id')
                ->where('is_active', '=', '1')
                ->where('employees.organization_id', $organizationId)
                ->whereMonth('join_date', '=', getUtcDate('m'))
                ->whereDay('join_date', '=', getUtcDate('d'))
                ->whereRaw('YEAR(join_date) = YEAR(DATE_ADD(CURDATE(), INTERVAL -5 YEAR))')
                ->groupBy('employees.id')
                ->get();

            foreach ($fiveYearCompletion as  $value) {
                $value->avatar = getFullImagePath($value->avatar_url);
            }
          
            $uId = User::select('id')->find($user->id);
            $unreadNotification = $uId->unreadNotifications()->count();

            //Dashboard Count
            $count = [];
            $employeeCount = User::whereEntityTypeId(3)->whereIsActive(1)->pluck('entity_id')->count();
            $customerCount = Customer::WhereNull('deleted_at')->count();
            $projectCount = Project::WhereNull('deleted_at')->count();

            // Present Employee List

            $presentEmployeeList = Attendance::whereRaw('DATE(attendances.created_at) = ' . '"' . $currentDate . '"')
                ->where('organization_id', $organizationId)
                ->whereNull('punch_out')
                ->get();
            //$absentEmployeeCount =$employeeCount-$presentEmployeeCount;   //Absent Employee Count
            
            //Absent Employee List

            $absentEmployees = $this->absentEmployeeList($user->entity_id);
          
            // Work From Home Employee List
           
            $wfhEmployees = $this->wfhEmployeeList($user->entity_id);
            
            $data['presentEmployeeList'] = $presentEmployeeList;
            $data['absentEmployeeList'] = $absentEmployees;
            $data['wfhEmployeeList'] = $wfhEmployees;
            $data['projects'] = $projectCount;
            $data['customers'] = $customerCount;
            $data['employees'] = $employeeCount;
            $data['present'] = count($presentEmployeeList);
            $data['absent'] = $employeeCount-count($presentEmployeeList); 
            $data['today_birthday'] =  $todayBirthday;
            $data['upcoming_birthday'] =  $upcomingBirthday;
            $data['three_completion'] =  $threeYearCompletion;
            $data['five_completion'] =  $fiveYearCompletion;
            $data['user_info'] = $userInfo;
            $data['notification_count'] = $unreadNotification;

            return $this->sendSuccessResponse(Lang::get('messages.success'), 200, $data);
        }catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while fetch upcomming wfh data";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function absentEmployeeList($userId){
       
        $organizationId = $this->getCurrentOrganizationId();
        $user = Auth::user();
        $roles = $user->roles;
        $allRoles = collect($roles)->map(function ($value) {
            return $value->slug;
        })->toArray();

        $startDate = Carbon::now()->format('Y-m-d');
        $endDate = Carbon::now()->format('Y-m-d');

        $permissions = $user->getAllPermissions()->pluck('name')->toArray();

        $query = Leave::withoutGlobalScopes([OrganizationScope::class])->join('leave_details', 'leaves.id', 'leave_details.leave_id')->join('employees',function($join) use($organizationId){
            $join->on('leaves.employee_id','=','employees.id');
            $join->where('employees.organization_id', $organizationId);
            })->whereBetween('leave_date', [$startDate, $endDate]);

        if (!in_array('administrator', $allRoles) && !in_array('manage_leaves', $permissions)) {
            $projectIds = ProjectEmployee::where('employee_id', $userId)->where('organization_id',$organizationId)->get('project_id')->pluck('project_id');

            $query =  $query->join('project_employees', 'employees.id', 'project_employees.employee_id')
                ->whereIn('project_employees.project_id', $projectIds)
                ->where('project_employees.organization_id', $organizationId);
        }
     
        $leaves = $query->where('leaves.organization_id', $organizationId)
        ->whereNull('leave_details.deleted_at')
        ->select('employees.first_name','employees.last_name','avatar_url','day_duration_id','leave_date','employees.id')
        ->orderBy('leave_date')
        ->get();

        foreach($leaves as $leave){
            if(!empty($leave->avatar_url)){
                $path = config('constant.avatar');
                $leave->avatar = getFullImagePath($path . '/' . $leave->avatar_url);
            }
        }
        $absentEmployee = array();
        $absentEmployee = $leaves;
        return $absentEmployee;
    }

    public function wfhEmployeeList($userId){
     
        $organizationId = $this->getCurrentOrganizationId();
        $user = Auth::user();
        $roles = $user->roles;
        $allRoles = collect($roles)->map(function ($value) {
            return $value->slug;
        })->toArray();

        $startDate = Carbon::now()->format('Y-m-d');
        $endDate = Carbon::now()->format('Y-m-d');

        $permissions = $user->getAllPermissions()->pluck('name')->toArray();

        $wfhEmployeeList = array();

        $query = WFHApplication::withoutGlobalScopes([OrganizationScope::class])->join('wfh_application_details', 'wfh_applications.id', 'wfh_application_details.wfh_application_id')->join('employees',function($join) use($organizationId){
            $join->on('wfh_applications.employee_id','=','employees.id');
            $join->where('employees.organization_id', $organizationId);
            $join->where('wfh_applications.organization_id', $organizationId);
        })->whereBetween('wfh_date', [$startDate, $endDate]);

        if (!in_array('administrator', $allRoles) && !in_array('manage_leaves', $permissions)) {
            
            $projectIds = ProjectEmployee::where('employee_id', $userId)->where('organization_id',$organizationId)->get('project_id')->pluck('project_id');
          
            $query =  $query->join('project_employees', 'employees.id', 'project_employees.employee_id')
                ->whereIn('project_employees.project_id', $projectIds)
                ->where('project_employees.organization_id', $organizationId);
        }
        $wfhApplications = $query->where('wfh_applications.organization_id',$organizationId)
          ->where('wfh_applications.wfh_status_id',WFHStatus::APPROVE)
          ->whereNull('wfh_application_details.deleted_at')
          ->select('employees.display_name','avatar_url','day_duration_id','wfh_date')
          ->orderBy('wfh_application_details.wfh_date', 'asc')
          ->groupBy('employees.id')
          ->groupBy('wfh_application_details.wfh_date')
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
        }
        $wfhEmployeeList = $wfhApplications;
        return $wfhEmployeeList;
    }

    public function attendancePopUp(Request $request)
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
}
