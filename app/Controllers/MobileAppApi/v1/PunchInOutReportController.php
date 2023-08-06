<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Log;
use Lang;
use DB;
use Auth;
use Storage;
use DateTime;
use Carbon\Carbon;
use App\Models\User; 
use App\Models\CurrentJob; 
use App\Models\EntityType;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\Holiday;

class PunchInOutReportController extends Controller
{
    use ResponseTrait;
    private $hoursPerDay;
    private $hoursForHalfDay;
    function __construct()
    {
        $this->hoursPerDay = config('constants.totalhoursPerDay');
        $this->hoursForHalfDay = config('constants.hoursForHalfDay');
    }

    public function index(Request $request)
    {
        try{
            $inputs = $request->all();

            $s = new \DateTime($inputs['start_date']);
            $e = new \DateTime($inputs['end_date']);
            $filterHours = $inputs['filter_hours'];

            $loopStart = $start = Carbon::parse($inputs['start_date'])->format('Y-m-d');
            $loopEnd = $end = Carbon::parse($inputs['end_date'])->format('Y-m-d');

            $days = $s->diff($e, true)->days;
            $noOfSaturday = intval($days / 7) + ($s->format('N') + $days % 7 >= 6);
            $noOfSunday = intval($days / 7) + ($s->format('N') + $days % 7 >= 7);

            $WeekEndDays = $noOfSaturday + $noOfSunday;

            $noOfDays = Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;

            $holiDayList = Holiday::select(DB::raw('COUNT(date) as holidays_count'))
                            ->where('holiday_type_id','=',Holiday::PUBLIC_HOLIDAY)
                            ->whereBetween('date', [$loopStart, $loopEnd])
                            ->get()->toArray();

            $totalHolidays = $holiDayList[0]['holidays_count'] + $WeekEndDays;

            $workingDays = $noOfDays - $totalHolidays;
            $workingHours = $workingDays * $this->hoursPerDay;

            $leaveHours = Leave::select('employee_id', DB::raw('SUM(CASE 
                            WHEN (holiday_type = '.Leave::HALFLEAVE.' && duration = '.Leave::FIRSTHALF.') THEN '.Leave::first_half_total_hours.' 
                            WHEN (holiday_type = '.Leave::HALFLEAVE.' && duration = '.Leave::SECONDHALF.') THEN '.Leave::second_half_total_hours.' 
                            WHEN holiday_type = '.Leave::FULLLEAVE.' THEN '.Leave::full_day_total_hours.' 
                            ELSE 0 END) AS leave_hours'))
                        ->whereBetween('leave_date', [$start, $end])
                        ->where('status', config('constants.approve_status'));

                        if(!empty($inputs['employee_id'])){
                            $leaveHours = $leaveHours->where('employee_id','=', $inputs['employee_id']);
                        }

                        $leaveHours = $leaveHours->groupBy('employee_id');
                        $leaveHours = $leaveHours->get();

            $lvs = $leaveHours->groupBy('employee_id');

            $data = Employee::join('users','employees.id','users.id')
                    ->leftJoin('attendence','employees.id','attendence.employee_id')
                    ->select('employees.id as employee_id','employees.display_name',DB::raw('SUM((time_to_sec(timediff(`punch_out`, `punch_in` )) / 3600)) as employeeTotalHours'))
                    ->whereBetween(DB::raw('DATE(attendence.created_at)'),  [$loopStart, $loopEnd]);

                    if(!empty($inputs['employee_id'])){
                        $data = $data->where('employees.id','=', $inputs['employee_id']);
                    }

                    $data = $data->where('users.is_active','=',1);
                    $data = $data->groupBy('attendence.employee_id');
                    $data = $data->get();

            $res = array();
            $result = array();

            foreach ($data as $key => $value) {

                if(!empty($lvs[$value['employee_id']]) && (isset($lvs[$value['employee_id']]))){
                    $res[$key]['leaveHours'] = (float)$lvs[$value['employee_id']][0]->leave_hours;
                }else{
                    $res[$key]['leaveHours'] = 0;
                }
                  
                $res[$key]['employeeId'] = $value->employee_id;
                $res[$key]['displayName'] = $value->display_name;
                $res[$key]['employeeTotalHours'] = ROUND($value->employeeTotalHours,1);
                $empHours = $value->employeeTotalHours + $res[$key]['leaveHours'];
                $res[$key]['empHrsPlusLeaveHrs'] = ROUND($empHours,1);            
                $res[$key]['sortHours'] = ROUND($workingHours - $empHours,1);
                $result[] = $res[$key];
                
            }

            $finalData = array();
            $finalData = $result;

            if($filterHours != 0){
                foreach ($result as $key => $value) {
                    if($value['sortHours'] < $filterHours){
                        unset($finalData[$key]);
                    }
                }
            }

            $finalResult = $finalData;
            $finalResult = collect($finalResult)->sortByDesc('sortHours')->values();

            $response = [
                'reportList' =>  $finalResult,
                'workingHours' => $workingHours
            ];

            return $this->sendSuccessResponse(Lang::get('messages.in-out-summary.show'),200,$response);
        } catch (\Exception $e) {
            Log::info($e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    public function punchInOutReportPopUp(Request $request)
    {
        try {
            $inputs = $request->all();
            
            $result = [];
            $attendanceData =  User::with('employee');

            if (!empty($inputs['start_date']) && empty($inputs['end_date'])) {
                $start = $inputs['start_date'];
                $attendanceData = $attendanceData->leftjoin('attendence', function ($join) use ($start) {
                    $join->on('attendence.employee_id', '=', 'users.entity_id');
                    $join->where('attendence.punch_in', '>=', $start);
                });
            }
            if (!empty($inputs['end_date']) && empty($inputs['start_date'])) {
                $end = $inputs['end_date'];
                $attendanceData = $attendanceData->leftjoin('attendence', function ($join) use ($end) {
                    $join->on('attendence.employee_id', '=', 'users.entity_id');
                    $join->where('attendence.punch_out', '<=', $end);
                });
            }
            if (!empty($inputs['start_date']) && !empty($inputs['end_date'])) {
                $start = $inputs['start_date'];
                $end = $inputs['end_date'];
                $attendanceData = $attendanceData->leftjoin('attendence', function ($join) use ($start, $end) {
                    $join->on('attendence.employee_id', '=', 'users.entity_id');
                    $join->whereBetween(DB::raw('DATE(attendence.created_at)'),  [$start, $end]);    
                });
            }
           
            $attendanceData = $attendanceData->where('entity_id', $inputs['employee_id'])
                ->where('entity_type_id', EntityType::Employee)
                ->where('is_active', 1)
                ->orderBy('entity_id', "ASC");
            
            $attendanceData = $attendanceData->get([
                'users.*',
                'attendence.id as attendance_id',
                'attendence.punch_in',
                'attendence.punch_out',
                'attendence.punch_in_ip',
                'attendence.punch_out_ip',
                'attendence.punch_in_location',
                'attendence.punch_out_location'
            ])->toArray();

            foreach ($attendanceData as $key => &$val) {
                $empId = $val['employee']['id'];
                if (!empty($val['punch_in'])) {
                    $punchIn = date("H:i", strtotime($val['punch_in']));
                    $today_date = date("d-m-Y", strtotime($val['punch_in']));
                } else {
                    $today_date = $inputs['current_date'];
                    $punchIn = null;
                }
                if (!empty($val['punch_out'])) {
                    $punchOut = date("H:i", strtotime($val['punch_out']));
                } else {
                    $punchOut = null;
                }
                if(!empty($val['punch_in_ip']) && ($val['punch_in_ip']==config('constants.IP1') || $val['punch_in_ip']==config('constants.IP2')) ){
                        $in_bg_color = null;
                }else{
                    $in_bg_color = '#ffd740';;
                }
                if(!empty($val['punch_out_ip']) && ($val['punch_out_ip']==config('constants.IP1') || $val['punch_out_ip']==config('constants.IP2')) ){
                    $out_bg_color = null;
                }else{
                    $out_bg_color = '#ffd740';;
                }

                $val['punch_in'] = $punchIn;
                $val['punch_out'] = $punchOut;
                $val['in_bg_color'] = $in_bg_color;
                $val['out_bg_color'] = $out_bg_color;
                $val['today'] = $today_date;
                $result[] = $val;
            }

            $holiDayList = Holiday::select('date as holiday_date','name as holiday_name')
                ->where('holiday_type_id','=',Holiday::PUBLIC_HOLIDAY)
                ->whereBetween('date', [$inputs['start_date'], $inputs['end_date']])
                ->get();

            $leavesList = Leave::select( 'leave_date', 'holiday_type')
                ->where('employee_id','=',$inputs['employee_id'])
                ->whereBetween('leave_date', [$inputs['start_date'], $inputs['end_date']])->where('status', config('constants.approve_status'))->get();

            $response = [
                'data' => $result,
                'holidays' => $holiDayList,
                'leaves' => $leavesList,
                'message' => 'success',
            ];

            return $this->sendSuccessResponse(Lang::get('messages.in-out-summary.show'),200,$response);
        } catch (\Exception $e) {
            Log::info($e);
            $response['message'] = $e->getMessage();
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }
}