<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Models\Skill;
use App\Models\EmployeeSkill;
use App\Validators\ProfileValidator;
use Illuminate\Http\Request;
use App\Traits\ResponseTrait;
use App\Validators\UserValidator;
use Hash;
use Storage;
use Lang;
use Log, Auth;
use DB;
use App\Traits\UploadFileTrait;

class ProfileController extends Controller
{
    use ResponseTrait, UploadFileTrait;
    private $profileValidator;

    function __construct()
    {
        $this->profileValidator = new UserValidator();
    }
    /**
     *Get all skills
     *
     *
     */
    public function getSkill()
    {
        try{
            $skills = Skill::select('id','name')->get();
            return $this->sendSuccessResponse(__('messages.success'), 200, $skills);        
        }
        catch (\Exception $ex) {
            $logMessage = "Something went wrong while getting skills";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    /**
     * Display a the resource.
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     *
     */
    public function showProfile()
    {
        try {
            $user = Auth::user();
            $employee = Employee::where('id', $user->entity_id)->first();
            $employee->email = $user->email;
            $skills = EmployeeSkill::where('employee_id', $employee->id)->where('organization_id', $employee->organization_id)->get()->pluck('skill_id')->toArray();
            $employee->skills = $skills;

            return $this->sendSuccessResponse(__('messages.success'), 200, $employee);
        }catch (\Exception $ex) {
            $logMessage = "Something went wrong while getting profile";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        try{
            DB::beginTransaction();
            $user = Auth::user();
            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $validation = $this->profileValidator->validateUpdateUserProfile($request, $user);
         
            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }
            $profileEmpData = [
                'first_name'=>$inputs['first_name'],
                'last_name'=>$inputs['last_name'],
                'mobile'=>$inputs['mobile'],
            ];
            if ($request->hasFile('avatar_url'))
            {
                $currentAvatarUrl = Employee::where('id',$user->entity_id)->first('avatar_url');
                $path = config('constant.avatar');
               
                if (Storage::disk('public')->exists($path . '/' . $currentAvatarUrl->avatar_url)) {
                    $this->removeFileOnLocal($currentAvatarUrl->avatar_url, $path);
                }
                $file = $this->uploadFileOnLocal($request->file('avatar_url'), $path);
                $profileEmpData['avatar_url'] = $file['file_name'];
            }
            if (!empty($inputs['skills'])) {
                EmployeeSkill::where('employee_id', $user->entity_id)->delete();
                $createdSkills = [];
                foreach ($inputs['skills'] as $skill) {
                    $data = [
                        'employee_id' => $user->entity_id,
                        'organization_id' => $organizationId,
                        'skill_id' => $skill,
                    ];
                    $createdSkills[] = EmployeeSkill::create($data);
                }
            }
            Employee::where('id', $user->entity_id)->update($profileEmpData);
            $profileEmpData['skills'] = $createdSkills;
            DB::commit();
            return $this->sendSuccessResponse(__('messages.profile_success'), 200, $profileEmpData);   
        } catch (\Exception $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while getting skill";           
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    public function updatePassword(Request $request)
    {
        try {
            DB::beginTransaction();
            $userId = Auth::user()->id;

            //validate request start
            $userChangePwdValidate = $this->profileValidator->validateUserChangePassword($request);
            if ($userChangePwdValidate->fails()) {
                return $this->sendFailResponse($userChangePwdValidate->errors()->first(), 422);
            }
            //validate request end

            $oldPassword = $request->old_password;
            $newPassword = $request->new_password;

            if ((Hash::check($oldPassword, Auth::user()->password)) == false) {
                return $this->sendFailResponse(["old_password"=>[__('messages.old_pwd_not_valid')]], 422);
            }
            if ((Hash::check($newPassword, Auth::user()->password)) == true) {
                return $this->sendFailResponse(["old_password"=>[__('messages.old_and_new_pwd_not_same')]], 422);
            }
            User::where('id', $userId)->update(['password' => Hash::make($newPassword)]);
            DB::commit();
            return $this->sendSuccessResponse(__('messages.pwd_change_success'), 200);
        }catch (\Exception $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while changing password";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
