<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\ActivityList;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ActivityLogHistoryController extends Controller
{

    use ResponseTrait;

    //Get activity list
    public function getActivityList(Request $request)
    {
        try {
            $perPage = $request->perPage ?? 10;
            $moduleName = $request->module_name;
            $moduleId = $request->module_id ?? null;
            $employee = $request->employee ?? null;
            $query = ActivityLog::with('updatedBy')
                ->where('module_name', $moduleName);
            $organizationId = $this->getCurrentOrganizationId();
                
            if($moduleName == 'timesheet'){
                $roles = $request->user()->roles->pluck('name')->toArray();
                if(!in_array('Administrator', $roles)){
                    $entityID = $request->user()->entity_id;
                   

                    $projects = Project::withoutGlobalScopes()->join('project_employees', function ($join) use ($entityID, $organizationId) {
                        $join->on('projects.id', '=',  'project_employees.project_id');
                        $join->where('project_employees.employee_id',  $entityID);
                        $join->where('project_employees.organization_id', $organizationId);
                        $join->where('projects.organization_id', $organizationId);
                        $join->orWhere(function ($join) use ($organizationId) {
                            $join->where('projects.default_access_to_all_users', 1);
                            $join->where('projects.organization_id', $organizationId);
                        });
                    })->groupBy('projects.id');
        
                    $projects = $projects->get(['projects.id'])->pluck('id')->toArray();
        
                    $projectID = !empty($projects) ? implode(',',$projects) : "";	
                    if(!empty($projectID)){
                        $query = $query->whereRaw('JSON_EXTRACT(old_data, "$.name") IN ('. $projectID.')');
                    }
                }
            }

            if($moduleName == 'LMS'){
                $permissions = $request->user()->getAllPermissions()->pluck('name')->toArray();
                if (!in_array('manage_leaves', $permissions)) {
                    $employee = $request->user()->entity_id;
                }

                if (!empty($employee)) {
                    $query = $query->where('module_id', $employee)->where('table_name', 'employees')->where('organization_id', $organizationId);
                }
            }

            if ($moduleName == 'COMPOFF') {
                $permissions = $request->user()->getAllPermissions()->pluck('name')->toArray();
                if (!in_array('manage_comp_off', $permissions)) {
                    $employee = $request->user()->entity_id;
                }
                if (!empty($employee)) {
                    $query = $query->where('module_id', $employee)->where('table_name', 'employees')->where('organization_id', $organizationId);
                }
            }

            if (!empty($moduleId)) {
                $query = $query->where('module_id', $moduleId);
            }

            $countQuery = clone $query;
            $totalCount = $countQuery->count();

            $query->orderBy('created_at', 'desc');

            $activitylogs = $query->simplePaginate($perPage);

            $collection = new Collection();
            foreach ($activitylogs as $log) {
                $user_name = $log->updatedBy->display_name ?? NULL;
                $action = $log->action ?? NULL;
                $oldData = $log->old_data ?? NULL;
                $newData = $log->new_data ?? NULL;
                $oldRecodeName = NULL;
                $newRecodeName = NULL;

                if (isset($action)) {
                    $table = $log->table_name;
                    $taskLog = new ActivityList();
                    $taskLog->setTable($table);
                    $taskLog->setConnection(env('DB_CONNECTION'));
                    if (isset($oldData)) {
                        //For old recode:
                        //STEP-1:Json decode tp array
                        $oldData = json_decode($oldData, true);
                        //STEP-2:Get key and Value from array

                        if(array_key_exists('plain',$oldData)){
                            if(is_bool($oldData['plain'])){
                                $oldData['plain'] = $oldData['plain']  === false ? 'false' : 'true';
                            }
                            $oldRecodeName = $oldData['plain'];
                        }else{
                            $oldDataKey = array_keys($oldData);
                            $oldDataValue = array_values($oldData);

                            if( strpos($oldDataValue[0], ',') !== false ) {
                                $oldDataValue = explode(',', $oldDataValue[0]);
                                $oldRecodeName = $this->processMultiValues($oldDataKey[0],$oldDataValue, $taskLog, $table, $organizationId);
                            }else{
                                $oldRecodeName = $this->processSingleValue($oldDataKey[0], $oldDataValue[0], $taskLog, $table, $organizationId);
                            }
                        }
                        
                    }

                    if (isset($newData)) {
                        //For new recode:
                        //STEP-1:Json decode tp array
                        $newData = json_decode($newData, true);

                        if(array_key_exists('plain',$newData)){
                            if(is_bool($newData['plain'])){
                                $newData['plain'] = $newData['plain']  === false ? 'false' : 'true';
                            }
                            $newRecodeName = $newData['plain'];
                        }else{
                            //STEP-2:Get key and Valuefrom array
                            $newDataKey = array_keys($newData);
                            $newDataValue = array_values($newData);

                            if( strpos($newDataValue[0], ',') !== false ) {
                                $newDataValue = explode(',', $newDataValue[0]);
                                $newRecodeName = $this->processMultiValues($newDataKey[0],$newDataValue, $taskLog, $table, $organizationId);
                            }else{
                                $newRecodeName = $this->processSingleValue($newDataKey[0], $newDataValue[0], $taskLog, $table, $organizationId);
                            }
                         
                        }
                    }

                    if (!isset($oldRecodeName) && !isset($newRecodeName)) {
                        $comment = $user_name . ' ' . $action;
                    } elseif (!isset($oldRecodeName) && isset($newRecodeName)) {
                        $comment = $user_name . ' ' . $action . ' to <b>"' . ucfirst($newRecodeName) . '"</b>';
                    } elseif (isset($oldRecodeName) && isset($newRecodeName)) {
                        $comment = $user_name . ' ' . $action . ' from <b>"' . ucfirst($oldRecodeName) . '"</b>' . ' to <b>"' . ucfirst($newRecodeName) . '"</b>';
                    } elseif (isset($oldRecodeName) && !isset($newRecodeName)) {
                        $comment = $user_name . ' ' . $action . ' for <b>' . ucfirst($oldRecodeName) . '</b>';
                    }

                    $avatar = '';
                    if($moduleName == 'task'){
                        $organizationId = $this->getCurrentOrganizationId();
                        $employee = User::withoutGlobalScopes([OrganizationScope::class])->join('employees', function($join) use($organizationId){
                                $join->on('employees.id' , '=' , 'users.entity_id');
                                $join->where('employees.organization_id', $organizationId);
                        })->where('users.id', $log->updated_by)->where('users.organization_id', $organizationId)->first(['employees.avatar_url']);
                        $path = config('constant.avatar');
                        if(!empty($employee->avatar_url)){
                            $avatar = getFullImagePath($path . '/' . $employee->avatar_url);
                        }
                        
                    }

                    $log->comment = $comment;
                    $log->display_name = $user_name;
                    $log->avatar = $avatar;
                    $log->employee_id = $log->updated_by;

                    unset($log->old_data);
                    unset($log->new_data);
                    unset($log->module_id);
                    unset($log->module_name);
                    unset($log->table_name);
                    unset($log->action);
                    unset($log->organization_id);
                    unset($log->updated_by);
                    $log->unsetRelation('updatedBy');

                    // $collection->push(
                    //     (object) 
                    //     [
                    //         'id' => $log->id,
                    //         'comment' => $comment,
                    //         'created_time' => $log->created_time,
                    //         'created_at' => $log->created_at,
                    //         'employee_id' => $log->updated_by,
                    //         'display_name' => $user_name,
                    //         'avatar' => $avatar
                    //     ]
                    // );
                }
            }

            $response = [
                'logs' => $activitylogs,
                'total_count' => $totalCount
            ];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get entries";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function processMultiValues($key, $value,  $taskLog, $table, $organizationId)
    {
 
        $record = $taskLog->select($key)->whereIn('id', $value);
        if($table == 'employees'){
            $record = $record->where('organization_id', $organizationId);
        }
        $recode = $record->get($key)->pluck($key)->toArray();
        $recodeName = implode(',',$recode);
        return $recodeName;
    }

    public function processSingleValue($key, $value,  $taskLog, $table, $organizationId)
    {   
        $recordName = "";
        if(!empty($value)){
            $record = $taskLog->select($key)->where('id', $value);
            if($table == 'employees'){
                $record = $record->where('organization_id', $organizationId);
            }
            $record = $record->first();
            if(!empty($record)){
                $recordName = $record->$key;
            }
        }
      
        return $recordName;
    }
}