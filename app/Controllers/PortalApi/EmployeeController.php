<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Models\Address;
use App\Models\AddressType;
use App\Models\City;
use App\Models\Country;
use App\Models\Department;
use App\Models\Designation;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Employee;
use App\Models\EmployeeContact;
use App\Models\EmployeeInvitation;
use App\Mail\EmployeeInvitation as MailEmployeeInvitation;
use App\Models\ActivityLog;
use App\Models\CountryDateFormat;
use App\Models\DateFormat;
use App\Models\EmailNotification;
use App\Models\EmployeementType;
use App\Models\EmployeeSkill;
use App\Models\EntityType;
use App\Models\ImportEmployeeHistory;
use App\Models\Leave;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceHistory;
use App\Models\LeaveStatus;
use App\Models\LeaveType;
use App\Models\LeaveTypeType;
use App\Models\Organization;
use App\Models\Relation;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\Setting;
use App\Models\Skill;
use App\Models\State;
use App\Models\Timezone;
use Carbon\Carbon;
use App\Traits\ResponseTrait;
use App\Traits\UploadFileTrait;
use App\Validators\EmployeeValidator;
use DB;
use Storage;
use Str;

class EmployeeController extends Controller
{
    use ResponseTrait, UploadFileTrait;
    private $employeeValidator;

    function __construct()
    {
        $this->employeeValidator = new EmployeeValidator();
    }

