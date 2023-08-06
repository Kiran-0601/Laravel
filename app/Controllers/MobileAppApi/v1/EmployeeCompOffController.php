<?php

namespace App\Http\Controllers\MobileAppApi\v1;



use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use App\Models\CompOff;
use App\Models\User;
use App\Jobs\SendEmailJob;
use App\Mail\ApplyCompOff;
use App\Mail\UpdateCompOffStatusMail;
use App\Models\CompensatoryOff;
use App\Models\CompensatoryOffStatus;
use App\Models\DayDuration;
use App\Validators\CompOffValidator;
use Carbon\Carbon;
use DB, Lang, Log, Auth;
use Illuminate\Http\Request;
use App\Models\Employee;
use App\Models\Scopes\OrganizationScope;


class EmployeeCompOffController extends Controller
{
    use ResponseTrait;

    private $compoffValidator;

    function __construct()
    {
        $this->compoffValidator = new CompoffValidator();
    }

    /**
     * Get Employee CompOff  BY  Employee ID, Month, Year
     *
     * @param Request $request
     * @return void
     */
    public function getEmployeeCompOff(Request $request) {
        try
        {
            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $year = !empty($inputs['year']) ? $inputs['year'] : date('Y');
            $status = isset($inputs['status']) && $inputs['status'] != null ? $inputs['status'] : 0;

            $perPage = $request->perPage ?? 10;
            $user = $request->user();
            $permissions = $user->getAllPermissions()->pluck('name')->toArray();

            $employeeId = !empty($inputs['employee_id']) ? $inputs['employee_id'] : $user->entity_id;

            $query = CompensatoryOff::withoutGlobalScopes([OrganizationScope::class])->join('employees', function ($join) use ($organizationId) {
                $join->on('compensatory_offs.employee_id', '=', 'employees.id');
                $join->where('employees.organization_id', $organizationId);
            })
            ->join('compensatory_off_statuses', 'compensatory_offs.compensatory_off_status_id', 'compensatory_off_statuses.id')
            ->join('day_durations', 'compensatory_offs.day_duration_id', 'day_durations.id')
                ->where('compensatory_offs.organization_id', $organizationId)
                ->whereYear('compensatory_offs.comp_off_date', $year)
                ->select(
                    'compensatory_offs.id',
                    'compensatory_offs.uuid',
                    'day_durations.duration',
                    'employees.display_name',
                    'comp_off_date',
                    'compensatory_offs.description',
                    'compensatory_off_statuses.name as compensatory_off_statuses_name',
                    'compensatory_offs.created_at as applied_date',
                );

            if (!in_array('manage_comp_off', $permissions)) {
                $query->where('compensatory_offs.employee_id', $employeeId);
            }

            if(!empty($request->start_date) && !empty($request->end_date)){
                $from_date = date('Y-m-d', strtotime($request->start_date));
                $to_date = date('Y-m-d', strtotime($request->end_date));
                $query->whereBetween('compensatory_offs.comp_off_date', [$from_date, $to_date]);
            }
            if ($status != 0) {
                $query->where('compensatory_offs.compensatory_off_status_id', $status);
            }
            $query = $query->where('compensatory_offs.organization_id', $organizationId)->groupBy('compensatory_offs.id');
            $total = $query->count();
           
            $data = $query->orderBy('compensatory_offs.comp_off_date')->orderBy('compensatory_off_statuses.id')->simplePaginate($perPage);
            $response = ['data' => $data, 'total_count' => $total];
            
            return $this->sendSuccessResponse(__('messages.success'),200,$response);
        }  catch (\Throwable $ex) {
            $logMessage = "Something went wrong while add holiday";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function applyCompOff(Request $request)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $validation = $this->compoffValidator->validateStore($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $user = Auth::user();
            $currentUserId = $request->user()->entity_id;
            $employeeId = !empty($inputs['employee_id']) ? $inputs['employee_id'] : $currentUserId;

            $holidayWeekends = $this->getHolidayAndWeekend($inputs['date'], $inputs['date']);

            if (!in_array($inputs['date'], $holidayWeekends)) {
                return $this->sendFailResponse(__('messages.weekend_holiday_date_required'), 422);
            }
            if ($inputs['duration'] == DayDuration::FULLDAY) {
                $durationName = DayDuration::FULLDAYNAME;
            } else {
                $durationName = DayDuration::HALFDAYNAME;
            }
            $compOffExist = CompensatoryOff::whereDate('comp_off_date', $inputs['date'])->where('employee_id', $employeeId)->whereIn('compensatory_off_status_id', [CompensatoryOffStatus::PENDING, CompensatoryOffStatus::APPROVE])->get(['compensatory_offs.id', 'day_duration_id']);

            foreach ($compOffExist as $val) {
                if ($val->day_duration_id == DayDuration::FULLDAY) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                } elseif ($val->day_duration_id == DayDuration::FIRSTHALF && $inputs['duration'] == DayDuration::FIRSTHALF) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                } elseif ($val->day_duration_id == DayDuration::SECONDHALF && $inputs['duration'] == DayDuration::SECONDHALF) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                } elseif (in_array($val->day_duration_id, [DayDuration::FIRSTHALF, DayDuration::SECONDHALF]) && $inputs['duration'] == DayDuration::FULLDAY) {
                    return $this->sendFailResponse(__('messages.already_applied'), 422);
                }
            }
            $compoff = CompensatoryOff::create([
                'uuid' => getUuid(),
                'employee_id' => $employeeId,
                'organization_id' => $organizationId,
                'day_duration_id' => $inputs['duration'],
                'description' => $inputs['description'],
                'compensatory_off_status_id' => CompensatoryOffStatus::PENDING,
                'comp_off_date' => $inputs['date']
            ]);

