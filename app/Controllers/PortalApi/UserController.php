<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ResponseTrait;
use App\Validators\UserValidator;
use Auth, Hash;
use Illuminate\Http\Request;
use App\Models\Address;
use App\Models\AddressType;
use App\Models\DateFormat;
use App\Models\Department;
use App\Models\Designation;
use App\Models\EmailNotification;
use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\EmployeeContact;
use App\Models\Role;
use App\Models\EntityType;
use App\Models\EmployeementType;
use App\Models\Timezone;
use DB;
use App\Traits\UploadFileTrait;

class UserController extends Controller
{

    private $userValidator;
    use ResponseTrait, UploadFileTrait;
    public function __construct()
    {
        $this->middleware('auth');
        $this->userValidator = new UserValidator();
    }

    //change password
    public function changePassword(Request $request)
    {
        try {
            $userId = Auth::user()->id;
            //validate request start
            $userChangePwdValidate = $this->userValidator->validateUserChangePassword($request);
            if ($userChangePwdValidate->fails()) {
                return $this->sendFailResponse($userChangePwdValidate->errors()->first(), 422);
            }
            //validate request end

            $oldPassword = $request->old_password;
            $newPassword = $request->new_password;

            if ((Hash::check($oldPassword, Auth::user()->password)) == false) {
                return $this->sendFailResponse(["old_password"=>[__('messages.old_pwd_not_valid')]], 422);
            } else if ((Hash::check($newPassword, Auth::user()->password)) == true) {
                return $this->sendFailResponse(["old_password"=>[__('messages.old_and_new_pwd_not_same')]], 422);
            }
            User::where('id', $userId)->update(['password' => Hash::make($newPassword)]);
            return $this->sendSuccessResponse(__('messages.pwd_change_success'), 200);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while changing password";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get users profile details
    public function profileDetail($uuid)
    {
        try {

            $employee = Employee::where('uuid', $uuid)->first();
           
            $user = User::where('entity_id', $employee->id)->first();
            $employee->email = $user->email;
            $employee->join_date = !empty($employee->join_date) ? convertUTCTimeToUserTime($employee->join_date) : '';
            $employee->dob = !empty($employee->dob) ? convertUTCTimeToUserTime($employee->dob) : '';
            $employee->first_job_start_date = !empty($employee->first_job_start_date) ? convertUTCTimeToUserTime($employee->first_job_start_date) : '';
            $employee->reliving_date = !empty($employee->reliving_date) ? convertUTCTimeToUserTime($employee->reliving_date) : '';
            $role = $user->roles->pluck('id');
            $address = Address::where('entity_id', $employee->id)->where('entity_type_id', EntityType::Employee)->where('organization_id', $employee->organization_id)->get();
            $permanent_address = $present_address = '';
            foreach ($address as $value) {
                if ($value->address_type_id === AddressType::PERMANENT) {
                    $permanent_address = $value;
                } else {
                    $present_address = $value;
                }
                $employee->address = ['permanent_address' => $permanent_address, 'present_address' => $present_address];
            }

            $skills = EmployeeSkill::where('employee_id', $employee->id)->where('organization_id', $employee->organization_id)->get()->pluck('skill_id')->toArray();
            $employee->skills = $skills;
            $contacts = EmployeeContact::where('employee_id', $employee->id)->where('organization_id', $employee->organization_id)->get(['name', 'relation_id', 'phone_no as mobile']);
            $employee->contacts = $contacts;
            $employeementType = EmployeementType::where('id', $employee->employeement_type_id)->select('id', 'display_label')->get();
            $employee->employeementType = $employeementType;
            $designation = Designation::where('id', $employee->designation_id)->select('id', 'name')->get();
            $employee->designation = $designation;
            $department = Department::where('id', $employee->department_id)->select('id', 'name')->get();
            $employee->department = $department;
            $roles = Role::whereIn('id', $role)->select('id', 'name')->get();
            $employee->roles = $roles;

            return $this->sendSuccessResponse(__('messages.success'), 200, $employee);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get employee details";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    // Update user profile details
    public function updateProfile(Request $request)
    {
        DB::beginTransaction();
        try {
            $inputs = json_decode($request->data,true);
            $employee = Employee::where('uuid', $inputs['uuid'])->first();
            $request->merge($inputs);
            $validation = $this->userValidator->validateUpdateUserProfile($request, $employee);
            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $employeeId = $employee->id;
            $organizationId = $this->getCurrentOrganizationId();

            if ($request->hasFile('avatar_url')) {
                $path = config('constant.avatar');
                $file = $this->uploadFileOnLocal($request->file('avatar_url'), $path);
                $avatar_url =  $file['file_name'];
            }

            $data = [
                'first_name' => $inputs['first_name'] ?? null,
                'middle_name' => $inputs['middle_name'] ?? null,
                'last_name' => $inputs['last_name'] ?? null,
                'display_name' => $inputs['display_name'] ?? null,
                'mobile' =>  $inputs['mobile'] ?? null,
                'skype_id' =>  $inputs['skype_id'] ?? null,
            ];
           
            if (!empty($avatar_url)) {
                $data['avatar_url'] = $avatar_url;
            }
            Employee::where('uuid', $inputs['uuid'])->update($data);

            EmployeeSkill::where('employee_id', $employeeId)->delete();
            if (!empty($inputs['skills'])) {
                $skills = $inputs['skills'];
                foreach ($skills as $value) {
                    $data = [
                        'employee_id' => $employeeId,
                        'organization_id' => $organizationId,
                        'skill_id' => $value['id'],
                    ];

                    EmployeeSkill::create($data);
                }
            }

            $user = User::where('entity_id', $employee->id)->where('entity_type_id', EntityType::Employee)->first();

            if (!empty($inputs['email'])) {
                $user->update([
                    'email' => $inputs['email']
                ]);
            }

            if (isset($inputs['present_address1'])) {
                $address = Address::where('entity_id', $employee->id)->where('entity_type_id', EntityType::Employee)->where('address_type_id', AddressType::PRESENT)->first();
                if ($address) {
                    $address->update([
                        'address' => $inputs['present_address1'],
                        'address2' =>  !empty($inputs['present_address2']) ? $inputs['present_address2']: null,
                        'country_id' => !empty($inputs['present_country']) ? $inputs['present_country'] : null,
                        'city_id' => !empty($inputs['present_city']) ?$inputs['present_city'] : null,
                        'state_id' => !empty($inputs['present_state']) ? $inputs['present_state'] : null,
                        'zipcode' => !empty($inputs['present_zipcode']) ? $inputs['present_zipcode'] :  null
                    ]);
                } else {
                    $employee['address'] = Address::create([
                        'address' => $inputs['present_address1'],
                        'address2' =>  !empty($inputs['present_address2']) ? $inputs['present_address2']: null,
                        'country_id' => !empty($inputs['present_country']) ? $inputs['present_country'] : null,
                        'city_id' => !empty($inputs['present_city']) ?$inputs['present_city'] : null,
                        'state_id' => !empty($inputs['present_state']) ? $inputs['present_state'] : null,
                        'zipcode' => !empty($inputs['present_zipcode']) ? $inputs['present_zipcode'] :  null,
                        'entity_id' => $employee->id,
                        'entity_type_id' => EntityType::Employee,
                        'organization_id' => $organizationId,
                        'address_type_id' => AddressType::PRESENT
                    ]);
                }
            }

            if (isset($inputs['permanent_address1'])) {
                $address = Address::where('entity_id', $employee->id)->where('entity_type_id', EntityType::Employee)->where('address_type_id', AddressType::PERMANENT)->first();
    
                if ($address) {
                    $address->update([
                        'address' => $inputs['permanent_address1'],
                        'address2' =>  !empty($inputs['permanent_address2']) ? $inputs['permanent_address2'] : null,
                        'country_id' => !empty($inputs['permanent_country']) ? $inputs['permanent_country'] : null,
                        'city_id' => !empty($inputs['permanent_city']) ? $inputs['permanent_city'] : null,
                        'state_id' => !empty($inputs['permanent_state']) ? $inputs['permanent_state'] : null,
                        'zipcode' => !empty($inputs['permanent_zipcode']) ? $inputs['permanent_zipcode'] : null
                    ]);
                } else {
                    $employee['address'] = Address::create([
                        'address' => $inputs['permanent_address1'],
                        'address2' =>  !empty($inputs['permanent_address2']) ? $inputs['permanent_address2'] : null,
                        'country_id' => !empty($inputs['permanent_country']) ? $inputs['permanent_country'] : null,
                        'city_id' => !empty($inputs['permanent_city']) ? $inputs['permanent_city'] : null,
                        'state_id' => !empty($inputs['permanent_state']) ? $inputs['permanent_state'] : null,
                        'zipcode' => !empty($inputs['permanent_zipcode']) ? $inputs['permanent_zipcode'] : null,
                        'entity_id' => $employee->id,
                        'entity_type_id' => EntityType::Employee,
                        'organization_id' => $organizationId,
                        'address_type_id' => AddressType::PERMANENT
                    ]);
                }
            }

            EmployeeContact::where('employee_id', $employeeId)->where('organization_id', $organizationId)->delete();
            if (!empty($inputs['contacts'])) {
                $contacts = $inputs['contacts'];
                foreach ($contacts as $value) {

                    $contactData = [
                        'employee_id' => $employeeId,
                        'organization_id' => $organizationId,
                        'name' =>  $value['name'] ?? null,
                        'phone_no' =>  $value['mobile'] ?? null,
                        'relation_id' => $value['relation_id'] ?? null,
                    ];

                    EmployeeContact::create($contactData);
                }
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.user_profile_update'), 200);
        } catch (\Throwable $ex) {           
            $logMessage = "Something went wrong while update employee details";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get users preference settings
    public function getSettings()
    {
        try {
            $data = [];

            $data['dateFormats'] = DateFormat::select('id as value', 'label')->get();
            $data['timezones'] = Timezone::select('id as value', 'label')->get();

            $response = [
                'data' => $data,            
            ];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while getting users settings";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    // Update user settings
    public function updateSetting(Request $request)
    {
        DB::beginTransaction();
        try {
            
            $timezone = $request->timezone;
            $dateFormat = $request->date_format;
            
            if($dateFormat){
                Auth::user()->update(['date_format_id'=>$dateFormat]);
            }
            if($timezone){
                Auth::user()->update(['timezone_id'=>$timezone]);
            }

            DB::commit();
            
            return $this->sendSuccessResponse(__('messages.success'), 200);

        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while update users settings";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get email notification settings
    public function getEmailNotificationSettings()
    {
        try {

            $userId = Auth::user()->id;
            $emailNotifications = EmailNotification::where('user_id', $userId)->get();

            $response = [
                'data' => $emailNotifications,            
            ];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while getting users email notification settings";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }   
    }

    //Update email notification settings
    public function updateEmailNotificationSettings(Request $request)
    {
        DB::beginTransaction();
        try {
            
            $inputs = $request->all();

            $userId = Auth::user()->id;

            $data = [
                'allow_all_notifications' => $inputs['allow_all_notifications'] ?? 0,
                'assign_task' => $inputs['assign_task'] ?? 0,
                'delete_task' => $inputs['delete_task'] ?? 0,
                'create_project' => $inputs['create_project'] ?? 0,
                'assign_project' => $inputs['assign_project'] ?? 0,
                'create_customer' => $inputs['create_customer'] ?? 0,
                'approve_timesheet' => $inputs['approve_timesheet'] ?? 0,
                'reject_timesheet' => $inputs['reject_timesheet'] ?? 0,
                'create_system' => $inputs['create_system'] ?? 0,
                'assign_system' => $inputs['assign_system'] ?? 0,
                'delete_system' => $inputs['delete_system'] ?? 0,
                'create_device' => $inputs['create_device'] ?? 0,
                'assign_device' => $inputs['assign_device'] ?? 0,
                'delete_device' => $inputs['delete_device'] ?? 0,
            ];
          
            EmailNotification::where('user_id', $userId)->update($data);
            
            DB::commit();
            
            return $this->sendSuccessResponse(__('messages.success'), 200);

        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while update users email notification settings";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
