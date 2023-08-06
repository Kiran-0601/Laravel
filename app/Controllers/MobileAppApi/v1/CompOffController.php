<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Notifications\PushNotifications;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use App\Jobs\SendEmailJob;
use DB, Lang, Log, Auth;
use App\Models\Employee;
use App\Models\CompOff;
use App\Models\Leave;
use App\Models\User;
use Carbon\Carbon;

class CompOffController extends Controller
{
    use ResponseTrait;

    //List of Recent Comp Off
    public function getRecentCompOff()
    {
        try{
            $allCompOff = CompOff::join('employees','comp_off.employee_id', '=', 'employees.id')
                            ->join('users','employees.id', '=', 'users.id')
                            ->select('comp_off.id','comp_off.employee_id','comp_off.type','comp_off.description','comp_off.applied_date','comp_off.status','employees.first_name','employees.last_name','users.email')
                            ->where('status',2)
                            ->orderBy('comp_off.updated_at','desc')
                            ->take(50)->get();

            return $this->sendSuccessResponse(Lang::get('messages.comp_off.list'),200,$allCompOff);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Comp Off Data List Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    // List pending comp off requests
    public function allpendingCompOff()
    {
        try{
            $status = config('constants.pending_status');
            $pendingCompOff = CompOff::join('employees','comp_off.employee_id', '=', 'employees.id')
                            ->select('comp_off.id','comp_off.employee_id','comp_off.type','comp_off.applied_date','employees.first_name','employees.last_name')
                            ->where('status', $status)
                            ->orderBy('comp_off.created_at')
                            ->get();
            return $this->sendSuccessResponse(Lang::get('messages.comp_off.list'),200,$pendingCompOff);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Comp Off Data List Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    // Detail of pending comp off request
    public function pendingCompOffDetail(Request $request) {
        try{
            $inputs = $request->all();
            $id = $inputs['id'];        

            $pendingCompOffDetail = CompOff::join('employees','comp_off.employee_id', '=', 'employees.id')
                            ->join('users','comp_off.employee_id', '=', 'users.id')
                            ->select('comp_off.id','comp_off.employee_id','comp_off.applied_date','comp_off.type','comp_off.description','comp_off.status','employees.first_name','employees.last_name',DB::raw('employees.employee_id as employee_id'),'users.email','employees.mobile',DB::raw('DATE(comp_off.created_at) as requested_date'),'comp_off.reject_remarks','comp_off.approve_date')
                            ->where('comp_off.id', $id)
                            ->get();

            return $this->sendSuccessResponse(Lang::get('messages.comp_off.list'),200,$pendingCompOffDetail);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Comp Off Data List Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    // Approve comp off request by admin
    public function approveCompOff(Request $request){
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $comp_off_id = $inputs['comp_off_id'];
            $compoff = CompOff::where('id', $comp_off_id)->first(['status','applied_date','employee_id','type']);
            $status = $compoff['status'];
            $compoff_date = date('d-m-Y',strtotime($compoff['applied_date']));
            if ($status == config('constants.pending_status')) {
                $data = ['status' => config('constants.approve_status'),'approve_date' => date('Y-m-d')];
                $updated_data = CompOff::where('id', $comp_off_id)->update($data);
            }
            
            $employee_email = CompOff::join('employees', 'comp_off.employee_id','=','employees.id')
            ->join('users','employees.id', '=','users.entity_id')
            ->where('comp_off.id', '=',$comp_off_id)->get(['email']);
            $email = $employee_email[0]['email'];
            $email_data['email'] = $email;
            $email_data['applied_date'] = $compoff_date;
            $email_data['comp_off_action'] = 'Approved';
            $email_data['type'] = 'AdminCompOffAction';

            SendEmailJob::dispatch($email_data);
            
            DB::commit();
            return $this->sendSuccessResponse(Lang::get('messages.comp_off.approve'),200,$updated_data);
                        return view('compoff.closeWindow');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Comp Off Data List Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    // Reject comp off request by admin
    public function rejectCompOff(Request $request){
        try {
            DB::beginTransaction();

            $inputs = $request->all();
            $comp_off_id = $inputs['comp_off_id']; 

            $compoff = CompOff::where('id', $comp_off_id)->first(['status','applied_date']);
            $status = $compoff['status'];
            $compoff_date = date('d-m-Y',strtotime($compoff['applied_date']));
            if ($status == config('constants.pending_status')) {
                $data = ['status' => config('constants.reject_status'),
                        'approve_date' => date('Y-m-d')];

                $rejected_data = CompOff::where('id', $comp_off_id)->update($data);

                $employee_email = CompOff::join('employees', 'comp_off.employee_id', '=', 'employees.id')
                    ->join('users', 'employees.id', '=', 'users.entity_id')
                    ->where('comp_off.id', '=', $comp_off_id)->get(['email']);
                $email = $employee_email[0]['email'];
        
                $email_data['email'] = $email;
                $email_data['applied_date'] = $compoff_date;
                $email_data['comp_off_action'] = 'Rejected';
                $email_data['type'] = 'AdminCompOffAction';
                SendEmailJob::dispatch($email_data);

            }
            DB::commit();
            return $this->sendSuccessResponse(Lang::get('messages.comp_off.reject'),200,$rejected_data);
       
        } catch (\Exception $e) {
            DB::rollBack();
            Log::info('Comp Off Data List Rollback...' . $e);
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }

    
}
