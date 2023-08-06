<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Jobs\TimesheetExportJob;
use App\Mail\TimesheetRejectNotify;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\EmailNotification;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\ProjectEmployee;
use App\Models\ProjectRole;
use App\Models\Scopes\OrganizationScope;
use App\Models\TimesheetExport;
use App\Models\TimesheetExportDetail;
use App\Models\TimesheetStatus;
use App\Models\User;
use App\Models\UserTimesheet;
use App\Traits\ResponseTrait;
use App\Traits\UploadFileTrait;
use App\Validators\TimesheetExportValidator;
use Auth;
use DB;
use Illuminate\Http\Request;
use Storage;

class ExportTimesheetController extends Controller
{
    use ResponseTrait, UploadFileTrait;

    private $exportedPath;
    private $timesheetExportValidator;
    function __construct()
    {
        $this->exportedPath = config('constant.timesheet_export');
        $this->timesheetExportValidator = new TimesheetExportValidator();
    }

    //Get employees with project manager
    public function getEmployeesByProjectManager($id)
    {
        try {
            $project = ProjectEmployee::where('employee_id', $id)->where('project_role_id', ProjectRole::PROJECT_MANAGER)->get('project_id')->pluck('project_id');

            $organizationId = $this->getCurrentOrganizationId();

            $employees = Employee::withoutGlobalScopes([OrganizationScope::class])
                ->join('project_employees', 'employees.id', 'project_employees.employee_id')
                ->whereIn('project_employees.project_id', $project)
                ->where('project_employees.project_role_id', ProjectRole::DEVELOPERANDQA)
                ->where('employees.organization_id', $organizationId)

                ->groupBy('employees.id')->get(['employee_id as id', 'display_name']);

            return $this->sendSuccessResponse(__('messages.success'), 200, $employees);
        } catch (\Throwable $ex) {

            $logMessage = "Something went wrong while get project manager's employee";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get pending timesheets for export
    public function getExportPendingList(Request $request)
    {
        try {

            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $user = $request->user();
            $roles = $user->roles;
            $allRoles = collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $permisions = $user->getAllPermissions()->pluck('name')->toArray();

            $projectQuery = Project::withoutGlobalScopes([OrganizationScope::class])->leftJoin('user_timesheets', 'projects.id', 'user_timesheets.project_id');

            if (!isset($inputs['employee']) && empty($inputs['employee'])) {
                $projectQuery =  $projectQuery->join('employees', function ($join) use ($organizationId) {
                    $join->on('user_timesheets.employee_id', '=',  'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                });
            }

            if (!empty($inputs['customer'])) {
                $projectQuery =  $projectQuery->where('projects.customer_id', $inputs['customer']);
            }

            if (!empty($inputs['project'])) {
                $projectQuery =  $projectQuery->where('projects.id', $inputs['project']);
            }

            $projectQuery = $projectQuery->select(
                'projects.id as project_id',
                'projects.uuid',
                'projects.name as project_name',
                DB::raw('SUM(user_timesheets.billing_hours) as total_billing_hours'),
                DB::raw('SUM(user_timesheets.working_hours) as total_actual_hours'),
                DB::raw('(SELECT group_concat(employee_id) from project_employees WHERE project_id = projects.id and project_role_id = ' . ProjectRole::PROJECT_MANAGER . ') as project_manager'),
                DB::raw('group_concat(DISTINCT(employees.display_name) separator ",\n") as project_employee'),
                DB::raw('(SELECT DATE(updated_at) from timesheet_exports where timesheet_status_id = '.TimesheetStatus::INVOICED.' and 
                project_id = projects.id order by timesheet_exports.created_at desc limit 1 ) as last_invoiced_date')
            )
                ->whereBetween('user_timesheets.date', [$request->start_date, $request->end_date])
                ->where('user_timesheets.timesheet_status_id', TimesheetStatus::PENDING)
                ->where('projects.billable', 1)
                ->whereNull('user_timesheets.deleted_at')
                ->orderBy('projects.created_at', 'desc')
                ->orderBy('projects.id', 'desc')
                ->groupBy('projects.id');

            if (in_array('create_manage_timesheet', $permisions) && !in_array('administrator', $allRoles)) {
                $projectQuery->join('project_employees', function ($join) use ($user) {
                   // $join->on('projects.id', '=',  'project_employees.project_id');
                   // $join->where('project_employees.project_role_id', ProjectRole::PROJECT_MANAGER);
                 //   $join->where('project_employees.employee_id',  $user->entity_id);

                    $join->on('projects.id', '=',  'project_employees.project_id');
                    $join->where('project_employees.id', '=', function($q) use ($user)
                        {
                        $q->from('project_employees')
                            ->selectRaw('id')
                            ->whereRaw('project_id  = `projects`.`id`')
                            ->where('employee_id', $user->entity_id)->limit(1);
                        });
                    
                });
            }

            if (isset($inputs['manager']) && !empty($inputs['manager']) && in_array('administrator', $allRoles)) {
                $projectQuery->join('project_employees', function ($join) use($inputs) {
                    $join->on('projects.id', '=',  'project_employees.project_id')
                        ->where('project_employees.project_role_id', ProjectRole::PROJECT_MANAGER)
                        ->where('project_employees.employee_id',  $inputs['manager']);
                }); 
            }

            if (isset($inputs['employee']) && !empty($inputs['employee'])) {
                    $projectQuery->join('employees', function ($join) use ($organizationId) {
                        $join->on('user_timesheets.employee_id', '=',  'employees.id');
                        $join->where('employees.organization_id', $organizationId);
                    })
                    ->where('user_timesheets.employee_id', $inputs['employee']);
            }
            $projectQuery->where('projects.organization_id', $organizationId);
            $projectQuery->where('user_timesheets.organization_id', $organizationId);
            $data = $projectQuery->get();

            foreach ($data as $detail) {
                $projectManager = explode(",", $detail->project_manager);
                $projectManager = collect($projectManager)->unique()->values()->toArray();
                $projectManager =  Employee::whereIn('id', $projectManager)
                    ->select('display_name as project_manager')->pluck('project_manager')->toArray();

                $detail->project_manager = implode(",\n", $projectManager);
            }
            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get pending timesheet entries";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get list of submitted timesheet export
    public function getExportedList(Request $request)
    {
        try {
            $inputs = $request->all();

            $user = $request->user();
            $roles = $user->roles;
            $allRoles = collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $projectId = isset($inputs['project_id']) ? $inputs['project_id'] : '';
            $projectManagerId = isset($inputs['manager']) ? $inputs['manager'] : '';
            $statusId = isset($inputs['status_id']) ? $inputs['status_id'] : TimesheetStatus::SUBMITTED;
            $organizationId = $this->getCurrentOrganizationId();
          //  $lastMonthDay = new Carbon('first day of last month');
          //  $lastMonthStartDate = $lastMonthDay->toDateString();
          //  $lastMonthEndDay = new Carbon('last day of last month');
          //  $lastMonthEndDate = $lastMonthEndDay->toDateString();
          //  $startDate = $lastMonthStartDate;
          //  $endDate = $lastMonthEndDate;

            $startDate = !empty($inputs['start_date']) ? $inputs['start_date'] : '';
            $endDate = !empty($inputs['end_date']) ? $inputs['end_date'] : '';

            $query = TimesheetExport::leftJoin('projects', 'timesheet_exports.project_id', 'projects.id')
                ->leftJoin('timesheet_export_details', 'timesheet_exports.id', 'timesheet_export_details.timesheet_export_id')
                ->leftJoin('user_timesheets', 'timesheet_export_details.user_timesheet_id', 'user_timesheets.id')
                
                ->leftJoin('users', function ($join) use ($organizationId) {
                    $join->on('timesheet_exports.created_by', '=',  'users.id');
                    $join->where('users.organization_id', $organizationId);
                })   
                ->leftJoin('employees as e', function ($join) use ($organizationId) {
                    $join->on('users.entity_id', '=',  'e.id');
                    $join->where('e.organization_id', $organizationId);
                })   
                ->leftJoin('employees', function ($join) use ($organizationId) {
                    $join->on('user_timesheets.employee_id', '=',  'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })             
                ->where('timesheet_exports.timesheet_status_id', $statusId)
                ->whereNULL('projects.deleted_at')
                ->whereNULL('timesheet_export_details.deleted_at')
                ->where('projects.organization_id', $organizationId);

            if($startDate && $endDate){
                $query->where(function ($q) use ($startDate, $endDate) {
                    $q->whereBetween(DB::raw('DATE(timesheet_exports.created_at)'), [$startDate, $endDate]);
                });
            }

            if (!in_array('administrator', $allRoles)) {

                $query->join('project_employees', function ($join) use($user) {
                    $join->on('projects.id', '=',  'project_employees.project_id');
                    $join->where('project_employees.id', '=', function($q) use ($user)
                        {
                        $q->from('project_employees')
                            ->selectRaw('id')
                            ->whereRaw('project_id  = `projects`.`id`')
                            ->where('employee_id', $user->entity_id)->limit(1);
                        });
                     });
            }

            
            if (!empty($projectId)) {
                $query =  $query->where('timesheet_exports.project_id', $projectId);
            }

            if (!empty($projectManagerId)) {
                $query->join('project_employees', function ($join) use ($projectManagerId) {
                    $join->on('projects.id', '=', 'project_employees.project_id');
                    $join->where('project_employees.project_role_id', ProjectRole::PROJECT_MANAGER);
                    $join->where('project_employees.employee_id', $projectManagerId);
                });
            }

            $query = $query->select(
                'projects.name as project_name',
                'timesheet_exports.exported_file',
                'timesheet_exports.id as id',
                'timesheet_exports.created_by',
                'timesheet_exports.created_at',
                'timesheet_exports.deleted_at',
                'timesheet_exports.timesheet_status_id',
                'timesheet_exports.reject_remarks',
                'e.display_name as exported_by_name',
                DB::raw('IFNULL(SUM(timesheet_export_details.log_billing_hours), SUM(user_timesheets.billing_hours)) as total_hours'),
                DB::raw('group_concat(DISTINCT(employees.display_name)) as developer')

            )
                ->selectSub(function ($q) {
                    $q->select(DB::raw('group_concat(employee_id) as manager'))->from('project_employees')->whereRaw("`project_employees`.`project_id` = `projects`.`id`")->where('project_employees.project_role_id', ProjectRole::PROJECT_MANAGER);
                }, 'project_manager')
                ->groupBy('timesheet_exports.id')
                ->orderBy('timesheet_exports.created_at', 'DESC')
                ->get();

            $data = $query;


            foreach ($data as $detail) {
            
             //   $user = User::where('id',$detail->created_by)->first('entity_id');
             //   $detail->exported_by_name = $detail->display_name;
                $projectManager = explode(",", $detail->project_manager);
                $projectManager = collect($projectManager)->unique()->values()->toArray();
                $projectManager = Employee::whereIn('id', $projectManager)
                    ->select(DB::raw('employees.display_name as project_manager'))->pluck('project_manager')->toArray();

                $detail->project_manager = implode(',', $projectManager);

                $detail->total_hours = round($detail->total_hours, 2);

                if (!empty($detail->exported_file)) {
                    $detail->exported_file = getFullImagePath($this->exportedPath . '/' . $detail->exported_file);
                }
            }
            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get submitted timesheet entries";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Submit timesheet export entries
    public function submitTimesheetEntries(Request $request)
    {
        try {
            $inputs = $request->all();

            DB::beginTransaction();

            $exportedID = !empty($inputs['exported_id']) ? $inputs['exported_id'] : ''; //For edit export entry 
            $excludeName = !empty($inputs['exclude_name']) ? $inputs['exclude_name'] : 0;
            $projectId = isset($inputs['project_id']) ? $inputs['project_id'] : '';
            $startDate = isset($inputs['start_date']) ? convertUserTimeToUTC($inputs['start_date']) : '';
            $endDate = isset($inputs['end_date']) ? convertUserTimeToUTC($inputs['end_date']) : '';
            $projectManager = !empty($inputs['project_manager']) ? $inputs['project_manager'] : '';
            $organizationId = $this->getCurrentOrganizationId();
            $projectDetail = Project::where('id',$projectId)->first('name');

            $projectName = str_replace(" ", "-", $projectDetail->name);
            $dateString = date('d-F-Y', strtotime($startDate)) . '-to-' . date('d-F-Y', strtotime($endDate));
            $fileName = "$projectName-TimeSheet-$dateString.pdf";

            $fileName = $this->updateUniqueName($fileName);
            $editExport = false;
            if (!empty($exportedID)) {
                $editExport = true;
                TimesheetExport::where('id', $exportedID)->update([
                    'exported_file' => $fileName,
                    'timesheet_status_id' => TimesheetStatus::SUBMITTED,
                    'is_exclude_name' => $excludeName,
                ]);
            } else {
                $exportData = TimesheetExport::create([
                    'project_id' => $projectId,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'created_by' => $request->user()->id,
                    'exported_file' => $fileName,
                    'timesheet_status_id' => TimesheetStatus::SUBMITTED,
                    'is_exclude_name' => $excludeName,
                ]);

                $exportedID = $exportData->id;
            }

            $exportDetails = [];
            if (!empty($inputs['data'])) {

                foreach ($inputs['data'] as $value) {
                    foreach ($value['data'] as $entries) {
                        $hours = 0;
                        foreach ($entries['entries'] as $data) {
                            // Update
                            UserTimesheet::find($data['id'])
                                ->update([
                                    'timesheet_status_id' => TimesheetStatus::SUBMITTED,
                                    'billing_hours' => $data['billing_hours'],
                                    'admin_notes' => $data['admin_notes'],
                                    'pm_note' => $data['pm_note'],
                                ]);

                            if (empty($editExport)) {
                                TimesheetExportDetail::create([
                                    'timesheet_export_id' => $exportedID,
                                    'user_timesheet_id' => $data['id'],
                                    'log_billing_hours' => $data['billing_hours'],
                                ]);
                            }else{

                                $exportTimesheetDetail = TimesheetExportDetail::where('user_timesheet_id', $data['id'])->first();
                                if(empty($exportTimesheetDetail)){
                                    TimesheetExportDetail::create([
                                        'timesheet_export_id' => $exportedID,
                                        'user_timesheet_id' => $data['id'],
                                        'log_billing_hours' => $data['billing_hours'],
                                    ]);
                                }else{
                                    $exportDetails[] = $data['id'];
                                    $exportTimesheetDetail->update(['log_billing_hours' => $data['billing_hours']]);
                                }
                            }

                            $hours += $data['billing_hours'];
                        }
                        $emp = $entries['employee'] . '(' . $hours . ' Hours)';
                        $entries['employee'] = $emp;
                    }
                }

                if (!empty($editExport)) {
                    $remainingEntries = TimesheetExportDetail::whereNotIn('user_timesheet_id', $exportDetails)->where('timesheet_export_id', $exportedID)->get(['timesheet_export_id','user_timesheet_id','id']);
                    if(!empty($remainingEntries)){
                        foreach($remainingEntries as $entry){
                            UserTimesheet::find($entry->user_timesheet_id)
                            ->update([
                                'timesheet_status_id' => TimesheetStatus::PENDING
                            ]);
                        }

                        TimesheetExportDetail::whereNotIn('user_timesheet_id', $exportDetails)->where('timesheet_export_id', $exportedID)->delete();
                    }
                }

                $activitylog = [
                    'module_id' => $exportedID,
                    'module_name' => 'timesheet',
                    'updated_by' => $request->user()->id,
                    'table_name' => 'projects',
                    'action' => 'has submitted timesheet from ' . date('d  M, Y', strtotime($startDate)) . ' to ' . date('d  M, Y',strtotime($endDate)),
                    'old_data' => json_encode(array('name' => $projectId)),
                    'new_data' => NULL,
                    'organization_id' => $organizationId
                ];
                ActivityLog::create($activitylog);
            }

            DB::commit();

            $isRollback = false;

            TimesheetExportJob::dispatch($exportedID, $this->exportedPath, $isRollback, $fileName, $projectManager);

            return $this->sendSuccessResponse(__('messages.timesheet_submit_success'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while submit timesheet entries";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Update file name when already exist in storage
    private function updateUniqueName($filename)
    {
        if (!Storage::disk('public')->exists($this->exportedPath . '/' . $filename)) {
            return $filename;
        }
        $fnameNoExt = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        $i = 1;
        while (Storage::disk('public')->exists($this->exportedPath . '/' . "$fnameNoExt ($i).$ext"))
            $i++;
        return "$fnameNoExt ($i).$ext";
    }

    //Get all entries for the export
    public function getEmployeeEntries(Request $request)
    {
        try {

            $organizationId = $this->getCurrentOrganizationId();

            $validation = $this->timesheetExportValidator->validateExport($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $allEntries = UserTimesheet::withoutGlobalScopes([OrganizationScope::class])
                ->leftJoin('employees', function ($join) use ($organizationId) {
                    $join->on('user_timesheets.employee_id', '=',  'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })
                ->leftjoin('departments', function ($join) {
                    $join->on('employees.department_id', '=',  'departments.id');
                    $join->on('departments.organization_id', '=', 'employees.organization_id');
                })->where('user_timesheets.project_id', $request->project_id)
                ->where('user_timesheets.organization_id', $organizationId)
                ->whereIn('user_timesheets.employee_id', $request->employee_ids)
                ->whereBetween('user_timesheets.date', [$request->start_date, $request->end_date])
                ->whereIn('user_timesheets.timesheet_status_id', [TimesheetStatus::PENDING, TimesheetStatus::REJECTED])
                ->select(
                    'user_timesheets.*',
                    'departments.name as department_name',
                    'employees.department_id as department_id',
                    DB::Raw('employees.display_name AS developer_name')
                )
                ->orderBy('user_timesheets.date', 'ASC')
                ->orderBy('employees.department_id', 'ASC')
                ->get();

            $allEntries = collect($allEntries)->groupBy('department_id');

            $projectManager = ProjectEmployee::join('employees', function ($join) use ($organizationId) {
                    $join->on('project_employees.employee_id', '=',  'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })
                ->where('project_employees.project_id', $request->project_id)
                ->where('project_employees.organization_id', $organizationId)
                ->where('project_employees.project_role_id', ProjectRole::PROJECT_MANAGER)
                ->select('display_name AS manager_name')
                ->get();

            $projectManager = $projectManager->implode('manager_name', ', ');

            $response = array();
            $project_manager = $projectManager;
            $count = 0;

            foreach ($allEntries as $department => $entries) {
                $departmentData = Department::find($department);

                if (!empty($departmentData)) {
                    $response[$count]['department'] = $departmentData->name;
                    $entries = collect($entries)->groupBy('employee_id');
                    $emp = 0;
                    foreach ($entries as $employee => $entry) {
                        $employeeData = Employee::find($employee);
                        $response[$count]['data'][$emp]['employee'] = $employeeData->display_name;
                        $response[$count]['data'][$emp]['entries'] = $entry;

                        $emp++;
                    }
                } else {
                    $response[$count]['label'] = 'Developer';
                    $response[$count]['value'] = $entries;
                }

                $count++;
            }
            //Display last pending entry for selected project
            $pendingEntry = UserTimesheet::withoutGlobalScopes([OrganizationScope::class])
                    ->leftJoin('employees', function ($join) use ($organizationId) {
                        $join->on('user_timesheets.employee_id', '=',  'employees.id');
                        $join->where('employees.organization_id', $organizationId);
                    })
                    ->where('user_timesheets.project_id', $request->project_id)
                    ->where('user_timesheets.organization_id', $organizationId)
                    ->whereIn('user_timesheets.timesheet_status_id', [TimesheetStatus::PENDING, TimesheetStatus::REJECTED])
                    ->select(
                       'user_timesheets.date',
                        DB::Raw('employees.display_name AS developer_name')
                    )
                    ->orderBy('user_timesheets.date', 'ASC')
                    ->orderBy('employees.department_id', 'ASC')
                    ->first();
            $pendingEntry->month = date('M',strtotime($pendingEntry->date));

            $holiday = $this->getHoliday($request->start_date, $request->end_date);
           
            $response = [
                'data' => $response,
                'pending_entry' => $pendingEntry,
                'project_manager' => $project_manager,
                'holiday' => $holiday
            ];

            return $this->sendSuccessResponse(__('messages.success'), 200,  $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while submit timesheet entries";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get employee list by the project
    public function getEmployeeByProject(Request $request)
    {
        try {
            $user = $request->user();
            $organizationId = $this->getCurrentOrganizationId();
            $roles = $user->roles;
            $allRoles = collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $validation = $this->timesheetExportValidator->validateTimesheetExportEmployee($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $projectId = $request->project;
            if (!is_numeric($projectId)) {
                $projectId = Project::where('uuid', $projectId)->pluck('id')->first();
            }

            if (!in_array('administrator', $allRoles)) {
                $isAccess = ProjectEmployee::join('employees', function ($join) use ($organizationId) {
                        $join->on('project_employees.employee_id', '=',  'employees.id');
                        $join->where('employees.organization_id', $organizationId);
                    })
                    ->where('project_employees.project_id', $projectId)
                    ->where('project_employees.employee_id', $user->entity_id)
                    ->where('project_employees.organization_id', $organizationId)
                    ->count();

                if ($isAccess <= 0) {
                    return $this->sendFailResponse(__('messages.prevent_export'), 422);
                }
            }

            $query = Employee::withoutGlobalScopes([OrganizationScope::class])->join('project_employees', 'employees.id', 'project_employees.employee_id')
                ->join('user_timesheets', 'employees.id', 'user_timesheets.employee_id')
                ->leftjoin('departments', function ($join) {
                    $join->on('employees.department_id', '=',  'departments.id');
                    $join->on('departments.organization_id', '=', 'employees.organization_id');
                })->where('user_timesheets.project_id', $projectId)
                ->where('user_timesheets.timesheet_status_id', TimesheetStatus::PENDING)
                ->where('user_timesheets.deleted_at', null)
                ->where('employees.organization_id', $organizationId)
                ->whereBetween('user_timesheets.date', [$request->start_date, $request->end_date])
                ->where('project_employees.project_id', $projectId)->where('project_employees.project_role_id', ProjectRole::DEVELOPERANDQA);

            if ($request->employee) {
                $query = $query->where('employees.id', $request->employee);
            }

            $employees = $query->groupBy('employees.id')->get(['user_timesheets.employee_id', 'display_name as developer', 'departments.name as department', 'project_employees.project_id']);

            return $this->sendSuccessResponse(__('messages.success'), 200,  $employees);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while fetch the employees for the project in export";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Update status of the timesheet export entry
    public function updateTimesheetExportStatus(Request $request)
    {
        DB::beginTransaction();
        try {

            $inputs = $request->all();
            $id = $inputs['id'];
            $statusId = $inputs['status_id'];
            $rejectRemarks = !empty($inputs['remarks']) ? $inputs['remarks'] : '';
            $organizationId = $this->getCurrentOrganizationId();

            $data['timesheet_status_id'] = $statusId;
            if (!empty($rejectRemarks)) {
                $data['reject_remarks'] = $rejectRemarks;
            }

            $export = TimesheetExport::find($id);

            $export->update($data);

            $action = '';
            if ($statusId == TimesheetStatus::INVOICED) {
                $action = 'has invoiced timesheet from ' . date('d  M, Y', strtotime($export->start_date))  . ' to ' .  date('d  M, Y', strtotime($export->end_date));
                $statusId = TimesheetStatus::INVOICED;
            } elseif ($statusId == TimesheetStatus::REJECTED) {
                $action = 'has rejected timesheet from ' .  date('d  M, Y', strtotime($export->start_date)) . ' to ' . date('d  M, Y', strtotime($export->end_date)) . ' with comment ' . $rejectRemarks;
                $statusId = TimesheetStatus::REJECTED;

                $createdByUser = User::where('id', $export->created_by)->first(['email', 'entity_id','id']);

                $notifications = EmailNotification::where('user_id',$createdByUser->id)->first(['allow_all_notifications','reject_timesheet']);

                if($notifications->allow_all_notifications == true && $notifications->reject_timesheet == true){

                    $actionByUser = User::where('id', $request->user()->id)->first(['email', 'entity_id']);
                    $project = Project::where('id', $export->project_id)->first('name');

                    $info = ['project_name' => $project->name, 'from_date' => date('d  M, Y', strtotime($export->start_date)), 'to_date' => date('d  M, Y', strtotime($export->end_date)), 'comment' => $rejectRemarks, 'export_id' => $id, 'action_by' => $actionByUser->display_name, 'created_by' => $createdByUser->display_name];

                    $data = new TimesheetRejectNotify($info);

                    $emailData = ['email' => $createdByUser['email'], 'email_data' => $data];

                    SendEmailJob::dispatch($emailData);
                }


            } elseif ($statusId == TimesheetStatus::APPROVED) {
                $action = 'has approved timesheet from ' .  date('d  M, Y', strtotime($export->start_date)) . ' to ' . date('d  M, Y', strtotime($export->end_date));
                $statusId = TimesheetStatus::APPROVED;
            }

            $timesheetExportDetails = $export->timesheetExportDetails;

            if (!empty($timesheetExportDetails) && !empty($timesheetExportDetails->toArray())) {

                foreach ($timesheetExportDetails as $detail) {
 
                    if(!empty($detail['user_timesheet_id'])){
                        // Update timesheet entries status to rejected
                        $updateRecord = UserTimesheet::find($detail['user_timesheet_id']);

                        if(!empty($updateRecord)){
                            $updateRecord->update([
                                'timesheet_status_id' => $statusId,
                            ]);
                        }
                    }
                }
            }

            $activitylog = [
                'module_id' => $id,
                'module_name' => 'timesheet',
                'updated_by' => $request->user()->id,
                'table_name' => 'projects',
                'action' => $action,
                'old_data' => json_encode(array('name' => $export->project_id)),
                'new_data' => NULL,
                'organization_id' => $organizationId
            ];
            ActivityLog::create($activitylog);

            DB::commit();
            return $this->sendSuccessResponse(__('messages.timesheet_status_success'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while update status of timesheet export entry";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Make export rollback
    public function rollbackExportDetails($exportedId)
    {
        DB::beginTransaction();
        try {

            $data = TimesheetExport::whereId($exportedId)->withTrashed()->first();

            $organizationId = $this->getCurrentOrganizationId();

            $timesheetExportDetails = $data->timesheetExportDetails;

            if (!empty($timesheetExportDetails) && !empty($timesheetExportDetails->toArray())) {
                                                                          
                foreach ($timesheetExportDetails as $detail) {
                    // Update
                    UserTimesheet::find($detail['user_timesheet_id'])
                        ->update([
                            'timesheet_status_id' => TimesheetStatus::PENDING,
                        ]);
                }
            }

            TimesheetExport::where('id', $data->id)->update(['is_rollback' => 1]);

            $data->timesheetExportDetails()->delete();
            $data->delete();

            $activitylog = [
                'module_id' => $exportedId,
                'module_name' => 'timesheet',
                'updated_by' => Auth::user()->id,
                'table_name' => 'projects',
                'action' => 'has rollbacked timesheet from ' . $data->start_date . ' To ' . $data->end_date,
                'old_data' => json_encode(array('name' => $data->project_id)),
                'new_data' => NULL,
                'organization_id' => $organizationId
            ];
            ActivityLog::create($activitylog);

            $isRollback = true;
            TimesheetExportJob::dispatch($exportedId, $this->exportedPath, $isRollback);

            DB::commit();
            return $this->sendSuccessResponse(__('messages.rollback_success'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while rollback exported entries";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get list of entries for view
    public function getViewExportedList(Request $request)
    {
        try {
            $exportedId = $request->exported_id;
            $organizationId = $this->getCurrentOrganizationId();

            $data = TimesheetExport::join('projects', 'timesheet_exports.project_id', 'projects.id')
                ->join('timesheet_export_details', 'timesheet_exports.id', 'timesheet_export_details.timesheet_export_id')
                ->join('user_timesheets', 'timesheet_export_details.user_timesheet_id', 'user_timesheets.id')
                ->join('employees', function ($join) use ($organizationId) {
                    $join->on('user_timesheets.employee_id', '=',  'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })
                ->select(
                    'timesheet_exports.project_id',
                    'timesheet_exports.start_date',
                    'timesheet_exports.end_date',
                    'timesheet_exports.is_exclude_name',
                    'projects.name as project_name',
                    DB::raw('group_concat(employees.id) as employee_ids'),
                    DB::raw('group_concat(timesheet_export_details.user_timesheet_id) as timesheet_ids')
                )
                // ->selectSub(function ($q) {
                //     $q->select(DB::raw('employees.display_name as name'))->from('employees')->whereRaw("`employees`.`id` = `timesheet_exports`.`created_by`");
                // }, 'exported_by_name')
                ->where('timesheet_exports.id', $exportedId)
                ->whereNull('timesheet_export_details.deleted_at')
                 ->withTrashed()
                ->first();
            $employeeIds = explode(',', $data->employee_ids);
            $timesheetIds = explode(',', $data->timesheet_ids);
            $displayStartDate = isset($data->start_date) ? $data->start_date : '';
            $displayEndDate = isset($data->end_date) ? $data->end_date : '';

            $summary = [
                'project_id' => $data->project_id,
                'start_date' => $data->start_date,
                'display_start_date' => $displayStartDate,
                'display_end_date' => $displayEndDate,
                'end_date' => $data->end_date,
                'employee_ids' => $employeeIds,
                'is_exclude_name' => $data->is_exclude_name,
                'project_name' => $data->project_name
            ];
            // get all data of timesheet entries
            $allEntries = UserTimesheet::withoutGlobalScopes([OrganizationScope::class])
                ->join('employees', function ($join) use ($organizationId) {
                    $join->on('user_timesheets.employee_id', '=',  'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })
                ->leftjoin('departments', function ($join) {
                    $join->on('employees.department_id', '=',  'departments.id');
                    $join->on('departments.organization_id', '=', 'employees.organization_id');
                })->where('user_timesheets.project_id', $summary['project_id'])
                ->whereIn('user_timesheets.employee_id', $summary['employee_ids'])
                ->whereIn('user_timesheets.id', $timesheetIds)
                ->where('user_timesheets.organization_id', $organizationId)
                ->whereBetween('user_timesheets.date', [$summary['start_date'], $summary['end_date']])
                ->select(
                    'user_timesheets.*',
                    'departments.name as department_name',
                    'employees.department_id as department_id',
                    DB::Raw('employees.display_name AS developer_name')
                )
                ->orderBy('user_timesheets.date', 'ASC')
                ->orderBy('employees.department_id', 'ASC')
                ->get();

            $allEntries = collect($allEntries)->groupBy('department_id');

            $projectManager = ProjectEmployee::join('employees', function ($join) use ($organizationId) {
                    $join->on('project_employees.employee_id', '=',  'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                 })
                ->where('project_role_id', ProjectRole::PROJECT_MANAGER)
                ->where('project_employees.organization_id', $organizationId)
                ->where('project_employees.project_id', $summary['project_id'])
                ->select(DB::raw('display_name AS manager_name'))
                ->get();

            $projectManager = $projectManager->implode('manager_name', ', ');

            $response = array();
            $empSummary = array();
            $count = 0;
            foreach ($allEntries as $department => $entries) {
                $departmentData = Department::find($department);

                if (!empty($departmentData)) {
                    $response[$count]['department'] = $departmentData->name;
                    $entries = collect($entries)->groupBy('employee_id');
                    $emp = 0;
                    foreach ($entries as $employee => $entry) {
                        $employeeData = Employee::find($employee);
                        $response[$count]['data'][$emp]['employee'] = $employeeData->display_name;
                        $response[$count]['data'][$emp]['entries'] = $entry;

                        $billingHours = collect($entry)->sum('billing_hours');
                        $workingHours = collect($entry)->sum('working_hours');
                        $empSummary[] = ['employee' => $employeeData->display_name, 'department_name'=> $departmentData->name, 'billing_hours' => $billingHours, 'actual_hours' => $workingHours];
                        $emp++;
                    }
                } else {
                    $response[$count]['label'] = 'Developer';
                    $response[$count]['value'] = $entries;
                }

                $count++;
            }

            unset($summary['start_date']);
            unset($summary['end_date']);
            unset($summary['employee_ids']);
            $response = [
                'summary' => $summary,
                'data' => $response,
                'empSummary' =>  $empSummary,
                'project_manager' => $projectManager,
            ];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while update status of timesheet export entry";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get exported timesheet employees detail
    public function getExportedTimesheetEmployeeDetail(Request $request)
    {
        try {
            $user = $request->user();
            $organizationId = $this->getCurrentOrganizationId();
            $roles = $user->roles;
            $allRoles = collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $exportedID = $request->exported_id;
            $exportDetails = TimesheetExport::where('id', $exportedID)->select('project_id','start_date', 'end_date')->first();
            $project = Project::where('id', $exportDetails->project_id)->select('name')->first();
            if (!in_array('administrator', $allRoles)) {
                $isAccess = ProjectEmployee::join('employees', function ($join) use ($organizationId) {
                        $join->on('project_employees.employee_id', '=',  'employees.id');
                        $join->where('employees.organization_id', $organizationId);
                    })
                    ->where('project_employees.project_id', $exportDetails->project_id)
                    ->where('project_employees.employee_id', $user->entity_id)
                    ->where('project_employees.organization_id', $organizationId)
                    ->count();

                if ($isAccess <= 0) {
                    return $this->sendFailResponse(__('messages.prevent_export'), 422);
                }
            }

            $query = Employee::withoutGlobalScopes([OrganizationScope::class])->join('project_employees', 'employees.id', 'project_employees.employee_id')
                ->join('user_timesheets', 'employees.id', 'user_timesheets.employee_id')
                ->leftjoin('departments', function ($join) {
                    $join->on('employees.department_id', '=',  'departments.id');
                    $join->on('departments.organization_id', '=', 'employees.organization_id');
                })->where('user_timesheets.project_id', $exportDetails->project_id)
                ->where('user_timesheets.deleted_at', null)
                ->whereIn('user_timesheets.timesheet_status_id', [TimesheetStatus::PENDING, TimesheetStatus::REJECTED])
                ->where('employees.organization_id', $organizationId)
                ->whereBetween('user_timesheets.date', [$exportDetails->start_date, $exportDetails->end_date])
                ->where('project_employees.project_id', $exportDetails->project_id)->where('project_employees.project_role_id', ProjectRole::DEVELOPERANDQA);

            $employees = $query->groupBy('employees.id')->get(['user_timesheets.employee_id', 'display_name as developer', 'departments.name as department', 'project_employees.project_id']);

            $data = TimesheetExport::join('timesheet_export_details', 'timesheet_exports.id', 'timesheet_export_details.timesheet_export_id')
                ->join('user_timesheets', 'timesheet_export_details.user_timesheet_id', 'user_timesheets.id')
                ->join('employees', function ($join) use ($organizationId) {
                    $join->on('user_timesheets.employee_id', '=',  'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })
                ->select('employees.id as employee_ids')
                ->groupBy('user_timesheets.employee_id')
                ->where('timesheet_exports.id', $exportedID)
                ->get()->pluck('employee_ids')->toArray();

            $employeeIds = $data;

            $response = [
                'project_id' => $exportDetails->project_id,
                'project_name' => $project->name,
                'start_date' => $exportDetails->start_date,
                'end_date' => $exportDetails->end_date,
                'allEmployees' => $employees,
                'selectedEmployees' => $employeeIds
            ];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while fetch employees details for edit export";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getExportOldData(Request $request){
        DB::beginTransaction();
        try {
            $exports = DB::connection('old_connection')->table('export_details')->get();

            $organizationId = $request->organization_id;

            if(!empty($exports)){
                foreach($exports as $export){

                    $project = DB::connection('old_connection')->table('projects')->where('id', $export->project_id)->first(['name']);

                    $projectId = "";
                    if (!empty($project)) {
                        $project = Project::where('name', 'LIKE', $project->name)->where('organization_id', $organizationId)->withTrashed()->first(['id']);
                        $projectId = $project->id;
                    }

                    $user = DB::connection('old_connection')->table('users')->where('id', $export->created_by)->first(['email']);
                    $userId = 2;
                    if (!empty($user)) {
                        $user = User::where('email', 'LIKE', $user->email)->where('organization_id', $organizationId)->first(['id']);
                       if(!empty($user)){
                            $userId = $user->id;
                       }

                    }

                    $exportData = TimesheetExport::create([
                        'project_id' => $projectId,
                        'start_date' => $export->start_date,
                        'end_date' => $export->end_date,
                        'created_by' => $userId??2,
                        'exported_file' => $export->exported_file,
                        'timesheet_status_id' => $export->billed == 0 ? TimesheetStatus::SUBMITTED : TimesheetStatus::INVOICED,
                        'is_exclude_name' => $export->is_exclude_name,
                        'is_rollback' => !empty($export->deleted_at) ? 1 : 0,
                        'created_at' => $export->created_at,
                        'deleted_at' => $export->deleted_at
                    ]);
    
                    $exportedID = $exportData->id;
                
    
                if (!empty($export->id)) {

                    $exportDetails = DB::connection('old_connection')->table('exported_logs')->where('exported_id', $export->id)->get();

                        if (!empty($exportDetails)) {
                            foreach ($exportDetails as $detail) {
                                $timesheet = DB::connection('old_connection')->table('user_timesheets')->where('id', $detail->timesheet_id)->first();
                                
                                $employee = DB::connection('old_connection')->table('employees')->where('id', $timesheet->employee_id)->first(['employee_id']);
                                $employeeId = $employee->employee_id;

                                
                                $userTimesheet = UserTimesheet::where(['project_id' =>  $project->id , 'employee_id' =>  $employeeId, 'working_hours' => $timesheet->working_hours,'billing_hours' => $timesheet->billing_hours,'note' => $timesheet->note, 'organization_id' => $organizationId])->withTrashed()->first();
                                
                                if(!empty($userTimesheet)){
                                    TimesheetExportDetail::create([
                                        'timesheet_export_id' => $exportedID,
                                        'user_timesheet_id' => $userTimesheet->id,
                                        'log_billing_hours' => $userTimesheet->billing_hours,
                                        'deleted_at' => $detail->deleted_at
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
                
            DB::commit();
            return $this->sendSuccessResponse(__('messages.export_timesheet_imported'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while exported timesheet imported";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Fetch weekends and holiday between two dates
    public function getHoliday($startDate, $endDate)
    {
        $query = Holiday::whereBetween('date', [$startDate, $endDate]);
        $holiday = $query->select('date')->pluck('date')->toArray();
        $weekends = $this->getWeekendDays($startDate, $endDate);

        if (is_array($weekends)) {
            $holiday = array_unique(array_merge($holiday, $weekends));
        }
        
        $holiday = array_values($holiday);

        return $holiday;
    }
}
