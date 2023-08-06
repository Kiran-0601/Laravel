<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\PunchInOutJob;
use App\Models\Attendance;
use App\Models\DayDuration;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveStatus;
use App\Models\OrganizationSetting;
use App\Models\Setting;
use App\Traits\ResponseTrait;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Auth;

class AttendanceController extends Controller
{

    use ResponseTrait;

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
    /* User Total Working Hours */
    public function getTotalHours($startDate, $endDate, $organizationId, $userId = 0, $isCurrentUser = 0)
    {
        $holidays = $this->getHoliday($startDate, $endDate, $isCurrentUser);

        $leaveOffDays = $this->getLeaveDays($startDate, $endDate, $organizationId, $userId);
        // $leaveOffDays = count($leaves['fullDay']) + (count($leaves['halfDay']) / 2);

        $noOfDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;

        $totalWorkingDays = $noOfDays - (count($holidays) + $leaveOffDays);
        $totalWorkingHours = $this->getSettings();

        return $totalWorkingDays * $totalWorkingHours;
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
  
    public function checkInOut(Request $request)
    {
        DB::beginTransaction();

        try {
            $inputs = $request->all();
            $ip = $request->ip();
            $employeeId = $inputs['employee'];
            $organizationId = $this->getCurrentOrganizationId();

            $lat = !empty($inputs['latitude']) ? $inputs['latitude'] : null;
            $lng = !empty($inputs['longitude']) ? $inputs['longitude'] : null;

            $dateTime = getDateTime();
            $employee = Employee::where('id', $employeeId)->where('organization_id', $organizationId)->first(['display_name', 'do_not_required_punchinout']);
            $response = [];

            $attendence = Attendance::where('employee_id', $employeeId)->where('organization_id', $organizationId)
                ->whereDate('punch_in', getUtcDate('Y-m-d'))
                ->first();

            if (!empty($attendence) && $inputs['check_in'] === false && empty($attendence['punch_out'])) {
                $attendanceData = [
                    'organization_id' => $organizationId,
                    'punch_out' => $dateTime,
                    'punch_out_lat' => $lat,
                    'punch_out_lng' => $lng,
                    'punch_out_ip' => $ip
                ];
                $attendence->update($attendanceData);
                $attendanceData['action'] = 'out';

                $message = __('messages.checkout_success');

                $response = ['punch_out_time' => convertUTCTimeToUserTime($dateTime, 'Y-m-d h:i a')];
            }

            if (empty($attendence) && $inputs['check_in'] === true) {
                $attendanceData = [
                    'employee_id' => $employeeId,
                    'organization_id' => $organizationId,
                    'punch_in' => $dateTime,
                    'punch_in_lat' => $lat,
                    'punch_in_lng' => $lng,
                    'punch_in_ip' => $ip
                ];
                $attendence = Attendance::firstOrCreate($attendanceData);
                $attendanceData['action'] = 'in';

                $response = [
                    'punch_in_time' => convertUTCTimeToUserTime($dateTime),
                    'punch_in_status' => true
                ];
                $message = __('messages.checkin_success');
            }

            $attendanceData['employee_name'] = $employee->display_name;
            if($employee->do_not_required_punchinout == false){

                PunchInOutJob::dispatch($attendanceData);
            }
            DB::commit();

            return $this->sendSuccessResponse($message, 200, $response);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while check in and check out";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function user_in_out_summary(Request $request)
    {
        $inputs = $request->all();
        $employeeId = isset($inputs['employee']) ? $inputs['employee'] :  Auth::user()->entity_id;
        $message = __('messages.attendence_report');
        $organizationId = $this->getCurrentOrganizationId();
        try {

            $attendence = Attendance::select('*', DB::raw('SUM((time_to_sec(timediff(`punch_out`, `punch_in` )) / 3600)) as total_hours'))->where('employee_id', $employeeId)->where('organization_id', $organizationId);
            $holidays = new Holiday();
            $leaves = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')
                ->where('employee_id', $employeeId)
                ->where('leaves.leave_status_id', LeaveStatus::APPROVE)
                ->whereNull('leave_details.deleted_at');

            if (isset($inputs['start_date']) && isset($inputs['end_date']) && !empty($inputs['start_date']) && !empty($inputs['end_date'])) {
                $startDate = $inputs['start_date'];
                $endDate = $inputs['end_date'];
                $attendence = $attendence->whereBetween('punch_in', [$startDate, $endDate]);
                $holidays = $holidays->whereBetween('date', [$startDate, $endDate]);
                $leaves = $leaves->whereBetween('leave_date', [$startDate, $endDate]);
            }

            $attendence = $attendence->groupBy(DB::raw('DATE(punch_in)'))->get();
            $leaves = $leaves->get(['leave_details.leave_date', 'leave_details.day_duration_id', 'leaves.leave_status_id']);
            $holidays = $holidays->get();
            $recordedHours = 0;
            $attendenceResponse = [];
            foreach ($attendence as $key => $value) {
                $punch_in = Carbon::parse($value->punch_in);
                $punch_out = $value->punch_out != null ? Carbon::parse($value->punch_out) : "";  
                $recordedHours += round($value->total_hours, 2);
                $value->date = $punch_in->format('Y-m-d');
                $value->punch_in = $punch_in  != null ? convertUTCTimeToUserTime($punch_in, 'H:i') : "";
                $value->punch_out = $punch_out  != null ?  convertUTCTimeToUserTime($punch_out, 'H:i') : "";  
                unset($value->total_hours);
                $attendenceResponse[$key] = $value;
            }

            $workingHours = $this->getTotalHours($startDate, $endDate, $organizationId, $employeeId, 0);
            $response['working_hours'] = $workingHours > 0 ? round($workingHours,1) : 0;
            $response['recorded_hours'] = round($recordedHours,1);
            $response['short_hours'] = ($recordedHours <= $workingHours) ? round($workingHours - $recordedHours, 1) : 0.0;
            $response['leave_summary'] =  $leaves;
            $response['holiday_summary'] = $holidays;
            $response['in_out_summary'] =  $attendenceResponse;

            return $this->sendSuccessResponse($message, 200, $response);
        } catch (\Throwable $th) {
            $logMessage = "Something went wrong while getting summary of in/out";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $th, $logMessage);
        }
    }

    //Display attendance of all users in the organization
    public  function attendanceReport(Request $request)
    {
        $inputs = $request->all();
        $organizationId = $this->getCurrentOrganizationId();
        $perPage = $request->perPage ?? 50;
        $message = __('messages.attendence_report');
        try {
            $currentDate = $inputs['date'];
            $startDate = $inputs['start_date'] ?? null;
            $endDate = $inputs['end_date'] ?? null;

            $attendence = Employee::withoutGlobalScopes()->active()->select('employees.id as employee_id', 'punch_in', 'punch_out', 'display_name as employee')
                ->leftJoin('attendances', function ($join) use ($organizationId, $currentDate, $startDate, $endDate) {
                    $join->on('attendances.employee_id', '=', 'employees.id');
                    $join->where('attendances.organization_id', $organizationId);
                    if (isset($startDate) && isset($endDate) && !empty($startDate) && !empty($endDate)) {
                        $join->where(DB::raw('DATE(punch_in)'), '>=', $startDate)->where(DB::raw('DATE(punch_in)'), '<=', $endDate);
                    } else {
                        $join->where(DB::raw('DATE(punch_in)'), $currentDate);
                    }
                })->where('employees.organization_id', $organizationId)->where('employees.do_not_required_punchinout', 0);

            if (!empty($inputs['employee']) && !empty($startDate)) {
                $attendence = $attendence->where('employee_id', $inputs['employee'])->where('join_date', '<=', $startDate);
            } else {
                $attendence = $attendence->whereDate('join_date', '<=', $currentDate);
            }

            $attendanceCountQuery = clone $attendence;
            $attendanceCount = $attendanceCountQuery->count();
            $attendence = $attendence->orderBy('entity_id', "ASC")->simplePaginate($perPage);

            $setting = Setting::where('key', 'send_late_punch_in_email_after_time')->first(['id']);
            $organizationSetting = OrganizationSetting::where('setting_id', $setting->id)->first(['value', 'id']);
            $latePunchIn = !empty($organizationSetting) ? $organizationSetting->value : 0;

            $setting = Setting::where('key', 'send_late_punch_out_email_after_time')->first(['id']);
            $organizationSetting = OrganizationSetting::where('setting_id', $setting->id)->first(['value', 'id']);
            $latePunchOut = !empty($organizationSetting) ? $organizationSetting->value : 0;

            $query = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')->where('organization_id', $organizationId);
            if (!empty($inputs['start_date'])) {

                $query->whereBetween('leave_date', [$startDate, $endDate]);
            } else {
                $query->where('leave_date', $currentDate);
            }

            $query->where('leave_status_id', LeaveStatus::APPROVE);

            if (!empty($inputs['employee'])) {
                $employeeInfo = Employee::where('id', $inputs['employee'])->first(['display_name', 'join_date']);
                $leave = $query->where('employee_id', $inputs['employee'])->get(['leave_date'])->pluck('leave_date')->toArray();
            } else {
                $leave = $query->get(['employee_id'])->pluck('employee_id')->toArray();
            }

            $loopDate = Carbon::parse($startDate);

            $holiday = $this->getHoliday($startDate, $endDate);
            while ($loopDate->format('Y-m-d') <= $endDate && $loopDate > $employeeInfo->join_date) {

                $formattedDate = $loopDate->format('Y-m-d');
                if (in_array($formattedDate, $holiday)) {
                    $loopDate->addDay();
                    continue;
                }

                $attendanceData = array_filter($attendence->items(), function ($item) use ($formattedDate) {
                    if (date('Y-m-d', strtotime($item->punch_in)) == $formattedDate) {
                        $item->date = $formattedDate;
                        return $item;
                    }
                });

                $data = new Employee;
                if (empty($attendanceData)) {
                    $data->date = $formattedDate;
                    $data->punch_in = '';
                    $data->punch_out = null;
                    $data->employee_id = $inputs['employee'];
                    $data->employee = $employeeInfo->display_name;
                    $attendence->push($data);
                    $attendanceCount = $attendanceCount + 1;
                }

                $loopDate->addDay();
            }
            $sortedResult = $attendence->getCollection()->sortBy('date')->values();
            $attendence->setCollection($sortedResult);

            foreach ($attendence as $key => $value) {
                $punch_in = $value->punch_in != null ? Carbon::parse($value->punch_in) : "";
                $punch_out = $value->punch_out != null ? Carbon::parse($value->punch_out) : "";

                $value->punch_in = !empty($punch_in) ? convertUTCTimeToUserTime($punch_in, 'H:i') : '';
                $value->punch_out = $value->punch_out != null ? convertUTCTimeToUserTime($punch_out, 'H:i') : null;

                $bgColor = "#fff";
                if (empty($value->punch_in) && empty($value->punch_out)) {
                    $bgColor = "#ffe5e5";
                }

                if ($value->punch_out >= $latePunchOut || $value->punch_in >= $latePunchIn) {
                    $bgColor = "#fff3cd";
                }

                if (!empty($inputs['employee'])) {
                    $value->date = !empty($punch_in) ? $punch_in->format('Y-m-d') : $value->date;
                    if (!empty($leave) && in_array($value->date, $leave)) {
                        $bgColor = "#eaf3ff";
                    }
                } else {
                    $value->date = !empty($punch_in) ? $punch_in->format('Y-m-d') : $currentDate;
                    if (!empty($leave) && in_array($value->employee_id, $leave)) {
                        $bgColor = "#eaf3ff";
                    }
                }
                $value->bgcolor = $bgColor;
            }

            $response['attendence_summary'] = $attendence;
            $response['attendence_count'] = $attendanceCount;
            return $this->sendSuccessResponse($message, 200, $response);
        } catch (\Throwable $th) {
            $logMessage = "Something went wrong while getting attendance report";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $th, $logMessage);
        }

    }
}
