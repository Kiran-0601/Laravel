<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\UserRole;
use App\Models\CompOff;
use App\Models\Leave;
use App\Models\User;
use App\Models\WorkFromHomeEmployee;
use App\Notifications\PushNotifications;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use App\Jobs\SendEmailJob;
use DB, Log, Lang, Auth;
use Carbon\Carbon;

class WorkFromHomeController extends Controller
{
    use ResponseTrait;

    /**
     * Get Recent Leaves
     *
     * 
     */
    public function getRecentWfh()
    {
        try{
            $approvedLeaves = WorkFromHomeEmployee::join('employees','work_from_home_employees.employee_id', '=', 'employees.id')
                            ->join('users','employees.id', '=', 'users.id')
                            ->select('work_from_home_employees.id','work_from_home_employees.employee_id','work_from_home_employees.wfh_type','work_from_home_employees.wfh_date','work_from_home_employees.status','work_from_home_employees.applied_date','employees.first_name','employees.last_name','users.email')
                            ->orderBy('work_from_home_employees.updated_at','desc')
                            ->take(50)->get();

            return $this->sendSuccessResponse(Lang::get('messages.wfh.list'),200,$approvedLeaves);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Work from home Data Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }
   
    // List pending work from home requests
    public function allpendingWfh()
    {
        try{
            $pendingWfh = WorkFromHomeEmployee::join('employees','work_from_home_employees.employee_id', '=', 'employees.id')
                            ->select('work_from_home_employees.id','work_from_home_employees.employee_id','work_from_home_employees.wfh_type','work_from_home_employees.wfh_date','work_from_home_employees.applied_date','employees.first_name','employees.last_name')
                            ->where('status', config('constants.pending_status'))
                            ->orderBy('work_from_home_employees.created_at')
                            ->get();

            return $this->sendSuccessResponse(Lang::get('messages.wfh.list'),200,$pendingWfh);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Work from home Data Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    // Detail of pending work from home request
    public function pendingWfhDetail(Request $request) 
    {
        try{
            $inputs = $request->all();
            $id = $inputs['id'];        

            $pendingWfhDetail = WorkFromHomeEmployee::join('employees','work_from_home_employees.employee_id', '=', 'employees.id')
                        ->join('users','work_from_home_employees.employee_id', '=', 'users.id')
                        ->select('work_from_home_employees.id','work_from_home_employees.employee_id','work_from_home_employees.wfh_type','work_from_home_employees.description','work_from_home_employees.wfh_date','work_from_home_employees.approve_date','work_from_home_employees.reject_remarks','work_from_home_employees.applied_date','work_from_home_employees.status','employees.first_name','employees.last_name',DB::raw('employees.employee_id as employee_id'),'users.email','employees.mobile','work_from_home_employees.duration')
                        ->where('work_from_home_employees.id', $id)
                        ->get();

            return $this->sendSuccessResponse(Lang::get('messages.wfh.list'),200,$pendingWfhDetail);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Work from home Data Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    // Approve work from home request by admin
    public function approveWfh(Request $request){
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $wfh_id = $inputs['wfh_id'];  
            $wfh = WorkFromHomeEmployee::where('id', $wfh_id)->first(['status','wfh_date','employee_id','wfh_type']);
            $status = $wfh['status'];
            $wfh_date = date('d-m-Y',strtotime($wfh['wfh_date']));
            if ($status == config('constants.pending_status')) {
                $data = ['status' => config('constants.approve_status'),'approve_date' => date('Y-m-d')];
                $updated_data = WorkFromHomeEmployee::where('id', $wfh_id)->update($data);
            }
            
            $employee_email = WorkFromHomeEmployee::join('employees', 'work_from_home_employees.employee_id','=','employees.id')
                ->join('users','employees.id', '=','users.entity_id')
                ->where('work_from_home_employees.id', '=',$wfh_id)
                ->get(['email']);
                $email = $employee_email[0]['email'];
            $email_data['email'] = $email;
            $email_data['wfh_date'] = $wfh_date;
            $email_data['wfh_action'] = 'Approved';
            $email_data['type'] = 'AdminWfhAction';

            SendEmailJob::dispatch($email_data);
            
            DB::commit();
            return $this->sendSuccessResponse(Lang::get('messages.wfh.approve'),200,$updated_data);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Work from home Data Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    // Reject work from home request by admin
    public function rejectWfh(Request $request){
        try {
            DB::beginTransaction();

            $inputs = $request->all();
            $wfh_id = $inputs['wfh_id']; 

            $wfh = WorkFromHomeEmployee::where('id', $wfh_id)->first(['status','wfh_date']);
            $status = $wfh['status'];
            $wfh_date = date('d-m-Y',strtotime($wfh['wfh_date']));
            if ($status == config('constants.pending_status')) {
                $data = ['status' => config('constants.reject_status'),
                         'approve_date' => date('Y-m-d')];

                $rejected_data = WorkFromHomeEmployee::where('id', $wfh_id)->update($data);

                $employee_email = WorkFromHomeEmployee::join('employees', 'work_from_home_employees.employee_id', '=', 'employees.id')
                    ->join('users', 'employees.id', '=', 'users.entity_id')
                    ->where('work_from_home_employees.id', '=', $wfh_id)->get(['email']);
                $email = $employee_email[0]['email'];
        
                $email_data['email'] = $email;
                $email_data['wfh_date'] = $wfh_date;
                $email_data['wfh_action'] = 'Rejected';
                $email_data['type'] = 'AdminWfhAction';
                SendEmailJob::dispatch($email_data);
            }
            DB::commit();
            
            return $this->sendSuccessResponse(Lang::get('messages.wfh.reject'),200,$rejected_data);
        
            return view('wfh.closeWindow');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Work from home Data Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

}
