<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\NewProjectCreate;
use App\Mail\ProjectAssignToUser;
use App\Models\Customer;
use App\Models\DefaultTaskStatus;
use App\Models\DefaultTaskType;
use App\Models\DefaultTaskTypeIcon;
use App\Models\Department;
use App\Models\EmailNotification;
use App\Models\EntityType;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\ProjectEmployee;
use App\Models\ProjectEstimation;
use App\Models\ProjectRole;
use App\Models\ProjectSkill;
use App\Models\ProjectStatus;
use App\Models\Scopes\OrganizationScope;
use App\Models\Skill;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\TaskTypeIcon;
use App\Models\User;
use App\Traits\ResponseTrait;
use App\Traits\UploadFileTrait;
use App\Validators\ProjectValidator;
use Auth, DB;
use Illuminate\Http\Request;
use Storage;
use ErlandMuchasaj\Sanitize\Sanitize;

class ProjectController extends Controller
{
    use ResponseTrait, UploadFileTrait;
    private $projectValidator;

    function __construct()
    {
        $this->projectValidator = new ProjectValidator();
    }

     //Get all projects
     public function index(Request $request)
     {
        $user = $request->user();
        $organizationId = $this->getCurrentOrganizationId();
        $roles = $user->roles;
        $allRoles = collect($roles)->map(function ($value) {
            return $value->slug;
        })->toArray();

        $data = [];
        if (in_array('administrator', $allRoles)) {
            $data = Project::select('id', 'name')->orderBy('id')->select('projects.name', 'projects.id', 'projects.uuid', 'projects.logo_url')->get();
        } else {
            $data = Project::withoutGlobalScopes()->join('project_employees', 'projects.id', 'project_employees.project_id')
                ->where('project_employees.employee_id', $user->entity_id)
                ->where('project_employees.organization_id', $organizationId)
                ->where('projects.organization_id', $organizationId)
                ->whereNull('projects.deleted_at')
                ->select('projects.name', 'projects.id', 'projects.uuid', 'projects.logo_url')
                ->groupBy('projects.id')
                ->get();
        }
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
     }


