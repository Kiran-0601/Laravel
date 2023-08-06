<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\User;
use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use App\Models\DeviceDetail;
use DB, Log, Lang, Auth;
use Storage;
use DateTime;
use Exception;
use Illuminate\Http\Request;
use App\Jobs\SendEmailJob;
use App\Models\DayDuration;
use App\Models\LeaveStatus;
use App\Models\Scopes\OrganizationScope;


class DashboardController extends Controller
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
            $userInfo = Employee::select('avatar_url','join_date','display_name')->where('id', $entityID)->first();

            $punchIn = Attendance::where('employee_id', $user->entity_id)->whereDate('punch_in', $currentDate)->select('punch_in')->first();
            $punchIn = !empty($punchIn) ? date("H:i:a", strtotime($punchIn->punch_in)) : "0.0";
            $joinDate = $userInfo->join_date;
            $previousWeek = strtotime("-1 week +1 day");
            $startWeek = strtotime("last sunday midnight",$previousWeek);
            $endWeek = strtotime("next saturday",$startWeek);
            $fromDate = $startDate = date("Y-m-d", strtotime('+ 1 day' , $startWeek));
            $endDate = date("Y-m-d",strtotime('+1 day', $endWeek));
          
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
            $sortHours = $sortHours < 0 ? "-" : $sortHours;
           
            $totalHrsData['totalSortHours'] = $sortHours;
            $totalHrsData['totalWorkingHours'] = $totalworkingHours;
            $totalHrsData['startDate'] = $fromDate;
            $totalHrsData['endDate'] = $endDate;
            $data['inOutSummary'] = $totalHrsData;

            //Birthday List
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

            //Get all employees who have a birthday in 7 days
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

            //Three Year Completion
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
            
            //Five Year Completion
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

            $data['today_birthday'] =  $todayBirthday;
            $data['upcoming_birthday'] =  $upcomingBirthday;
            $data['three_completion'] =  $threeYearCompletion;
            $data['five_completion'] =  $fiveYearCompletion;
            $data['user_info'] = $userInfo;
            $data['punch_in'] = $punchIn;
            $data['notification_count'] = $unreadNotification;

            return $this->sendSuccessResponse(Lang::get('messages.success'), 200, $data);
        } catch (\Exception $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while getting app version";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

     /**
     * Update Device Token
     *
     * @param array $data
     * @param int $statusCode
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateDeviceToken(Request $request)
    {
        try{
            DB::beginTransaction();
            $inputs = $request->all();
            $device_id = $inputs['device_id'];
            $device_type = $inputs['device_type'];

            $deviceData = DeviceDetail::where('device_id',$device_id)->where('device_type',$device_type)->first(['id']);
            if(!empty($deviceData)){
                $data = ['device_token'=>$inputs['device_token']];
                DeviceDetail::where('id', $deviceData->id)->update($data);
            }
            DB::commit();
            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Exception $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while getting app version";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

}