    //Get all employees
    public function index()
    {
        $organizationId = $this->getCurrentOrganizationId();
        $data = Employee::withoutGlobalScopes([OrganizationScope::class])->active()->where('employees.organization_id',$organizationId)->select('employees.id','employees.organization_id','employees.display_name','employees.avatar_url')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    //Get employees list
    public function getEmployeeList(Request $request)
    {
        $department = $request->department ?? 0;
        $employeeType = $request->employeeType ?? 0;
        $keyword = $request->keyword ??  '';
        $perPage = $request->perPage ??  '';
        $status = $request->status ?? 0;

        // Login user organization id
        $organizationId = $this->getCurrentOrganizationId();

        $data = $this->getEmployees($department, $employeeType, $keyword, $status, $perPage, $organizationId);

        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    //Export employees list
    public function export(Request $request)
    {
        $department = $request->department ?? 0;
        $employeeType = $request->employeeType ?? 0;
        $keyword = $request->keyword ??  '';
        $perPage = $request->perPage ??  '';
        $status = $request->status ?? 0;
        
        // Login user organization id
        $organizationId = $this->getCurrentOrganizationId();

        $data = $this->getEmployees($department, $employeeType, $keyword, $status, $perPage, $organizationId, true);

        $employeeData = $data['employee'];
        
      
        return $this->sendSuccessResponse(__('messages.success'), 200, $employeeData);
    }

    public function getEmployees($department,$employeeType, $keyword, $status, $perPage, $organizationId, $export=false)
    {

        DB::statement(DB::raw('set @row=0'));

        $employeeData = Employee::withoutGlobalScopes([OrganizationScope::class])->join('users', function ($join) {
            $join->on('users.entity_id', '=',  'employees.id');
            $join->on('users.organization_id', '=', 'employees.organization_id');
        })->leftjoin('departments', function ($join) {
            $join->on('employees.department_id', '=',  'departments.id');
            $join->on('departments.organization_id', '=', 'employees.organization_id');
        })->select(DB::raw('@row:=@row+1 as sr_no'),'employees.*', 'users.is_active', 'users.email','departments.name as department')
            ->where('employees.organization_id', $organizationId);

        $employeeData->where('employees.employeement_type_id', $employeeType);

        $employeeData =  $employeeData->where(function ($q1) use ($department, $keyword,$status) {
            if (!empty($department)) {
                $q1->where('departments.id', $department);
            }

            if (isset($status) && $status == true) {
                $q1->where('users.is_active', "=", 0);
            }else{
                $q1->where('users.is_active', "=", 1);
            }

            if (!empty($keyword)) {
                $q1->where(function($q2) use($keyword){
                    $q2->where('employees.display_name', "like", '%'.$keyword.'%');
                    $q2->orWhere('employees.first_name', "like", '%'.$keyword.'%');
                    $q2->orWhere('employees.last_name', "like",'%'.$keyword.'%');
                    $q2->orWhere('users.email', "like",'%'.$keyword.'%');
                });
            }
        });

        $employeeData->whereNull('employees.deleted_at');

        if (isset($status) && $status == true) {
            $employeeData->orderby('employees.reliving_date', 'desc');
        }

        $totalRecords = $employeeData->count();

        $employeeData = $employeeData->orderby('employees.id', 'desc');
        if($export == true){
            $employeeData = $employeeData->get();
        }else{
            $employeeData = $employeeData->paginate($perPage);
        }
        

        foreach ($employeeData as $value) {
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


        $data['employee'] = $employeeData;
        $data['count'] = $totalRecords;

        return $data;
    }

    // Get employeement type list
    public function employeeTypeList()
    {
        $data = EmployeementType::select('id', 'type','display_label')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    // Get relation list
    public function relationList()
    {
        $data = Relation::select('id as value', 'name as label')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    //Get employee details
    public function employeeDetail($uuid)
    {
        try {
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

            //Todo  Add system name for display

            return $this->sendSuccessResponse(__('messages.success'), 200, $employee);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get employee details";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    // Update employees details
    public function updateEmployee(Request $request)
    {
        DB::beginTransaction();
        try {
            $inputs = json_decode($request->data,true);

            $employee = Employee::where('uuid', $inputs['uuid'])->first();

            $request->merge($inputs);

            $validation = $this->employeeValidator->validateUpdate($request, $employee);

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
                'dob' => !empty($inputs['dob']) ? convertUserTimeToUTC($inputs['dob']) : null,
                'join_date' => !empty($inputs['join_date']) ? convertUserTimeToUTC($inputs['join_date']) : null,
                'first_job_start_date' => !empty($inputs['first_job_start_date']) ? convertUserTimeToUTC($inputs['first_job_start_date']) : null,
                'reliving_date' => !empty($inputs['reliving_date']) ? convertUserTimeToUTC($inputs['reliving_date']) : null,
                'mobile' =>  $inputs['mobile'] ?? null,
                'designation_id' => $inputs['designation'] ?? null,
                'department_id' => $inputs['department'] ?? null,
                //'skype_id' =>  $inputs['skype_id'] ?? null,
                'ctc' =>  $inputs['ctc'] ?? null,
                'uhid_no' =>  $inputs['uhid_no'] ??  null,
                'uan_no' =>  $inputs['uan_no'] ??  null,
                'availability_comments' => isset($inputs['available_comment']) ? $inputs['available_comment'] : null,
                'on_notice_period' => isset($inputs['on_notice_period']) ? $inputs['on_notice_period'] : 0,
                'on_bench' =>  isset($inputs['on_bench']) ? $inputs['on_bench'] : 0,
                'working_on_dedicated_project' => isset($inputs['dedicated_project']) ? $inputs['dedicated_project'] : 0,
                'do_not_required_punchinout' => isset($inputs['punch_in_out']) ? $inputs['punch_in_out'] : 0,
                'timesheet_filling_not_required' => isset($inputs['timesheet_filling_not_required']) ? $inputs['timesheet_filling_not_required'] : 0,
                'employeement_type_id' => isset($inputs['employeement_type_id']) ? $inputs['employeement_type_id'] : EmployeementType::PERMANENT,
                'resign_date' => !empty($inputs['resign_date']) ? convertUserTimeToUTC($inputs['resign_date']) : null,
                'probation_period_end_date' => !empty($inputs['probation_period_end_date'])  ? convertUserTimeToUTC($inputs['probation_period_end_date']) : null, 
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
            if (!empty($inputs['roles'])) {
                $user->roles()->detach();
                foreach($inputs['roles'] as $value){
                    $role = Role::find($value);
                    $user->assignRole($role);
                }
            }

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

            if((!empty($inputs['on_notice_period']) && $employee->on_notice_period == false) || (empty($inputs['on_notice_period']) && $employee->on_notice_period == true)){
               $setting =  Setting::join('organization_settings', 'settings.id', 'organization_settings.setting_id')->where('key', 'allow_leave_during_notice_period')->where('organization_id', $organizationId)->first('organization_settings.value');
               if($setting->value == false){
                $leaveTypes = LeaveType::withoutGlobalScopes([OrganizationScope::class])->where('leave_type_type_id', '!=', LeaveTypeType::CompensatoryOffID)->where('organization_id', $organizationId)->whereNull('leave_types.deleted_at')->select('id', 'name', 'accrual', 'accrual_period', 'accrual_date', 'accrual_month', 'no_of_leaves')->get();

                    foreach ($leaveTypes as $type) {
                        if (!empty($type->accrual)) {
                            $accrualPeriod = $type->accrual_period;
                            $accrualDate = $type->accrual_date;
                            $accrualMonth = $type->accrual_month;
                            $totalLeaves = $type->no_of_leaves;
                            $date = date('j', strtotime($inputs['resign_date']));
                            $month = date('n', strtotime($inputs['resign_date']));

                            $leaveBalanceRecord = LeaveBalance::where('employee_id', $employeeId)->where('organization_id', $organizationId)->where('leave_type_id', $type->id)->orderBy('id', 'desc')->first(['balance', 'id']);

                            $periodConfig = config('constant.job_schedule_period');
                            if ($accrualPeriod == $periodConfig['Yearly']) {
                                $accuredLeave = round(((12 - $month) * round(($totalLeaves / 12), 2)), 2);
                            }

                            if ($accrualPeriod == $periodConfig['Half yearly']) {
                                $monthList = config('constant.half_year_month_list');
                                $accrualMonth = $monthList[$type->accrual_month];
                                if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                                    $month = $month - $accrualMonth[0];
                                } else if ($month < $accrualMonth[0]) {
                                    $month = $accrualMonth[0] - $month;
                                } else if ($month > $accrualMonth[1]) {
                                    $month = $month - $accrualMonth[1];
                                }
                                $accuredLeave = round(((6 - $month) * round(($totalLeaves / 6), 2)), 2);

                            }

                            if ($accrualPeriod == $periodConfig['Quarterly']) {
                                $monthList = config('constant.quartarly_month_list');
                                $accrualMonth = $monthList[$type->accrual_month];

                                if ($month >= $accrualMonth[0] && $month < $accrualMonth[1]) {
                                    $month = $month - $accrualMonth[0];
                                }

                                if ($month >= $accrualMonth[1] && $month < $accrualMonth[2]) {
                                    $month = $month - $accrualMonth[1];
                                }

                                if ($month >= $accrualMonth[2] && $month < $accrualMonth[3]) {
                                    $month = $month - $accrualMonth[2];
                                }

                                if ($month >= $accrualMonth[3] && $month < $accrualMonth[0]) {
                                    $month = $month - $accrualMonth[3];
                                }

                                if ($month >= $accrualMonth[3] && $month >= $accrualMonth[0]) {
                                    $month = $month - $accrualMonth[0];
                                }

                                if ($month < $accrualMonth[0]) {
                                    $month = $accrualMonth[0] - $month;
                                }
                                $accuredLeave = round(((3 - $month) * round(($totalLeaves / 3), 2)), 2);

                            }

                            if ($accrualPeriod == $periodConfig['Monthly']) {

                                $firstDay = Carbon::parse($inputs['resign_date'])->startOfMonth();
                                $lastDay = Carbon::parse($inputs['resign_date'])->endOfMonth();
                                $diff = $firstDay->diffInDays($lastDay->addDay()->startOfDay());
                                $today = date('d', strtotime($inputs['resign_date']));
                                $accuredLeave = round((($diff - $today) * round(($totalLeaves / $diff), 2)), 2);
                            }

                            if (!empty($inputs['on_notice_period']) && $employee->on_notice_period == false) {
                                $logData = ['organization_id' => $organizationId, 'new_data' => json_encode(["display_name" => $employeeId]), 'old_data' => NULL, 'action' => $accuredLeave . ' leave of ' . $type->name . ' reduced for ' . $employee->display_name . ' as serving notice period', 'table_name' => 'employees', 'updated_by' => '', 'module_id' => $type->id, 'module_name' => 'LMS'];
                                $activityLog = new ActivityLog();
                                $activityLog->createLog($logData);

                                $balanceHistory = ($leaveBalanceRecord->balance - $accuredLeave) >= 0 ? ($leaveBalanceRecord->balance - $accuredLeave) : 0;
                                LeaveBalance::where('id', $leaveBalanceRecord->id)->update(['balance' => $balanceHistory]);
                                $this->addLeaveBalanceHistory($type->id, $employee->id, $organizationId, $balanceHistory);
                            }

                            if (empty($inputs['on_notice_period']) && $employee->on_notice_period == true) {
                                $leaveBalanceRecord = LeaveBalanceHistory::where('employee_id', $employeeId)->where('organization_id', $organizationId)->where('leave_type_id', $type->id)->orderBy('id', 'desc')->select('balance', 'id')->offset(1)->limit(1)->get();

                                $leaveBalance = LeaveBalance::where('employee_id', $employeeId)->where('organization_id', $organizationId)->where('leave_type_id', $type->id)->orderBy('id', 'desc')->first(['balance', 'id']);
                                LeaveBalance::where('id', $leaveBalance->id)->update(['balance' => $leaveBalanceRecord[0]->balance]);

                                $logData = ['organization_id' => $organizationId, 'new_data' => json_encode(["display_name" => $employeeId]), 'old_data' => NULL, 'action' => $leaveBalanceRecord[0]->balance . ' leave of ' . $type->name . ' added for ' . $employee->display_name . ' as withdraw resign', 'table_name' => 'employees', 'updated_by' => '', 'module_id' => $type->id, 'module_name' => 'LMS'];
                                $activityLog = new ActivityLog();
                                $activityLog->createLog($logData);

                                $this->addLeaveBalanceHistory($type->id, $employee->id, $organizationId, $leaveBalanceRecord[0]->balance);
                            }
                        }
                    }
                }
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.employee_update'), 200);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while update employee details";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function addLeaveBalanceHistory($leaveType, $employeeId, $organizationId , $balance)
    {
        $totalBalance = 0;
        $leaveBalance = LeaveBalance::where('employee_id', $employeeId)->where('organization_id', $organizationId)->where('leave_type_id' , $leaveType)->first('balance');
        if(!empty($leaveBalance)){
            $totalBalance = $leaveBalance->balance;
        }
        LeaveBalanceHistory::create([
            'employee_id' => $employeeId,
            'organization_id' => $organizationId,
            'leave_type_id' => $leaveType,
            'balance' => $balance,
            'total_balance' => $totalBalance
        ]);
    }
    
    // Change employee status
    public function changeStatus(Request $request)
    {   
        DB::beginTransaction();
        try {
            $uuid = $request->uuid;
            
            $employee = Employee::where('uuid', $uuid)->first();            
            $user = User::where('entity_type_id', EntityType::Employee)
                        ->where('entity_id', $employee->id)
                        ->where('organization_id', $employee->organization_id)->first();
            
            $user->is_active = !$user->is_active;
            $user->save();

            $data = ['id' => $employee->id, 'is_active' => $user->is_active];

            DB::commit();
            return $this->sendSuccessResponse(__('messages.employee_change_status'), 200, $data);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while send invitation";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    
    // Delete employee status
    public function deleteEmployee(Request $request)
    {   
        DB::beginTransaction();
        try {
            $uuid = $request->uuid;
            $employee = Employee::where('uuid', $uuid)->first();

            $user = User::where('entity_type_id', EntityType::Employee)
                        ->where('entity_id', $employee->id)
                        ->where('organization_id', $employee->organization_id)->first();
            
            // remove from employee table
            Employee::where('uuid', $uuid)->delete();

            // remove from employee invitation table
            // $employeeInvitation = EmployeeInvitation::where('organization_id', $employee->organization_id)
            // ->where('email', $user->email)->first();
            // $employeeInvitation->delete();
            
            // remove from users table
            $user->delete();

            DB::commit();
            return $this->sendSuccessResponse(__('messages.employee_deleted'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while send invitation";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Read files and send all the fields
    public function importEmployees(Request $request){

        try{

            $file = $request->file('employee_csv');
            if(!empty($file)){
                $i = 1;
            
                $record = [];
                if (($handle = fopen($file, "r")) !== FALSE) {
                    $columns = fgetcsv($handle, 1000, ",");
                    
                    while (($row = fgetcsv($handle, 1000, ",")) !== false) {
                        
                        $record[] = array_combine($columns, $row);

                        $i++;
                    }
                    
                    fclose($handle);
                    
                }
            }

            $fields = [
                [
                    'value' => 'id',
                    'label' => 'Employee Id'
                ],
                [
                    'value' => 'first_name',
                    'label' => 'First Name'
                ],
                [
                    'value' => 'last_name',
                    'label' => 'Last Name'
                ],
                [
                    'value' => 'middle_name',
                    'label' => 'Middle Name'
                ],
                [
                    'value' => 'display_name',
                    'label' => 'Display Name'
                ],
                [
                    'value' => 'email',
                    'label' => 'Email'
                ],
                [
                    'value' => 'mobile',
                    'label' => 'Mobile'
                ],
                [
                    'value' => 'designation',
                    'label' => 'Designation'
                ],
                [
                    'value' => 'ctc',
                    'label' => 'CTC'
                ],
                [
                    'value' => 'department',
                    'label' => 'Department'
                ],
                [
                    'value' => 'role',
                    'label' => 'Role'
                ],
                [
                    'value' => 'skill',
                    'label' => 'Skill'
                ],
                [
                    'value' => 'dob',
                    'label' => 'Date Of Birth'
                ],
                [
                    'value' => 'join_date',
                    'label' => 'Join Date'
                ],
                [
                    'value' => 'first_job_start_date',
                    'label' => 'Career Start Date'
                ],
                [
                    'value' => 'reliving_date',
                    'label' => 'Relieving Date'
                ],
                [
                    'value' => 'uhid_no',
                    'label' => 'Medical UHID No'
                ],
                [
                    'value' => 'uan_no',
                    'label' => 'EPFO UAN No'
                ],
                [
                    'value' => 'present_address',
                    'label' => 'Present Address'
                ],
                [
                    'value' => 'present_address2',
                    'label' => 'Present Address2'
                ],
                [
                    'value' => 'present_city',
                    'label' => 'Present City'
                ],
                [
                    'value' => 'present_state',
                    'label' => 'Present State'
                ],
                [
                    'value' => 'present_country',
                    'label' => 'Present Country'
                ],
                [
                    'value' => 'present_zipcode',
                    'label' => 'Present Zipcode'
                ],
                [
                    'value' => 'permanent_address',
                    'label' => 'Permanent Address'
                ],
                [
                    'value' => 'permanent_address2',
                    'label' => 'Permanent Address2'
                ],
                [
                    'value' => 'permanent_city',
                    'label' => 'Permanent City'
                ],
                [
                    'value' => 'permanent_state',
                    'label' => 'Permanent State'
                ],
                [
                    'value' => 'permanent_country',
                    'label' => 'Permanent Country'
                ],
                [
                    'value' => 'permanent_zipcode',
                    'label' => 'Permanent Zipcode'
                ],
                [
                    'value' => 'working_on_dedicated_project',
                    'label' => 'Woring on dedicated project'
                ],
                [
                    'value' => 'on_bench',
                    'label' => 'On bench'
                ],
                [
                    'value' => 'on_notice_period',
                    'label' => 'Serving notice period'
                ],
                [
                    'value' => 'do_not_required_punchinout',
                    'label' => 'Do not required punch in out'
                ],
                [
                    'value' => 'timesheet_filling_not_required',
                    'label' => 'Timesheet Filling not Required'
                ],
                [
                    'value' => 'availability_comments',
                    'label' => 'Availability comments'
                ],
                [
                    'value' => 'emergency_contact',
                    'label' => 'Emergency contacts'
                ]
            ];

            

            $response = ['header' => $columns, 'records' => $record, 'fields' => $fields];
            $response = mb_convert_encoding($response, "UTF-8", "auto");
            
            return $this->sendSuccessResponse(__('messages.employee_imported'), 200, $response);

        }catch (\Throwable $ex) {
            $logMessage = "Something went wrong while import employee csv";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
        
    }

    //Check the duplicate entries from the import employee csv
    public function checkDuplicateEntries(Request $request){
        try{
            if(!empty($request->email)){
                $email = $request->email;
            }
    
            $duplicate = [];
            $usersData = [];
            if(!empty($request->records)){
                foreach($request->records as $record){
                    if(!empty($record[$email]) && filter_var($record[$email], FILTER_VALIDATE_EMAIL) && preg_match('/@.+\./', $record[$email])){

                        $user = User::where('email',$record[$email])->first(['email','entity_id']);
                    
                        if(!empty($user)){
                            $duplicate[] = $user;
                        } else{
                            $usersData[] = $record;
                        }
                    }else{
                        return $this->sendFailResponse(__('messages.invalid_email_warning'), 422);
                    }
                }
            }
    
            $duplicateCount = count($duplicate);
          
            $response = ['duplicates' => $duplicate, 'duplicate_count' => $duplicateCount, 'records' => $usersData];
            
            return $this->sendSuccessResponse(__('messages.employee_imported'), 200, $response);

        }catch (\Throwable $ex) {
            $logMessage = "Something went wrong while import employee csv";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Import employee in database
    public function sendEmployeeImportInvitation(Request $request){

        try{
            DB::beginTransaction();

            $organizationId = $this->getCurrentOrganizationId();
            $organization = Organization::where('id', $organizationId)->first(['organization_name']);
            $records = $request->records;
            $fields = $request->selectedFields;
            $mappedField = array_map(function ($item) {
                return array($item['value'] => $item['header']);
            }, $fields);
            $mappedField = array_merge(...$mappedField);
            $duplicate = 0;
            $added = 0;

            if(!empty($records)){

                $import = ImportEmployeeHistory::create([
                    'organization_id' => $organizationId,
                    'total_added' => $added,
                    'total_skipped' => $duplicate,
                    'total_imported' => $added,
                    'imported_by' => $request->user()->id
                ]);

                foreach($records as $record){
                    $employeeId = !empty($mappedField['id']) ? $record[$mappedField['id']] : null;
                    $email = !empty($mappedField['email']) ? $record[$mappedField['email']] : null;
                    $firstName = !empty($mappedField['first_name']) ? $record[$mappedField['first_name']] : null;
                    $lastName = !empty($mappedField['last_name']) ? $record[$mappedField['last_name']] : null;
                    $middleName = !empty($mappedField['middle_name']) ? $record[$mappedField['middle_name']] : null;
                    $displayName = !empty($mappedField['display_name']) ? $record[$mappedField['display_name']] : null;
                    $department = !empty($mappedField['department']) ? $record[$mappedField['department']] : null;
                    $designation = !empty($mappedField['designation']) ? $record[$mappedField['designation']] : null;
                    $joinDate = (!empty($mappedField['join_date']) && $record[$mappedField['join_date']])? date('Y-m-d H:i:s', strtotime($record[$mappedField['join_date']])) : getUtcDate();
                    $dob = (!empty($mappedField['dob']) && $record[$mappedField['dob']]) ? date('Y-m-d H:i:s', strtotime($record[$mappedField['dob']])) : null;
                    $firstJobStartDate = (!empty($mappedField['first_job_start_date']) && $record[$mappedField['first_job_start_date']]) ? date('Y-m-d H:i:s', strtotime($record[$mappedField['first_job_start_date']])) : null;
                    $relivingDate = (!empty($mappedField['reliving_date']) &&  $record[$mappedField['reliving_date']]) ?  date('Y-m-d H:i:s', strtotime($record[$mappedField['reliving_date']])) : null;
                    $mobile = !empty($mappedField['mobile']) ? $record[$mappedField['mobile']] : null;
                    $ctc = !empty($mappedField['ctc']) ? $record[$mappedField['ctc']] : null;
                    $uhidNo = !empty($mappedField['uhid_no']) ? $record[$mappedField['uhid_no']] : null;
                    $uanNo = !empty($mappedField['uan_no']) ? $record[$mappedField['uan_no']] : null;
                    $availabilityComment = !empty($mappedField['availability_comments']) ? $record[$mappedField['availability_comments']] : null;
                    $skill = !empty($mappedField['skill']) ? $record[$mappedField['skill']] : null;
                    $presentAddress = !empty($mappedField['present_address']) ? $record[$mappedField['present_address']] : null;
                    $presentAddress2 = !empty($mappedField['present_address2']) ? $record[$mappedField['present_address2']] : null;
                    $presentCountry = !empty($mappedField['present_country']) ? $record[$mappedField['present_country']] : null;
                    $presentCity = !empty($mappedField['present_city']) ? $record[$mappedField['present_city']] : null;
                    $presentState = !empty($mappedField['present_state']) ? $record[$mappedField['present_state']] : null;
                    $presentZipcode = !empty($mappedField['present_zipcode']) ? $record[$mappedField['present_zipcode']] : null;
                    $permanentAddress = !empty($mappedField['permanent_address']) ? $record[$mappedField['permanent_address']] : null;
                    $permanentAddress2 = !empty($mappedField['permanent_address2']) ? $record[$mappedField['permanent_address2']] : null;
                    $permanentCountry = !empty($mappedField['permanent_country']) ? $record[$mappedField['permanent_country']] : null;
                    $permanentCity = !empty($mappedField['permanent_city']) ? $record[$mappedField['permanent_city']] : null;
                    $permanentState = !empty($mappedField['permanent_state']) ? $record[$mappedField['permanent_state']] : null;
                    $permanentZipcode = !empty($mappedField['permanent_zipcode']) ? $record[$mappedField['permanent_zipcode']] : null;
                    $emergencyContact = !empty($mappedField['emergency_contact']) ? $record[$mappedField['emergency_contact']] : null;

                    $user = User::where('email',$email)->first(['email']);
                    
                    if(!empty($user)){
                        $duplicate++;
                        if(end($records) == $record) {
                            // last iteration
                            GoTo ENDLoop;
                        }
                        continue;
                    }
                    if(!empty($employeeId)){
                        $employee = Employee::where('organization_id', $organizationId)->where('id', $employeeId)->first();
                        if(empty($employee)){
                            $id = $employeeId;
                        }else{
                            $duplicate++;
                            if(end($records) == $record) {
                                // last iteration
                                GoTo ENDLoop;
                            }
                            continue;
                        }
                    }else{
                        $employee = Employee::where('organization_id', $organizationId)->orderBy('id', 'desc')->withTrashed()->first();
                        $id = $employee->id + 1;
                    }
            
                    if(!empty($department)){
                        $slug = strtolower(str_replace(' ', '-', $department));
                        $departmentData = Department::where('slug', $slug)->first();
                        if(!empty($departmentData)){
                            $departmentId = $departmentData->id;
                        }else{
                            $departmentData = Department::firstOrCreate([
                                'organization_id' => $organizationId,
                                'name' => $department,
                                'slug' => $slug
                            ]);
                
                            $departmentId = $departmentData->id;
                        }
                    }

                    if(!empty($designation)){
                        $slug = strtolower(str_replace(' ', '-', $designation));
                        $designationData = Designation::where('slug', $slug)->first();
                        if(!empty($designationData)){
                            $designationId = $designationData->id;
                        }else{
                            $designationData = Designation::firstOrCreate([
                                // 'organization_id' => $organizationId,
                                'name' => $designation,
                                'slug' => $slug
                            ]);
                
                            $designationId = $designationData->id;
                        }
                    }

                  //  $employee = DB::connection('old_connection')->table('employees')->where('employee_id', $id)->first(['avatar_url','id']);
                    $avatar = '';
                    // if(!empty($employee->avatar_url)){
                    //     $url = explode('/',$employee->avatar_url);
                    //     $avatar = end($url);
                    // }
                    
                    $fullName = "$firstName $lastName";
                    $employee = Employee::create([
                        'id' => $id, 
                        'organization_id' => $organizationId, 
                        'uuid' => getUuid(), 
                        'first_name' => $firstName ?? null,
                        'middle_name' => $middleName ?? null,
                        'last_name' =>  $lastName ?? null,
                        'display_name' => $displayName ?? $fullName,
                        'department_id' => $departmentId ?? null,
                        'designation_id' => $designationId ?? null,
                        'join_date' => $joinDate ?? getUtcDate(),
                        'avatar_url' => $avatar,
                        'employeement_type_id' =>  EmployeementType::PERMANENT,
                        'dob' => $dob ?? null,
                        'first_job_start_date' => $firstJobStartDate ?? null,
                        'reliving_date' => $relivingDate ?? null,
                        'mobile' =>  $mobile ?? null,
                        'ctc' =>  $ctc ?? null,
                        'uhid_no' => $uhidNo ?? null,
                        'uan_no' =>  $uanNo ?? null,
                        'availability_comments' => $availabilityComment ?? null,
                        'on_notice_period' => isset($mappedField['on_notice_period']) ? $record[$mappedField['on_notice_period']] : 0,
                        'on_bench' =>  isset($mappedField['on_bench']) ? $record[$mappedField['on_bench']] : 0,
                        'working_on_dedicated_project' => isset($mappedField['working_on_dedicated_project']) ? $record[$mappedField['working_on_dedicated_project']] : 0,
                        'do_not_required_punchinout' => isset($mappedField['do_not_required_punchinout']) ? $record[$mappedField['do_not_required_punchinout']] : 0,
                        'timesheet_filling_not_required' => isset($mappedField['timesheet_filling_not_required']) ? $record[$mappedField['timesheet_filling_not_required']] : 0,
                        'import_id' => $import->id
                        ]
                    );
            
                    $employee_id = $employee->id;

                    $added++;
            
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

                  //  $user = DB::connection('old_connection')->table('users')->where('email', $email)->first(['password','is_active']);

                    $password = \Hash::make('0V55ZMOT!qhe');
                    $isActive = 0;
                    // if(!empty($user->password)){
                    //     $password = $user->password;
                    //     $isActive = $user->is_active;
                    // }
                    $user = User::create([
                        'email' => $email,
                        'password' => $password,
                        'entity_type_id' => EntityType::Employee,
                        'entity_id' => $employee_id,
                        'organization_id' => $organizationId,
                        'timezone_id' =>  $timezoneId,
                        'is_active' =>  $isActive,
                        'date_format_id' => $format
                    ]);
            
                    $employee['status'] = "import";

                    EmailNotification::firstOrCreate([
                        'user_id' => $user->id
                    ]);
                    
                    $role = Role::where('slug','employee')->first();
                  
                    $user->assignRole($role);

                    $user->employee = $employee;

                    if (!empty($skill)) {
                        $skills = explode(',',$skill);
                        foreach ($skills as $value) {

                            $skill = Skill::where('slug', Str::slug($value))->first(['id']);
                            if(empty($skill)){
                                $skill = Skill::create([
                                    'uuid' => getUuid(),
                                    'organization_id' => $organizationId,
                                    'name' => $value,
                                    'slug' => Str::slug($value)
                                ]);
                            }
                            $data = [
                                'employee_id' => $employee->id,
                                'organization_id' => $organizationId,
                                'skill_id' => $skill->id,
                            ];

                            EmployeeSkill::create($data);
                        }
                    }

                    if (!empty($presentAddress)) {
                            $countryId = null;
                            if(!empty($presentCountry)){
                                $country = Country::where('name', $presentCountry)->first(['id']);
                                
                                if(!empty($country)){
                                    $countryId = $country->id;
                                }
                            }

                            $stateId = null;
                            if(!empty($presentState)){
                                $state = State::where('state_name', $presentState)->first(['id','country_id']);
                            
                                if (!empty($state)) {
                                    $stateId = $state->id;
                                    $countryId = $state->country_id;
                                }   
                            }
            
                            $cityId = null;
                            if(!empty($presentCity)){
                                $city = City::where('city_name', $presentCity)->first(['id','state_id']);

                                if(!empty($city)){
                                    $cityId = $city->id;
                                    $stateId = $city->state_id;
                                }
                            }

                            Address::create([
                                'address' => $presentAddress,
                                'address2' =>  $presentAddress2 ?? null,
                                'country_id' => !empty($countryId) ? $countryId : null,
                                'city_id' => !empty($cityId) ? $cityId : null,
                                'state_id' => !empty($stateId) ? $stateId : null,
                                'zipcode' => $presentZipcode ?? null,
                                'entity_id' => $employee->id,
                                'entity_type_id' => EntityType::Employee,
                                'organization_id' => $organizationId,
                                'address_type_id' => AddressType::PRESENT
                            ]);
                        
                    }

                    if (!empty($permanentAddress)) {
                      
                        $countryId = null;
                        if(!empty($permanentCountry)){
                            $country = Country::where('name', $permanentCountry)->first(['id']);
        
                            if(!empty($country)){
                                $countryId = $country->id;
                            }
                        }

                        $stateId = null;
                        if(!empty($permanentState)){
                            $state = State::where('state_name', $permanentState)->first(['id','country_id']);
                           
                            if (!empty($state)) {
                                $stateId = $state->id;
                                $countryId = $state->country_id;
                            }   
                        }
        
                        $cityId = null;
                        if(!empty($permanentCity)){
                            $city = City::where('city_name', $permanentCity)->first(['id','state_id']);

                            if(!empty($city)){
                                $cityId = $city->id;
                                $stateId = $city->state_id;
                            }
                        }

                        Address::create([
                            'address' => $permanentAddress,
                            'address2' =>  $permanentAddress2 ?? null,
                            'country_id' => !empty($countryId) ? $countryId : null,
                            'city_id' => !empty($cityId) ? $cityId : null,
                            'state_id' => !empty($stateId) ? $stateId : null,
                            'zipcode' => $permanentZipcode ?? null,
                            'entity_id' => $employee->id,
                            'entity_type_id' => EntityType::Employee,
                            'organization_id' => $organizationId,
                            'address_type_id' => AddressType::PERMANENT
                        ]);
                        
                    }

                    if(empty($presentAddress) && !empty($permanentAddress)){
                        Address::create([
                            'address' => $permanentAddress,
                            'address2' =>  $permanentAddress2 ?? null,
                            'country_id' => !empty($countryId) ? $countryId : null,
                            'city_id' => !empty($cityId) ? $cityId : null,
                            'state_id' => !empty($stateId) ? $stateId : null,
                            'zipcode' => $permanentZipcode ?? null,
                            'entity_id' => $employee->id,
                            'entity_type_id' => EntityType::Employee,
                            'organization_id' => $organizationId,
                            'address_type_id' => AddressType::PRESENT
                        ]);
                    }

                    if(!empty($presentAddress) && empty($permanentAddress)){
                        Address::create([
                            'address' => $presentAddress,
                            'address2' =>  $presentAddress2 ?? null,
                            'country_id' => !empty($countryId) ? $countryId : null,
                            'city_id' => !empty($cityId) ? $cityId : null,
                            'state_id' => !empty($stateId) ? $stateId : null,
                            'zipcode' => $presentZipcode ?? null,
                            'entity_id' => $employee->id,
                            'entity_type_id' => EntityType::Employee,
                            'organization_id' => $organizationId,
                            'address_type_id' => AddressType::PERMANENT
                        ]);
                    }

                    if (!empty($emergencyContact)) {
                        $contacts = json_decode($emergencyContact, true);
                        foreach ($contacts as $value) {

                            if(!empty($value['relation'])){
                                $relation = Relation::where('name', 'LIKE', '%' . $value['relation'] . '%')->first();
                            }

                            $contactData = [
                                'employee_id' => $employeeId,
                                'organization_id' => $organizationId,
                                'name' =>  $value['name'] ?? null,
                                'phone_no' =>  $value['phone_no'] ?? null,
                                'relation_id' => !empty($relation) ? $relation->id :  null,
                            ];

                            EmployeeContact::create($contactData);
                        }
                    }

                    $token = getUuid();
            
                    $inviteData = [
                        'first_name' => $firstName ?? null,
                        'last_name' => $lastName ?? null,
                        'email' => $email ?? null,
                        'token' => $token,
                        'is_active' => $isActive,
                        'department_id' => $departmentId ?? null,
                        'employee_type_id' => EmployeementType::PERMANENT,
                        'organization_id' => $organizationId,
                        'join_date' => $joinDate ?? getUtcDate(),
                        'roles' => $role->id,
                        'import_id' => $import->id
                    ];
        
                    $invitation = EmployeeInvitation::create($inviteData);
                    $invitation['status'] = "import";
                    $invitation['is_token'] = false;
                    $invitation['organization_name'] = $organization->name;
                 
                     //Add leave balance to employee for all leave types
                    $leaveTypes = LeaveType::select('id')->get();
                    foreach($leaveTypes as $leaveType) {
                        $data = ['employee_id' => $employee_id, 'organization_id' => $organizationId, 'leave_type_id' => $leaveType->id];
                        LeaveBalance::firstOrCreate($data);
                        LeaveBalanceHistory::firstOrCreate($data);
                    }
            
                    $data = new MailEmployeeInvitation($invitation);
            
                    $emailData = ['email' => $record[$mappedField['email']], 'email_data' => $data];
            
                    SendEmailJob::dispatch($emailData);   
                }

            }

            ENDLoop:

            ImportEmployeeHistory::where('id', $import->id)->update([
                'total_added' => $added,
                'total_skipped' => $duplicate,
                'total_imported' => $added
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.employee_imported'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while import employee csv";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get history of imported employees
    public function getImportHistory(Request $request)
    {
        try{

            $history = ImportEmployeeHistory::orderBy('id', 'desc')->get();

            return $this->sendSuccessResponse(__('messages.success'), 200, $history);

        }catch (\Throwable $ex) {
            $logMessage = "Something went wrong while import employee csv";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Rollback imported employees detail
    public function rollbackImport(Request $request)
    {
        try{

            $importId = $request->import_id;

            $import = ImportEmployeeHistory::where('id', $importId)->first();

            if(!empty($import)){
                $now = Carbon::now();
                $importDate = Carbon::parse($import->created_at);

                $hours = $now->diffInHours($importDate);

                if($hours < 24){
                    $records = Employee::where('import_id', $importId)->get();

                    foreach($records as $record){

                        $user = User::where('entity_id', $record->id)->where('is_active',0)->first(['id', 'email']);

                        if(!empty($user)){
                            Employee::where('uuid', $record->uuid)->forceDelete();
                        
                            $email = $user->email; 
                            Address::where('entity_id', $record->id)->forceDelete();
                            EmployeeInvitation::where('email', $email)->forceDelete();
            
                            $user->forceDelete();
                        }
                      
                    }
                    
                    ImportEmployeeHistory::where('id', $importId)->delete();
                }
            }
          
            return $this->sendSuccessResponse(__('messages.rollback_import'), 200);

        }catch (\Throwable $ex) {
            $logMessage = "Something went wrong while rollback import employee";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function updateEmployeeFromOldFovero(Request $request)
    {
        try{

            $inputs = $request->all();

            $organizationId = 1;

            $employee = Employee::where('id', $inputs['employee_id'])->where('organization_id',$organizationId)->first();
            $employeeId = $employee->id;

            if (!empty($inputs['avatar_url'])) {

                $path = config('constant.avatar');

                $url = $inputs['avatar_url'];
                $contents = file_get_contents($url);
                $name = substr($url, strrpos($url, '/') + 1);

                Storage::disk('public')->put($path.'/'.$name, $contents);

                $avatar_url = $name;
            }

            if(!empty($inputs['department_id'])){
                $department = DB::connection('old_connection')->table('department')->where('id', $inputs['department_id'])->first(['name','slug']);
                $departmentData = Department::where('slug', $department->slug)->first();
                if(!empty($departmentData)){
                    $departmentId = $departmentData->id;
                }else{
                    $departmentData = Department::firstOrCreate([
                        'organization_id' => $organizationId,
                        'name' => $department->name,
                        'slug' => $department->slug
                    ]);
        
                    $departmentId = $departmentData->id;
                }
            }

            if(!empty($inputs['designation_id'])){
                $designation = DB::connection('old_connection')->table('designation')->where('id', $inputs['designation_id'])->first(['name','slug']);
                $designationData = Designation::where('slug', $designation->slug)->first();
                if(!empty($designationData)){
                    $designationId = $designationData->id;
                }else{
                    $designationData = Designation::firstOrCreate([
                        'organization_id' => $organizationId,
                        'name' => $designation->name,
                        'slug' => $designation->slug
                    ]);
        
                    $designationId = $designationData->id;
                }
            }

            $data = [
                'first_name' => $inputs['first_name'] ?? null,
                'last_name' => $inputs['last_name'] ?? null,
                'display_name' => $inputs['employee_display_name'] ?? null,
                'dob' => !empty($inputs['dob']) ? $inputs['dob'] : null,
                'join_date' => !empty($inputs['join_date']) ? $inputs['join_date'] : null,
                'first_job_start_date' => !empty($inputs['first_job_start_date']) ? $inputs['first_job_start_date'] : null,
                'reliving_date' => !empty($inputs['last_date']) ? $inputs['last_date'] : null,
                'mobile' =>  $inputs['mobile'] ?? null,
                'designation_id' => $designationId ?? null,
                'department_id' => $departmentId  ?? null,
                'uhid_no' =>  $inputs['uhid_no'] ??  null,
                'uan_no' =>  $inputs['uan_no'] ??  null,
                'availability_comments' => isset($inputs['availability_comments']) ? $inputs['availability_comments'] : null,
                'on_notice_period' => isset($inputs['notice_period']) ? $inputs['notice_period'] : 0,
                'do_not_required_punchinout' => isset($inputs['in_dailyreport']) ? 0 : 1,
                'employeement_type_id' => isset($inputs['employeement_type_id']) ? $inputs['employeement_type_id'] : EmployeementType::PERMANENT
            ];
           
            if (!empty($avatar_url)) {
                $data['avatar_url'] = $avatar_url;
            }

            Employee::where('uuid', $employee->uuid)->update($data);


            if (!empty($inputs['skills_id'])) {
                EmployeeSkill::where('employee_id', $employeeId)->delete();
                $skills = $inputs['skills_id'];

                foreach ($skills as $value) {

                    $skill = DB::connection('old_connection')->table('skills')->where('id', $value)->first(['name','slug']);

                    $skill = Skill::where('slug', $skill->slug)->first(['id']);
                    if(empty($skill)){
                        $skill = Skill::create([
                            'uuid' => getUuid(),
                            'organization_id' => $organizationId,
                            'name' => $skill->name,
                            'slug' => $skill->slug
                        ]);
                    }
                    $data = [
                        'employee_id' => $employeeId,
                        'organization_id' => $organizationId,
                        'skill_id' => $skill->id,
                    ];

                    EmployeeSkill::create($data);
                 }
            }

            $user = User::where('entity_id', $employee->id)->where('entity_type_id', EntityType::Employee)->first();
            if (!empty($inputs['role_id'])) {
                $user->roles()->detach();
                foreach($inputs['role_id'] as $value){
                    $role = DB::connection('old_connection')->table('roles')->where('id', $value)->first(['name','slug']);
                    $role = Role::where('slug', $role->slug)->first();
                    $user->assignRole($role);
                }
            }

            if (!empty($inputs['email'])) {
                $user->update([
                    'email' => $inputs['email']
                ]);
            }

            if (isset($inputs['address'])) {
                $address = Address::where('entity_id', $employee->id)->where('entity_type_id', EntityType::Employee)->where('address_type_id', AddressType::PERMANENT)->first();
                $countryId = null;
                if(!empty($inputs['country_id'])){
                    $country = DB::connection('old_connection')->table('country')->where('id', $inputs['country_id'])->first(['name']);
                    
                    if(!empty($country)){
                        $country = Country::where('name', $country->name)->first(['id']);
                        
                        if(!empty($country)){
                            $countryId = $country->id;
                        }
                    }
                }

                $stateId = null;
                if(!empty($inputs['state_id'])){
                    $state = DB::connection('old_connection')->table('state')->where('id', $inputs['state_id'])->first(['state_name']);
                    
                    if(!empty($state)){
                        $state = State::where('state_name', $state->state_name)->first(['id','country_id']);
                        
                        if(!empty($state)){
                            $stateId = $state->id;
                            $countryId = $state->country_id;
                        }
                    }
                }

                $cityId = null;
                if(!empty($inputs['city_id'])){
                    $city = DB::connection('old_connection')->table('city')->where('id', $inputs['city_id'])->first(['city_name']);
                    
                    if(!empty($city)){
                        $city = City::where('city_name', $city->city_name)->first(['id','state_id']);
                        
                        if(!empty($city)){
                            $cityId = $city->id;
                            $stateId = $city->state_id;
                        }
                    }
                }
                if ($address) {
                    $address->update([
                        'address' => $inputs['address'],
                        'address2' =>  !empty($inputs['address2']) ? $inputs['address2']: null,
                        'country_id' => !empty($countryId) ? $countryId : null,
                        'city_id' => !empty($cityId) ? $cityId : null,
                        'state_id' => !empty($stateId) ? $stateId : null,
                        'zipcode' => !empty($inputs['zipcode']) ? $inputs['zipcode'] :  null
                    ]);
                } else {
                    $employee['address'] = Address::create([
                        'address' => $inputs['address'],
                        'address2' =>  !empty($inputs['address2']) ? $inputs['address2']: null,
                        'country_id' => !empty($countryId) ? $countryId : null,
                        'city_id' => !empty($cityId) ? $cityId : null,
                        'state_id' => !empty($stateId) ? $stateId : null,
                        'zipcode' => !empty($inputs['zipcode']) ? $inputs['zipcode'] :  null,
                        'entity_id' => $employee->id,
                        'entity_type_id' => EntityType::Employee,
                        'organization_id' => $organizationId,
                        'address_type_id' => AddressType::PERMANENT
                    ]);
                }
            }

            if (isset($inputs['temp_address'])) {

                $countryId = null;
                if(!empty($inputs['temp_country_id'])){
                    $country = DB::connection('old_connection')->table('country')->where('id', $inputs['temp_country_id'])->first(['name']);
                    
                    if(!empty($country)){
                        $country = Country::where('name', $country->name)->first(['id']);
                        
                        if(!empty($country)){
                            $countryId = $country->id;
                        }
                    }
                }

                $stateId = null;
                if(!empty($inputs['temp_state_id'])){
                    $state = DB::connection('old_connection')->table('state')->where('id', $inputs['temp_state_id'])->first(['state_name']);
                    
                    if(!empty($state)){
                        $state = State::where('state_name', $state->state_name)->first(['id','country_id']);
                        
                        if(!empty($state)){
                            $stateId = $state->id;
                            $countryId = $state->country_id;
                        }
                    }
                }

                $cityId = null;
                if(!empty($inputs['temp_city_id'])){
                    $city = DB::connection('old_connection')->table('city')->where('id', $inputs['temp_city_id'])->first(['city_name']);
                    
                    if(!empty($city)){
                        $city = City::where('city_name', $city->city_name)->first(['id','state_id']);
                        
                        if(!empty($city)){
                            $cityId = $city->id;
                            $stateId = $city->state_id;
                        }
                    }
                }

                $address = Address::where('entity_id', $employee->id)->where('entity_type_id', EntityType::Employee)->where('address_type_id', AddressType::PRESENT)->first();
    
                if ($address) {
                    $address->update([
                        'address' => $inputs['temp_address'],
                        'address2' =>  !empty($inputs['temp_address2']) ? $inputs['temp_address2'] : null,
                        'country_id' => !empty($countryId) ? $countryId : null,
                        'city_id' => !empty($cityId) ? $cityId : null,
                        'state_id' => !empty($stateId) ? $stateId : null,
                        'zipcode' => !empty($inputs['temp_zipcode']) ? $inputs['temp_zipcode'] : null
                    ]);
                } else {
                    $employee['address'] = Address::create([
                        'address' => $inputs['temp_address'],
                        'address2' =>  !empty($inputs['temp_address2']) ? $inputs['temp_address2'] : null,
                        'country_id' => !empty($countryId) ? $countryId : null,
                        'city_id' => !empty($cityId) ? $cityId : null,
                        'state_id' => !empty($stateId) ? $stateId : null,
                        'zipcode' => !empty($inputs['temp_zipcode']) ? $inputs['temp_zipcode'] : null,
                        'entity_id' => $employee->id,
                        'entity_type_id' => EntityType::Employee,
                        'organization_id' => $organizationId,
                        'address_type_id' => AddressType::PRESENT
                    ]);
                }
            }

           
            if (!empty($inputs['contact'])) {
                EmployeeContact::where('employee_id', $employeeId)->where('organization_id', $organizationId)->delete();
                $contacts = $inputs['contact'];
                foreach ($contacts as $value) {

                    if(!empty($value['relation'])){
                        $relation = Relation::where('name', 'LIKE', '%' . $value['relation'] . '%')->first();
                    }

                    $contactData = [
                        'employee_id' => $employeeId,
                        'organization_id' => $organizationId,
                        'name' =>  $value['contact_name'] ?? null,
                        'phone_no' =>  $value['contact_mobile'] ?? null,
                        'relation_id' => !empty($relation) ? $relation->id :  null,
                    ];

                    EmployeeContact::create($contactData);
                }
            }

            DB::commit();

         
            return $this->sendSuccessResponse(__('messages.update_employee'), 200);

        }catch (\Throwable $ex) {
            $logMessage = "Something went wrong while update employee from old fovero";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function updateEmployeeProfileFromOldFovero(Request $request)
    {
        try{

            $inputs = $request->all();
            $organizationId = 1;
            $employee = Employee::where('id', $inputs['employee_id'])->where('organization_id',$organizationId)->first();
            $employeeId = $employee->id;

            if (!empty($inputs['avatar_url'])) {

                $path = config('constant.avatar');

                $url = $inputs['avatar_url'];
                $contents = file_get_contents($url);
                $name = substr($url, strrpos($url, '/') + 1);
                Storage::disk('public')->put($path.'/'.$name, $contents);

                $avatar_url = $name;
            }

            $data = [
                'first_name' => $inputs['first_name'] ?? null,
                'last_name' => $inputs['last_name'] ?? null,
                'join_date' => !empty($inputs['join_date']) ? $inputs['join_date'] : null,
                'mobile' =>  $inputs['mobile'] ?? null,
            ];
           
            if (!empty($avatar_url)) {
                $data['avatar_url'] = $avatar_url;
            }
            Employee::where('uuid', $employee->uuid)->update($data);

            if (!empty($inputs['skills_id'])) {
                EmployeeSkill::where('employee_id', $employeeId)->delete();
                $skills = $inputs['skills_id'];

                foreach ($skills as $value) {

                    $skill = DB::connection('old_connection')->table('skills')->where('id', $value)->first(['name','slug']);

                    $skill = Skill::where('slug', $skill->slug)->first(['id']);
                    if(empty($skill)){
                        $skill = Skill::create([
                            'uuid' => getUuid(),
                            'organization_id' => $organizationId,
                            'name' => $skill->name,
                            'slug' => $skill->slug
                        ]);
                    }
                    $data = [
                        'employee_id' => $employeeId,
                        'organization_id' => $organizationId,
                        'skill_id' => $skill->id,
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

            DB::commit();

         
            return $this->sendSuccessResponse(__('messages.update_employee'), 200);

        }catch (\Throwable $ex) {
            $logMessage = "Something went wrong while update employee from old fovero";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function updateEmployeeActiveStatus(Request $request){
        try{

            $inputs = $request->all();

            $organizationId = 1;
            $employee = Employee::where('id', $inputs['employee_id'])->where('organization_id',$organizationId)->first();

            $user = User::where('entity_id', $employee->id)->where('entity_type_id', EntityType::Employee)->first();

            if (!empty($inputs['email'])) {
                $user->update([
                    'is_active' => $inputs['is_active']
                ]);
            }

            DB::commit();

         
            return $this->sendSuccessResponse(__('messages.update_employee'), 200);

        }catch (\Throwable $ex) {
            $logMessage = "Something went wrong while update status employee from old fovero";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }

    }
}
