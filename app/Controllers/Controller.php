<?php

namespace App\Http\Controllers;

use App\Models\DayDuration;
use App\Models\EmailNotification;
use App\Models\Employee;
use App\Models\ExceptionalWorkingDay;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveStatus;
use App\Models\OrganizationSetting;
use App\Models\OrganizationWeekend;
use App\Models\Role;
use App\Models\User;
use Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Log;
use Carbon\Carbon;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    function getCurrentUser()
    {
        $user = Auth::user();

        return $user;
    }

    function getCurrentOrganizationId()
    {
        $user = Auth::user();

        $organizationId = '';

        if (!empty($user)) {
            $organizationId = $user->organization_id;
        }
        return $organizationId;
    }

    function getUserRolePermissions($user)
    {
        try {
            if (!empty($user)) {
                $roles = $user->roles;

                $allRoles =  collect($roles)->map(function ($value) {
                    return $value->slug;
                })->toArray();

                $permissions = $user->getAllPermissions();

                $permissions =  collect($permissions)->map(function ($value) {
                    return $value->name;
                })->toArray();

                $user->user_roles = $allRoles;
                $user->permission = $permissions;
            }

            return $user;
        } catch (\Throwable $ex) {
            Log::error($ex);
        }
    }

    function getAdminUser($permission = ''){
        $organizationId = $this->getCurrentOrganizationId();

        if(!empty($organizationId)){
            $role = Role::where('organization_id', $organizationId)->where('slug', 'administrator')->first();
            $users = User::role($role->id)->get(['email', 'entity_id','organization_id','id']);

            $userIds = $users->pluck('id')->toArray();
            $adminUsers = $users->toArray();

            if(empty($permission)){
                return $adminUsers;
            }
            $notifications = EmailNotification::whereIn('user_id',$userIds)->get(['allow_all_notifications',$permission, 'user_id'])->toArray();
     
            $emailUsers = [];
            $emailUsers = array_map(function($user, $notification) use($emailUsers, $permission){

                if($user['id'] == $notification['user_id'] && $notification['allow_all_notifications'] == true && $notification[$permission] == true){
                     array_push($emailUsers, $user);
                }

                return $emailUsers;

            }, $adminUsers, $notifications);

            $emailUsers = array_values(array_filter($emailUsers)); 
            if(!empty($emailUsers)){
                $emailUsers = collect($emailUsers[0]);
            }
        }

        return $emailUsers;
    }

    function getHRUser($permission = ''){
        $organizationId = $this->getCurrentOrganizationId();

        if(!empty($organizationId)){
            $role = Role::where('organization_id', $organizationId)->where('slug', 'hr')->first();
            $users = User::role($role->id)->get(['email', 'entity_id','organization_id','id']);

            $userIds = $users->pluck('id')->toArray();
            $hrUsers = $users->toArray();

            if(empty($permission)){
                return $hrUsers;
            }
            $notifications = EmailNotification::whereIn('user_id',$userIds)->get(['allow_all_notifications',$permission, 'user_id'])->toArray();
     
            $emailUsers = [];
            $emailUsers = array_map(function($user, $notification) use($emailUsers, $permission){

                if($user['id'] == $notification['user_id'] && $notification['allow_all_notifications'] == true && $notification[$permission] == true){
                     array_push($emailUsers, $user);
                }

                return $emailUsers;

            }, $hrUsers, $notifications);

            $emailUsers = array_values(array_filter($emailUsers)); 
            if(!empty($emailUsers)){
                $emailUsers = collect($emailUsers[0]);
            }
        }

        return $emailUsers;
    }

    function getWeekendDays($startDate, $endDate)
    {
        $day_add = 86400;
        $return = [];
        $dates = [];

        $startDate = strtotime($startDate);
        $endDate = strtotime($endDate);

        if ($startDate > $endDate)
            return false;

        $organizationId = $this->getCurrentOrganizationId();
        $weekends = OrganizationWeekend::where('organization_id', $organizationId)->select('week_day')->get()->pluck('week_day')->toArray();
        // if(empty($weekends)){
        //     $weekends = [6,7];
        // }

        do {
            $weekDay = date('N', $startDate); //ISO-8601 numeric representation of the day of the week (added in PHP 5.1.0)
            if (in_array($weekDay, $weekends))
                $return[] = $startDate;

            $startDate = $startDate + $day_add;
        } while ($endDate > $startDate - $day_add);

        foreach ($return as $day) {
            $dates[] = date('Y-m-d', $day);
        }

        return $dates;
    }

    public function getHolidayAndWeekend($startDate, $endDate)
    {
        $query = Holiday::whereBetween('date', [$startDate, $endDate]);
        $holiday = $query->select('date')->pluck('date')->toArray();
        $weekends = $this->getWeekendDays($startDate, $endDate);

        if (is_array($weekends)) {
            $holiday = array_unique(array_merge($holiday, $weekends));
        }
        $workingDays = ExceptionalWorkingDay::whereBetween('date', [$startDate, $endDate]);
        $days = $workingDays->select('date')->pluck('date')->toArray();

        $holiday = array_filter($holiday, function($key) use($days){
            return !in_array($key, $days);
        });
        
        $holiday = array_values($holiday);

     
        return $holiday;
    }
    function getSettings(){
        $setting = OrganizationSetting::with('setting')->whereHas('setting', function ($subQuery) {
            $subQuery->where('settings.key','LIKE','office_timing_%');
        })->get();


        $totalHours = 0;
        if(!empty($setting)){
            $startTime = $setting[0]->value;
            $endTime = $setting[1]->value;
            //To check if the time is in night shift then two days are different for example: 20:00 to 4:00
            if($startTime > $endTime){
                $startHour = explode(':',$startTime);
                $endHour = explode(':',$endTime);
                $startHr = 24 - $startHour[0];
                $startMin = !empty($startHour[1]) ? 60 -  $startHour[1] : 0;
                $endHr = $endHour[0];
                $endMin = !empty($endHour[1]) ? 60 - $endHour[1] : 0;
                $totalHours = $startHr + $endHr;
               
                $totalMins = $startMin + $endMin;
                if($totalMins >= 60){
                    $remainingMin = 60 - $totalMins;
                    $totalHours = $totalHours + 1;
                    
                }
                $totalMins = $totalHours * 60 + $remainingMin;
            }else{
                //For day time calculation for example 10:00 to 7:00
                $startTime = Carbon::parse($setting[0]->value);
                $endTime =  Carbon::parse($setting[1]->value);
    
                $totalMins = $startTime->diffInMinutes($endTime);
            }

            $totalHours = round($totalMins / 60,2);
        }

        return $totalHours;
    }
    public function getHoliday($startDate, $endDate, $isCurrentUser = 0)
    {
        $employeeId = Auth::user()->entity_id;
        $employeeJoinDate = Employee::where('id', $employeeId)->select('join_date')->first();
        if ($startDate == null && $endDate == null) {
            $holidays = Holiday::get();
            return $holidays;
        }
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

    
     
}
