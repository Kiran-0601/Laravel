<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TestingDevice;
use App\Traits\ResponseTrait;
use App\Models\Vendor;
use Lang, Log, DB;

class DeviceController extends Controller
{
    use ResponseTrait;
    public function index(Request $request)
    {
        try{
            $inputs = $request->all();
            $type = $inputs['type'];
            
            $getData = TestingDevice::leftJoin('employees', 'employees.id', '=', 'testing_device.employee_id')
                ->join('it_vendor', 'it_vendor.id', '=', 'testing_device.vendor_id')
                ->select('testing_device.id','testing_device.name','testing_device.new_old','testing_device.device_info_url','testing_device.manufacturer_company','testing_device.os','testing_device.purchase_date','testing_device.type','testing_device.model',
                    'employees.display_name','it_vendor.name AS vendor');
                
            if (isset($type) && $type != 2) {
                $getData = $getData->where('testing_device.new_old', '=', $type);
            }

            $getData = $getData->orderBy('testing_device.id', 'desc')->get();

            $device_count = count($getData);     

            return $this->sendSuccessResponse(Lang::get('messages.testing_device.list'),200,$getData);
        } catch (\Exception $e) {
            Log::info($e);
            DB::rollBack();
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
    }
    }
}