    //Get project list with pagination and filter
    public function getProjectList(Request $request)
    {
        try {

            $keyword = $request->keyword ??  '';
            $perPage = $request->perPage ??  '';
            $status = $request->status;
            $customer = $request->customer;
            $skill = $request->skill;
            $projectManager = $request->project_manager;
            $organizationId = $this->getCurrentOrganizationId();

            $user = Auth::user();
            $roles = $user->roles;
            $allRoles =  collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $projectData = Project::withoutGlobalScopes()->with(['customer:id,display_name', 'project_status:id,name,color_code', 'createdBy:id,entity_id'])
            ->leftJoin('user_timesheets', 'projects.id', 'user_timesheets.project_id')
          
            ->where('projects.organization_id', $organizationId)
            ->whereNull('projects.deleted_at');

            $projectData =  $projectData->groupBy('projects.id');
         
            if (!in_array('administrator', $allRoles)) {
                $projectData = $projectData->join('project_employees', function ($join) use ($user, $organizationId) {
                    $join->on('projects.id', '=',  'project_employees.project_id');
                    $join->where('project_employees.employee_id',  $user->entity_id);
                    $join->where('project_employees.organization_id', $organizationId);
                });             
            }

            $words = explode(' ', $keyword);
            foreach ($words as $term) {
            
                $word = Sanitize::sanitize($term); // <== clean user input
            
                $word = str_replace(['%', '_'], ['\\%', '\\_'], $word);
            
                $searchTerm = '%'.$word.'%';

                $projectData =  $projectData->where(function ($q1) use ($searchTerm) {

                    if (!empty($searchTerm)) {
                        $q1->where(function ($q2) use ($searchTerm) {
                            $q2->where('projects.name', "like", '%' . $searchTerm . '%');
                            $q2->orWhere('projects.description', "like", '%' . $searchTerm . '%');
                        });
                    }
                });
            }
          
            if (!empty($status)) {
                $projectData = $projectData->whereIn('projects.status_id', [$status]);
            }

            if (!empty($customer)) {
                $projectData = $projectData->where('projects.customer_id', $customer);
            }

            if (!empty($skill)) {
                $projectData = $projectData->leftJoin('project_skills', 'projects.id', 'project_skills.project_id')->groupBy('project_skills.project_id')->where('project_skills.skill_id', $skill);
            }

            if (!empty($projectManager) && $projectManager != 'none') {
                $projectData = $projectData->join('project_employees', function ($join) use ($projectManager, $organizationId) {
                    $join->on('projects.id', '=',  'project_employees.project_id');
                    $join->where('project_employees.employee_id',  $projectManager);
                    $join->where('project_employees.project_role_id', ProjectRole::PROJECT_MANAGER);
                    $join->where('project_employees.organization_id', $organizationId);
                });   
            }

            if($projectManager == 'none'){
                $projectData = $projectData->leftJoin('project_employees', function ($join) use ($organizationId) {
                    $join->on('projects.id', '=',  'project_employees.project_id');
                    $join->where('project_employees.project_role_id', ProjectRole::PROJECT_MANAGER);
                    $join->where('project_employees.organization_id', $organizationId);
                }); 

                $projectData->whereNull('project_employees.employee_id');
            }

            $query = clone $projectData;
            $totalRecords = $query->get(['projects.id'])->count();

            $projectData->select('projects.id', 'projects.uuid', 'name as project_name', 'customer_id', 'projects.status_id', 'created_by', DB::raw("MAX(user_timesheets.date) as last_worked"));
            $projectData = $projectData->orderBy('last_worked', 'desc')->orderby('projects.id', 'desc');
            $projectData = $projectData->simplePaginate($perPage);
       
            $projectData = $projectData->map(function ($project) use($organizationId) {

                $managers = ProjectEmployee::leftJoin('employees', function ($join) use ($organizationId) {
                    $join->on('project_employees.employee_id', '=',  'employees.id');
                    $join->where('employees.organization_id', $organizationId);
                })->where(['project_employees.project_id' => $project->id])->select('employees.display_name','project_employees.project_role_id')->get();


                $projectManager = $managers->filter(function ($manager) {
                    if ($manager->project_role_id == ProjectRole::PROJECT_MANAGER) {
                        return $manager;
                    }
                });

                $salesManager = $managers->filter(function ($manager) {
                    if ($manager->project_role_id == ProjectRole::SALES_MANAGER) {
                        return $manager;
                    }
                });

                $projectManager = $projectManager->toArray();
                $salesManager = $salesManager->toArray();
               
                $project['project_managers_name'] = implode(",\n", array_column($projectManager, 'display_name'));

                $project['sales_managers_name'] = implode(",\n", array_column($salesManager, 'display_name'));

                $project['last_worked'] = !empty($project['last_worked']) ? $project['last_worked'] : '';

                return $project;
            });


            $data['projects'] = $projectData;
            $data['count'] = $totalRecords;

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while list projects";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get project detail with uuid
    public function show($uuid)
    {

        $project = Project::where('uuid', $uuid)->first();

        $projectManagers = ProjectEmployee::where('project_id', $project->id)->where('project_role_id', ProjectRole::PROJECT_MANAGER)->get('employee_id')->pluck('employee_id');
        $salesManagers = ProjectEmployee::where('project_id', $project->id)->where('project_role_id', ProjectRole::SALES_MANAGER)->get('employee_id')->pluck('employee_id');
        $others = ProjectEmployee::where('project_id', $project->id)->where('project_role_id', ProjectRole::DEVELOPERANDQA)->get('employee_id')->pluck('employee_id');

        $project->project_managers = $projectManagers;
        $project->sales_managers = $salesManagers;
        $project->users = $others;

        if ($project->logo_url) {
            $path = config('constant.project_logo');
            $project->logo_url =  getFullImagePath($path.'/'. $project->logo_url);
        }

        $skills = ProjectSkill::where('project_id', $project->id)->get('skill_id')->pluck('skill_id');
        $project->skills = $skills;

        $estimations = ProjectEstimation::where('project_id', $project->id)->get();
        $project->estimations = $estimations;

        $attachments = ProjectAttachment::where('project_id', $project->id)->orderBy('created_at', 'desc')->get();
        $project->documents = $attachments;

        return $this->sendSuccessResponse(__('messages.success'), 200, $project);
    }

    //Create new project
    public function store(Request $request)
    {
        try {

            $inputs = $request->all();

            DB::beginTransaction();

            $organizationId = $this->getCurrentOrganizationId();

            $validation = $this->projectValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $user = Auth::user();
            $roles = $user->roles;
            $allRoles =  collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();
            

            $below50 = ProjectStatus::where('slug', ProjectStatus::BELOW50)->first('id');
            $below50 = $below50->id;

            if(!empty($inputs['name'])){
                $name = $inputs['name'];
             
                $abbrName = $this->generateAbbr($name);
            }

            $project = Project::create([
                'uuid' => getUuid(),
                'organization_id' => $organizationId,
                'name' => $inputs['name'],
                'abbreviation' => $abbrName,
                'billable' => $inputs['billable'],
                'customer_id' => $inputs['customer_id'],
                'status_id' => $below50,
                'created_by' => $user->id,
            ]);

            $customer = Customer::where('id', $inputs['customer_id'])->first(['display_name', 'sales_manager_id']);

            $info = ['project_name' => $project->name, 'project_id' => $project->uuid, 'created_by_name' => $user->display_name, 'customer_name' => $customer->display_name];

            $data = new NewProjectCreate($info);

            if (!in_array('administrator', $allRoles)) {
                ProjectEmployee::firstOrCreate([
                    'project_id' => $project->id,
                    'employee_id' => $user->entity_id,
                    'organization_id' => $organizationId,
                    'project_role_id' => ProjectRole::PROJECT_MANAGER
                ]);
            }

            if (!empty($inputs['project_manager'])) {
                $managers = $inputs['project_manager'];

                foreach ($managers as  $employeeId) {
                    ProjectEmployee::firstOrCreate([
                        'project_id' => $project->id,
                        'employee_id' => $employeeId,
                        'organization_id' => $organizationId,
                        'project_role_id' => ProjectRole::PROJECT_MANAGER
                    ]);

                    $newUser = User::where('entity_id', $employeeId)->whereIn('entity_type_id', [EntityType::Employee, EntityType::Admin])->first(['email', 'entity_id', 'id']);

                    $notifications = EmailNotification::where('user_id',$newUser->id)->first(['allow_all_notifications','assign_project']);

                    if($notifications->allow_all_notifications == true && $notifications->assign_project == true){

                        $emailData = ['email' => $newUser['email'], 'email_data' => $data];

                        SendEmailJob::dispatch($emailData);
                    }
                }
            }

            if (!empty($inputs['employee'])) {
                $users = $inputs['employee'];

                foreach ($users as  $employeeId) {
                    ProjectEmployee::firstOrCreate([
                        'project_id' => $project->id,
                        'employee_id' => $employeeId,
                        'organization_id' => $organizationId,
                        'project_role_id' => ProjectRole::DEVELOPERANDQA
                    ]);

                  
                    $newUser = User::where('entity_id', $employeeId)->first(['email', 'entity_id']);

                    $info = ['project_name' => $project->name, 'display_name' => $newUser->display_name];

                    $data = new ProjectAssignToUser($info);

                    $emailData = ['email' => $newUser['email'], 'email_data' => $data];

                    SendEmailJob::dispatch($emailData);
                    
                }
            }

            //Not needed as project created by sales manager only
            // if (!empty($customer->sales_manager_id)) {
            //     $manager = $customer->sales_manager_id;

            //     $newUser = User::where('entity_id', $manager)->first(['email', 'entity_id']);

            //     $emailData = ['email' => $newUser['email'], 'email_data' => $data];

            //     SendEmailJob::dispatch($emailData);
            // }

            //Assign default task status to project
            $defaultTaskStatus = DefaultTaskStatus::select('name',DB::raw($project->id." as project_id"))->get()->toArray();
            TaskStatus::insert($defaultTaskStatus);

            //Assign default task type icon to project
            $defaultTaskTypeIcon = DefaultTaskTypeIcon::select('icon_path',DB::raw($project->id." as project_id"))->get()->toArray();
            foreach($defaultTaskTypeIcon as $icon){
                $typeIcons[] = TaskTypeIcon::create($icon);
            }

            $defaultTaskType = DefaultTaskType::select('name',  'icon_id', DB::raw($project->id." as project_id"))->get()->toArray();
            foreach($defaultTaskType as $key => $type){
                $type['icon_id'] = $typeIcons[$key]->id; 
                TaskType::create($type);
            }
            
            $adminUsers = $this->getAdminUser('create_project');

            if(!empty($adminUsers) && count($adminUsers) > 0){
                foreach ($adminUsers as $adminUser) {
                   if($adminUser['email'] == $user->email){
                    continue;
                   }
                   $emailUsers[] = $adminUser;
                }
                if(!empty($emailUsers)){
                    $emailData = ['email' => $emailUsers, 'email_data' => $data];
                    SendEmailJob::dispatch($emailData);
                }
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.project_added'), 200, $project);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while create project";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function generateAbbr($name)
    {
        if(strlen($name) > 0){
            $abbrName = strtoupper($name[0]);
            $parts = explode(' ',$name);
            if(count($parts) > 0 && count($parts) == 1){
                $abbrName .= substr($parts[0], 1, 2);
            }
            if(count($parts) > 0 && count($parts) == 2){
                $abbrName .= substr($parts[0], 1, 1);
                $abbrName .= substr($parts[1], 0, 1);
            }
            if(count($parts) > 0 && count($parts) == 3){
                $abbrName .= substr($parts[1], 0, 1);
                $abbrName .= substr($parts[2], 0, 1);
            }

            $counter = 1;
            $modifiedAcronym =  $abbrName;
            // Append a counter until a unique acronym is found
            while (Project::where('abbreviation', $modifiedAcronym)->exists()) {
                $modifiedAcronym = $abbrName . $counter;
                $counter++;
            }

            if(!empty($modifiedAcronym)){
                $abbrName = $modifiedAcronym ;
            }

            return $abbrName;
        }
    }

    //Update project details
    public function updateProject(Request $request)
    {
        try {
            $inputs = json_decode($request->data, true);
            $request->merge($inputs);

            $project = Project::where('uuid', $inputs['uuid'])->first();

            DB::beginTransaction();

            $validation = $this->projectValidator->validateUpdate($request, $project);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $user = Auth::user();
            $organizationId = $this->getCurrentOrganizationId();
          
            if ($request->hasFile('logo_url')) {
                $path = config('constant.project_logo');
               
                $file = $this->uploadFileOnLocal($request->file('logo_url'), $path);

                $logo_url = $file['file_name'];
            }


            $data = [
                'customer_id' => $inputs['customer_id'],
                'name' => $inputs['name'],
                'abbreviation' => $inputs['abbreviation'],
                'billable' => $inputs['billable'] ? 1 : 0,
                'default_access_to_all_users' => $inputs['default_access_to_all_users'] ? 1 : 0,
                'description' => isset($inputs['description']) ? $inputs['description'] : null,
                'start_date' => !empty($inputs['start_date']) ? convertUserTimeToUTC($inputs['start_date']) : null,
                'end_date' => !empty($inputs['end_date']) ? convertUserTimeToUTC($inputs['end_date']) : null,
                'estimated_hours' => isset($inputs['estimated_hours']) ? $inputs['estimated_hours'] : null,
                'status_id' => $inputs['status_id'],
                'total_project_cost' => !empty($inputs['total_project_cost']) ? $inputs['total_project_cost'] : null,
                'billing_method_id' => !empty($inputs['billing_method_id']) ? $inputs['billing_method_id'] : null,
                'currency_id' => !empty($inputs['currency_id']) ? $inputs['currency_id'] : null,
                'updated_by' => $user->id
            ];

            if (!empty($logo_url)) {
                $data['logo_url'] = $logo_url;
            }

            $project->update($data);

            ProjectEmployee::where(['project_id' => $project->id, 'project_role_id' => ProjectRole::PROJECT_MANAGER])->delete();
            if (!empty($inputs['project_managers'])) {
                $managers = $inputs['project_managers'];

                foreach ($managers as  $employeeId) {
                    ProjectEmployee::firstOrCreate([
                        'project_id' => $project->id,
                        'employee_id' => $employeeId,
                        'organization_id' => $organizationId,
                        'project_role_id' => ProjectRole::PROJECT_MANAGER
                    ]);
                }
            }

            ProjectEmployee::where(['project_id' => $project->id, 'project_role_id' => ProjectRole::SALES_MANAGER])->delete();
            if (!empty($inputs['sales_managers'])) {
                $managers = $inputs['sales_managers'];

                foreach ($managers as  $employeeId) {
                    ProjectEmployee::firstOrCreate([
                        'project_id' => $project->id,
                        'employee_id' => $employeeId,
                        'organization_id' => $organizationId,
                        'project_role_id' => ProjectRole::SALES_MANAGER
                    ]);
                }
            }


            $projectEmployees = ProjectEmployee::where(['project_id' => $project->id, 'organization_id' => $organizationId, 'project_role_id' => ProjectRole::DEVELOPERANDQA])->get();

            ProjectEmployee::where(['project_id' => $project->id, 'organization_id' => $organizationId,  'project_role_id' => ProjectRole::DEVELOPERANDQA])->delete();
            if (!empty($inputs['project_users'])) {
                $users = $inputs['project_users'];

                foreach ($users as  $employeeId) {
                    ProjectEmployee::firstOrCreate([
                        'project_id' => $project->id,
                        'employee_id' => $employeeId,
                        'organization_id' => $organizationId,
                        'project_role_id' => ProjectRole::DEVELOPERANDQA
                    ]);

                    $filtered = $projectEmployees->filter(function ($item) use ($employeeId) {
                        return $item->employee_id == $employeeId;
                    })->values();

                    if (count($filtered) == 0) {
                        $newUser = User::where('entity_id', $employeeId)->first(['email', 'entity_id']);

                        $info = ['project_name' => $project->name, 'display_name' => $newUser->display_name];

                        $data = new ProjectAssignToUser($info);

                        $emailData = ['email' => $newUser['email'], 'email_data' => $data];

                        SendEmailJob::dispatch($emailData);
                    }
                }
            }

            ProjectSkill::where(['project_id' => $project->id])->delete();
            if (!empty($inputs['skills'])) {
                $skills = $inputs['skills'];

                foreach ($skills as  $skill) {
                    ProjectSkill::firstOrCreate([
                        'project_id' => $project->id,
                        'skill_id' => $skill
                    ]);
                }
            }

            if (!empty($inputs['estimations'])) {
                $project_estimations = $inputs['estimations'];
                foreach ($project_estimations as $value) {
                    if (empty($value['ids'])) {

                        $estimationData = [
                            'project_id' => $project->id,
                            'organization_id' => $organizationId,
                            'employee_id' =>  $value['employee'] ?? null,
                            'department_id' =>  $value['department'] ?? null,
                            'skill' =>  $value['skill_set'] ?? null,
                            'hours' => $value['hours'] ?? null,
                            'comment' => isset($value['comment']) ? $value['comment'] : null
                        ];

                        ProjectEstimation::create($estimationData);
                    }
                }
            }

            //Delete project estimation 
            if (!empty($inputs['project_estimation_delete'])) {
                foreach ($inputs['project_estimation_delete'] as $deleteItem) {
                    ProjectEstimation::where('project_id', $project->id)->where('id', $deleteItem)->where('organization_id', $organizationId)->delete();
                }
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.project_updated'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while update project";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Delete project with uuid
    public function destroy($uuid)
    {
        DB::beginTransaction();
        try {

            $project = Project::where('uuid', $uuid)->first();

            // remove from project table
            if (!empty($project)) {
                $project->delete();
            }

            DB::commit();
            return $this->sendSuccessResponse(__('messages.project_deleted'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while delete project";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Assign project to users
    public function assignProject(Request $request)
    {
        DB::beginTransaction();
        try {

            $inputs = $request->all();

            $users = $inputs['users'];
            $project = $inputs['project'];
            $project = Project::where('uuid', $project)->first(['id', 'name']);

            if (!empty($users)) {

                $organizationId = $this->getCurrentOrganizationId();

                $projectEmployees = ProjectEmployee::where(['project_id' => $project->id, 'organization_id' => $organizationId, 'project_role_id' => ProjectRole::DEVELOPERANDQA])->get();

                ProjectEmployee::where(['project_id' => $project->id, 'organization_id' => $organizationId, 'project_role_id' => ProjectRole::DEVELOPERANDQA])->delete();
                foreach ($users as  $user) {
                    ProjectEmployee::firstOrCreate([
                        'project_id' => $project->id,
                        'employee_id' => $user['id'],
                        'organization_id' => $organizationId,
                        'project_role_id' => ProjectRole::DEVELOPERANDQA
                    ]);

                    $filtered = $projectEmployees->filter(function ($item) use ($user) {
                        return $item->employee_id == $user['id'];
                    })->values();

                    if (count($filtered) == 0) {
                        $newUser = User::where('entity_id', $user['id'])->first(['email', 'entity_id']);

                        $info = ['project_name' => $project->name, 'display_name' => $newUser->display_name];

                        $data = new ProjectAssignToUser($info);

                        $emailData = ['email' => $newUser['email'], 'email_data' => $data];

                        SendEmailJob::dispatch($emailData);
                    }
                }
            }

            DB::commit();
            return $this->sendSuccessResponse(__('messages.project_assigned'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while assign project";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get assigned users
    public function getProjectUsers($uuid)
    {

        $project = Project::where('uuid', $uuid)->first('id');
        $organizationId = $this->getCurrentOrganizationId();
        $data = ProjectEmployee::where(['project_id' => $project->id, 'organization_id' => $organizationId, 'project_role_id' => ProjectRole::DEVELOPERANDQA])->get()->pluck('employee_id');

        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    //Get projects assigned to user
    public function getUserProjects()
    {
        $user = Auth::user();
        $entityID = $user->entity_id;
        // Login user organization id
        $organizationId = $this->getCurrentOrganizationId();

        $projects = Project::withoutGlobalScopes()->join('project_employees', 'projects.id', 'project_employees.project_id')
            ->where('project_employees.employee_id', $entityID)
            ->where('projects.organization_id', $organizationId)
            ->where('project_employees.organization_id', $organizationId)
            ->where('project_employees.project_role_id', ProjectRole::DEVELOPERANDQA)
            ->whereNull('projects.deleted_at')
            ->get(['projects.name', 'projects.id', 'projects.uuid']);

        return $this->sendSuccessResponse(__('messages.success'), 200, $projects);
    }

    //Get projects assigned to project manager
    public function getProjectManagerProjects()
    {
        $user = Auth::user();
        $entityID = $user->entity_id;
        // Login user organization id
        $organizationId = $this->getCurrentOrganizationId();

        $projects = Project::withoutGlobalScopes()->join('project_employees', 'projects.id', 'project_employees.project_id')
            ->where('project_employees.employee_id', $entityID)
            ->where('projects.organization_id', $organizationId)
            ->where('project_employees.organization_id', $organizationId)
            ->where('project_employees.project_role_id', ProjectRole::PROJECT_MANAGER)
            ->get(['projects.name', 'projects.id', 'projects.uuid']);

        return $this->sendSuccessResponse(__('messages.success'), 200, $projects);
    }

    //Get customer's projects
    public function getCustomersProjects(Request $request)
    {
        $customer = $request->customer;
        
        // Login user organization id
        $organizationId = $this->getCurrentOrganizationId();

        $archived = ProjectStatus::where('slug', ProjectStatus::ARCHIVED)->first('id');
        $archived = $archived->id;        

        $projects = Project::withoutGlobalScopes([OrganizationScope::class])->join('customers', 'projects.customer_id', 'customers.id')
            ->where('customers.id', $customer)
            ->where('customers.organization_id', $organizationId)
            ->where('projects.organization_id', $organizationId)
            ->where('projects.status_id', '!=', $archived)
            ->get(['projects.name', 'projects.id', 'projects.uuid']);

        return $this->sendSuccessResponse(__('messages.success'), 200, $projects);
    }

    public function uploadDocuments(Request $request)
    {
        DB::beginTransaction();
        try {
            $uuid = $request->uuid;
            $project = Project::where('uuid', $uuid)->first('id');

            if (!empty($request->attachments)) {
                $attachments = $request->attachments;

                $path = config('constant.project_documents');

                foreach ($attachments as $attachment) {
                    $file = $this->uploadFileOnLocal($attachment, $path);

                    $mimeType = $attachment->getMimeType();
                    $fileName = $attachment->getClientOriginalName();

                    if (!empty($file['file_name'])) {
                        $attachmentData = [
                            'project_id' =>  $project->id,
                            'attachment_path' => $file['file_name'],
                            'mime_type' => $mimeType,
                            'file_name' => $fileName
                        ];

                        $uploads[] = ProjectAttachment::create($attachmentData);
                    }
                }
            }

            $projectAttachments = $uploads;

            DB::commit();
            return $this->sendSuccessResponse(__('messages.project_document_uploaded'), 200, $projectAttachments);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while project document uploaded";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Delete documents
    public function deleteDocument(Request $request)
    {
        DB::beginTransaction();
        try {
            $id = $request->uuid;
            if (!empty($id)) {
            
                $attachment = ProjectAttachment::where('id', $id)->first();

                $path = config('constant.project_documents');

                if (Storage::disk('public')->exists($path . '/' . $attachment->attachment_path)) {

                    $this->removeFileOnLocal($attachment->attachment_path, $path);

                }
                ProjectAttachment::where('id', $id)->delete();
            }


            DB::commit();
            return $this->sendSuccessResponse(__('messages.project_document_deleted'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while project document deleted";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function importProject(Request $request)
    {
        DB::beginTransaction();
        try {
            $projects = DB::connection('old_connection')->table('projects')->get();

            $organizationId = $request->organization_id;

            if (!empty($projects)) {
                foreach ($projects as $input) {
                    $exist = Project::where('name', $input->name)->where('organization_id', $organizationId)->first(['id']);
                    if(!empty($exist)){
                        if(end($projects) == $input) {
                            // last iteration
                            GoTo ENDLoop;
                        }
                        continue;
                    }

                    $status = DB::connection('old_connection')->table('status')->where('id', $input->status_id)->first(['name']);

                    $projectStatus = ProjectStatus::where('name', 'LIKE', $status->name)->where('organization_id',$organizationId)->first('id');
                   
                    $projectStatus = $projectStatus->id;

                    $name = $abbrName = '';
                    if (!empty($input->name)) {
                        $name = $input->name;

                        if (strlen($name) > 0) {
                            $abbrName = strtoupper($name[0]);
                            $parts = explode(' ', $name);
                            if (count($parts) > 0 && count($parts) == 1) {
                                $abbrName .= substr($parts[0], 1, 2);
                            }
                            if (count($parts) > 0 && count($parts) == 2) {
                                $abbrName .= substr($parts[0], 1, 1);
                                $abbrName .= substr($parts[1], 0, 1);
                            }
                            if (count($parts) > 0 && count($parts) == 3) {
                                $abbrName .= substr($parts[1], 0, 1);
                                $abbrName .= substr($parts[2], 0, 1);
                            }
                        }
                    }

                    $customer = DB::connection('old_connection')->table('customers')->where('id', $input->customer_id)->first(['company_name']);

                    if (!empty($customer)) {
                        $customer = Customer::where('company_name', 'LIKE', $customer->company_name)->where('organization_id',$organizationId)->first(['id']);
                    }

                    $user = DB::connection('old_connection')->table('users')->where('id', $input->created_by)->first(['email']);
                    $userId = 2;
                    if (!empty($user->email)) {
                        $user = User::where('email', 'LIKE', $user->email)->where('organization_id', $organizationId)->first(['id']);
                        if(!empty($user)){
                            $userId = $user->id;
                        }
                    }

                    $project = Project::create([
                        'uuid' => getUuid(),
                        'organization_id' => $organizationId,
                        'name' => $name,
                        'abbreviation' => $abbrName,
                        'billable' => $input->billable,
                        'default_access_to_all_users' => $input->default_access_to_all_users,
                        'description' => !empty($input->description) ? $input->description : null,
                        'start_date' => !empty($input->start_date) ? $input->start_date : null,
                        'end_date' => !empty($input->end_date) ? $input->end_date : null,
                        'estimated_hours' => !empty($input->estimated_hours) ? $input->estimated_hours : null,
                        'total_project_cost' => !empty($input->total_project_cost) ? $input->total_project_cost : null,
                        'billing_method_id' => !empty($input->billing_method_id) ? $input->billing_method_id : null,
                        'customer_id' => $customer->id,
                        'status_id' => $projectStatus,
                        'created_by' => $userId,
                        'created_at' => $input->created_at,
                        'deleted_at' => !empty($input->deleted_at) ? $input->deleted_at : null
                    ]);
                    

                    //Assign default task status to project
                    $defaultTaskStatus = DefaultTaskStatus::select('name', DB::raw($project->id . " as project_id"))->get()->toArray();
                    TaskStatus::insert($defaultTaskStatus);

                    //Assign default task type icon to project
                    $defaultTaskTypeIcon = DefaultTaskTypeIcon::select('icon_path', DB::raw($project->id . " as project_id"))->get()->toArray();
                    foreach ($defaultTaskTypeIcon as $icon) {
                        $typeIcons[] = TaskTypeIcon::create($icon);
                    }

                    $defaultTaskType = DefaultTaskType::select('name', 'icon_id', DB::raw($project->id . " as project_id"))->get()->toArray();
                    foreach ($defaultTaskType as $key => $type) {
                        $type['icon_id'] = $typeIcons[$key]->id;
                        TaskType::create($type);
                    }

                    $managers = DB::connection('old_connection')->table('project_managers')->where('manager_type', 1)->where('project_id', $input->id)->get();

                    foreach ($managers as $employee) {

                        $employee = DB::connection('old_connection')->table('employees')->where('id', $employee->manager_id)->first(['employee_id']);
                        $employeeId = $employee->employee_id;

                        ProjectEmployee::firstOrCreate([
                            'project_id' => $project->id,
                            'employee_id' => $employeeId,
                            'organization_id' => $organizationId,
                            'project_role_id' => ProjectRole::PROJECT_MANAGER
                        ]);

                    }

                    $managers = DB::connection('old_connection')->table('project_managers')->where('manager_type', 2)->where('project_id', $input->id)->get();
                    if (!empty($managers)) {

                        foreach ($managers as $employee) {

                            $employee = DB::connection('old_connection')->table('employees')->where('id', $employee->manager_id)->first(['employee_id']);
                            $employeeId = $employee->employee_id;

                            ProjectEmployee::firstOrCreate([
                                'project_id' => $project->id,
                                'employee_id' => $employeeId,
                                'organization_id' => $organizationId,
                                'project_role_id' => ProjectRole::SALES_MANAGER
                            ]);
                        }
                    }

                    $employees = DB::connection('old_connection')->table('project_employee')->where('project_id', $input->id)->get();

                    if (!empty($employees)) {

                        foreach ($employees as $employee) {

                            $employee = DB::connection('old_connection')->table('employees')->where('id', $employee->employee_id)->first(['employee_id']);
                            $employeeId = $employee->employee_id;

                            if(!empty($employeeId)){
                                ProjectEmployee::firstOrCreate([
                                    'project_id' => $project->id,
                                    'employee_id' => $employeeId,
                                    'organization_id' => $organizationId,
                                    'project_role_id' => ProjectRole::DEVELOPERANDQA
                                ]);
                            }
                            
                        }
                    }

                    $employees = DB::connection('old_connection')->table('project_qa')->where('project_id', $input->id)->get();

                    if (!empty($employees)) {

                        foreach ($employees as $employee) {

                            $employee = DB::connection('old_connection')->table('employees')->where('id', $employee->qa_id)->first(['employee_id']);
                            $employeeId = $employee->employee_id;

                            ProjectEmployee::firstOrCreate([
                                'project_id' => $project->id,
                                'employee_id' => $employeeId,
                                'organization_id' => $organizationId,
                                'project_role_id' => ProjectRole::DEVELOPERANDQA
                            ]);
                        }
                    }


                    $projectSkills = DB::connection('old_connection')->table('project_skills')->where('project_id', $input->id)->get(['skill_id']);

                    if (!empty($projectSkills)) {

                        foreach ($projectSkills as $skill) {
                         
                            $skills = DB::connection('old_connection')->table('skills')->where('id', $skill->skill_id)->first(['name']);
                            $skillName = $skills->name;

                            $currentSkill = Skill::where('name', 'LIKE', $skillName)->where('organization_id',$organizationId)->first(['id']);
                            if(!empty($currentSkill->id)){
                                ProjectSkill::firstOrCreate([
                                    'project_id' => $project->id,
                                    'skill_id' => $currentSkill->id
                                ]);
                            }
                           
                        }
                    }


                    $projectEstimation = DB::connection('old_connection')->table('project_estimation')->where('project_id', $input->id)->whereNull('deleted_at')->get();

                    if (!empty($projectEstimation)) {
                        foreach ($projectEstimation as $value) {

                            $employee = DB::connection('old_connection')->table('employees')->where('id', $value->employee_id)->first(['employee_id']);
                            if(!empty($employee)){
                                $employeeId = $employee->employee_id;
                            }
                            
                            $department = DB::connection('old_connection')->table('department')->where('id', $value->department_id)->whereNull('deleted_at')->first(['name']);
                

                            $department = Department::where('name','LIKE', $department->name)->first(['id']);
                            $departmentId = '';
                            if(!empty($department->id)){
                                $departmentId = $department->id;
                            }

                            if(!empty($employeeId)){
                                $estimationData = [
                                    'project_id' => $project->id,
                                    'organization_id' => $organizationId,
                                    'employee_id' => $employeeId ?? null,
                                    'department_id' => $departmentId ?? null,
                                    'skill' => $value->skill ?? null,
                                    'hours' => $value->hours ?? null,
                                    'comment' => isset($value->comment) ? $value->comment : null
                                ];
                            }

                            ProjectEstimation::create($estimationData);

                        }
                    }

                    $projectAttachment = DB::connection('old_connection')->table('project_attachment')->where('project_id', $input->id)->get();
                    if (!empty($projectAttachment)) {

                        foreach ($projectAttachment as $attachment) {

                            if (!empty($attachment->attachment_path)) {
                                $fileName = explode('/', $attachment->attachment_path);
                                $fileName = $fileName[2];

                                $mimeType = Storage::mimeType($fileName);


                                $attachmentData = [
                                    'project_id' => $project->id,
                                    'attachment_path' => $fileName,
                                    'mime_type' => $mimeType,
                                    'file_name' => $fileName
                                ];

                                ProjectAttachment::create($attachmentData);
                            }
                        }

                    }
                }
            }

            ENDLoop:
            
            DB::commit();
            return $this->sendSuccessResponse(__('messages.project_imported'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while project imported";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
