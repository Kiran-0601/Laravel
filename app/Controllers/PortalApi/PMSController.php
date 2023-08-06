<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\AssignEmployeeTask;
use App\Mail\DeleteTask;
use App\Models\ActivityLog;
use App\Models\EmailNotification;
use App\Models\Employee;
use App\Models\EntityType;
use App\Models\Holiday;
use App\Models\LinkedTask;
use App\Models\OrganizationSetting;
use App\Models\Project;
use App\Models\ProjectEmployee;
use App\Models\ProjectRole;
use App\Models\ProjectStatus;
use App\Models\ReasonForLinkTask;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\Sprint;
use App\Models\SprintStatus;
use App\Models\SprintTask;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\TaskEmployee;
use App\Models\TaskPriorityType;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\User;
use App\Models\UserTimesheet;
use App\Traits\ResponseTrait;
use App\Traits\UploadFileTrait;
use App\Validators\SprintValidator;
use App\Validators\TaskCommentValidator;
use App\Validators\TaskValidator;
use App\Validators\TaskStatusValidator;
use Auth;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;

class PMSController extends Controller
{

    use ResponseTrait, UploadFileTrait;

    private $taskValidator;
    private $sprintValidator;

    private $taskCommentValidator;

    private $workingHoursPerDay;
    private $taskStatusValidator;
    function __construct()
    {
        $this->taskValidator = new TaskValidator();
        $this->sprintValidator = new SprintValidator();
        $this->taskCommentValidator = new TaskCommentValidator();
        $this->taskStatusValidator = new TaskStatusValidator();

        $setting = OrganizationSetting::with('setting')->whereHas('setting', function ($subQuery) {
            $subQuery->where("settings.key", "working_hours");
        })->first();

        $this->workingHoursPerDay = $setting->value;
    }

