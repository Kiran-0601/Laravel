<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\System;
use App\Models\SystemHDDType;
use App\Traits\ResponseTrait;
use App\Validators\HDDTypeValidator;
use DB;
use Illuminate\Http\Request;

class HDDTypeController extends Controller
{
    use ResponseTrait;
    private $hddTypeValidator;

    function __construct()
    {
        $this->hddTypeValidator = new HDDTypeValidator();
    }
    public function index()
    {
        $data = SystemHDDType::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->hddTypeValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $hddType = SystemHDDType::create([
                'name' => $inputs['name'],
                'organization_id' => $organizationId
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.hdd_type_store'), 200, $hddType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add hdd type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function show(SystemHDDType $hdd_type)
    {
        $hddType = $hdd_type;
        return $this->sendSuccessResponse(__('messages.success'), 200, $hddType);
    }

    public function update(Request $request, SystemHDDType $hdd_type)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->hddTypeValidator->validateUpdate($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $hddType = $hdd_type->update([
                'name' => $inputs['name']
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.hdd_type_update'), 200, $hddType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update hdd type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function destroy(SystemHDDType $hdd_type){
        try {
            DB::beginTransaction();

            $hddType = System::where('system_hdd_type_id', $hdd_type->id)->first();

            if(empty($hddType)){

                $hdd_type->delete();
            }else{
                return $this->sendFailResponse(__('messages.delete_system_parts_warning'), 422);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.hdd_type_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete hdd type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
