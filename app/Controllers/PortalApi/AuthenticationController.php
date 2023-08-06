<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\OrganizationDataSync;
use App\Jobs\SendEmailJob;
use App\Mail\VerifyEmail;
use App\Models\DefaultRole;
use App\Models\Permission;
use App\Models\Employee;
use App\Models\EmployeementType;
use App\Models\EntityType;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Http\Request;
use Auth, Mail, Hash, DB;
use App\Models\User;
use App\Traits\ResponseTrait;
use App\Validators\UserValidator;
use Illuminate\Support\Facades\Crypt;
use App\Mail\MailEmployeeWelcome;
use App\Models\Attendance;
use App\Models\Country;
use App\Models\CountryDateFormat;
use App\Models\DateFormat;
use App\Models\DayDuration;
use App\Models\DefaultDepartment;
use App\Models\DefaultLeaveType;
use App\Models\DefaultProjectStatus;
use App\Models\DefaultSkill;
use App\Models\Department;
use App\Models\EmailNotification;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceHistory;
use App\Models\LeaveType;
use App\Models\LeaveTypeAllowedDuration;
use App\Models\OrganizationBilling;
use App\Models\OrganizationSetting;
use App\Models\ProjectStatus;
use App\Models\Setting;
use App\Models\Skill;
use App\Models\Timezone;
use Carbon\Carbon;
use Illuminate\Auth\Events\PasswordReset;
use Password;
use Str;

class AuthenticationController extends Controller
{
    private $userValidator;
    use ResponseTrait;
    public function __construct()
    {
        $this->userValidator = new UserValidator();
    }