    //Get pms dashboard project list
    public function getUserProjectList(Request $request)
    {
        try {
            $keyword = $request->keyword ?? '';
            $perPage = $request->perPage ?? '';
            $is_archive = !empty($request->is_archived) ? 1 : 0;
            $status = $request->status;
            $customer = $request->customer;
            $organizationId = $this->getCurrentOrganizationId();

            $user = $request->user();
            $roles = $user->roles;
            $allRoles = collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $sprints = Sprint::select('project_id','completed_at', 'start_date', 'end_date','sprint_status_id')->where('organization_id', $organizationId)->get()->toArray();
            $tasks = Task::join('task_statuses', 'tasks.status_id', 'task_statuses.id')->join('task_types', 'tasks.type_id', 'task_types.id')->where('task_types.name', '!=','epic')->where('task_statuses.name', '!=','completed')->select('tasks.project_id', DB::raw('count(tasks.id) as task_count'))->groupBy('tasks.project_id')->get()->pluck('task_count','project_id')->toArray();
            $taskActivity = ActivityLog::withoutGlobalScopes([OrganizationScope::class])->where('module_name', 'task')->where('activity_logs.organization_id', $organizationId)->join('tasks', 'activity_logs.module_id', 'tasks.id')->select('tasks.project_id', DB::raw('MAX(activity_logs.created_at) as update_time'),'activity_logs.created_at')->groupBy('project_id')->orderBy('activity_logs.created_at', 'desc')->get()->pluck('update_time', 'project_id');
            
            $projectData = Project::withoutGlobalScopes()
                ->leftJoin('user_timesheets', function ($join) use ($user, $organizationId) {
                    $join->on('projects.id', '=', 'user_timesheets.project_id');
                    $join->where('user_timesheets.employee_id', $user->entity_id);
                    $join->where('user_timesheets.organization_id', $organizationId);
                })
                ->select('projects.id', 'projects.uuid', 'name as project_name', 'abbreviation as project_key', 'projects.logo_url', DB::raw("MAX(user_timesheets.date) as last_worked"))
                ->where('projects.organization_id', $organizationId)
                ->whereNull('projects.deleted_at');

            $projectData = $projectData->orderBy('last_worked', 'desc')->groupBy('projects.id');

            if (!in_array('administrator', $allRoles)) {
                $projectData = $projectData->join('project_employees', function ($join) use ($user, $organizationId) {
                    $join->on('projects.id', '=', 'project_employees.project_id');
                    $join->where('project_employees.employee_id', $user->entity_id);
                    $join->where('project_employees.organization_id', $organizationId);
                    $join->orWhere('projects.default_access_to_all_users', 1);
                });
            }

            $projectData = $projectData->where(function ($q1) use ($keyword) {

                if (!empty($keyword)) {
                    $q1->where(
                        function ($q2) use ($keyword) {
                            $q2->where('projects.name', "like", '%' . $keyword . '%');
                            $q2->orWhere('projects.description', "like", '%' . $keyword . '%');
                        }
                    );
                }
            });

            $archived = ProjectStatus::where('slug', ProjectStatus::ARCHIVED)->first('id');
            $archived = $archived->id;
            if (empty($is_archive)) {
                $projectData = $projectData->whereNotIn('projects.status_id', [$archived]);
            } else {
                $projectData = $projectData->whereIn('projects.status_id', [$archived]);
            }

            if (!empty($status)) {
                $projectData = $projectData->whereIn('projects.status_id', [$status]);
            }

            if (!empty($customer)) {
                $projectData = $projectData->where('projects.customer_id', $customer);
            }

            $projectData = $projectData->orderby('projects.id', 'desc');
            
            $projectData = $projectData->simplePaginate($perPage);
           
            foreach ($projectData as $value) {
                if (!empty($value->logo_url)) {
                    $path = config('constant.project_logo');
                    $value->logo_url = getFullImagePath($path . '/' . $value->logo_url);
                }

              
                $projectSprint = array_filter($sprints, function($sprint) use($value) {
                    
                    if($sprint['project_id'] == $value->id){
                        return $sprint;
                    }
                });

               $redSprint='false';
               $redItem = array_map(function($sprint) use($redSprint){
                    if($sprint['end_date'] < getUtcDate() && $sprint['sprint_status_id'] != SprintStatus::COMPLETE){
                        $redSprint = 'true';
                    }
                    return $redSprint;
               },$projectSprint);

               if(!empty($redItem) && in_array('true', $redItem)){
                  $redSprint = "true";
               }

                $value->sprints = count($projectSprint);
                $value->redSprint = $redSprint;
                $value->tasks = !empty($tasks[$value->id]) ? $tasks[$value->id] : 0;
                $value->last_activity = !empty($taskActivity[$value->id]) ? $taskActivity[$value->id] : '';
                
            }

            $data['projects'] = $projectData;

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while list projects in PMS dashboard";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }


    //Get all task related informations
    public function getTaskInformation(Request $request)
    {
        try {
            $inputs = $request->all();
            $expectedModule = !empty($inputs['expected_module']) ? $inputs['expected_module'] : '';
            $projectId = !empty($inputs['project_id']) ? $inputs['project_id'] : '';

            $project = Project::where('uuid', $projectId)->first();
            $response = [];
            if (empty($expectedModule) || in_array('task_status', $expectedModule)) {
                $data = TaskStatus::where('project_id', $project->id)->select('id', 'name')->get();

                // if (empty($taskType) && !empty($project->id)) {
                //     $fixed = TaskStatus::where('project_id', $project->id)->where('name', 'fixed')->select('id')->first();
                //     $reopen = TaskStatus::where('project_id', $project->id)->where('name', 'reopen')->select('id')->first();

                //     if (!empty($fixed) && !empty($reopen)) {
                //         $data = TaskStatus::where('project_id', $project->id)->select('id', 'name')->get()->except([$fixed->id, $reopen->id]);
                //     }
                // }

                $response['task_status'] = $data;
            }

            if (empty($expectedModule) || in_array('task_type', $expectedModule)) {

                $data = TaskType::join('task_type_icons', 'task_types.icon_id', 'task_type_icons.id')->where('task_types.project_id', $project->id)->select('task_types.id', 'task_types.name', 'task_types.icon_id', 'icon_path as icon')->get();

                $response['task_type'] = $data;
            }

            if (empty($expectedModule) || in_array('task_priority', $expectedModule)) {

                $data = TaskPriorityType::select('id', 'name', 'icon')->get();

                $response['task_priority'] = $data;
            }

            if (empty($expectedModule) || in_array('assigned_to', $expectedModule)) {
                $project = Project::select('default_access_to_all_users', 'id')->where('uuid', $projectId)->first();

                $organizationId = $this->getCurrentOrganizationId();

                if ($project->default_access_to_all_users == 1) {

                    $employees = Employee::select('employees.id', 'employees.uuid', 'employees.display_name as name','employees.avatar_url')->get();
                } else {
                    $employees = Employee::withoutGlobalScopes([OrganizationScope::class])->join('project_employees', function ($join) use ($organizationId, $project) {
                        $join->on('employees.id', '=', 'project_employees.employee_id');
                        $join->where('project_employees.organization_id', $organizationId);
                        $join->where('project_employees.project_id', $project->id);
                        $join->where('project_role_id', ProjectRole::DEVELOPERANDQA);
                    })->where('employees.organization_id', $organizationId)->select('employees.display_name as name', 'employees.avatar_url', 'employees.uuid')
                        ->get();
                }

                $response['assigned_to'] = $employees;
            }

            if (empty($expectedModule) || in_array('reporters', $expectedModule)) {
                $project = Project::select('default_access_to_all_users', 'id')->where('uuid', $projectId)->first();
                $organizationId = $this->getCurrentOrganizationId();
                $role = Role::where('name', 'administrator')->first();

                if ($project->default_access_to_all_users == 1) {

                    $employees = Employee::select('employees.id', 'employees.uuid', 'employees.display_name as name','employees.avatar_url')->get();
                } else {
                    $employees = Employee::withoutGlobalScopes([OrganizationScope::class])
                        ->leftJoin('project_employees', function ($join) use ($organizationId) {
                            $join->on('employees.id', '=', 'project_employees.employee_id');
                            $join->where('project_employees.organization_id', $organizationId);
                        })->leftJoin('users', function ($join) use ($organizationId) {
                        $join->on('users.entity_id', '=', 'employees.id');
                        $join->where('users.organization_id', $organizationId);
                    })->leftJoin('model_has_roles', 'users.id', 'model_has_roles.model_id')
                        ->where(function ($query) use ($role, $project) {
                            $query->where('project_employees.project_id', $project->id);
                            $query->orWhere('model_has_roles.role_id', $role->id);
                        })
                        ->where('employees.organization_id', $organizationId)
                        ->select('employees.display_name as name', 'employees.avatar_url', 'employees.uuid')
                        ->groupBy('employee_id')
                        ->get();
                }

                $response['reporters'] = $employees;
            }

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while task information";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }

    }

    //Create task
    public function createTask(Request $request)
    {
        try {
            $inputs = json_decode($request->data, true);
            $project = Project::where('uuid', $inputs['project_id'])->first(['id', 'name', 'abbreviation as project_key']);
            $taskType = TaskType::where('project_id', $project->id)->where('name', 'task')->first('id');

            $inputs['type'] = !empty($inputs['type']) ? $inputs['type'] : $taskType->id;
            $request->merge($inputs);

            $user = $request->user();
            $validation = $this->taskValidator->validatestore($request);
            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }
            DB::beginTransaction();

            $organizationId = $this->getCurrentOrganizationId();

            if (!empty($inputs['assigned_to'])) {
                $employee = Employee::where('uuid', $inputs['assigned_to'])->first('id');
                $employeeId = $employee->id;
            }

            if (!empty($inputs['reporter'])) {
                $employee = Employee::where('uuid', $inputs['reporter'])->first('id');
                $reporterId = $employee->id;
            }

            $activitylog = [
                'module_name' => 'task',
                'updated_by' => $user->id
            ];


            $task = Task::where('project_id', $project->id)->orderBy('id', 'desc')->first();
            $taskId = !empty($task) ? $task->task_abbr_id : 0;
            $taskOrder = !empty($task) ? $task->order : 0;

            $taskStatus = TaskStatus::where('project_id', $project->id)->where('name', TaskStatus::TODO)->first();

            $taskData = [
                'title' => $inputs['title'],
                'type_id' => $inputs['type'],
                'parent_id' => $inputs['parent_id'] ?? 0,
                'status_id' => $inputs['status'] ?? $taskStatus->id,
                'project_id' => $project->id,
                'priority_id' => $inputs['priority'] ?? TaskPriorityType::MEDIUM,
                'description' => $inputs['description'] ?? null,
                'created_by' =>  $reporterId ?? $user->entity_id,
                'updated_by' => $user->id,
                'organization_id' => $organizationId,
                'task_abbr_id' => $taskId + 1,
                'estimated_hours' => $inputs['estimation'] ?? 0,
                'order' => $taskOrder + 1
            ];

            $task = Task::firstOrCreate($taskData);

            if (!empty($inputs['sprint_id'])) {
                $data = [
                    'task_id' => $task->id,
                    'sprint_id' => $inputs['sprint_id']
                ];
                SprintTask::firstOrCreate($data);
            }


            if (!empty($request->attachments)) {

                $attachments = $request->attachments;

                $path = config('constant.task_attachments');

                foreach ($attachments as $attachment) {

                    $file = $this->uploadFileOnLocal($attachment, $path);

                    $mimeType = $attachment->getMimeType();
                    $fileName = $attachment->getClientOriginalName();

                    if (!empty($file['file_name'])) {
                        $attachmentData = [
                            'task_id' => $task->id,
                            'attachment_path' => $file['file_name'],
                            'mime_type' => $mimeType,
                            'file_name' => $fileName
                        ];

                        TaskAttachment::create($attachmentData);
                    }

                }
            }

            if (!empty($employeeId)) {
                $taskEmployee = TaskEmployee::where('task_id', $task->id)->first();

                $employeeData = [
                    'task_id' => $task->id,
                    'employee_id' => $employeeId,
                    'organization_id' => $organizationId
                ];
                if ($taskEmployee) {
                    TaskEmployee::where('id',$taskEmployee->id)->update($employeeData);
                } else {
                    TaskEmployee::create($employeeData);
                }

                $activitylog['module_id'] = $task->id;
                $activitylog['table_name'] = 'employees';
                $activitylog['action'] = 'has assigned';
                $activitylog['old_data'] = NULL;
                $activitylog['new_data'] = json_encode(array('display_name' => $employeeId));
                $activitylog['organization_id'] = $organizationId;
                ActivityLog::create($activitylog);

                $userData = User::where('entity_id', $employeeId)->whereIn('entity_type_id', [EntityType::Employee, EntityType::Admin])->first();

                $notifications = EmailNotification::where('user_id',$userData->id)->first(['allow_all_notifications','assign_task']);

                if($notifications->allow_all_notifications == true && $notifications->assign_task == true){

                    $info = ['display_name' => $userData->display_name, 'title' => $task->title, 'email' => $userData->email, 'priority' => $task->priority->display_name, 'project_name' => $project->name];

                    $data = new AssignEmployeeTask($info);

                    $emailData = ['email' => $userData->email, 'email_data' => $data];

                    SendEmailJob::dispatch($emailData);
                }

            }
            $taskData['id'] = $task->id;

            DB::commit();

            $task = Task::leftJoin('task_types', 'tasks.type_id', 'task_types.id')
                ->leftJoin('task_type_icons', 'task_types.icon_id', 'task_type_icons.id')
                ->leftJoin('task_priority_types', 'tasks.priority_id', 'task_priority_types.id')
                ->leftJoin('task_statuses', 'tasks.status_id', 'task_statuses.id')
                ->leftJoin('task_employees', 'tasks.id', 'task_employees.task_id')
                ->leftJoin('employees', function ($join) use ($organizationId) {
                    $join->on('task_employees.employee_id', '=', 'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })->where('tasks.id', $task->id)
                ->select('tasks.id', 'tasks.task_abbr_id', 'title', 'tasks.type_id', 'task_types.name as type_name', 'tasks.status_id', 'task_statuses.name as status_name', 'employees.uuid', 'employees.avatar_url', 'tasks.priority_id', 'task_type_icons.icon_path', 'task_priority_types.icon')->first();

            if (!empty($task->avatar_url)) {
                $path = config('constant.avatar');
                $task->avatar = getFullImagePath($path . '/' . $task->avatar_url);
            }

            if (!empty($task->icon)) {
                $task->priority_icon = url('/image/' . $task->icon);
            }

            if (!empty($task->icon_path)) {
                $path = config('constant.task_type_icons');
                $task->type_icon = getFullImagePath($path . '/' . $task->icon_path);
            }

            $task->task_id = $project->project_key . '-' . $task->task_abbr_id;
            $task->sprint_id = $inputs['sprint_id'] ?? null;


            return $this->sendSuccessResponse(__('messages.task_store'), 200, $task);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while create task";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get task detail
    public function getTaskDetail(Request $request, $result = 'external')
    {
        try {

            $inputs = $request->all();

            $user = $request->user();
            $roles = $user->roles;
            $organizationId = $this->getCurrentOrganizationId();
            $allRoles = collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            //Get task id from task abbr id
            if (!empty($inputs['task_id'])) {
                $taskAbbrId = $inputs['task_id'];
                $parts = explode('-', $taskAbbrId);
                $taskId = end($parts);
                $projectId = $inputs['project_id'];
                $project = Project::where('uuid', $projectId)->first();
                $task = Task::where('project_id', $project->id)->where('task_abbr_id', $taskId)->first();
                $taskId = $task->id;

                if (!in_array('administrator', $allRoles)) {
                    $isAccess = ProjectEmployee::join('employees', function ($join) use ($organizationId) {
                            $join->on('project_employees.employee_id', '=',  'employees.id');
                            $join->where('employees.organization_id', $organizationId);
                        })
                        ->where('project_employees.project_id', $project->id)
                        ->where('project_employees.employee_id', $user->entity_id)
                        ->where('project_employees.organization_id', $organizationId)
                        ->count();
    
                    if ($isAccess <= 0) {
                        return $this->sendFailResponse(__('messages.access_denied'), 403);
                    }
                }
            } else {
                $taskId = $inputs['id'];
            }
            
            $task = Task::with(['taskAttachments:id,task_id,file_name,attachment_path,mime_type', 'comments:id,employee_id,organization_id,task_id,comment,created_at'])
                ->leftJoin('tasks as parent_task', 'tasks.parent_id', 'parent_task.id')
                ->leftJoin('task_types as parent_task_type', 'parent_task.type_id', 'parent_task_type.id')   
                ->leftJoin('task_type_icons as parent_task_icon', 'parent_task_type.icon_id', 'parent_task_icon.id')
                ->leftJoin('sprint_tasks', 'tasks.id', 'sprint_tasks.task_id')
                ->leftJoin('sprints', 'sprint_tasks.sprint_id', 'sprints.id')
                ->leftJoin('task_types', 'tasks.type_id', 'task_types.id')
                ->leftJoin('task_type_icons', 'task_types.icon_id', 'task_type_icons.id')
                ->leftJoin('task_priority_types', 'tasks.priority_id', 'task_priority_types.id')
                ->leftJoin('task_statuses', 'tasks.status_id', 'task_statuses.id')
                ->leftJoin('task_employees', 'tasks.id', 'task_employees.task_id')
                ->leftJoin('employees', function ($join) use ($organizationId) {
                    $join->on('task_employees.employee_id', '=', 'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })
                ->leftJoin('employees as reporter', function ($join) use ($organizationId) {
                    $join->on('tasks.created_by', '=', 'reporter.id');
                    $join->where('reporter.organization_id', $organizationId);
                })
                ->where('tasks.id', $taskId)->orderBy('tasks.created_at', 'desc')
                ->select('tasks.id',
                            'tasks.parent_id',
                            'tasks.task_abbr_id',
                            'tasks.title',
                            'tasks.project_id',
                            'tasks.description',
                            'tasks.status_id',
                            'tasks.priority_id',
                            'tasks.estimated_hours',
                            'tasks.type_id',
                            'tasks.created_at',
                            'tasks.updated_at',
                            'task_statuses.name as status_name',
                            'task_types.icon_id',
                            'task_types.name as type_name',
                            'parent_task.title as parent_task_title',
                            'parent_task.task_abbr_id as parent_task_abbr',
                            'parent_task_type.name as parent_task_type_name',
                            'parent_task_icon.icon_path as parent_task_icon',
                            'task_type_icons.icon_path',
                            'task_priority_types.icon',
                            'employees.uuid',
                            'employees.avatar_url',
                            'employees.display_name',
                            'reporter.uuid as reporter_uuid',
                            'reporter.avatar_url as reporter_url',
                            'reporter.display_name as reporter_display_name',
                            'sprint_tasks.sprint_id',
                            'sprints.name as sprint_name',
                            DB::raw('IF(`parent_task_type`.`name` <> "epic", "true", "false") child_task'))
                ->first();

            $task = $this->getTaskUpdatedInfo($task);

            foreach ($task->comments as $comment) {
                $comment->employee = $comment->getAuthorData();
            }

            $subtasks = Task::leftJoin('task_types', 'tasks.type_id', 'task_types.id')
                ->leftJoin('task_type_icons', 'task_types.icon_id', 'task_type_icons.id')
                ->leftJoin('task_priority_types', 'tasks.priority_id', 'task_priority_types.id')
                ->leftJoin('task_statuses', 'tasks.status_id', 'task_statuses.id')
                ->leftJoin('task_employees', 'tasks.id', 'task_employees.task_id')
                ->leftJoin('employees', function ($join) use ($organizationId) {
                    $join->on('task_employees.employee_id', '=', 'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })
                ->where('tasks.parent_id', $taskId)
                ->orderBy('tasks.created_at', 'desc')
                ->select('tasks.id', 'tasks.project_id', 'title', 'tasks.type_id', 'task_types.name as type_name', 'tasks.status_id', 'task_statuses.name as status_name', 'employees.avatar_url', 'tasks.priority_id', 'tasks.task_abbr_id', 'task_type_icons.icon_path', 'task_priority_types.icon', 'task_priority_types.name as priority_name', 'employees.uuid', 'employees.display_name as assigned_to')
                ->get();

            if (!empty($subtasks)) {
                foreach ($subtasks as &$subtask) {
                    $subtask = $this->getTaskUpdatedInfo($subtask);
                }

                $task->subtasks = $subtasks;
            }

            $linkedTasks = LinkedTask::join('tasks', 'tasks.id', 'linked_tasks.link_task_id')
                ->leftJoin('task_types', 'task_types.id', 'tasks.type_id')
                ->leftJoin('task_type_icons', 'task_types.icon_id', 'task_type_icons.id')
                ->leftJoin('task_priority_types', 'tasks.priority_id', 'task_priority_types.id')
                ->leftJoin('task_statuses', 'tasks.status_id', 'task_statuses.id')
                ->leftJoin('task_employees', 'tasks.id', 'task_employees.task_id')
                ->leftJoin('employees', function ($join) use ($organizationId) {
                    $join->on('task_employees.employee_id', '=', 'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })
                ->where('linked_tasks.task_id', $taskId)
                ->orderBy('tasks.created_at', 'desc')
                ->select('linked_tasks.id', 'linked_tasks.reason_id', 'linked_tasks.link_task_id', 'tasks.project_id', 'title', 'tasks.type_id', 'task_types.name as type_name', 'tasks.status_id', 'task_statuses.name as status_name', 'employees.avatar_url', 'tasks.priority_id', 'tasks.task_abbr_id', 'task_type_icons.icon_path', 'task_priority_types.icon', 'task_priority_types.name as priority_name', 'employees.uuid', 'employees.display_name as assigned_to')
                ->get();

            $count = 0;
            $data = [];
            if (!empty($linkedTasks)) {

                $linkedTasks = $linkedTasks->groupBy('reason_id');

                foreach ($linkedTasks as $reason => $linkedTask) {
                    $reasonName = ReasonForLinkTask::where('id', $reason)->first('name');

                    foreach ($linkedTask as &$linked) {
                        $linked = $this->getTaskUpdatedInfo($linked);
                    }

                    $data[$count]['name'] = $reasonName->name;
                    $data[$count]['tasks'] = $linkedTask;

                    $count++;
                }

                $task->linkedTasks = $data;
            }

            $project = Project::where('id', $task->project_id)->first(['name', 'logo_url','abbreviation']);

            if (!empty($project->logo_url)) {
                $path = config('constant.project_logo');
                $project->logo = getFullImagePath($path . '/' . $project->logo_url);
            }

            $task->project_name = $project->name;
            $task->project_logo = $project->logo ?? null;

            $taskType = TaskType::join('task_type_icons', 'task_types.icon_id', 'task_type_icons.id')->where('task_types.project_id', $task->project_id)->where('task_types.name', 'epic')->select('task_types.id', 'task_types.name', 'task_types.icon_id', 'icon_path as icon')->first();

            $epics = '';
            if (!empty($taskType)) {
                $epics = Task::where('type_id', $taskType->id)->orderBy('tasks.order', 'asc')->get(['id', 'title','task_abbr_id']);
            }
            if(!empty($epics)){

                $path = config('constant.task_type_icons');
                $icon =  getFullImagePath($path .'/'.$taskType->icon );

                foreach($epics as $epic){
                  
                    $epic->icon_url = $icon;
                    $epic->name = $project->abbreviation.'-'.$epic->task_abbr_id. ' '. $epic->title;
                    $epic->display_name = $project->abbreviation.'-'.$epic->task_abbr_id;
                }
            }

            $task->epics = $epics;

            $sprints = Sprint::where('project_id', $task->project_id)->where('sprint_status_id', '!=', SprintStatus::COMPLETE)->orderBy('created_at', 'desc')->get(['id', 'name']);
            $task->sprints = $sprints;

            $allChildTasks = Task::leftJoin('tasks as parent_task', 'tasks.parent_id', 'parent_task.id')->leftJoin('task_types', 'task_types.id', 'parent_task.type_id')->where('tasks.project_id', $task->project_id)->where('tasks.parent_id', '!=', 0)->where('task_types.name', '!=', 'epic')->orderBy('tasks.created_at', 'desc')->get(['tasks.id', 'tasks.title']);
            $task->childtasks = $allChildTasks;

            $allParentTasks = Task::leftJoin('tasks as parent_task', 'tasks.parent_id', 'parent_task.id')->leftJoin('task_types','task_types.id','tasks.type_id')->leftJoin('task_type_icons', 'task_types.icon_id', 'task_type_icons.id')->leftJoin('task_types as parent_task_type', 'parent_task_type.id', 'parent_task.type_id')
                                    ->where('tasks.project_id', $task->project_id)
                                    ->where('task_types.name', '!=', 'epic')
                                    ->where(function($q){
                                        $q->where('tasks.parent_id', 0);
                                        $q->orWhere('parent_task_type.name', 'epic');
                                    })
                                    ->orderBy('tasks.created_at', 'desc')
                                    ->get(['tasks.id', 'tasks.title','tasks.task_abbr_id', 'task_type_icons.icon_path']);
            foreach($allParentTasks as $parent){
                $parent->icon = "";
                if (!empty($parent->icon_path)) {
                    $path = config('constant.task_type_icons');
                    $parent->icon_url =  getFullImagePath($path .'/'.$parent->icon_path );
                }

                $parent->name = $project->abbreviation.'-'.$parent->task_abbr_id. ' '. $parent->title;
                $parent->display_name = $project->abbreviation.'-'.$parent->task_abbr_id;
            }
          
                                    
            $task->parentTasks = $allParentTasks;

            $allTasksForLink = Task::where('project_id', $task->project_id)->orderBy('created_at', 'desc')->get(['id', 'title']);
            $task->tasks = $allTasksForLink;

            $estimatedHours = $task->estimated_hours;
            $timesheetQuery = UserTimesheet::where('task_id', $taskId);
            $hours = $timesheetQuery
                ->select('working_hours')
                ->get();

            $loggedHours = 0;
            if (!empty($hours)) {
                $total = collect($hours)->sum('working_hours');
                $loggedHours = $total;
            }
            if ($estimatedHours == 0) {
                $progress = 0;
            } else {
                $progress = (($loggedHours * 100) / $estimatedHours);
                if ($progress > 100) {
                    $progress = 100;
                }
            }
            $task->logged_hours = $loggedHours;
            $percentage = round($progress, 2);

            $task->logged_time = $percentage;

            if ($result == 'internal') {
                return $task;
            }

            return $this->sendSuccessResponse(__('messages.success'), 200, $task);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while task detail";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    private function getTaskUpdatedInfo($task)
    {

        $task->avatar = null;
        if (!empty($task->avatar_url)) {
            $path = config('constant.avatar');
            $task->avatar = getFullImagePath($path . '/' . $task->avatar_url);
        }

        if (!empty($task->reporter_url)) {
            $path = config('constant.avatar');
            $task->reporter_avatar = getFullImagePath($path . '/' . $task->reporter_url);
        }

        if (!empty($task->icon)) {
            $task->priority_icon = url('/image/' . $task->icon);
        }

        if (!empty($task->icon_path)) {
            $path = config('constant.task_type_icons');
            $task->type_icon = getFullImagePath($path . '/' . $task->icon_path);
        }
        $task->parent_task_type_icon = null;
        if (!empty($task->parent_task_icon)) {
            $path = config('constant.task_type_icons');
            $task->parent_task_type_icon = getFullImagePath($path . '/' . $task->parent_task_icon);
        }

        $project = Project::where('id', $task->project_id)->first('abbreviation as key');
        if (!empty($project)) {
            $task->task_id = $project->key . '-' . $task->task_abbr_id;
            $task->parent_task_id = null;
            if(!empty($task->parent_task_abbr)){
                $task->parent_task_id = $project->key . '-' . $task->parent_task_abbr;
            }
        }

        return $task;
    }

    // Update task
    public function updateTask(Request $request, Task $task)
    {
        try {
            $inputs = $request->all();
            $user = $request->user();
            $organizationId = $this->getCurrentOrganizationId();
            DB::beginTransaction();

            //For activityLog
            $activitylog = [
                'module_id' => $task->id,
                'module_name' => 'task',
                'updated_by' => $user->id
            ];
            //End

            if (!empty($inputs['status'])) {
                $taskData['status_id'] = $inputs['status'];
                //ActivityLog
                $activitylog['table_name'] = 'task_statuses';
                $activitylog['old_data'] = json_encode(array('name' => $task->status_id));
                $activitylog['new_data'] = json_encode(array('name' => $taskData['status_id']));
                $activitylog['action'] = 'changed status';
                //End
            }
            if (!empty($inputs['title'])) {
                $taskData['title'] = $inputs['title'];
                //ActivityLog
                $activitylog['table_name'] = 'tasks';
                $activitylog['old_data'] = json_encode(array('plain' => $task->title));
                $activitylog['new_data'] = json_encode(array('plain' => $taskData['title']));
                $activitylog['action'] = 'updated the Summary';
                //End
            }

            if (!empty($inputs['priority'])) {
                $taskData['priority_id'] = $inputs['priority'];
                 //ActivityLog
                 $activitylog['table_name'] = 'task_priority_types';
                 $activitylog['old_data'] = json_encode(array('name' => $task->priority_id));
                 $activitylog['new_data'] = json_encode(array('name' => $taskData['priority_id']));
                 $activitylog['action'] = 'changed the Priority';
                 //End
            }

            if (!empty($inputs['epic'])) {
                $taskData['parent_id'] = $inputs['epic'];
                 //ActivityLog
                 $activitylog['table_name'] = 'tasks';
                 $activitylog['old_data'] = !empty($task->parent_id) ? json_encode(array('title' => $task->parent_id)) : NULL;
                 $activitylog['new_data'] = json_encode(array('title' => $taskData['parent_id']));
                 $activitylog['action'] = 'changed the Parent';
                 //End
            }

            if (!empty($inputs['description'])) {
                $taskData['description'] = $inputs['description'];
                  //ActivityLog
                  $activitylog['table_name'] = 'tasks';
                  $activitylog['old_data'] = json_encode(array('plain' => $task->description));
                  $activitylog['new_data'] = json_encode(array('plain' => $taskData['description']));
                  $activitylog['action'] = 'updated the Description';
                  //End
            }
            if (isset($inputs['estimation'])) {
                $taskData['estimated_hours'] = $inputs['estimation'];
                //ActivityLog
                $activitylog['table_name'] = 'tasks';
                $activitylog['old_data'] = json_encode(array('plain' => $task->estimated_hours));
                $activitylog['new_data'] = json_encode(array('plain' => $taskData['estimated_hours']));
                $activitylog['action'] = 'updated the estimate';
                //End
            }
            if (!empty($inputs['reporter'])) {
                $employee = Employee::Where('uuid', $inputs['reporter'])->first(['id']);
                $taskData['created_by'] = $employee->id;

                //ActivityLog
                $activitylog['table_name'] = 'employees';
                $activitylog['old_data'] = json_encode(array('display_name' => $task->created_by));
                $activitylog['new_data'] = json_encode(array('display_name' => $taskData['created_by']));
                $activitylog['action'] = 'updated the Reporter';
                //End
            }

            if(!empty($inputs['unlink_parent'])){
                $taskData['parent_id'] = 0;
            }

            $taskData['updated_by'] = $user->id;
            $task->update($taskData);

            if (!empty($inputs['child'])) {
                Task::where('id', $inputs['child']['id'])->update(['parent_id' => $task->id]);
            }

            if(!empty($inputs['deleted_child'])){
                Task::where('id', $inputs['deleted_child'])->update(['parent_id' => 0]);
            }

            if (!empty($inputs['linked'])) {
                foreach ($inputs['linked'] as $linked) {
                    $linkedTaskData = ['task_id' => $task->id, 'link_task_id' => $linked['id'], 'reason_id' => $inputs['reason_id']];
                    LinkedTask::create($linkedTaskData);

                     //ActivityLog
                  $activitylog['table_name'] = 'tasks';
                  $activitylog['old_data'] = NULL;
                  $activitylog['new_data'] = json_encode(array('title' => $linked['id']));
                  $activitylog['action'] = 'updated the Link';
                  //End
                }
            }

            if(!empty($inputs['deleted_linked'])){
                LinkedTask::where('id', $inputs['deleted_linked'])->delete();
            }

            if (!empty($inputs['sprint'])) {

                $sprintTask = SprintTask::where('task_id', $task->id)->first();

                $sprintTaskData = [
                    'task_id' => $task->id,
                    'sprint_id' => $inputs['sprint'],
                ];
                //ActivityLog
                $activitylog['table_name'] = 'sprints';
                $activitylog['action'] = 'updated sprint';
                $activitylog['old_data'] = !empty($sprintTask) ? json_encode(array('name' => $sprintTask->sprint_id)) : NULL;
                $activitylog['new_data'] = json_encode(array('name' => $inputs['sprint']));
                //end
                if ($sprintTask) {
                    SprintTask::where('id',$sprintTask->id)->update($sprintTaskData);
                } else {
                    SprintTask::create($sprintTaskData);
                }
            }

            if (!empty($request->attachments)) {
                $attachments = $request->attachments;

                $path = config('constant.task_attachments');

                foreach ($attachments as $attachment) {

                    $file = $this->uploadFileOnLocal($attachment, $path);

                    $mimeType = $attachment->getMimeType();
                    $fileName = $attachment->getClientOriginalName();

                    if (!empty($file['file_name'])) {
                        $attachmentData = [
                            'task_id' => $task->id,
                            'attachment_path' => $file['file_name'],
                            'mime_type' => $mimeType,
                            'file_name' => $fileName
                        ];

                        TaskAttachment::create($attachmentData);
                    }

                }
            }
            if (isset($inputs['assigned_to'])) {
                $taskEmployee = TaskEmployee::where('task_id', $task->id)->first();
                $employee = Employee::where('uuid', $inputs['assigned_to'])->first('id');
                $employeeId = $employee->id;

                $employeeData = [
                    'task_id' => $task->id,
                    'employee_id' => $employeeId,
                    'organization_id' => $organizationId
                ];
                //ActivityLog
                $activitylog['table_name'] = 'employees';
                $activitylog['action'] = 'has assigned';
                $activitylog['old_data'] = NULL;
                $activitylog['new_data'] = json_encode(array('display_name' => $employeeId));
                //end
                if ($taskEmployee) {
                    $activitylog['old_data'] = json_encode(array('display_name' => $taskEmployee->employee_id));
                    $taskEmployee->update($employeeData);
                } else {
                    TaskEmployee::create($employeeData);
                }

                $newUser = User::where('entity_id', $employeeId)->whereIn('entity_type_id', [EntityType::Employee, EntityType::Admin])->first(['email', 'entity_id','id']);

                $notifications = EmailNotification::where('user_id',$newUser->id)->first(['allow_all_notifications','assign_task']);

                if($notifications->allow_all_notifications == true && $notifications->assign_task == true){

                    $priority = TaskPriorityType::where('id', $task->priority_id)->first();

                    $project = Project::where('id', $task->project_id)->first(['name']);

                    $info = ['title' => $task->title, 'email' => $newUser->email, 'project_name' => $project->name, 'display_name' => $newUser->display_name, 'priority' => $priority->display_name];
    
                    $data = new AssignEmployeeTask($info);
    
                    $emailData = ['email' => $newUser['email'], 'email_data' => $data];
    
                    SendEmailJob::dispatch($emailData);
                }
            }

            if (!empty($activitylog['action'])) {
                $activitylog['organization_id'] = $organizationId;
                ActivityLog::create($activitylog);
            }
            DB::commit();

            $request->merge(['id' => $task->id]);

            $taskDetail = $this->getTaskDetail($request, 'internal');
            $task = $taskDetail;

            return $this->sendSuccessResponse(__('messages.success'), 200, $task);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while task update";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function loggedTimeHistory(Request $request)
    {
        try {
            $inputs = $request->all();

            $taskId = $inputs['task_id'];
            $organizationId = $this->getCurrentOrganizationId();

            $timesheetQuery = UserTimesheet::withoutGlobalScopes([OrganizationScope::class])->join('projects', 'user_timesheets.project_id', 'projects.id')
                ->join('employees', function ($join) {
                    $join->on('user_timesheets.employee_id', '=', 'employees.id');
                    $join->on('employees.organization_id', '=', 'user_timesheets.organization_id');
                })->where('user_timesheets.task_id', $taskId);

            $timesheetQuery->where('user_timesheets.organization_id', $organizationId);

            $timesheetQuery->orderBy('user_timesheets.created_at', 'desc')
                ->select(
                    'user_timesheets.id',
                    'user_timesheets.employee_id',
                    'user_timesheets.project_id',
                    'employees.display_name',
                    'user_timesheets.note',
                    'user_timesheets.working_hours as hours',
                    'user_timesheets.date',
                    'user_timesheets.timesheet_status_id'
                );

            $timesheetList = $timesheetQuery->get();

            foreach ($timesheetList as &$value) {
                if (!empty($value->hours)) {

                    $hours = intval($value->hours);
                    $minutes = round($value->hours - $hours, 2);
                    $minutes = round($minutes * 60);
                    $value->spent_total_hours = $hours . ' H ' . $minutes . ' M';
                }
            }

            return $this->sendSuccessResponse(__('messages.success'), 200, $timesheetList);

        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while logged history";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Delete task
    public function deleteTask($taskId)
    {
        try {
            DB::beginTransaction();

            $taskEmployee = TaskEmployee::where('task_id', $taskId)->first('employee_id');
            $task = Task::where('id', $taskId)->first(['created_by','title','project_id']);
            $project = Project::where('id', $task->project_id)->first(['name']);
            $displayName = Auth::user()->display_name;

            $employees = [$task->created_by];

            if(!empty($taskEmployee->employee_id)){
                array_push($employees, $taskEmployee->employee_id);
            }
            
            $userData = User::whereIn('entity_id', $employees)->whereIn('entity_type_id', [EntityType::Employee, EntityType::Admin])->get(['id','email', 'entity_id']);
            
            foreach($userData as $user){

                $notifications = EmailNotification::where('user_id',$user->id)->first(['allow_all_notifications','delete_task']);

                if($notifications->allow_all_notifications == true && $notifications->delete_task == true){

                    $info = [ 'title' => $task->title, 'project_name' => $project->name, 'display_name' => $displayName];

                    $data = new DeleteTask($info);

                    $emailData = ['email' => $user, 'email_data' => $data];

                    SendEmailJob::dispatch($emailData);
                }
            }

            SprintTask::Where('task_id', $taskId)->delete();

            Task::where('id', $taskId)->delete();

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while update sprint status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Create sprint
    public function createSprint(Request $request)
    {
        
        try {

            $inputs = $request->all();
            DB::beginTransaction();

            $validation = $this->sprintValidator->validateStore($request);
            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $organizationId = $this->getCurrentOrganizationId();
            $project = Project::where('uuid', $inputs['project_id'])->first('id');

            $sprint_data = [
                'name' => $inputs['sprint_name'],
                'organization_id' => $organizationId,
                'project_id' => $project->id,
                'sprint_duration_id' => $inputs['sprint_duration_id'],
                'sprint_status_id' => $inputs['sprint_status_id'] ?? SprintStatus::DEFAULT ,
                'start_date' => $inputs['start_date'] ?? null,
                'end_date' => $inputs['end_date'] ?? null,
            ];
            $sprintData = Sprint::firstOrCreate($sprint_data);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.sprint_store'), 200, $sprintData);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while create sprint";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get sprint detail
    public function getSprintDetail($id)
    {
        try {

            $sprint = Sprint::where('id', $id)->first();

            return $this->sendSuccessResponse(__('messages.success'), 200, $sprint);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get sprint detail";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Update sprint
    public function updateSprint(Request $request, $id)
    {
        try {
            $inputs = $request->all();
            DB::beginTransaction();

            $sprint = Sprint::where('id', $id)->first();
            $project = Project::where('uuid', $inputs['project_id'])->first('id');

            $validation = $this->sprintValidator->validateUpdate($request, $sprint,$project);
            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $sprintData = [
                'name' => $inputs['sprint_name'],
                'sprint_duration_id' => $inputs['sprint_duration_id'],
                'start_date' => $inputs['start_date'],
                'end_date' => $inputs['end_date']
            ];

            Sprint::where('id', $sprint->id)->update($sprintData);

            DB::commit();

            $data = ['id' => $id];

            return $this->sendSuccessResponse(__('messages.sprint_update'), 200, $data);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while update sprint";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Update sprint status by complete
    public function updateSprintStatus(Request $request, $id)
    {
        try {
            $inputs = $request->all();
            DB::beginTransaction();

            if (!empty($inputs['sprint_status_id']) && !empty($inputs['sprint_status_id'])) {
                $data['sprint_status_id'] = $inputs['sprint_status_id'];
            }

            if($inputs['sprint_status_id'] == SprintStatus::COMPLETE){
                $data['completed_at'] = getUtcDate('Y-m-d H:i:s');
            }

            Sprint::where('id', $id)->update($data);

            if (($inputs['sprint_status_id'] == SprintStatus::COMPLETE)) {
                $project = Project::where('uuid', $inputs['project_id'])->first('id');
                $taskStatus = TaskStatus::where('project_id', $project->id)->where('name', TaskStatus::DONE)->first(['id']);
                $sprintTasks = SprintTask::join('tasks', 'sprint_tasks.task_id', 'tasks.id')->where('tasks.project_id', $project->id)->where('sprint_tasks.sprint_id', $inputs['id'])->where('tasks.status_id', '!=', $taskStatus->id)->get(['sprint_tasks.id']);
            }

            if (!empty($inputs['move_to_sprint'])) {
                foreach ($sprintTasks as $task) {
                    SprintTask::where('id', $task->id)->update(['sprint_id' => $inputs['move_to_sprint']]);
                }
            }

            if (($inputs['sprint_status_id'] == SprintStatus::COMPLETE) && empty($inputs['move_to_sprint'])) {
                foreach ($sprintTasks as $task) {
                    SprintTask::where('id', $task->id)->where('sprint_id', $inputs['id'])->delete();
                }
            }

            DB::commit();

            $data = ['id' => $id, 'status' => $inputs['sprint_status_id']];

            return $this->sendSuccessResponse(__('messages.sprint_update'), 200, $data);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while update sprint status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function deleteSprint($sprintId)
    {
        try {
            DB::beginTransaction();

            $sprintTasks = SprintTask::where('sprint_id', $sprintId)->get();
            if (!empty($sprintTasks)) {
                foreach ($sprintTasks as $sprintTask) {
                    $sprintTask->delete();
                }
            }

            Sprint::where('id', $sprintId)->delete();

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while update sprint status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get sprint history
    public function getSprintHistory(Request $request)
    {
        try {

            $keyword = $request->keyword ?? "";
            $perPage = $request->per_page ? $request->per_page : 50;

            $project = Project::where('uuid', $request->project)->first(['id','name', 'logo_url']);
           
            $project->logo = null;
            if(!empty($project->logo_url)){
                $path = config('constant.project_logo');
                $project->logo = getFullImagePath($path . '/' . $project->logo_url);
            }
           
            $query = Sprint::withCount(['tasks'])
                ->where('sprint_status_id', SprintStatus::COMPLETE)
                ->where('project_id', $project->id);

            if(!empty($keyword)){
                $query = $query->where('name', 'LIKE', '%' . $keyword . '%');
            }
            
            $sprintHistory = $query->orderBy('completed_at', 'desc')->paginate($perPage);

            foreach($sprintHistory as $historyRecord){
                $historyRecord->completed_at = convertUTCTimeToUserTime($historyRecord->completed_at);
            }

            $response = [
                'project' => $project,
                'sprints' => $sprintHistory
            ];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while update sprint status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getSprintHoursLog($sprintId)
    {
        try {
            $organizationId = $this->getCurrentOrganizationId();

            $data = [];
            $data['sprint_hours_data'] = [];
            $data['employee_hours_data'] = [];
            $sprint = Sprint::where('id', $sprintId)
                ->select('sprints.id', 'sprints.start_date', 'sprints.project_id')->first();
          
            $taskStatus = TaskStatus::where('project_id', $sprint->project_id)->where('name', TaskStatus::DONE)->first();

            //employees wise task hours
            $query = Sprint::where('sprints.id', $sprintId)
                ->leftJoin('projects', 'sprints.project_id', 'projects.id')
                ->leftjoin('project_employees', 'projects.id', 'project_employees.project_id')
                ->leftJoin('employees', function ($join) use ($organizationId) {
                    $join->on('project_employees.employee_id', '=', 'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })
                ->select('sprints.id', 'project_employees.employee_id', 'employees.display_name');

            $query = $query->selectSub(function ($query) use ($sprintId) {
                $query->select(DB::raw("SUM(task_emp.estimated_hours)"))
                    ->leftJoin('task_employees', 'task_employees.task_id', 'task_emp.id')
                    ->leftJoin('sprint_tasks', 'task_emp.id', '=', 'sprint_tasks.task_id')
                    ->from('tasks as task_emp')
                    ->where('sprint_tasks.sprint_id', $sprintId)
                    ->whereRaw("`task_employees`.`employee_id` = `employees`.`id`")
                    ->whereNull('task_emp.deleted_at')
                    ->groupBy('project_employees.employee_id');
            }, 'emp_task_assign_hours');
            $query = $query->selectSub(function ($query) use ($sprintId, $taskStatus) {
                $query->select(DB::raw("SUM(task_emp.estimated_hours)"))
                    ->leftJoin('task_employees', 'task_employees.task_id', 'task_emp.id')
                    ->leftJoin('sprint_tasks', 'task_emp.id', '=', 'sprint_tasks.task_id')
                    ->from('tasks as task_emp')
                    ->where('sprint_tasks.sprint_id', $sprintId)
                    ->whereRaw("`task_employees`.`employee_id` = `employees`.`id`")
                    ->where('task_emp.status_id', '!=', $taskStatus->id)
                    ->whereNull('task_emp.deleted_at')
                    ->groupBy('project_employees.employee_id');
            }, 'emp_task_pending_hours');
            $query = $query->selectSub(function ($query) use ($sprintId, $taskStatus) {
                $query->select(DB::raw("SUM(task_emp.estimated_hours)"))
                    ->leftJoin('task_employees', 'task_employees.task_id', 'task_emp.id')
                    ->leftJoin('sprint_tasks', 'task_emp.id', '=', 'sprint_tasks.task_id')
                    ->from('tasks as task_emp')
                    ->where('sprint_tasks.sprint_id', $sprintId)
                    ->whereRaw("`task_employees`.`employee_id` = `employees`.`id`")
                    ->where('task_emp.status_id', $taskStatus->id)
                    ->whereNull('task_emp.deleted_at')
                    ->groupBy('project_employees.employee_id');
            }, 'emp_task_completed_hours');
            $sprintData = $query->whereNotNull('project_employees.employee_id')->havingRaw('emp_task_assign_hours IS NOT NULL')->groupBy('project_employees.employee_id')->orderBy('sprints.created_at', 'desc')->get();
            $queryArray = $sprintData->toArray();

            $empTaskAssignHours = array_column($queryArray, 'emp_task_assign_hours');
            $empTaskPendingHours = array_column($queryArray, 'emp_task_pending_hours');
            $empTaskCompletedHours = array_column($queryArray, 'emp_task_completed_hours');
            $overallData['total_task_assigned_hours'] = array_sum($empTaskAssignHours);
            $overallData['total_task_pending_hours'] = array_sum($empTaskPendingHours);
            $overallData['total_task_completed_hours'] = array_sum($empTaskCompletedHours);

            $data['sprint_hours_data'] = $overallData;
            $data['employee_hours_data'] = $sprintData;

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while update sprint status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Calculate sprint date for the end date, first build date,second build date
    public function calculateSprintDate(Request $request)
    {
        try {
            $inputs = $request->all();
            $startDate = $inputs['start_date'] ?? '';
            $duration = $inputs['sprint_duration_id'] ?? '';

            // Step:1 Get End Date
            //get end date:Add daye to start date
            $totalDay = $duration * 7 - 1;
            $endDate = Carbon::parse($startDate)->addDays($totalDay);
            $endDate = $this->getEndDate($startDate, $endDate);

            if (!empty($inputs['end_date'])) {
                $endDate = $inputs['end_date'];
            }

            $data = [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ];

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while get sprint dates";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get end date by check the holidays
    public function getEndDate($startDate, $date)
    {

        $endDate = $date->format("Y-m-d");
        $holidays = $this->getHoliday($startDate, $endDate);

        if (!in_array($endDate, $holidays)) {
            return $endDate;
        } else {
            $date = $date->subDay(1);
            $endDate = $this->getEndDate($startDate, $date);
        }
        return $endDate;
    }

    //Get holiday excludes date
    public function getHolidayExcludeDates($startDate, $endDate)
    {

        $holidays = $this->getHoliday($startDate, $endDate);
        // Get All Dates exclude Holiday List
        $interval = new \DateInterval('P1D');
        $from = new \DateTime($startDate);
        $to = new \DateTime($endDate);
        $to = $to->modify('+1 day');
        $period = new \DatePeriod($from, $interval, $to);

        $dates = [];

        // Convert the period to an array of dates
        foreach ($period as $date) {
            $currentDate = $date->format("Y-m-d");

            if (!in_array($currentDate, $holidays)) {
                array_push($dates, $currentDate);
            }
        }

        return $dates;
    }
    //Get holiday between two dates
    public function getHoliday($startDate, $endDate)
    {
        $holiday = Holiday::whereBetween('date', [$startDate, $endDate])->groupby('date')->pluck('date')->toArray();

        return $holiday;
    }


    //Get backlog list for sprint and tasks
    public function getBacklogList(Request $request)
    {
        try {

            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $keyword = !empty($inputs['keyword']) ? $inputs['keyword'] : '';
            $statusId = !empty($inputs['status_id']) ? $inputs['status_id'] : '';
            $typeId = !empty($inputs['type_id']) ? $inputs['type_id'] : '';
            $epic = !empty($inputs['epic']) ? $inputs['epic'] : '';

            if (!empty($inputs['project_id'])) {

                $project = Project::where('uuid', $inputs['project_id'])->first(['id', 'abbreviation as project_key', 'name', 'logo_url', 'uuid', 'default_access_to_all_users']);
                $projectId = $project->id;
                $projectKey = $project->project_key;
                $path = config('constant.project_logo');
                $project->logo = null;
                if(!empty($project->logo_url)){
                    $project->logo = getFullImagePath($path . '/' . $project->logo_url);
                }
            }

            $user = $request->user();
            $roles = $user->roles;
            $allRoles = collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            if (!in_array('administrator', $allRoles)) {
                $isAccess = ProjectEmployee::join('employees', function ($join) use ($organizationId) {
                        $join->on('project_employees.employee_id', '=',  'employees.id');
                        $join->where('employees.organization_id', $organizationId);
                    })
                    ->where('project_employees.project_id', $project->id)
                    ->where('project_employees.employee_id', $user->entity_id)
                    ->where('project_employees.organization_id', $organizationId)
                    ->count();

                $defaultProject = Project::where('default_access_to_all_users', 1)->where('id', $project->id)->first(['id']);

                if (empty($defaultProject) && $isAccess <= 0) {
                    return $this->sendSuccessResponse(__('messages.access_denied'), 403);
                }
            }

            $taskStatus = TaskStatus::where('project_id', $project->id)->where('name', TaskStatus::DONE)->first();

            $path = config('constant.avatar');
            $query = Sprint::where('project_id', $projectId)->where('sprint_status_id', '!=', SprintStatus::COMPLETE)->whereNULL('deleted_at');
            $sprints = $query->orderBy('sprint_status_id', 'desc')->orderBy('id', 'desc')
                ->get(['id', 'name', 'start_date', 'end_date', 'first_build', 'second_build', 'release_date', 'sprint_status_id']);
            $sprintList = [];
            foreach ($sprints as $sprint) {

                $query = Task::join('sprint_tasks', 'tasks.id', 'sprint_tasks.task_id')
                    ->leftJoin('tasks as parent_task', 'tasks.parent_id', 'parent_task.id')
                    ->leftJoin('task_types as parent_task_type', 'parent_task.type_id', 'parent_task_type.id')
                    ->leftJoin('task_types', 'tasks.type_id', 'task_types.id')
                    ->leftJoin('task_type_icons', 'task_types.icon_id', 'task_type_icons.id')
                    ->leftJoin('task_priority_types', 'tasks.priority_id', 'task_priority_types.id')
                    ->leftJoin('task_statuses', 'tasks.status_id', 'task_statuses.id')
                    ->leftJoin('task_employees', 'tasks.id', 'task_employees.task_id')
                    ->leftJoin('employees', function ($join) use ($organizationId) {
                        $join->on('task_employees.employee_id', '=', 'employees.id');
                        $join->where('employees.organization_id', $organizationId);
                    })
                    ->where('sprint_id', $sprint->id)
                    ->where('task_types.name', '!=', 'epic')
                    ->where(
                            function ($q){
                                $q->where('parent_task_type.name', 'epic');
                                $q->orWhereNULL('parent_task_type.name');
                            }
                        )
                   
                    ->where('tasks.project_id', $projectId);

                if (!empty($keyword)) {
                    $query = $query->where('tasks.title', 'like', '%' . $keyword . '%');
                }

                if (!empty($typeId)) {
                    $query = $query->where('tasks.type_id', $typeId);
                }

                if (!empty($statusId)) {
                    $query = $query->where('tasks.status_id', $statusId);
                }

                if (!empty($epic) && $epic != 'Without') {
                    $query = $query->whereIn('tasks.parent_id', $epic);
                }

                if (!empty($epic) && $epic == 'Without') {
                    $query = $query->where('tasks.parent_id', 0);
                }

                if (!empty($inputs['employee_id']) && $inputs['employee_id'] != 'all') {
                    $employee = Employee::where('uuid', $inputs['employee_id'])->first('id');
                    if (!empty($employee)) {
                        $query->where('task_employees.employee_id', $employee->id);
                    }

                }

                $tasks = $query->select('tasks.id', 'tasks.title', 'tasks.type_id', 'task_types.name as type_name', 'tasks.status_id', 'task_statuses.name as status_name', 'employees.avatar_url', 'employees.uuid', 'tasks.priority_id', 'tasks.task_abbr_id','tasks.estimated_hours', 'task_type_icons.icon_path', 'task_priority_types.icon', DB::raw('exists(select 1 from tasks t1 where t1.parent_id = tasks.id) has_child_rows'))
                    ->orderBy('tasks.order', 'asc')
                    ->orderBy('tasks.created_at', 'desc')
                    ->get();

                $sprint['start_date'] = convertUTCTimeToUserTime($sprint->start_date, 'Y-m-d');
                $sprint['end_date'] = convertUTCTimeToUserTime($sprint->end_date, 'Y-m-d');
                $sprint['first_build'] = convertUTCTimeToUserTime($sprint->first_build, 'Y-m-d');
                $sprint['second_build'] = convertUTCTimeToUserTime($sprint->second_build, 'Y-m-d');
                $sprint['release_date'] = convertUTCTimeToUserTime($sprint->release_date, 'Y-m-d');
                $sprint['total_item'] = count($tasks);
                $assignedHours = $tasks->sum('estimated_hours');
                $completedHours = $tasks->where('status_id', $taskStatus->id)->sum('estimated_hours');

                if ($assignedHours == 0) {
                    $progress = 0;
                } elseif ($completedHours == 0) {
                    $progress = 0;
                } else {
                    $progress = (($completedHours * 100) / $assignedHours);
                    if ($progress > 100) {
                        $progress = 100;
                    }
                }

                $percentage = round($progress, 2);

                foreach ($tasks as $task) {
                    $task->avatar = null;
                    if (!empty($task->avatar_url)) {
                        $task->avatar = getFullImagePath($path . '/' . $task->avatar_url);
                    }

                    if (!empty($task->icon)) {
                        $task->priority_icon = url('/image/' . $task->icon);
                    }

                    if (!empty($task->icon_path)) {
                        $path = config('constant.task_type_icons');
                        $task->type_icon = getFullImagePath($path . '/' . $task->icon_path);
                    }

                    $task->task_id = $projectKey . '-' . $task->task_abbr_id;
                }
                $sprint['progress'] = $percentage;
                $sprint['tasks'] = $tasks;

                $sprintList[] = ['id' => $sprint->id, 'name' => $sprint->name];
            }

            $backlogQuery = Task::leftJoin('sprint_tasks', 'tasks.id', 'sprint_tasks.task_id')
                ->leftJoin('tasks as parent_task', 'tasks.parent_id', 'parent_task.id')
                ->leftJoin('task_types as parent_task_type', 'parent_task.type_id', 'parent_task_type.id')
                ->leftJoin('task_types', 'tasks.type_id', 'task_types.id')
                ->leftJoin('task_type_icons', 'task_types.icon_id', 'task_type_icons.id')
                ->leftJoin('task_priority_types', 'tasks.priority_id', 'task_priority_types.id')
                ->leftJoin('task_statuses', 'tasks.status_id', 'task_statuses.id')
                ->leftJoin('task_employees', 'tasks.id', 'task_employees.task_id')
                ->leftJoin('employees', function ($join) use ($organizationId) {
                    $join->on('task_employees.employee_id', '=', 'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })
                ->whereNull('sprint_id')
                ->where('tasks.project_id', $projectId)
                ->where('task_types.name', '!=', 'epic')
                ->where(
                        function ($q){
                            $q->where('parent_task_type.name', 'epic');
                            $q->orWhere('tasks.parent_id', 0);
                        }
                    );

            if (!empty($keyword)) {
                $backlogQuery = $backlogQuery->where('tasks.title', 'like', '%' . $keyword . '%');
            }

            if (!empty($typeId)) {
                $backlogQuery = $backlogQuery->where('tasks.type_id', $typeId);
            }

            if (!empty($statusId)) {
                $backlogQuery = $backlogQuery->where('tasks.status_id', $statusId);
            }

            if (!empty($epic) && $epic != 'Without') {
                $backlogQuery = $backlogQuery->whereIn('tasks.parent_id', $epic);
            }

            if (!empty($epic) && $epic == 'Without') {
                $backlogQuery = $backlogQuery->where('tasks.parent_id', 0);
            }

            if (!empty($inputs['employee_id']) && $inputs['employee_id'] != 'all') {
                $employee = Employee::where('uuid', $inputs['employee_id'])->first('id');
                if (!empty($employee)) {
                    $backlogQuery->where('task_employees.employee_id', $employee->id);
                }
            }

            $backlogTasks = $backlogQuery->orderBy('tasks.order', 'asc')->orderBy('tasks.created_at', 'desc')
                ->select('parent_task_type.name','tasks.id', 'tasks.order', 'tasks.title', 'tasks.type_id', 'task_types.name as type_name', 'tasks.status_id', 'task_statuses.name as status_name', 'employees.avatar_url', 'employees.uuid', 'tasks.priority_id', 'tasks.task_abbr_id', 'task_type_icons.icon_path', 'task_priority_types.icon', DB::raw('exists(select 1 from tasks t1 where t1.parent_id = tasks.id) has_child_rows'))
                ->get();

            foreach ($backlogTasks as $task) {
                $task->avatar = null;
                if (!empty($task->avatar_url)) {
                    $task->avatar = getFullImagePath($path . '/' . $task->avatar_url);
                }

                if (!empty($task->icon)) {
                    $task->priority_icon = url('/image/' . $task->icon);
                }

                if (!empty($task->icon_path)) {
                    $path = config('constant.task_type_icons');
                    $task->type_icon = getFullImagePath($path . '/' . $task->icon_path);
                }

                $task->task_id = $projectKey . '-' . $task->task_abbr_id;
            }

            $taskType = TaskType::where('project_id', $projectId)->where('name', 'epic')->first('id');

            $epics = '';
            if (!empty($taskType)) {
                $epics = Task::where('type_id', $taskType->id)->orderBy('tasks.order', 'asc')->get(['id', 'title']);
            }

            if ($project->default_access_to_all_users == 1) {

                $assignedEmployees = Employee::withoutGlobalScopes([OrganizationScope::class])->active()->select('employees.id as employee_id','employees.uuid', 'employees.first_name', 'employees.last_name', 'employees.avatar_url', 'employees.display_name')->get();
            } else {
                
                $assignedEmployees = Employee::withoutGlobalScopes([OrganizationScope::class])->join('project_employees', function ($join) use ($organizationId) {
                    $join->on('project_employees.employee_id', '=', 'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })
                    ->join('users', function ($join) use ($organizationId) {
                        $join->on('users.entity_id', '=', 'employees.id');
                        $join->where('users.organization_id', $organizationId);
                    })
                    ->where('users.is_active', 1)
                    ->where('project_id', $projectId)
                    ->where('project_role_id', ProjectRole::DEVELOPERANDQA)
                    ->select('employee_id','employees.uuid', 'employees.first_name', 'employees.last_name', 'employees.avatar_url', 'employees.display_name',
                     DB::raw('(CASE 
                    WHEN employee_id = '.$user->entity_id.' THEN "0" 
                    ELSE "employee_id" 
                    END) AS employee_order'))
                    ->orderBy('employee_order', 'ASC')
                    ->get();
            }

            $data = ['sprints' => $sprints, 'backlog' => $backlogTasks, 'moveToList' => $sprintList, 'epics' => $epics, 'assignedEmployees' => $assignedEmployees, 'project' => $project];
            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while create task";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get reason for link task
    public function getReasonForLinkTasks()
    {
        $data = ReasonForLinkTask::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    //Move task from one sprint to other or backlog
    public function moveTask(Request $request)
    {

        try {
            DB::beginTransaction();

            $inputs = $request->all();
            $sprint = !empty($inputs['sprint']) ? $inputs['sprint'] : '';
            $backlog = !empty($inputs['backlog']) ? $inputs['backlog'] : '';
            $taskId = $inputs['task'];
            $sprintTask = SprintTask::where('task_id', $taskId)->first();
            $organizationId = $this->getCurrentOrganizationId();

            //ActivityLog
            $activitylog['table_name'] = 'sprints';
            $activitylog['action'] = 'updated sprint';
            $activitylog['old_data'] = !empty($sprintTask) ? json_encode(array('name' => $sprintTask->sprint_id)) : NULL;
            $activitylog['new_data'] = json_encode(array('name' => $sprint));
            //end

            if (!empty($sprint)) {

                $sprintTaskData = [
                    'task_id' => $taskId,
                    'sprint_id' => $sprint,
                ];

                if ($sprintTask) {
                    SprintTask::where('id',$sprintTask->id)->update($sprintTaskData);
                } else {
                    SprintTask::create($sprintTaskData);
                }
            } else if (!empty($backlog)) {

                $sprintTask->delete();
            }

            if (!empty($activitylog['action'])) {
                $activitylog['organization_id'] = $organizationId;
                ActivityLog::create($activitylog);
            }
            DB::commit();
            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while move task";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    // Delete attachment from task
    public function deleteAttachment($attachmentId)
    {

        try {
            DB::beginTransaction();

            TaskAttachment::Where('id', $attachmentId)->delete();

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while delete task attachment";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Add comment in task
    public function addCommentInTask(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();

            $validation = $this->taskCommentValidator->validate($request);
            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $user = $request->user();

            $taskId = $inputs['task_id'];
            $comment = !empty($inputs['comment']) ? $inputs['comment'] : '';
            $organizationId = $this->getCurrentOrganizationId();

            $data = [
                'task_id' => $taskId,
                'comment' => $comment,
                'employee_id' => $user->entity_id,
                'organization_id' => $organizationId
            ];

            $taskComment = TaskComment::create($data);
            $taskCommentEmployee = $taskComment->getAuthorData();
            if (!empty($taskCommentEmployee)) {
                $taskComment->employee = $taskCommentEmployee;
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200, $taskComment);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while add task comment";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Delete comment
    public function deleteComment($commentId)
    {
        try {
            DB::beginTransaction();

            TaskComment::where('id', $commentId)->delete();

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while delete task comment";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Update comment
    public function updateComment(Request $request, $commentId)
    {
        try {
            $inputs = $request->all();
            DB::beginTransaction();

            $validation = $this->taskCommentValidator->validate($request);
            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $data = [
                'comment' => $inputs['comment']
            ];

            TaskComment::where('id', $commentId)->update($data);

            DB::commit();

            $data = ['id' => $commentId];

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while update comment";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get list of all comments and history
    public function getAllHistoyComments(Request $request)
    {
        try {
            $history = new ActivityLogHistoryController();
            $history = $history->getActivityList($request);
            $history = $history->original['result']['logs'];

            $taskId = $request->module_id;

            $comments = TaskComment::where('task_id', $taskId)->get(['id', 'employee_id', 'organization_id', 'task_id', 'comment', 'created_at']);

            foreach ($comments as $comment) {
                $comment->is_comment = true;
                $comment->employee = $comment->getAuthorData();
            }

            $merged = $history->merge($comments);
            $sorted = $merged->sortByDesc('created_at');
            $allData = $sorted->values();

            return $this->sendSuccessResponse(__('messages.success'), 200, $allData);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get all the comments and activity history";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get list of subtasks
    public function getSubTaskList(Request $request)
    {
        try {

            $search = !empty($request->search) ? $request->search : '';
            $perPage = !empty($request->per_page) ? $request->per_page : 10;
            $project_id = !empty($request->project_id) ? $request->project_id : null;

            $project = Project::where('uuid', $project_id)->first(['id', 'name', 'abbreviation as project_key']);

            $query = Task::where('parent_id', '!=', 0);

            if (!empty($search)) {
                $query = $query->where('title', 'LIKE', '%' . $search . '%');
            }

            $query = $query->where('project_id', $project->id)->orderBy('created_at', 'desc');

            $subTasks = $query->select('id', 'title')->paginate($perPage);

            return $this->sendSuccessResponse(__('messages.success'), 200, $subTasks);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get subtasks";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Update order of the task
    public function changeOrderOfTasks(Request $request)
    {

        try {
            $inputs = $request->all();
            DB::beginTransaction();

            $beforeTask = $inputs['beforeTask'];
            $afterTask = $inputs['afterTask'];

            if ($beforeTask != "") {
                $beforeTaskOrder = Task::where('id', $beforeTask)->first(['order', 'project_id']);
                $newOrder = $beforeTaskOrder->order;

                $tasks = Task::where('order', '>=', $newOrder)->where('project_id', $beforeTaskOrder->project_id)->get(['id', 'order']);

                foreach ($tasks as $task) {
                    $order = $task->order + 1;
                    Task::where('id', $task->id)->update(['order' => $order]);
                }
            } else {
                $afterTaskOrder = Task::where('id', $afterTask)->first(['project_id']);
                $maxOrder = Task::where('project_id', $afterTaskOrder->project_id)->max('order');
                $newOrder = $maxOrder + 1;
            }


            Task::where('id', $afterTask)->update(['order' => $newOrder]);

            $sprint = !empty($inputs['sprint']) ? $inputs['sprint'] : '';
            $backlog = !empty($inputs['backlog']) ? $inputs['backlog'] : '';

            if (!empty($sprint) || !empty($backlog)) {
                $taskId = $afterTask;
                $sprintTask = SprintTask::where('task_id', $taskId)->first();
                $organizationId = $this->getCurrentOrganizationId();

                //ActivityLog
                $activitylog['table_name'] = 'sprints';
                $activitylog['action'] = 'updated sprint';
                $activitylog['old_data'] = !empty($sprintTask) ? json_encode(array('name' => $sprintTask->sprint_id)) : NULL;
                $activitylog['new_data'] = json_encode(array('name' => $sprint));
                //end

                if (!empty($sprint)) {

                    $sprintTaskData = [
                        'task_id' => $taskId,
                        'sprint_id' => $sprint,
                    ];

                    if ($sprintTask) {
                        SprintTask::where('id',$sprintTask->id)->update($sprintTaskData);
                    } else {
                        SprintTask::create($sprintTaskData);
                    }
                } else if (!empty($backlog)) {

                    $sprintTask->delete();
                }

                if (!empty($activitylog['action'])) {
                    $activitylog['organization_id'] = $organizationId;
                    ActivityLog::create($activitylog);
                }
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while update comment";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get sprint history tasks list
    public function getSprintHistoryTaskList(Request $request)
    {
        try {

            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $keyword = !empty($inputs['keyword']) ? $inputs['keyword'] : '';
            $sprintId = !empty($inputs['sprint']) ? $inputs['sprint'] : '';
            $perPage = $request->perPage ??  '';
    
            if (!empty($inputs['project'])) {

                $project = Project::where('uuid', $inputs['project'])->first(['id','abbreviation as project_key']);
                $projectId = $project->id;
                $projectKey = $project->project_key;
            }

            $sprint = Sprint::where('id', $sprintId)->first('name');

            $query = Task::join('sprint_tasks', 'tasks.id', 'sprint_tasks.task_id')
                ->leftJoin('tasks as parent_task', 'tasks.parent_id', 'parent_task.id')
                ->leftJoin('task_types as parent_task_type', 'parent_task.type_id', 'parent_task_type.id')
                ->leftJoin('task_types', 'tasks.type_id', 'task_types.id')
                ->leftJoin('task_type_icons', 'task_types.icon_id', 'task_type_icons.id')
                ->leftJoin('task_priority_types', 'tasks.priority_id', 'task_priority_types.id')
                ->leftJoin('task_statuses', 'tasks.status_id', 'task_statuses.id')
                ->leftJoin('task_employees', 'tasks.id', 'task_employees.task_id')
                ->leftJoin('employees', function ($join) use ($organizationId) {
                    $join->on('task_employees.employee_id', '=', 'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })
                ->where('sprint_id', $sprintId)
                ->where('task_types.name', '!=', 'epic')
                ->where(
                        function ($q){
                            $q->where('parent_task_type.name', 'epic');
                            $q->orWhereNULL('parent_task_type.name');
                        }
                    )
                
                ->where('tasks.project_id', $projectId);

            if (!empty($keyword)) {
                $query = $query->where('tasks.title', 'like', '%' . $keyword . '%');
            }

            $tasks = $query->select('tasks.id', 'tasks.title', 'task_types.name as type_name', 'task_statuses.name as status_name', 'employees.display_name','employees.avatar_url', 'tasks.priority_id', 'tasks.task_abbr_id', 'task_type_icons.icon_path','task_priority_types.name','task_priority_types.icon', DB::raw('exists(select 1 from tasks t1 where t1.parent_id = tasks.id) has_child_rows'))
                ->orderBy('tasks.order', 'asc')
                ->orderBy('tasks.created_at', 'desc')
                ->simplePaginate($perPage);

            foreach ($tasks as $task) {
                $task->avatar = null;
                $path = config('constant.avatar');
                if (!empty($task->avatar_url)) {
                    $task->avatar = getFullImagePath($path . '/' . $task->avatar_url);
                }

                if (!empty($task->icon)) {
                    $task->priority_icon = url('/image/' . $task->icon);
                }

                if (!empty($task->icon_path)) {
                    $path = config('constant.task_type_icons');
                    $task->type_icon = getFullImagePath($path . '/' . $task->icon_path);
                }

                $task->task_id = $projectKey . '-' . $task->task_abbr_id;
            }

            $data = ['sprint_name' => $sprint->name, 'tasks' => $tasks];

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while create task";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getPMSOldData(Request $request){

        DB::beginTransaction();
        try {

            $organizationId = $request->organization_id;

            $types = [
                '1' => 'task',
                '2' => 'epic',
                '3' => 'story',
                '4' => 'bug'
            ];

            $statuses = [
                '1' => 'to do',
                '2' => 'in progress',
                '3' => 'completed',
            ];
            $existTask = [];
            $projectTaskAbbr = [];
            $sprints = DB::connection('old_connection')->table('sprints')->get();

            foreach ($sprints as $sprint) {

                $project = DB::connection('old_connection')->table('projects')->where('id', $sprint->project_id)->first(['name']);

                if (!empty($project)) {
                    $project = Project::where('name', 'LIKE', $project->name)->where('organization_id', $organizationId)->withTrashed()->first(['id']);
                }

                $sprint_data = [
                    'name' => $sprint->name,
                    'organization_id' => $organizationId,
                    'project_id' => $project->id,
                    'sprint_duration_id' => $sprint->sprint_durations_id,
                    'sprint_status_id' => $sprint->sprint_statuses_id  ,
                    'start_date' => $sprint->start_date,
                    'end_date' => $sprint->end_date,
                    'completed_at' => $sprint->completed_date
                ];

                $sprintData = Sprint::firstOrCreate($sprint_data);

                $sprintTasks = DB::connection('old_connection')->table('sprint_tasks')->where('sprint_id',$sprint->id)->get();

                foreach ($sprintTasks as $sprintTask) {

                            $task = DB::connection('old_connection')->table('tasks')->where('id', $sprintTask->task_id)->first();
                    
                            $typeId = TaskType::where('task_types.project_id', $project->id)->where('task_types.name', $types[$task->type_id])->select('task_types.id')->first();

                            $taskStatus = TaskStatus::where('project_id', $project->id)->where('task_statuses.name', $statuses[$task->status_id])->first();

                            $taskCurrent = Task::where('project_id', $project->id)->orderBy('created_at', 'desc')->first();
                            $taskAbbrId = !empty($taskCurrent) ? $taskCurrent->task_abbr_id : 0;
                            $taskOrder = !empty($taskCurrent) ? $taskCurrent->order : 0;

                            if(!empty( $projectTaskAbbr[$project->id])){
                                $projectTaskAbbr[$project->id] = $projectTaskAbbr[$project->id] + 1;
                            }else{
                                $projectTaskAbbr[$project->id] = $taskAbbrId + 1;
                            }

                            $employee = DB::connection('old_connection')->table('employees')->where('id', $task->created_by)->first(['employee_id']);
                            $employeeId = 1;
                            if(!empty($employee)){
                                $employeeId = $employee->employee_id;
                            }

                            $taskData = [
                                'title' => $task->name,
                                'type_id' => $typeId->id,
                                'parent_id' => 0,
                                'status_id' => $taskStatus->id,
                                'project_id' => $project->id,
                                'priority_id' => $task->priority_id,
                                'description' => $task->description,
                                'created_by' => $employeeId??1,
                                'updated_by' => 2,
                                'organization_id' => $organizationId,
                                'task_abbr_id' => $projectTaskAbbr[$project->id],
                                'estimated_hours' => $task->estimated_hours,
                                'order' =>  $taskOrder + 1,
                                'deleted_at' => $task->deleted_at
                            ];

                          
                            if(!empty($existTask[$sprintTask->task_id])){
                                $task = new Task();
                                $task->id = $existTask[$sprintTask->task_id];

                                if (!empty($sprintData->id)) {
                                    $data = [
                                        'task_id' => $task->id,
                                        'sprint_id' => $sprintData->id
                                    ];
                                    SprintTask::firstOrCreate($data);
                                }

                                continue;
                            }else{
                              
                                $task = Task::firstOrCreate($taskData);    
                                $existTask[$sprintTask->task_id] = $task->id;                                
                            }

                            if (!empty($sprintData->id)) {
                                $data = [
                                    'task_id' => $task->id,
                                    'sprint_id' => $sprintData->id
                                ];
                                SprintTask::firstOrCreate($data);
                            }


                            $taskAttachments = DB::connection('old_connection')->table('task_attachments')->where('task_id', $sprintTask->task_id)->get();


                            if (!empty($taskAttachments)) {

                                $attachments = $taskAttachments;

                                $path = config('constant.task_attachments');

                                foreach ($attachments as $attachment) {

                                   $path = explode('/',$attachment->attachment);
                                   $attachmentPath = end($path);

                                  // $ext = explode('.',$attachmentPath);
                                   $mimeType = $this->mime_type($attachmentPath);
                                    if (!empty($attachmentPath)) {
                                        $attachmentData = [
                                            'task_id' => $task->id,
                                            'attachment_path' => $attachmentPath,
                                            'mime_type' => $mimeType,
                                            'file_name' => $attachment->name
                                        ];

                                        TaskAttachment::create($attachmentData);
                                    }

                                }
                            }

                            $taskEmployee = DB::connection('old_connection')->table('task_employee')->where('task_id', $sprintTask->task_id)->first();

                            if (!empty($taskEmployee)) {
                                $employee = DB::connection('old_connection')->table('employees')->where('id', $taskEmployee->employee_id)->first(['employee_id']);
                                $employeeId = $employee->employee_id;

                                $employeeData = [
                                    'task_id' => $task->id,
                                    'employee_id' => $employeeId,
                                    'organization_id' => $organizationId
                                ];

                                $newTaskEmployee = TaskEmployee::where('task_id', $task->id)->first();
                                
                                if ($newTaskEmployee) {
                            
                                    TaskEmployee::where('id',$newTaskEmployee->id)->update($employeeData);
                                } else {
                                    TaskEmployee::create($employeeData);
                                }
                            }

                            $taskComments = DB::connection('old_connection')->table('task_comments')->where('task_id', $sprintTask->task_id)->get();

                            foreach($taskComments as $comment){
                                $employee = DB::connection('old_connection')->table('employees')->where('id', $comment->employee_id)->first();
                                $employeeId = $employee->employee_id ?? 1;

                                $commentData = [
                                    'task_id' => $task->id,
                                    'comment' => $comment->comment,
                                    'employee_id' => $employeeId,
                                    'organization_id' => $organizationId,
                                    'created_at' => $comment->created_at
                                ];

                                TaskComment::create($commentData);
                            }

                            $activityLogs = DB::connection('old_connection')->table('activity_logs')->where('module_id', $sprintTask->task_id)->get();

                            foreach ($activityLogs as $log) {
                                if (!empty($log->action)) {

                                    $user = DB::connection('old_connection')->table('users')->where('id', $log->updated_by)->first(['email']);
                                    if(!empty($user->email)){
                                        $user = User::where('email', $user->email)->where('organization_id',$organizationId)->first(['id']);
                                        $updatedBy = $user->id ?? 2;
                                    }

                                    if(!empty($log->new_data) && $log->table_name == 'employees'){
                                        $newData = json_decode($log->new_data, true);
                                        $employee = $newData['display_name'];

                                        $employee = DB::connection('old_connection')->table('employees')->where('id', $employee)->first();
                                        $employeeId = $employee->employee_id ?? 2;
                                        $newData['display_name'] = $employeeId;
                                        $log->new_data = json_encode($newData);

                                    }

                                    if(!empty($log->new_data) && $log->table_name == 'task_statuses'){
                                        $newData = json_decode($log->new_data, true);
                                        $status = $newData['name'];
        
                                        $newData['name'] = $status == 3 ? 5 : $status;
                                        $log->new_data = json_encode($newData);
                                    }
        
                                    if(!empty($log->old_data) && $log->table_name == 'task_statuses'){
                                        $oldData = json_decode($log->old_data, true);
                                        $status = $oldData['name'];
        
                                        $oldData['name'] = $status == 3 ? 5 : $status;
                                        $log->old_data = json_encode($oldData);
                                    }

                                    $activitylog['module_id'] = $task->id;
                                    $activitylog['module_name'] = $log->module_name;
                                    $activitylog['table_name'] = $log->table_name;
                                    $activitylog['action'] = $log->action;
                                    $activitylog['old_data'] = $log->old_data;
                                    $activitylog['new_data'] = $log->new_data;
                                    $activitylog['organization_id'] = $organizationId;
                                    $activitylog['created_at'] = $log->created_at;
                                    $activitylog['updated_by'] = $updatedBy;
            

                                    ActivityLog::create($activitylog);
                                }
                            }
                    }
                }

                $taskExceptSprint =DB::connection('old_connection')->table('tasks')->leftJoin('sprint_tasks','tasks.id','sprint_tasks.task_id')->whereNull('sprint_tasks.sprint_id')->select('tasks.id','tasks.name','tasks.project_id','tasks.type_id','tasks.status_id','tasks.priority_id','tasks.description','tasks.estimated_hours','tasks.deleted_at')->get();
               
                foreach($taskExceptSprint as $oldTask){

                    $task = DB::connection('old_connection')->table('tasks')->where('id', $oldTask->id)->first();

                    $project = DB::connection('old_connection')->table('projects')->where('id', $oldTask->project_id)->first(['name']);

                    if (!empty($project)) {
                        $project = Project::where('name', 'LIKE', $project->name)->where('organization_id', $organizationId)->withTrashed()->first(['id']);
                    }
                    
                    $typeId = TaskType::where('task_types.project_id', $project->id)->where('task_types.name', $types[$oldTask->type_id])->select('task_types.id')->first();

                    $taskStatus = TaskStatus::where('project_id', $project->id)->where('task_statuses.name', $statuses[$oldTask->status_id])->first();

                    $taskCurrent = Task::where('project_id', $project->id)->orderBy('created_at', 'desc')->first();
                    $taskAbbrId = !empty($taskCurrent) ? $taskCurrent->task_abbr_id : 0;
                
                    if(!empty( $projectTaskAbbr[$project->id])){
                        $projectTaskAbbr[$project->id] = $projectTaskAbbr[$project->id] + 1;
                    }else{
                        $projectTaskAbbr[$project->id] = $taskAbbrId + 1;
                    }

                    $taskOrder = !empty($taskCurrent) ? $taskCurrent->order : 0;

                    $employee = DB::connection('old_connection')->table('employees')->where('id', $task->created_by)->first(['employee_id']);
                    $employeeId = 1;
                    if(!empty($employee)){
                        $employeeId = $employee->employee_id;
                    }

                    $taskData = [
                        'title' => $task->name,
                        'type_id' => $typeId->id,
                        'parent_id' => 0,
                        'status_id' => $taskStatus->id,
                        'project_id' => $project->id,
                        'priority_id' => $task->priority_id,
                        'description' => $task->description,
                        'created_by' => $employeeId??1,
                        'updated_by' => 2,
                        'organization_id' => $organizationId,
                        'task_abbr_id' =>  $projectTaskAbbr[$project->id],
                        'estimated_hours' => $task->estimated_hours,
                        'order' => $taskOrder + 1,
                        'deleted_at' => $task->deleted_at
                    ];

                    if(!empty($existTask[$oldTask->id])){
                        $task = new Task();
                        $task->id = $existTask[$oldTask->id];
                        continue;
                    }else{
                        $task = Task::firstOrCreate($taskData);    
                        $existTask[$oldTask->id] = $task->id;
                    }

                    $taskAttachments = DB::connection('old_connection')->table('task_attachments')->where('task_id', $oldTask->id)->get();


                    if (!empty($taskAttachments)) {

                        $attachments = $taskAttachments;

                        $path = config('constant.task_attachments');

                        foreach ($attachments as $attachment) {

                           $path = explode('/',$attachment->attachment);
                           $attachmentPath = end($path);

                   
                           $mimeType = $this->mime_type($attachmentPath);

                            if (!empty($attachmentPath)) {
                                $attachmentData = [
                                    'task_id' => $task->id,
                                    'attachment_path' => $attachmentPath,
                                    'mime_type' => $mimeType,
                                    'file_name' => $attachment->name
                                ];

                                TaskAttachment::create($attachmentData);
                            }

                        }
                    }

                    $taskEmployee = DB::connection('old_connection')->table('task_employee')->where('task_id', $oldTask->id)->first();

                    if (!empty($taskEmployee)) {
                        $employee = DB::connection('old_connection')->table('employees')->where('id', $taskEmployee->employee_id)->first(['employee_id']);
                        $employeeId = $employee->employee_id;

                        $employeeData = [
                            'task_id' => $task->id,
                            'employee_id' => $employeeId,
                            'organization_id' => $organizationId
                        ];

                        $newTaskEmployee = TaskEmployee::where('task_id', $task->id)->first();
                        
                        if ($newTaskEmployee) {
                    
                            TaskEmployee::where('id',$newTaskEmployee->id)->update($employeeData);
                        } else {
                            TaskEmployee::create($employeeData);
                        }
                    }

                    $taskComments = DB::connection('old_connection')->table('task_comments')->where('task_id', $oldTask->id)->get();

                    foreach($taskComments as $comment){
                        $employee = DB::connection('old_connection')->table('employees')->where('id', $comment->employee_id)->first();
                        $employeeId = $employee->employee_id ?? 1;

                        $commentData = [
                            'task_id' => $task->id,
                            'comment' => $comment->comment,
                            'employee_id' => $employeeId,
                            'organization_id' => $organizationId,
                            'created_at' => $comment->created_at
                        ];

                        TaskComment::create($commentData);
                    }

                    $activityLogs = DB::connection('old_connection')->table('activity_logs')->where('module_id', $oldTask->id)->get();

                    foreach ($activityLogs as $log) {
                        if (!empty($log->action)) {

                            $user = DB::connection('old_connection')->table('users')->where('id', $log->updated_by)->first(['email']);
                            if(!empty($user->email)){
                                $user = User::where('email', $user->email)->where('organization_id',$organizationId)->first(['id']);
                                $updatedBy = $user->id ?? 2;
                            }

                            if(!empty($log->new_data) && $log->table_name == 'employees'){
                                $newData = json_decode($log->new_data, true);
                                $employee = $newData['display_name'];

                                $employee = DB::connection('old_connection')->table('employees')->where('id', $employee)->first();
                                $employeeId = $employee->employee_id ?? 1;
                                $newData['display_name'] = $employeeId;
                                $log->new_data = json_encode($newData);

                            }

                            if(!empty($log->new_data) && $log->table_name == 'task_statuses'){
                                $newData = json_decode($log->new_data, true);
                                $status = $newData['name'];

                                $newData['name'] = $status == 3 ? 5 : $status;
                                $log->new_data = json_encode($newData);
                            }

                            if(!empty($log->old_data) && $log->table_name == 'task_statuses'){
                                $oldData = json_decode($log->old_data, true);
                                $status = $oldData['name'];

                                $oldData['name'] = $status == 3 ? 5 : $status;
                                $log->old_data = json_encode($oldData);
                            }

                            $activitylog['module_id'] =  $task->id;
                            $activitylog['module_name'] = $log->module_name;
                            $activitylog['table_name'] = $log->table_name;
                            $activitylog['action'] = $log->action;
                            $activitylog['old_data'] = $log->old_data;
                            $activitylog['new_data'] = $log->new_data;
                            $activitylog['organization_id'] = $organizationId;
                            $activitylog['created_at'] = $log->created_at;
                            $activitylog['updated_by'] = $updatedBy;
    

                            ActivityLog::create($activitylog);
                        }
                    }
                }

                $userTimesheets = DB::connection('old_connection')->table('user_timesheets')->whereNotNull('task_id')->get();
                foreach($userTimesheets as $timesheet){
                    if(!empty($existTask[$timesheet->task_id])){

                        $taskId = $existTask[$timesheet->task_id];

                        $employee = DB::connection('old_connection')->table('employees')->where('id', $timesheet->employee_id)->first(['employee_id']);
                        $employeeId = $employee->employee_id;

                        $project = DB::connection('old_connection')->table('projects')->where('id', $timesheet->project_id)->first(['name']);
                        // print_r($project->name);
                        // echo '<br/>';
                        if (!empty($project)) {
                            $project = Project::where('name', 'LIKE', $project->name)->where('organization_id',$organizationId)->withTrashed()->first(['id']);
                        }
                    
                        $userTimesheet = UserTimesheet::where(['project_id' =>  $project->id , 'employee_id' =>  $employeeId, 'working_hours' => $timesheet->working_hours,'billing_hours' => $timesheet->billing_hours,'note' => $timesheet->note, 'organization_id' => $organizationId])->first();
                    
                        if (!empty($userTimesheet)) {
                            UserTimesheet::where('id', $userTimesheet->id)->update(['task_id' => $taskId]);
                        }
                    }
                } 
            
            DB::commit();
            return $this->sendSuccessResponse(__('messages.project_task_imported'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while project imported";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }

    }
    public function createTaskStatus(Request $request)
    {
        try {
            DB::beginTransaction();
            $validation = $this->taskStatusValidator->validate($request);
            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }
            $project = Project::where('uuid',$request->project_id)->first('id');

            $task = TaskStatus::where('project_id', $project->id)->orderBy('order', 'desc')->first('order');
            $taskOrder = !empty($task) ? $task->order : 0;

            $taskstatus = TaskStatus::insert([
                'name' => $request->name,
                'project_id' => $project->id,
                'order' => $taskOrder + 1
            ]);

            DB::commit();
            return $this->sendSuccessResponse(__('messages.task_status_store'), 200, $taskstatus);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add task status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
       
    }
    public function getTaskStatus(Request $request)
    {
        try {
            $project_id = $request->project_id;
            $data = TaskStatus::where('project_id', $project_id)->orderBy('order', 'asc')->get();
            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get subtasks";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }

    }
    public function updateTaskStatus(Request $request,$id)
    {
        try {
            DB::beginTransaction();
            $project = Project::where('uuid', $request->project_id)->first(['id', 'organization_id']);
            $organizationId =  $this->getCurrentOrganizationId();
            $validation = $this->taskStatusValidator->validate($request);
            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }
            if($organizationId == $project->organization_id){
                $data = TaskStatus::where('id', $id)->update(['name' => $request->name]);
            }
            DB::commit();
            return $this->sendSuccessResponse(__('messages.task_status_update'), 200, $data);
        }  catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update task status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    public function deleteTaskStatus($id)
    {
        try {
            DB::beginTransaction();
            $project = TaskStatus::where('id', $id)->first('project_id');
            $organization = Project::where('id', $project->project_id)->first('organization_id');
            $organizationId =  $this->getCurrentOrganizationId();
        
            if($organizationId == $organization->organization_id){
               TaskStatus::where('id', $id)->delete();
            }
            DB::commit();

            return $this->sendSuccessResponse(__('messages.task_status_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete task status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    public function updateTaskStatusOrder(Request $request)
    {
        try {
            $inputs = $request->all();
            DB::beginTransaction();

            $beforeTask = $inputs['beforeTaskStatus'];
            $afterTask = $inputs['afterTaskStatus'];

            if ($beforeTask != "") {

                $beforeTaskOrder = TaskStatus::where('id', $beforeTask)->first(['order', 'project_id']);
                $newOrder = $beforeTaskOrder->order;

                $taskstatus = TaskStatus::where('order', '>=', $newOrder)->where('project_id', $beforeTaskOrder->project_id)->get(['id', 'order']);

                foreach ($taskstatus as $task) {
                    $order = $task->order + 1;
                    TaskStatus::where('id', $task->id)->update(['order' => $order]);
                }
            } else {
                $afterTaskOrder = TaskStatus::where('id', $afterTask)->first(['project_id']);
                $maxOrder = TaskStatus::where('project_id', $afterTaskOrder->project_id)->max('order');
                $newOrder = $maxOrder + 1;
            }

            TaskStatus::where('id', $afterTask)->update(['order' => $newOrder]);
            DB::commit();
            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete task status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }


    function mime_type($filename) {

        $mime_types = array(
           'txt' => 'text/plain',
           'htm' => 'text/html',
           'html' => 'text/html',
           'css' => 'text/css',
           'json' => array('application/json', 'text/json'),
           'xml' => 'application/xml',
           'swf' => 'application/x-shockwave-flash',
           'flv' => 'video/x-flv',
      
           'hqx' => 'application/mac-binhex40',
           'cpt' => 'application/mac-compactpro',
           'csv' => array('text/x-comma-separated-values', 'text/comma-separated-values', 'application/octet-stream', 'application/vnd.ms-excel', 'application/x-csv', 'text/x-csv', 'text/csv', 'application/csv', 'application/excel', 'application/vnd.msexcel'),
           'bin' => 'application/macbinary',
           'dms' => 'application/octet-stream',
           'lha' => 'application/octet-stream',
           'lzh' => 'application/octet-stream',
           'exe' => array('application/octet-stream', 'application/x-msdownload'),
           'class' => 'application/octet-stream',
           'so' => 'application/octet-stream',
           'sea' => 'application/octet-stream',
           'dll' => 'application/octet-stream',
           'oda' => 'application/oda',
           'ps' => 'application/postscript',
           'smi' => 'application/smil',
           'smil' => 'application/smil',
           'mif' => 'application/vnd.mif',
           'wbxml' => 'application/wbxml',
           'wmlc' => 'application/wmlc',
           'dcr' => 'application/x-director',
           'dir' => 'application/x-director',
           'dxr' => 'application/x-director',
           'dvi' => 'application/x-dvi',
           'gtar' => 'application/x-gtar',
           'gz' => 'application/x-gzip',
           'php' => 'application/x-httpd-php',
           'php4' => 'application/x-httpd-php',
           'php3' => 'application/x-httpd-php',
           'phtml' => 'application/x-httpd-php',
           'phps' => 'application/x-httpd-php-source',
           'js' => array('application/javascript', 'application/x-javascript'),
           'sit' => 'application/x-stuffit',
           'tar' => 'application/x-tar',
           'tgz' => array('application/x-tar', 'application/x-gzip-compressed'),
           'xhtml' => 'application/xhtml+xml',
           'xht' => 'application/xhtml+xml',             
           'bmp' => array('image/bmp', 'image/x-windows-bmp'),
           'gif' => 'image/gif',
           'jpeg' => array('image/jpeg', 'image/pjpeg'),
           'jpg' => array('image/jpeg', 'image/pjpeg'),
           'jpe' => array('image/jpeg', 'image/pjpeg'),
           'png' => array('image/png', 'image/x-png'),
           'tiff' => 'image/tiff',
           'tif' => 'image/tiff',
           'shtml' => 'text/html',
           'text' => 'text/plain',
           'log' => array('text/plain', 'text/x-log'),
           'rtx' => 'text/richtext',
           'rtf' => 'text/rtf',
           'xsl' => 'text/xml',
           'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
           'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
           'word' => array('application/msword', 'application/octet-stream'),
           'xl' => 'application/excel',
           'eml' => 'message/rfc822',
      
           // images
           'png' => 'image/png',
           'jpe' => 'image/jpeg',
           'jpeg' => 'image/jpeg',
           'jpg' => 'image/jpeg',
           'gif' => 'image/gif',
           'bmp' => 'image/bmp',
           'ico' => 'image/vnd.microsoft.icon',
           'tiff' => 'image/tiff',
           'tif' => 'image/tiff',
           'svg' => 'image/svg+xml',
           'svgz' => 'image/svg+xml',
      
           // archives
           'zip' => array('application/x-zip', 'application/zip', 'application/x-zip-compressed'),
           'rar' => 'application/x-rar-compressed',
           'msi' => 'application/x-msdownload',
           'cab' => 'application/vnd.ms-cab-compressed',
      
           // audio/video
           'mid' => 'audio/midi',
           'midi' => 'audio/midi',
           'mpga' => 'audio/mpeg',
          'mp2' => 'audio/mpeg',
           'mp3' => array('audio/mpeg', 'audio/mpg', 'audio/mpeg3', 'audio/mp3'),
           'aif' => 'audio/x-aiff',
           'aiff' => 'audio/x-aiff',
           'aifc' => 'audio/x-aiff',
           'ram' => 'audio/x-pn-realaudio',
           'rm' => 'audio/x-pn-realaudio',
           'rpm' => 'audio/x-pn-realaudio-plugin',
           'ra' => 'audio/x-realaudio',
           'rv' => 'video/vnd.rn-realvideo',
           'wav' => array('audio/x-wav', 'audio/wave', 'audio/wav'),
           'mpeg' => 'video/mpeg',
           'mpg' => 'video/mpeg',
           'mpe' => 'video/mpeg',
           'qt' => 'video/quicktime',
           'mov' => 'video/quicktime',
           'avi' => 'video/x-msvideo',
           'movie' => 'video/x-sgi-movie',
      
           // adobe
           'pdf' => 'application/pdf',
           'psd' => array('image/vnd.adobe.photoshop', 'application/x-photoshop'),
           'ai' => 'application/postscript',
           'eps' => 'application/postscript',
           'ps' => 'application/postscript',
      
           // ms office
           'doc' => 'application/msword',
           'rtf' => 'application/rtf',
           'xls' => array('application/excel', 'application/vnd.ms-excel', 'application/msexcel'),
           'ppt' => array('application/powerpoint', 'application/vnd.ms-powerpoint'),
      
           // open office
           'odt' => 'application/vnd.oasis.opendocument.text',
           'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        );
      
        $ext = explode('.', $filename);
        $ext = strtolower(end($ext));
       
        if (array_key_exists($ext, $mime_types)) {
          return (is_array($mime_types[$ext])) ? $mime_types[$ext][0] : $mime_types[$ext];
        } else if (function_exists('finfo_open')) {
           if(file_exists($filename)) {
             $finfo = finfo_open(FILEINFO_MIME);
             $mimetype = finfo_file($finfo, $filename);
             finfo_close($finfo);
             return $mimetype;
           }
        }
       
        return 'application/octet-stream';
      }

}