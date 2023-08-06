<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\System;
use App\Models\SystemSSDType;
use App\Traits\ResponseTrait;
use App\Validators\SSDTypeValidator;
use DB;
use Illuminate\Http\Request;

class SSDTypeController extends Controller
{
    use ResponseTrait;
    private $ssdTypeValidator;

    function __construct()
    {
        $this->ssdTypeValidator = new SSDTypeValidator();
    }
    public function index()
    {
        $data = SystemSSDType::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->ssdTypeValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $ssdType = SystemSSDType::create([
                'name' => $inputs['name'],
                'organization_id' => $organizationId
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.ssd_type_store'), 200, $ssdType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add ssd type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function show(SystemSSDType $ssd_type)
    {
        $ssdType = $ssd_type;
        return $this->sendSuccessResponse(__('messages.success'), 200, $ssdType);
    }

    public function update(Request $request, SystemSSDType $ssd_type)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->ssdTypeValidator->validateUpdate($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $ssdType = $ssd_type->update([
                'name' => $inputs['name']
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.ssd_type_update'), 200, $ssdType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update ssd type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function destroy(SystemSSDType $ssd_type){
        try {
            DB::beginTransaction();

            $ssdType = System::where('system_ssd_type_id', $ssd_type->id)->first();

            if(empty($ssdType)){

                $ssd_type->delete();
            }else{
                return $this->sendFailResponse(__('messages.delete_system_parts_warning'), 422);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.ssd_type_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete ssd type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

}
