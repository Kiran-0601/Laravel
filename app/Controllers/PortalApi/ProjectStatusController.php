<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Validators\ProjectStatusValidator;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use App\Models\ProjectStatus;
use Storage;
use Str;
use DB;

class ProjectStatusController extends Controller
{
    use ResponseTrait;
    public $projectStatusValidator;

    public function __construct(){
        $this->projectStatusValidator = new ProjectStatusValidator();
    }
    public function list(Request $request)
    {
        $perPage = $request->perPage ?? '';
        $projectStatusData = ProjectStatus::select('id', 'name');
        $projectStatusData = $projectStatusData->simplePaginate($perPage);
        return $this->sendSuccessResponse(__('messages.success'), 200, $projectStatusData);
    }
    public function store(Request $request)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $validation = $this->projectStatusValidator->validateStore($request,$organizationId);
            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }
            $data = [
                'uuid' => getUuid(),
                'organization_id' => $organizationId,
                'name' => $inputs['name'],
                'slug' => Str::slug($inputs['name'])
            ];
            $projectStatus = ProjectStatus::create($data);
            DB::commit();
            return $this->sendSuccessResponse(__('messages.project_status_store'), 200, $projectStatus);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add project status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    public function update(Request $request, $projectStatus)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $projectStatus = ProjectStatus::where('uuid', $projectStatus)->first();
            $validation = $this->projectStatusValidator->validateUpdate($request,$projectStatus);
            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }
            $projectStatus->update([
                'name' => $inputs['name'],
                'slug' => Str::slug($inputs['name'])
            ]);
            DB::commit();
            return $this->sendSuccessResponse(__('messages.project_status_update'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update project status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    public function delete($projectStatus)
    {
        try {
            DB::beginTransaction();
            ProjectStatus::where('uuid', $projectStatus)->delete();
            DB::commit();
            return $this->sendSuccessResponse(__('messages.project_status_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete project status";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
