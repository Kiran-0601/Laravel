<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Leave;
use App\Models\Holiday;
use App\Models\CompOff;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\LeaveDeatil;
use App\Models\DeviceDetail;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use Maatwebsite\Excel\Facades\Excel;
use App\Notifications\PushNotifications;
use App\Validators\LeaveValidator;
use App\Jobs\SendEmailJob;
use Log, Lang, DB, Auth;
use Carbon\Carbon;
use Validator;
use Storage;
use PDF;

class LeaveController extends Controller
{
    use ResponseTrait;
    private $holidayList;
  
    function __construct()
    {
        $this->holidayList = $this->getHolidayList();
    }

    /**
     * Get Recent Leaves
     *
     * 
     */
    public function getRecentLeaves()
    {
        try{
            $approvedLeaves = Leave::join('leave_types','leaves.leave_type_id', '=', 'leave_types.id')
                            ->join('employees','leaves.employee_id', '=', 'employees.id')
                            ->join('users','employees.id', '=', 'users.id')
                            ->select('leaves.id','leaves.employee_id','leaves.holiday_type','leaves.leave_date','leaves.status','leaves.applied_date','leave_types.type','employees.first_name','employees.last_name','users.email')
                            ->orderBy('leaves.updated_at','desc')
                            ->take(50)->get();

            return $this->sendSuccessResponse(Lang::get('messages.leave.get_pendingLeaves'),200,$approvedLeaves);
        } catch (\Exception $e) {           
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    /**
     * Display a listing of the pending leaves.
     *
     * @return \Illuminate\Http\Response
     */
    public function allpendingLeave()
    {
        try{
            $status = config('constants.pending_status');
            $pendingLeaves = Leave::join('leave_types','leaves.leave_type_id', '=', 'leave_types.id')
                            ->join('employees','leaves.employee_id', '=', 'employees.id')
                            ->select('leaves.id','leaves.employee_id','leaves.holiday_type','leaves.leave_date','leaves.applied_date','leave_types.type','employees.first_name','employees.last_name')
                            ->where('status', $status)
                            ->orderBy('leaves.created_at')
                            ->get();

            return $this->sendSuccessResponse(Lang::get('messages.leave.get_pendingLeaves'),200,$pendingLeaves);

        } catch (\Exception $e) {           
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    /**
     * Get Pending Leave Detail
     *
     * @return void
     */
    public function pendingLeaveDetail(Request $request) {
        try{
            $inputs = $request->all();
            $id = $inputs['id'];            

            $pendingLeaveDetail = Leave::join('leave_types','leaves.leave_type_id', '=', 'leave_types.id')
                            ->join('employees','leaves.employee_id', '=', 'employees.id')
                            ->select('leaves.id','leaves.employee_id','leaves.holiday_type','leaves.leave_date','leaves.applied_date','leaves.duration','leave_types.type','leaves.status','employees.first_name','employees.last_name')
                            ->where('leaves.id', $id)
                            ->get();

            return $this->sendSuccessResponse(Lang::get('messages.leave.pendingLeave_detail'),200,$pendingLeaveDetail);
        } catch (\Exception $e) {           
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    /**
     * Approve leave applied by employee from Pending leave list
     *
     * 
     * 
     */
    public function approvePendingLeave(Request $request){
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $leave_id = $inputs['leave_id'];

            $updatedData = '';
            $leave = Leave::where('id', $leave_id)->first(['status','leave_date','employee_id','holiday_type']);
            $userId = $leave['employee_id'];
            $status = $leave['status'];
            $leave_date = date('d-m-Y',strtotime($leave['leave_date']));
            if ($status == config('constants.pending_status')) {
                $data = ['status' => config('constants.approve_status'),'approve_date' => date('Y-m-d')];
                $updatedData = Leave::where('id', $leave_id)->update($data);
            }
            
            $employee_email = Leave::join('employees', 'leaves.employee_id','=','employees.id')
            ->join('users','employees.id', '=','users.entity_id')
            ->where('leaves.id', '=',$leave_id)->select('email','employees.id')->first();

            $user = User::where('id',$employee_email->id)->first();

            $tokens = DeviceDetail::where('employee_id',$userId)->pluck('device_token');

            if(count($tokens) > 0){
                $deviceTokens = array();
                foreach($tokens as $value) {
                    $deviceTokens[] = $value;
                }

                $message['msg'] = 'Your leave request has been approved';
                $message['module_type'] = 'Leave';
                $user->notify(new PushNotifications($message));
               
                $data = [
                    'title' =>'Leave Approve',
                    'body' => $message['msg'],
                    'type' => $message['module_type']
                ];
                $pushNotification = sendPushNotification($data,$deviceTokens);
            }

            $emailData['email'] = $employee_email['email'];
            $emailData['leave_date'] = $leave_date;
            $emailData['leave_action'] = 'Approved';
            $emailData['type'] = 'AdminAction';

            SendEmailJob::dispatch($emailData);
            
            DB::commit();
            if(isset($employee_email['email']) && !empty($employee_email['email'])){
                return $this->sendSuccessResponse(Lang::get('messages.leave.approve'),200,$updatedData);
            }
        } catch (\Exception $e) { 
            Log::info($e);          
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    /**
     * Reject leave applied by employee from Pending leave list
     *
     * @param  $leave_id ,$description
     * 
     */
    public function rejectPendingLeave(Request $request){
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $leave_id = $inputs['leave_id'];

            $rejectedData = '';
            $leave = Leave::where('id', $leave_id)->first(['status','leave_date','employee_id']);
            $userId = $leave['employee_id'];
            $status = $leave['status'];
            $leave_date = date('d-m-Y',strtotime($leave['leave_date']));
            if ($status == config('constants.pending_status')) {
                $data = ['status' => config('constants.reject_status'),
                         'approve_date' => date('Y-m-d')];

                $rejectedData = Leave::where('id', $leave_id)->update($data);

                $employee_email = Leave::join('employees', 'leaves.employee_id', '=', 'employees.id')
                    ->join('users', 'employees.id', '=', 'users.entity_id')
                    ->where('leaves.id', '=', $leave_id)->select('email','employees.id')->first();

                $user = User::where('id',$employee_email->id)->first();

                $tokens = DeviceDetail::where('employee_id',$userId)->pluck('device_token');

                if(count($tokens) > 0){
                    $deviceTokens = array();
                    foreach($tokens as $value) {
                        $deviceTokens[] = $value;
                    }

                    $message['module_type'] = 'Leave';
                    $message['msg'] = 'Your leave request has been rejected';
                    $user->notify(new PushNotifications($message));
               
                    $data = [
                        'title' =>'Leave Reject',
                        'body' => $message['msg'],
                        'type' => $message['module_type']
                    ];
                    $pushNotification = sendPushNotification($data,$deviceTokens);
                }
        
                $emailData['email'] = $employee_email['email'];
                $emailData['leave_date'] = $leave_date;
                $emailData['leave_action'] = 'Rejected';
                $emailData['type'] = 'AdminAction';
                SendEmailJob::dispatch($emailData);
            }
            DB::commit();
            
            return $this->sendSuccessResponse(Lang::get('messages.leave.reject'),200,$rejectedData);
        } catch (\Exception $e) {  
            Log::info($e);         
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    /**
     * Auto leave list
     *
     * 
     * 
     */
    public function getAutoLeaves()
    {
        try{
            $date = Carbon::now()->subDays(5);

            $autoLeaves = Leave::join('leave_types','leaves.leave_type_id', '=', 'leave_types.id')
                            ->join('employees','leaves.employee_id', '=', 'employees.id')
                            ->select('leaves.id','leaves.employee_id','leaves.holiday_type','leaves.leave_date','leave_types.type','employees.first_name','employees.last_name')
                            ->where('system_leave',1)
                            ->whereDate('leaves.created_at', '<=', $date)
                            ->orderBy('leaves.created_at','desc')
                            ->get();

            return $this->sendSuccessResponse(Lang::get('messages.leave.list'),200,$autoLeaves);
        } catch (\Exception $e) {  
            Log::info($e);         
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Leave  $leave
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $leave_id = $inputs['leave_id'];
            $user = Auth::user();
            $userId = $user->id;

            $userName = User::join('employees','users.id', '=', 'employees.id')
                                ->where('users.id',$userId)
                                ->value('display_name');

            if (!empty($leave_id)) {
                $data = Leave::join('employees','leaves.employee_id', '=', 'employees.id')
                                ->where('leaves.id',$leave_id)
                                ->select('leave_date','employees.display_name','employees.first_name','leaves.system_leave')
                                ->first();

                $email_data['system_leave'] = 'false';
                if($data->system_leave == 1){
                    $email_data['system_leave'] = 'true';
                }
                $l = Leave::where('id',$leave_id)->delete();
                DB::commit();

                $email_data['deleted_by_name'] = $userName;
                $email_data['employee_name'] = $data->display_name;
                $email_data['first_name'] = $data->first_name;
                $email_data['type'] = 'DeletedLeave';
                $email_data['leave_date'] = $data->leave_date;

                // foreach (config('constants.admin_mail') as $recipient) {
                //     $email_data['email'] = $recipient;  
                //     SendEmailJob::dispatch($email_data);
                // }

                $email_data['email'] = config('constants.admin_mail');
                $recipients = collect($email_data['email'] );
                $email_data['email'] = $recipients ;
                SendEmailJob::dispatch($email_data); 

                return $this->sendSuccessResponse(Lang::get('messages.leave.delete'),200);
            }
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
            
        } catch (\Exception $e) { 
            Log::info($e);          
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    /**
     * Get Employee Leave  BY  Employee ID, Month, Year With LeaveCount
     *
     * @param Request $request
     * @return void
     */
    public function getEmployeeLeave(Request $request) {
        $inputs = $request->all();
        $query = "CAST(employees.employee_id AS UNSIGNED) ASC";

        $employeeID = isset($inputs['employee_id']) && $inputs['employee_id'] != null ? $inputs['employee_id'] : null;
        $from_date = date('Y-m-d', strtotime($request->start_date));
        $to_date = date('Y-m-d', strtotime($request->end_date));

        $data = Leave::join('leave_types','leaves.leave_type_id', '=', 'leave_types.id')
            ->join('employees','leaves.employee_id', '=', 'employees.id')
            ->select('leaves.id','employees.id as empId','employees.employee_id as emp_id', DB::raw('SUM(holiday_type) as leaveCount'),'employees.first_name','employees.last_name',DB::raw('employees.display_name as EmployeeName'))
            ->whereBetween('leaves.leave_date', [$from_date, $to_date])
            ->whereIn('status',[config('constants.pending_status'),config('constants.approve_status')]);

        if  ($employeeID) {
            $data = $data->where('leaves.employee_id', $employeeID);
        }

        $data = $data->groupBy('leaves.employee_id');
        $data = $data->orderByRaw($query);
        $data = $data->get();

        return $this->sendSuccessResponse(Lang::get('messages.leave.list'),200,$data);
    }

    /**
     * Get Employee Leave  BY  Employee ID, Month, Year
     *
     * @param Request $request
     * @return void
     */
    public function getEmployeeLeaveDetail(Request $request) {
        try{
            $inputs = $request->all();
            $employeeID = $inputs['employee_id'];
            $from_date = date('Y-m-d', strtotime($request->start_date));
            $to_date = date('Y-m-d', strtotime($request->end_date));

            $data = Leave::with('leaveType:id,type')
                        ->join('employees','leaves.employee_id', '=', 'employees.id')
                        ->leftJoin('user_timesheets', function ($join) {
                            $join->on('leaves.employee_id', '=', 'user_timesheets.employee_id')
                                ->on('user_timesheets.date', '=', 'leaves.leave_date');
                        })
                        ->select('employees.first_name','employees.last_name','employees.id','leaves.id','leaves.employee_id','leaves.leave_date','leaves.leave_type_id','leaves.holiday_type',
                            DB::raw('DATE(leaves.applied_date) as applied_date'),'leaves.status','leaves.reject_remarks','user_timesheets.id as timesheet_id')
                        ->whereIn('status',[config('constants.pending_status'),config('constants.approve_status'),config('constants.reject_status')])
                        ->whereBetween('leaves.leave_date', [$from_date, $to_date])
                        ->where('leaves.employee_id','=',$employeeID)
                        ->groupBy('leaves.id')            
                        ->orderBy('leaves.leave_date', 'desc')
                        ->get();

            return $this->sendSuccessResponse(Lang::get('messages.leave.list'),200,$data);
            
        } catch (\Exception $e) { 
            Log::info($e);   
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);       
        }
    }
    

}
