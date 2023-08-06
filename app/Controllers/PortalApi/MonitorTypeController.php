<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\System;
use App\Models\SystemMonitorType;
use App\Traits\ResponseTrait;
use App\Validators\MonitorTypeValidator;
use DB;
use Illuminate\Http\Request;

class MonitorTypeController extends Controller
{
    use ResponseTrait;
    private $monitorTypeValidator;

    function __construct()
    {
        $this->monitorTypeValidator = new MonitorTypeValidator();
    }
    public function index()
    {
        $data = SystemMonitorType::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->monitorTypeValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $monitorType = SystemMonitorType::create([
                'name' => $inputs['name'],
                'organization_id' => $organizationId
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.monitor_type_store'), 200, $monitorType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add monitor type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function show(SystemMonitorType $monitor_type)
    {
        $monitorType = $monitor_type;
        return $this->sendSuccessResponse(__('messages.success'), 200, $monitorType);
    }

    public function update(Request $request, SystemMonitorType $monitor_type)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->monitorTypeValidator->validateUpdate($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $monitorType = $monitor_type->update([
                'name' => $inputs['name']
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.monitor_type_update'), 200, $monitorType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update monitor type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function destroy(SystemMonitorType $monitor_type){
        try {
            DB::beginTransaction();

            $monitorType = System::where('system_monitor_type_id', $monitor_type->id)->first();

            if(empty($monitorType)){

                $monitor_type->delete();
            }else{
                return $this->sendFailResponse(__('messages.delete_system_parts_warning'), 422);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.monitor_type_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete monitor type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

}
