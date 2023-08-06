<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use App\Models\EntityType;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\LeaveStatus;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use Auth, DB, Log, Lang;
use Storage;
use DateTime;
use Carbon\Carbon;
 
class UserPunchInOutReportController extends Controller
{
    use ResponseTrait;

    public function getTotalHours($startDate, $endDate, $organizationId, $userId = 0, $isCurrentUser = 0)
    {
        $holidays = $this->getHoliday($startDate, $endDate, $isCurrentUser);

        $leaveOffDays = $this->getLeaveDays($startDate, $endDate, $organizationId, $userId);

        $noOfDays = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;

        $totalWorkingDays = $noOfDays - (count($holidays) + $leaveOffDays);
        $totalWorkingHours = $this->getSettings();

        return $totalWorkingDays * $totalWorkingHours;
    }

    public function punchInOutSummary(Request $request)
    {
        try {            
            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $user = Auth::user();
            $employeeId = $user->entity_id;

            $attendence = Attendance::select('punch_in','punch_out',DB::raw('SUM((time_to_sec(timediff(`punch_out`, `punch_in` )) / 3600)) as total_hours'))->where('employee_id', $employeeId)->where('organization_id', $organizationId);
            $holidays = new Holiday();
            $leaves = Leave::join('leave_details', 'leaves.id', 'leave_details.leave_id')
                ->where('employee_id', $employeeId)
                ->where('leaves.leave_status_id', LeaveStatus::APPROVE)
                ->whereNull('leave_details.deleted_at');
                 
            if (isset($inputs['start_date']) && isset($inputs['end_date']) && !empty($inputs['start_date']) && !empty($inputs['end_date'])) {
                $startDate = Carbon::parse($inputs['start_date'])->format('Y-m-d');
                $endDate = Carbon::parse($inputs['end_date'])->format('Y-m-d');
                $attendence = $attendence->whereBetween('punch_in', [$startDate, $endDate]);
                $holidays = $holidays->whereBetween('date', [$startDate, $endDate]);
                $leaves = $leaves->whereBetween('leave_details.leave_date', [$startDate, $endDate]);
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

            $response = [
                'leave_summary' => $leaves,
                'holiday_summary' => $holidays,
                'in_out_summary' => $attendenceResponse
            ];
            return $this->sendSuccessResponse(Lang::get('messages.success'),200,$response);
        } catch (\Exception $ex) {
            $logMessage = "Something went wrong while getting app version";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
