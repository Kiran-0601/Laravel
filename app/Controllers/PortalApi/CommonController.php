<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Gender;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectBillingType;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\Salutation;
use App\Models\TaskPriorityType;
use App\Models\TaskStatus;
use App\Models\TaskType;
use App\Models\User;
use App\Traits\ResponseTrait;

class CommonController extends Controller
{
    use ResponseTrait;

    public function currencyList()
    {
        $data = Currency::select('id', 'name', 'symbol')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function salutationList()
    {
        $data = Salutation::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function genderList()
    {
        $data = Gender::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function salesmanagerList()
    {
        $data = [];
        $organizationId = $this->getCurrentOrganizationId();
        $role = Role::where('organization_id', $organizationId)->where('slug', 'sales-team')->first();
        $users = User::role($role->id)->where('is_active',1)->get(['entity_id', 'organization_id']);

        foreach ($users as $user) {
            $data[] = ["id" => $user->entity_id, "name" => $user->display_name];
        }
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function projectmanagerList()
    {
        $data = [];
        $users = User::all();
        $permission = Permission::where('name', 'create_project')->first();
        foreach ($users as $user) {
            if ($user->hasPermissionTo($permission->id)) {
                $data[] = ["id" => $user->entity_id, "name" => $user->display_name];
            }
        }
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function projectStatusList()
    {
        $data = ProjectStatus::select('id', 'name')->orderBy('id')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function projectBillingMethodList()
    {
        $data = ProjectBillingType::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function taskStatusList($projectId, $taskType = '')
    {
        $project = Project::where('uuid', $projectId)->first();
        $data = TaskStatus::where('project_id', $project->id)->select('id', 'name')->get();

        // if (empty($taskType) && !empty($project->id)) {
        //     $fixed = TaskStatus::where('project_id', $project->id)->where('name', 'fixed')->select('id')->first();
        //     $reopen = TaskStatus::where('project_id', $project->id)->where('name', 'reopen')->select('id')->first();

        //     if (!empty($fixed) && !empty($reopen)) {
        //         $data = TaskStatus::where('project_id', $project->id)->select('id', 'name')->get()->except([$fixed->id, $reopen->id]);
        //     }
        // }

        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function taskTypeList($projectId)
    {
        $project = Project::where('uuid', $projectId)->first();
        $data = TaskType::where('project_id', $project->id)->select('id', 'name', 'icon_id')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function taskPriorityList()
    {
        $data = TaskPriorityType::select('id', 'name', 'icon')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }
}
