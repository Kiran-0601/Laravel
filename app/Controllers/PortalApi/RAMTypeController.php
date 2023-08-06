<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\System;
use App\Models\SystemRAMType;
use App\Traits\ResponseTrait;
use App\Validators\RAMTypeValidator;
use DB;
use Illuminate\Http\Request;

class RAMTypeController extends Controller
{
    use ResponseTrait;
    private $ramTypeValidator;

    function __construct()
    {
        $this->ramTypeValidator = new RAMTypeValidator();
    }
    public function index()
    {
        $data = SystemRAMType::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->ramTypeValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $ramType = SystemRAMType::create([
                'name' => $inputs['name'],
                'organization_id' => $organizationId
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.ram_type_store'), 200, $ramType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add ram type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function show(SystemRAMType $ram_type)
    {
        $ramType = $ram_type;
        return $this->sendSuccessResponse(__('messages.success'), 200, $ramType);
    }

    public function update(Request $request, SystemRAMType $ram_type)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->ramTypeValidator->validateUpdate($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $ramType = $ram_type->update([
                'name' => $inputs['name']
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.ram_type_update'), 200, $ramType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update ram type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function destroy(SystemRAMType $ram_type){
        try {
            DB::beginTransaction();

            $ramType = System::where('system_ram_type_id', $ram_type->id)->first();

            if(empty($ramType)){

                $ram_type->delete();
            }else{
                return $this->sendFailResponse(__('messages.delete_system_parts_warning'), 422);
            }  

            DB::commit();

            return $this->sendSuccessResponse(__('messages.ram_type_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete ram type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
