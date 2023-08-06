<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\EmployeeInvitation as MailEmployeeInvitation;
use App\Models\Country;
use App\Models\CountryDateFormat;
use App\Models\DateFormat;
use App\Models\Department;
use App\Models\EmailNotification;
use App\Models\Employee;
use App\Models\EmployeeInvitation;
use App\Models\EmployeementType;
use App\Models\EntityType;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceHistory;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Timezone;
use App\Models\User;
use App\Validators\EmployeeInvitationValidator;
use DB, Mail;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Validators\EmployeeValidator;
use Carbon\Carbon;
use Http;

class EmployeeInvitationController extends Controller
{
    private $employeeInvitationValidator;
    private $employeeValidator;
    use ResponseTrait;
    function __construct()
    {
        $this->employeeInvitationValidator = new EmployeeInvitationValidator();
        $this->employeeValidator = new EmployeeValidator();
    }

    // Send invitation to user for add as a employee
    public function sendInvitation(Request $request)
    {
        $inputs = $request->all();

        $validation = $this->employeeInvitationValidator->validateStore($request);

        if ($validation->fails()) {
            return $this->sendFailResponse($validation->errors(), 422);
        }
        DB::beginTransaction();

        try {
            $token = getUuid();
            $organizationId = $this->getCurrentOrganizationId();
            $roles = '';
            if (!empty($inputs['roles'])) {

                if (is_array($inputs['roles'])) {
                    $roles = implode(',', $inputs['roles']);
                } else {
                    $roles = $inputs['role'];
                }
            }

            $inviteData = [
                'first_name' => $inputs['first_name'],
                'last_name' => $inputs['last_name'],
                'email' => $inputs['email'],
                'token' => $token,
                'is_active' => 0,
                'department_id' => $inputs['department'],
                'employee_type_id' => $inputs['employeement_type'],
                'organization_id' => $organizationId,
                'join_date' => !empty($inputs['join_date']) ? convertUserTimeToUTC($inputs['join_date']) : getUtcDate(),
                'roles' => $roles
            ];

            $checkExists = EmployeeInvitation::where('email', $inputs['email'])->first('email');
            if ($checkExists) {
                return $this->sendFailResponse(__('messages.invitation_email_unique'), 422);
            } else {
                $invitation = EmployeeInvitation::create($inviteData);
                $invitation['status'] = "pending";
                $invitation['is_token'] = false;
                Mail::to($inputs['email'])->send((new MailEmployeeInvitation($invitation)));
                unset($invitation['status']);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.invitation_store'), 200, $invitation);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while send invitation";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    // Get specific invitation data or return expiration message if it's hitted by 7 days later
    public function getInvitation($token)
    {
        DB::beginTransaction();
        try {
            $currentTime = strtotime(getDateTime());

            $invitation = $invitation = EmployeeInvitation::join('organizations','employee_invitations.organization_id','organizations.id')->where('token', $token)->select('employee_invitations.organization_id','email','first_name','last_name','department_id','token','employee_type_id','join_date','is_active','roles','organizations.organization_name', 'organization_billings.billing_name')
            ->join('organization_billings', 'organization_billings.organization_id', 'organizations.id')->first();

            if (!empty($invitation)) {
                $afterSevenDaysDate = date('Y-m-d h:i:sa', strtotime($invitation->updated_at . '+7 days'));
                $createdTimestamp = strtotime($afterSevenDaysDate);
   
                if ($currentTime <= $createdTimestamp) {
                    return $this->sendSuccessResponse(__('messages.success'), 200, $invitation);
                } else {
                    $invitation->token = '';
                    $invitation->save();

                    DB::commit();

                    return $this->sendFailResponse(__('messages.invitation_expired'), 200);
                }
            }
            return $this->sendFailResponse(__('messages.invitation_accepted'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while get invitation";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Resend invitation to same user after expiration of 7 days
    public function resendInvitation(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            EmployeeInvitation::where('id', $id)->update(['token' => getUuid()]);

            $employeeInvitation = EmployeeInvitation::where('id', $id)->first();
            $employeeInvitation->status = "pending";
            $employeeInvitation->is_token = false;

            Mail::to($employeeInvitation->email)->send(new MailEmployeeInvitation($employeeInvitation));
            DB::commit();

            return $this->sendSuccessResponse(__('messages.invitation_resend'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while edit invitation";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Accept invitation will create employee
    public function acceptInvitation(Request $request)
    {
        try {

            $inputs = $request->all();

            DB::beginTransaction();

            $validation = $this->employeeValidator->validateStore($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $invitation = EmployeeInvitation::where('token', $inputs['token'])->where('email', $inputs['email'])->first();

            $organizationId = $invitation->organization_id;

            EmployeeInvitation::where('email', $inputs['email'])
            ->update([
                'is_active' => 1,
                'token' => ''
            ]);

            if(!empty($invitation->import_id)){
                $user = User::where('email', $invitation->email)->first();
                if(!empty($user)){
                    $user->update([
                        'is_active' => 1,
                        'password' => \Hash::make($inputs['password']),
                    ]);
                }

                $user->token = $user->createToken(env('PASSPORT_TOKEN_STR'))->accessToken;

                
                DB::commit();

                return $this->sendSuccessResponse(__('messages.invitation_accept'), 200, $user);
               
            }

            //To calculate the probation period end date, we need to add leave balance as per settings
            $probationPeriodLeave = Setting::join('organization_settings', 'settings.id', 'organization_settings.setting_id')->where('key', 'allow_leave_during_probation_period')->where('organization_id', $organizationId)->first('organization_settings.value');
            $probationPeriodLeave = $probationPeriodLeave->value;

            $joinDate = date('Y-m-d H:i:s', strtotime($invitation['join_date']. ' -1 day'));

            //If allow leave during probation period then compare join date
            if($probationPeriodLeave == true){
                $currentDate = getUtcDate();
                $compareDate = $currentDate;
            }else{
                $days = Setting::join('organization_settings', 'settings.id', 'organization_settings.setting_id')->where('key', 'probation_period_days')->where('organization_id', $organizationId)->first('organization_settings.value');
              
                $previousDateTime = Carbon::parse($joinDate)->addDays($days->value);
          
                $compareDate = date('Y-m-d', strtotime($previousDateTime->toDateString()));
            }

            $employee = Employee::where('organization_id', $organizationId)->orderBy('id', 'desc')->withTrashed()->first();
            $id = $employee->id + 1;

            $employee = Employee::create([
                'id' => $id, 
                'organization_id' => $organizationId, 
                'uuid' => getUuid(), 
                'first_name' => $invitation['first_name'],
                'last_name' => $invitation['last_name'],
                'display_name' => $invitation['first_name'].' '.$invitation['last_name'],
                'department_id' => $invitation['department_id'],
                'join_date' => $joinDate,
                'employeement_type_id' => $invitation['employee_type_id'] ?? EmployeementType::PERMANENT,
                'probation_period_end_date' => $compareDate]
            );

            $employee_id = $employee->id;

            //Add leave balance to employee for all leave types
            $leaveTypes = LeaveType::select('id')->get();
            foreach($leaveTypes as $leaveType) {
                $data = ['employee_id' => $employee_id, 'organization_id' => $organizationId, 'leave_type_id' => $leaveType->id];
                LeaveBalance::firstOrCreate($data);
                LeaveBalanceHistory::firstOrCreate($data);
            }

            $apiKey = env('IPGEOLOCATIONKEY');
            $ip = get_client_ip();
            $location = get_geolocation($apiKey, $ip);
            $decodedLocation = json_decode($location, true);
            $timezoneId = '308';
            if(!empty($decodedLocation) && !empty($decodedLocation['time_zone'])){
                $timezoneName= $decodedLocation['time_zone']['name'];
                $timezoneId = Timezone::where('value',$timezoneName)->first('id');
                $timezoneId = $timezoneId->id;
                
            }

            $format = DateFormat::Common;
            if(!empty($decodedLocation['country_name'])){
                $country = Country::where('name' , $decodedLocation['country_name'])->first('id');
                if(!empty($country)){
                    $countryFormat = CountryDateFormat::where('country_id', $country->id)->first('date_format_id');
                    if(!empty($countryFormat->date_format_id)){
                        $format = $countryFormat->date_format_id;
                    }
                }
            }

            $user = User::create([
                'email' => $inputs['email'],
                'password' => \Hash::make($inputs['password']),
                'entity_type_id' => EntityType::Employee,
                'entity_id' => $employee_id,
                'organization_id' => $organizationId,
                'timezone_id' =>  $timezoneId,
                'date_format_id' => $format
            ]);

            EmailNotification::firstOrCreate([
                'user_id' => $user->id
            ]);

            $employee['status'] = "accept";

            $invitationRoles = explode(',',$invitation['roles']);    
            $roles = Role::whereIn('id',$invitationRoles)->get();
            foreach($roles as $role){
               // $assignRole = Role::find($role);
                $user->assignRole($role);
            }

            $user->token = $user->createToken(env('PASSPORT_TOKEN_STR'))->accessToken;

            $user = $this->getUserRolePermissions($user);

            $user->employee = $employee;

            $data = new MailEmployeeInvitation($user);

            $emailData = ['email' => $inputs['email'], 'email_data' => $data];

            SendEmailJob::dispatch($emailData);

            $department = Department::where('id', $invitation['department_id'])->first(['name']);
            $department = DB::connection('old_connection')->table('department')->where('name','LIKE', $department->name)->whereNull('deleted_at')->first(['id']);

            $departmentId = '';
            if(!empty($department->id)){
                $departmentId = $department->id;
            }
            $foveroApp = config('app.fovero_app_url');
            Http::post($foveroApp.'invitation', [
                'email' => $inputs['email'],
                'password' => $inputs['password'],
                'first_name' => $invitation['first_name'],
                'department' => $departmentId,
                'from_organization' => true,
		        'employee_id' => $employee_id
            ]);
            
            DB::commit();

            return $this->sendSuccessResponse(__('messages.invitation_accept'), 200, $user);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while accept invitation";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    // Get invited employee list
    public function getInvitedEmployeeList(Request $request)
    {
        try {
            $keyword = $request->keyword ?? '';
            $perPage = $request->perPage ?? 10;

            $query = EmployeeInvitation::where('is_active', 0)->orderBy('created_at', 'DESC');
            $totalRecords = $query->select('first_name', 'last_name', 'email', 'created_at', 'updated_at', 'id',DB::raw('(CASE WHEN updated_at <= "' . Carbon::now()->subDays(7)->toDateTimeString() .'"  THEN "Expired" ELSE "" END) AS status'))->get()->count();
            if (!empty($keyword)) {
              
                $query->where(function($query) use($keyword){
                    $query->orWhere('first_name', "like", '%' . $keyword . '%');
                    $query->orWhere('last_name', "like", '%' . $keyword . '%');
                    $query->orWhere('email', "like", '%' . $keyword . '%');
                });
            }
        
            $data['invitations'] = $query->paginate($perPage);
            $data['count'] = $totalRecords;

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {

            $logMessage = "Something went wrong while get invited employee list";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function deleteInvitation($id)
    {
        DB::beginTransaction();
        try {
            
            // remove from employee invitation table
            EmployeeInvitation::where('id', $id)->delete();

            DB::commit();
            return $this->sendSuccessResponse(__('messages.employee_invitation_deleted'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while send invitation";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
