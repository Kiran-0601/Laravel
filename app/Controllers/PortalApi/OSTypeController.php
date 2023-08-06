<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\System;
use App\Models\SystemOSType;
use App\Traits\ResponseTrait;
use App\Validators\OSTypeValidator;
use DB;
use Illuminate\Http\Request;

class OSTypeController extends Controller
{
    use ResponseTrait;
    private $osTypeValidator;

    function __construct()
    {
        $this->osTypeValidator = new OSTypeValidator();
    }
    public function index()
    {
        $data = SystemOSType::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->osTypeValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $osType = SystemOSType::create([
                'name' => $inputs['name'],
                'organization_id' => $organizationId
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.os_type_store'), 200, $osType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add os type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function show(SystemOSType $os_type)
    {
        $osType = $os_type;
        return $this->sendSuccessResponse(__('messages.success'), 200, $osType);
    }

    public function update(Request $request, SystemOSType $os_type)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->osTypeValidator->validateUpdate($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $osType = $os_type->update([
                'name' => $inputs['name']
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.os_type_update'), 200, $osType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update os type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function destroy(SystemOSType $os_type){
        try {
            DB::beginTransaction();

            $osType = System::where('system_os_type_id', $os_type->id)->first();

            if(empty($osType)){

                $os_type->delete();
            }else{
                return $this->sendFailResponse(__('messages.delete_system_parts_warning'), 422);
            }
            
            DB::commit();

            return $this->sendSuccessResponse(__('messages.os_type_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete os type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
