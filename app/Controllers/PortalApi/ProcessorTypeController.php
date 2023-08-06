<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\System;
use App\Models\SystemProcessorType;
use App\Traits\ResponseTrait;
use App\Validators\ProcessorTypeValidator;
use DB;
use Illuminate\Http\Request;

class ProcessorTypeController extends Controller
{
    use ResponseTrait;
    private $processorTypeValidator;

    function __construct()
    {
        $this->processorTypeValidator = new ProcessorTypeValidator();
    }
    public function index()
    {
        $data = SystemProcessorType::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->processorTypeValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $processorType = SystemProcessorType::create([
                'name' => $inputs['name'],
                'organization_id' => $organizationId
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.processor_type_store'), 200, $processorType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add processor type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function show(SystemProcessorType $processor_type)
    {
        $processorType = $processor_type;
        return $this->sendSuccessResponse(__('messages.success'), 200, $processorType);
    }

    public function update(Request $request, SystemProcessorType $processor_type)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->processorTypeValidator->validateUpdate($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $processorType = $processor_type->update([
                'name' => $inputs['name']
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.processor_type_update'), 200, $processorType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update processor type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function destroy(SystemProcessorType $processor_type){
        try {
            DB::beginTransaction();

            $processorType = System::where('system_processor_type_id', $processor_type->id)->first();

            if(empty($processorType)){

                $processor_type->delete();
            }else{
                return $this->sendFailResponse(__('messages.delete_system_parts_warning'), 422);
            }  

            DB::commit();

            return $this->sendSuccessResponse(__('messages.processor_type_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete processor type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