            $adminUsers = $this->getAdminUser();
            $info = ['employee_name' => $user->display_name, 'date' => $compoff->comp_off_date, 'description' => $compoff->description, 'duration' => $durationName, 'compoff_id' => $compoff->uuid];
           
            $data = new ApplyCompOff($info);
            $emailData = ['email' => $adminUsers, 'email_data' => $data];
          
            SendEmailJob::dispatch($emailData);
            $hrUsers = $this->getHRUser();
            $emailData = ['email' => $hrUsers, 'email_data' => $data];
            DB::commit();
            return $this->sendSuccessResponse(__('messages.success'), 200, $compoff);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add comp off";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

     /**
     * Get employee future comp off
     *
     * 
     * 
     */
    public function getEmployeeFutureCompOff()
    {
        try{
            $startDate = Carbon::tomorrow()->format('Y-m-d');
            $endDate = Carbon::now()->addWeek()->format('Y-m-d');

            $futureCompOff = CompensatoryOff::join('compensatory_off_statuses', 'compensatory_offs.compensatory_off_status_id', 'compensatory_off_statuses.id')
                ->join('day_durations','compensatory_offs.day_duration_id','day_durations.id')
                ->whereBetween('compensatory_offs.comp_off_date', [$startDate, $endDate])
                ->first(['compensatory_offs.id','uuid','employee_id','day_durations.duration','description','comp_off_date', 'remarks', 'cancel_remarks', 'compensatory_off_statuses.name']);

            return $this->sendSuccessResponse(__('messages.success'),200,$futureCompOff);
        }  catch (\Throwable $ex) {
            $logMessage = "Something went wrong while list compensatory request";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    /**
     * Cancel comp off by employee
     *
     * 
     */
    public function cancelEmployeeCompOff(Request $request){
        try {
            DB::beginTransaction();
            $inputs = $request->all();

            $compOffId = $inputs['id'];
            $status = $inputs['status'] ?? CompensatoryOffStatus::CANCEL;
            $comment = $inputs['comment'];
            $userId = Auth::user()->id;
            
            $compOff = CompensatoryOff::where('uuid', $compOffId)->first(['id', 'employee_id', 'description', 'comp_off_date', 'day_duration_id']);
            
            CompensatoryOff::where('uuid', $compOffId)->update(['compensatory_off_status_id' => CompensatoryOffStatus::CANCEL, 'cancel_remarks' => $comment, 'action_date' => getDateTime(), 'action_by_id' => $userId]);
    
            $adminUsers = $this->getAdminUser();

            $info['comp_off_date'] = $compOff->comp_off_date;
            $data = new UpdateCompOffStatusMail($info);

            $emailData = ['email' => $adminUsers, 'email_data' => $data];

            SendEmailJob::dispatch($emailData);

            $hrUsers = $this->getHRUser();

            if (!empty($hrUsers)) {
                $emailData = ['email' => $hrUsers, 'email_data' => $data];
                SendEmailJob::dispatch($emailData);
            }
            DB::commit();
            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update comp off status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