    public function sendVerificationEmail(Request $request)
    {
        try {
            //validate request start
            $verifyEmailValidate = $this->userValidator->validateVerifyEmail($request);
            if ($verifyEmailValidate->fails()) {
                return $this->sendFailResponse($verifyEmailValidate->errors(), 422);
            }
            //validate request end

            $firstName = $request->firstname;
            $lastName = $request->lastname;
            $organizationName = $request->companyname;
            $email = $request->email;

            $data = [
                'firstname' => $firstName,
                'lastname' => $lastName,
                'email' => $email,
                'companyname' => $organizationName
            ];

            $encriptedData = Crypt::encrypt($data);

            $url = env('APP_URL') . 'create-password/' . $encriptedData;

            Mail::to($email)->send(new VerifyEmail($url));

            return $this->sendSuccessResponse(__('messages.verification_email'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while register user";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Check user exit before register new organization
    public function checkUserExist(Request $request)
    {
        if (!empty($request->token)) {
            $decryptedData = Crypt::decrypt($request->token, true);

            $request->merge($decryptedData);
        }

        $user = User::where('email', $request->email)->first();

        if(!empty($user)){
            return $this->sendSuccessResponse(__('messages.exist_user'), 200, $user);
        }

        return $this->sendSuccessResponse(__('messages.success'), 200);
    }

    // user register 
    public function register(Request $request)
    {
        DB::beginTransaction();
        try {
            if (!empty($request->token)) {
                $decryptedData = Crypt::decrypt($request->token, true);

                $request->merge($decryptedData);
            }

            //validate request start
            $userRegisterValidate = $this->userValidator->validateRegister($request);
            if ($userRegisterValidate->fails()) {
                return $this->sendFailResponse($userRegisterValidate->errors()->first(), 422);
            }
            //validate request end

            $firstName = $request->firstname;
            $lastName = $request->lastname;
            $organizationName = $request->companyname;
            $email = !empty($request->email) ? $request->email : NULL;
            $password = !empty($request->password) ? $request->password : NULL;
            $socialType = !empty($request->social_media_type) ? $request->social_media_type : NULL;
            $socialId = !empty($request->social_id) ? $request->social_id : NULL;
            $verify = $request->verify ? true : false;


            $organizationData = [
                "uuid" => getUuid(),
                "organization_name" => $organizationName,
                "organization_email" => $email
            ];

            $organizationInfo = Organization::create($organizationData);

            OrganizationBilling::create([
                'billing_name' => $organizationName,
                'organization_id' => $organizationInfo->id
            ]);

            $employeeData = [
                "first_name" => $firstName,
                "last_name" => $lastName,
                "id" => '1',
                "organization_id" => $organizationInfo->id,
                "uuid" => getUuid(),
                "display_name" => $firstName . " " . $lastName,
                "employeement_type_id" => EmployeementType::PERMANENT,
                "join_date" => getUtcDate(),
                "probation_period_end_date" => getUtcDate(),
            ];

            $employeeInfo = Employee::create($employeeData);

            $apiKey = env('IPGEOLOCATIONKEY');
            $ip = get_client_ip();
            $location = get_geolocation($apiKey, $ip);
            $decodedLocation = json_decode($location, true);
            $timezoneId = '308';
            if(!empty($decodedLocation['time_zone'])){
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

            $userData = [
                "email" => $email,
                "password" => !empty($password) ? bcrypt($password) : NULL,
                "social_media_type" => $socialType,
                "social_id" => $socialId,
                "entity_type_id" => EntityType::Employee,
                "entity_id" => $employeeInfo->id,
                "organization_id" => $organizationInfo->id,
                "timezone_id" => $timezoneId,
                "date_format_id" => $format
            ];

            $userInfo = User::create($userData);

            $data = ['created_by' => $userInfo->id];

            Organization::where('id', $organizationInfo->id)->update($data);

            //All default roles and permissions assigned to organization
            $defaultRoles = DefaultRole::all()->except([DefaultRole::SUPERADMINISTRATOR]);

            $allPermissions = [];
        
            foreach ($defaultRoles as $defaultRole) {

                $permissions = $defaultRole->permissions->pluck('id');

                $defaultRole->organization_id = $organizationInfo->id;
                $role = Role::create(['name' => $defaultRole->name, 'guard_name' => 'api', 'slug' => $defaultRole->slug, 'organization_id' => $defaultRole->organization_id]);

                if (!empty($permissions) && count($permissions) > 0) {
                    $permissions = Permission::whereIn('id', $permissions)->get()->pluck('name');

                    $allPermissions[] = $permissions;

                    $role->syncPermissions($permissions);
                }

                if ($role->name == 'Administrator') {
                    $userInfo->assignRole($role);
                }
            }

            //Currently organization owner have all permission assigned 
            $userInfo->syncPermissions($allPermissions);
            // Todo   whenever we have packages for subscribe at that we need to give permission module vise

            $userInfo = $this->getUserRolePermissions($userInfo);

            OrganizationDataSync::dispatch($organizationInfo);

            //Add deefault settings to organization
            $settings = Setting::select('id as setting_id', 'value',DB::raw($organizationInfo->id." as organization_id"))->get()->toArray();
            OrganizationSetting::insert($settings);

            $setting = Setting::join('organization_settings', 'settings.id','organization_settings.setting_id')->where('key', 'send_today_leave_details_to')->where('organization_id', $organizationInfo->id)->first(['organization_settings.id', 'organization_settings.value']);
            OrganizationSetting::where('id',$setting->id)->update(['value' => $userInfo->id]);

            $setting = Setting::join('organization_settings', 'settings.id','organization_settings.setting_id')->where('key', 'send_mail_for_auto_leave_to')->where('organization_id', $organizationInfo->id)->first(['organization_settings.id', 'organization_settings.value']);
            OrganizationSetting::where('id',$setting->id)->update(['value' => $userInfo->id]);

            EmailNotification::firstOrCreate([
                'user_id' => $userInfo->id
            ]);

            //Assign default leave type to organization
            $defaultLeaveType = DefaultLeaveType::select(DB::raw('uuid() as uuid'),'type as name', 'code','no_of_leaves','is_default','is_primary',  'leave_type_type_id', DB::raw($organizationInfo->id." as organization_id"), DB::raw('1 as accrual'), DB::raw('1 as accrual_period'), DB::raw('1 as accrual_date'), DB::raw('1 as accrual_month'), DB::raw('1 as reset'), DB::raw('1 as reset_period'),DB::raw('31 as reset_date'),DB::raw('12 as reset_month'),DB::raw('0 as encashment'),DB::raw('-1 as carryforward'))->get()->toArray();
            LeaveType::insert($defaultLeaveType);

            //Allowed leave type duration for apply leave
            $leaveTypes = LeaveType::select('id')->get();
            foreach($leaveTypes as $leaveType){
                LeaveTypeAllowedDuration::firstOrCreate(['leave_type_id' => $leaveType->id, 'duration_id' => DayDuration::FULLDAY]);
                LeaveTypeAllowedDuration::firstOrCreate(['leave_type_id' => $leaveType->id, 'duration_id' => DayDuration::FIRSTHALF]);
                LeaveTypeAllowedDuration::firstOrCreate(['leave_type_id' => $leaveType->id, 'duration_id' => DayDuration::SECONDHALF]);

                $data = ['employee_id' => $employeeInfo->id, 'organization_id' => $organizationInfo->id, 'leave_type_id' => $leaveType->id];
                LeaveBalance::firstOrCreate($data);
                LeaveBalanceHistory::firstOrCreate($data);
            }

            $defaultDepartment = Department::where('slug', 'other')->first();
            if(!empty($defaultDepartment)){
                Employee::where('uuid', $employeeInfo->uuid)->update(['department_id' => $defaultDepartment->id]);
            }


            if($verify){
                $emailData = ['email' => $email];
                $token = Crypt::encrypt($emailData);
                // Send confirm mail
                $url = env('APP_URL') . 'verification/org?token=' . $token;

                Mail::to($email)->send(new VerifyEmail($url));

                $resInfo['verifyStatus'] = true;

                DB::commit();

                return $this->sendSuccessResponse(__('messages.verification_email'), 200, $resInfo);
                
            }else{
                User::where('id',$userInfo->id)->update(['email_verified_at' => Carbon::now('UTC')]);
                //generate passport token
                $userInfo->token = $userInfo->createToken(env('PASSPORT_TOKEN_STR'))->accessToken;
                                
                // Send confirm mail
                $data = new MailEmployeeWelcome($employeeInfo);
                
                $emailData = ['email' => $email, 'email_data' => $data];
                
                SendEmailJob::dispatch($emailData);

                DB::commit();

                return $this->sendSuccessResponse(__('messages.register_success'), 200, $userInfo);
            }
            
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while register user";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //user login
    public function login(Request $request)
    {
        try {
            //validate request start
            $userLoginValidate = $this->userValidator->validateUserLogin($request);
            if ($userLoginValidate->fails()) {
                return $this->sendFailResponse($userLoginValidate->errors()->first(), 422);
            }
            //validate request end

            $email = $request->email;
            $password = $request->password;

            //check credential as per its data
            $credentials = $this->credentials($email, $password);

            if (!Auth::attempt($credentials)) {
                return $this->sendFailResponse(__('messages.login_fail'), 422);
            }
            $userInfo = Auth::user();

            if ($userInfo->is_active == false) {
                return $this->sendFailResponse(__('messages.inactive_account'), 422);
            }

            //remove older token / one device login start
       //     Auth::user()->AauthAcessToken()->delete();
            //remove older token / one device login end
            $userInfo = $this->getUserRolePermissions($userInfo);
            User::where('id', $userInfo->id)->update(['last_login_time' => now()]);
            $userInfo->token = $userInfo->createToken(env('PASSPORT_TOKEN_STR'))->accessToken;
            return $this->sendSuccessResponse(__('messages.login_success'), 200, $userInfo);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while login user";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //User login with username, email, mobile 
    protected function credentials($username = "", $password = "")
    {
        if (is_numeric($username)) {
            return ['mobile' => $username, 'password' => $password];
        } elseif (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            return ['email' => $username, 'password' => $password];
        }
        return ['username' => $username, 'password' => $password];
    }

    //user social login/register
    public function socialLogin(Request $request)
    {
        try {
            //validate request start
            $userLoginValidate = $this->userValidator->validateUserSocialLogin($request);
            if ($userLoginValidate->fails()) {
                return $this->sendFailResponse($userLoginValidate->errors()->first(), 422);
            }
            //validate request end

            $socialType = $request->social_media_type;
            $socialId = $request->social_id;
            $email = !empty($request->email) ? $request->email : NULL;

            //check with social id
            $userInfo = User::where('social_id', $socialId)->first();

            //check with email if user detail not found with social id start
            if (!empty($email) && empty($userInfo)) {
                $userInfo = User::where('email', $email)->first();
            }

            //check with email if user detail not found with social id end

            //check user detail with valid social type start
            if (!empty($userInfo) && $userInfo->social_media_type != $socialType) {
                return $this->sendFailResponse(__('messages.already_login_other_social_login', ["social_type" => $userInfo->social_type]), 422);
            }
            //check user detail with valid social type end

            // register user if user detail not found start
            if (empty($userInfo)) {
                // $userInfo = User::create($userData);
                // $userInfo->token = $userInfo->createToken(env('PASSPORT_TOKEN_STR'))->accessToken;
                return $this->sendSuccessResponse('', 200);
            }
            // register user if user detail not found end

            //Verification needed for social login with manually added email
            if (!empty($userInfo) && $userInfo->email_verified_at==null) {
                return $this->sendFailResponse(__('messages.verification_email'), 422);
            }

            $userInfo = $this->getUserRolePermissions($userInfo);

            //return user detail and token if user is valid start
            $userInfo->token = $userInfo->createToken(env('PASSPORT_TOKEN_STR'))->accessToken;
            User::where('id', $userInfo->id)->update(['last_login_time' => now()]);
            return $this->sendSuccessResponse(__('messages.login_success'), 200, $userInfo);
            //return user detail and token if user is valid end

        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while social login";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Verify email for social signup
    public function verifyEmailForSocialSignUp(Request $request)
    {
        try {
            $input = $request->token;
            $email = Crypt::decrypt($input, true);
            $userInfo = User::where("email", "LIKE", $email)->first();
            if (empty($userInfo)) {
                return $this->sendFailResponse(__('messages.email_not_found'), 422);
            }

            $userInfo->email_verified_at = Carbon::now('UTC');
            $userInfo->save();
            $userInfo->token = $userInfo->createToken(env('PASSPORT_TOKEN_STR'))->accessToken;

            return $this->sendSuccessResponse(__('messages.reset_pwd_success'), 200, $userInfo);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while reset password";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //forgot user password
    public function forgotPassword(Request $request)
    {
        try {
            //validate request start
            $userForgotPwdValidate = $this->userValidator->validateUserForgotPassword($request);
            if ($userForgotPwdValidate->fails()) {
                return $this->sendFailResponse($userForgotPwdValidate->errors()->first(), 422);
            }
            //validate request end
            $email = $request->email;
            $userIfo = User::where("email", "LIKE", $email)->first();
            if (empty($userIfo)) {
                return $this->sendFailResponse(__('messages.email_not_found'), 422);
            }
        
            $status = Password::sendResetLink(
                $request->only('email')
            );

            if($status == Password::RESET_LINK_SENT){
                return $this->sendSuccessResponse(__('messages.forgot_pwd_mail_success'), 200);
            }elseif($status == Password::RESET_THROTTLED){
                return $this->sendSuccessResponse(__('messages.forgot_pwd_mail_warning'), 422);
            }
            return $this->sendSuccessResponse(__('messages.exception_msg'), 422);
            
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while doing forgot password action";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //reset password
    public function resetPassword(Request $request)
    {
        try {
            //validate request start
            $userResetPwdValidate = $this->userValidator->validateUserResetPassword($request);
            if ($userResetPwdValidate->fails()) {
                return $this->sendFailResponse($userResetPwdValidate->errors()->first(), 422);
            }
            //validate request end
            $email = $request->email;
            $password = $request->password;
            $userInfo = User::where("email", "LIKE", $email)->first();
            if (empty($userInfo)) {
                return $this->sendFailResponse(__('messages.email_not_found'), 422);
            }

            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    $user->forceFill([
                        'password' => Hash::make($password)
                    ])->setRememberToken(Str::random(60));
         
                    $user->save();
         
                    event(new PasswordReset($user));
                }
            );

            if($status == Password::PASSWORD_RESET){
                $userInfo->token = $userInfo->createToken(env('PASSPORT_TOKEN_STR'))->accessToken;
                $userInfo = $this->getUserRolePermissions($userInfo);
                return $this->sendSuccessResponse(__('messages.reset_pwd_success'), 200, $userInfo);
            }elseif($status == Password::INVALID_TOKEN){
                
                return $this->sendSuccessResponse(__('messages.reset_pwd_warning'), 422);
            }

            return $this->sendSuccessResponse(__('messages.exception_msg'), 422);
           
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while reset password";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    // Get current user data
    public function getCurrentUser()
    {
        $currentUser = Auth::user();

        $employee = Employee::where('id', $currentUser->entity_id)->first();

        $currentUser->employee = $employee;

        $dateFormat = DateFormat::where('id', $currentUser->date_format_id)->first('label');
        $currentUser->settings = ['date_format' => $dateFormat->label];

        $attendence = Attendance::where('employee_id', $employee->id)->where('organization_id', $employee->organization_id)
        ->whereDate('punch_in', getUtcDate('Y-m-d'))
        ->first();

        $currentUser->punch_in_status = false;
        if(!empty($attendence)){
            $currentUser->punch_in_time = convertUTCTimeToUserTime($attendence->punch_in);
            $currentUser->punch_out_time = !empty($attendence->punch_out) ? convertUTCTimeToUserTime($attendence->punch_out, 'H:i') : "";
            $currentUser->punch_in_status = true;
        }

        if ($currentUser->is_active) {

            $currentUser = $this->getUserRolePermissions($currentUser);

            return $this->sendSuccessResponse(__('general.success_message'), 200, $currentUser);
        } else {
            return $this->sendFailResponse(__('messages.inactive_user'), 422);
        }
    }

    //logout user
    public function logout()
    {
        try {
            if (!Auth::check()) {
                return $this->sendFailResponse(__('messages.logout_error'), 200);
            }
            //remove existing token and logout user 
            Auth::user()->token()->revoke();

            return $this->sendSuccessResponse(__('messages.logout_success'), 200);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while logout";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
