<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\Policy;
use App\Traits\ResponseTrait;
use App\Traits\UploadFileTrait;
use App\Validators\PolicyValidator;
use DB;
use Illuminate\Http\Request;

class PolicyController extends Controller
{
    use ResponseTrait, UploadFileTrait;

    public $policyValidator;

    public function __construct(){

        $this->policyValidator = new PolicyValidator();
    }

    public function index(Request $request)
    {
        $policies = Policy::all();
        return $this->sendSuccessResponse(__('messages.success'), 200, $policies);
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $validation = $this->policyValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $data = [
                'uuid' => getUuid(),
                'title' => $inputs['title'],
                'organization_id' => $organizationId
            ];

            if (!empty($request->attachment)) {
                $attachment = $request->attachment;

                $path = config('constant.policy_attachment');
                $file = $this->uploadFileOnLocal($attachment, $path);

                if (!empty($file['file_name'])) {
                    $data['file'] = $file['file_name'];
                }
            }

            $policy = Policy::create($data);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.policy_store'), 200, $policy);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add policy";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function show($policy)
    {
        $data = Policy::where('uuid',$policy)->first();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function update(Request $request, $policy)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();

            $organizationId = $this->getCurrentOrganizationId();
            $validation = $this->policyValidator->validateUpdate($request, $policy ,$organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $policy = Policy::where('uuid', $policy)->first();

            $data = [
                'title' => $inputs['title']
            ];

            if (!empty($request->attachment)) {
                $attachment = $request->attachment;

                $path = config('constant.policy_attachment');
                $file = $this->uploadFileOnLocal($attachment, $path);

                if (!empty($file['file_name'])) {
                    $data['file'] = $file['file_name'];
                }
            }

            $policy->update($data);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.policy_update'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update policy";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function destroy($policy)
    {
        try {
            DB::beginTransaction();

            Policy::where('uuid', $policy)->delete();

            DB::commit();

            return $this->sendSuccessResponse(__('messages.policy_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete policy";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
