<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use App\Traits\ResponseTrait;
use App\Models\User;
use App\Models\City;
use App\Models\State;
use App\Models\Country;
use App\Models\Employee;
use App\Models\EntityType;
use App\Models\Department;
use App\Models\Designation;
use App\Models\EmployeeSkill;
use App\Models\EmployeeInvitation;
use App\Jobs\SendEmailJob;
use App\Models\Address;
use App\Models\AddressType;
use App\Models\EmployeeContact;
use Carbon\Carbon;
use DB, Lang, Log, Auth;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    use ResponseTrait;

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try
        {
            $organizationId = $this->getCurrentOrganizationId();
            $data = Employee::withoutGlobalScopes([OrganizationScope::class])->active()->where('employees.organization_id',$organizationId)->select('employees.id','employees.organization_id','employees.display_name','employees.avatar_url')->get();
            
            return $this->sendSuccessResponse(__('messages.success'), 200, $data);

        } catch (\Exception $ex) {
            $logMessage = "Something went wrong while changing password";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    /**
     * Active Employee List
     *
     *
     */
    public function activeEmployeeList(Request $request)
    {
        try
        {
            $organizationId = $this->getCurrentOrganizationId();

            $data = Employee::withoutGlobalScopes()
                ->join('users', 'employees.id', '=', 'users.entity_id')
                ->where('is_active','=',1)
                ->where('employees.organization_id', $organizationId)
                ->where('users.organization_id', $organizationId)
                ->select('employees.uuid','employees.organization_id','employees.reliving_date','employees.first_job_start_date','employees.id','display_name', 'avatar_url', 'join_date', 'first_name', 'last_name')
                ->get();

            foreach ($data as $value) {
                if (!empty($value->first_job_start_date)) {
                    $today = Carbon::now()->format("Y-m-d");
                    $to = \Carbon\Carbon::createFromFormat('Y-m-d', $today);
                    $from = \Carbon\Carbon::createFromFormat('Y-m-d', $value->first_job_start_date);
                    $diff_in_months = $to->diffInMonths($from);
                    $exp = round(($diff_in_months / 12), 1);
                    $value->exp = $exp;
                } else {
                    $value->exp = null;
                }
                $value->join_date = $value->join_date ?  convertUTCTimeToUserTime($value->join_date) : '';
                $value->reliving_date = !empty($value->reliving_date) ? convertUTCTimeToUserTime($value->reliving_date) : "";
            }

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        
        } catch (\Exception $ex) {
            $logMessage = "Something went wrong while changing password";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    /**
     * Get Employee Information
     *
     * @param Employee $employee
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request)
    {
        try{
            $employee = Employee::where('uuid', $uuid)->first();
           
            $user = User::where('entity_id', $employee->id)->first();
            $employee->email = $user->email;
            $employee->join_date = !empty($employee->join_date) ? convertUTCTimeToUserTime($employee->join_date, 'Y-m-d') : '';
            $employee->dob = !empty($employee->dob) ? convertUTCTimeToUserTime($employee->dob) : '';
            $employee->first_job_start_date = !empty($employee->first_job_start_date) ? convertUTCTimeToUserTime($employee->first_job_start_date) : '';
            $employee->reliving_date = !empty($employee->reliving_date) ? convertUTCTimeToUserTime($employee->reliving_date) : '';
            $employee->role = $user->roles->pluck('id');
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

            $employee['temp_address']['temp_state'] = State::whereKey($employee['temp_address']->state_id)->first();
            $employee['temp_address']['temp_country'] = Country::whereKey($employee['temp_address']->country_id)->first();
            
            return $this->sendSuccessResponse(Lang::get('messages.employee.list'),200,$employee);
        
        }  catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get employee details";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    /**
     * Get Employee list by skill
     */

    public function getUsersBySkill(Request $request)
    {
        try{
            $empData = [];
            $inputs = $request->all();

            $empDataQuery = User::join('employees', 'employees.id', 'users.entity_id')
                ->leftjoin('employee_skills', 'employees.id', 'employee_skills.employee_id')
                ->whereEntityTypeId(EntityType::Employee)
                ->whereIsActive(1);


            if ($inputs['skill_id'] != 0) {
                $empDataQuery->where('employee_skills.skill_id', $inputs['skill_id']);
            }
            $empData = $empDataQuery->select(
                'employees.id as id',
                'employees.display_name as name',
                'users.email as email')
                ->groupBy('employees.id')
                ->get();

            $i = 0;

            foreach ($empData as $data) {
                $i++;
                $data->serial_no = $i;
                $data->skills = EmployeeSkill::join('skills', 'employee_skills.skill_id', '=', 'skills.id')
                    ->where('employee_skills.employee_id', "=", $data->id)
                    ->pluck('skills.name AS name')
                    ->toArray();
            }

            return $this->sendSuccessResponse(Lang::get('messages.list'),200,$empData);
        } catch (\Exception $e) {
            Log::info($e);
            $response['message'] = $e->getMessage();
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }
}
