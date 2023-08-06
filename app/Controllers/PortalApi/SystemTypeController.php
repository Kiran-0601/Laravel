<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\System;
use App\Models\SystemInventoryType;
use App\Traits\ResponseTrait;
use App\Validators\SystemTypeValidator;
use DB;
use Illuminate\Http\Request;

class SystemTypeController extends Controller
{
    use ResponseTrait;
    private $systemTypeValidator;

    function __construct()
    {
        $this->systemTypeValidator = new SystemTypeValidator();
    }
    public function index()
    {
        $data = SystemInventoryType::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->systemTypeValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $systemType = SystemInventoryType::create([
                'name' => $inputs['name'],
                'organization_id' => $organizationId
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.system_type_store'), 200, $systemType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add system type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function show(SystemInventoryType $system_type)
    {
        $systemType = $system_type;
        return $this->sendSuccessResponse(__('messages.success'), 200, $systemType);
    }

    public function update(Request $request, SystemInventoryType $system_type)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->systemTypeValidator->validateUpdate($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $systemType = $system_type->update([
                'name' => $inputs['name']
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.system_type_update'), 200, $systemType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update system type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function destroy(SystemInventoryType $system_type){
        try {
            DB::beginTransaction();

            $systemType = System::where('system_inventory_type_id', $system_type->id)->first();

            if(empty($systemType)){

                $system_type->delete();
            }else{
                return $this->sendFailResponse(__('messages.delete_system_parts_warning'), 422);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.system_type_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete system type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
