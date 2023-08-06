<?php

namespace App\Http\Controllers\MobileAppApi\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\System;
use App\Models\Vendor;
use App\Models\Comment;
use App\Models\Employee;
use App\Models\EntityType;
use App\Models\SystemComment;
use App\Traits\ResponseTrait;
use Lang, Log, DB;
use App\Models\User;

class SystemController extends Controller
{
    use ResponseTrait;   
    public function index(Request $request)
    {
        try{
            $inputs = $request->all();
            $type = $inputs['type'];
            $device = $inputs['device'];
            $avaible = $inputs['avaible'];
            $multiple_device = $inputs['multiple_device'];

            $getData = System::join('it_vendor', 'it_vendor.id', '=', 'system_inventory.vendor_id')->leftJoin('employees', 'system_inventory.employee_id', '=', 'employees.id')
                ->leftJoin('system_inventory_type', 'system_inventory_type.id', '=', 'system_inventory.system_inventory_type_id')
                ->leftJoin('system_inventory_processor', 'system_inventory_processor.id', '=', 'system_inventory.system_inventory_processor_id')
                ->leftJoin('system_inventory_ram', 'system_inventory_ram.id', '=', 'system_inventory.system_inventory_ram_id')
                ->leftJoin('system_inventory_hdd', 'system_inventory_hdd.id', '=', 'system_inventory.system_inventory_hdd_id')
                ->leftJoin('system_inventory_ssd', 'system_inventory_ssd.id', '=', 'system_inventory.system_inventory_ssd_id')
                ->leftJoin('system_inventory_operating_system', 'system_inventory_operating_system.id', '=', 'system_inventory.system_inventory_operating_system_id')
                ->leftJoin('system_inventory_monitor', 'system_inventory_monitor.id', '=', 'system_inventory.system_inventory_monitor_id')
                ->leftJoin('system_inventory_keyboard', 'system_inventory_keyboard.id', '=', 'system_inventory.system_inventory_keyboard_id')
                ->leftJoin('system_inventory_mouse', 'system_inventory_mouse.id', '=', 'system_inventory.system_inventory_mouse_id')
                ->select(
                    'system_inventory.id',
                    'system_inventory.computer_name',
                    'system_inventory.new_old',
                    'system_inventory_type.name AS system_inventory_type',
                    'system_inventory_processor.name AS system_inventory_processor',
                    'system_inventory_ram.name AS system_inventory_ram',
                    'system_inventory_hdd.name AS system_inventory_hdd',
                    'system_inventory_ssd.name AS system_inventory_ssd',
                    'system_inventory_operating_system.name AS system_inventory_operating_system',
                    'system_inventory_monitor.name AS system_inventory_monitor',
                    'system_inventory_keyboard.name AS system_inventory_keyboard',
                    'system_inventory_mouse.name AS system_inventory_mouse',
                    'it_vendor.name AS vendor',
                    'system_inventory.purchase_date',
                    'system_inventory.mother_board',
                    'system_inventory.employee_id',
                    'system_inventory.using_temporary',
                    'system_inventory.ms_office',
                    'system_inventory.visual_studio',
                    'system_inventory.cabinet',
                    'employees.display_name'
                );

                if (isset($avaible) && $avaible != '') {
                    $getData = $getData->where(function ($query) {
                        $query->where('system_inventory.employee_id', '=', null)->orWhere('system_inventory.using_temporary', '=', 1);
                    });
                }
                if (isset($type) && $type != 2) {
                    $getData = $getData->where('system_inventory.new_old', '=', $type);
                }

                if (isset($device) && $device != 4) {
                    $getData = $getData->where('system_inventory.system_inventory_type_id', '=', $device);
                }

                if (isset($multiple_systems) && $multiple_systems != '') {
                    $emp_id = [];
                    $data1 = $getData->get();
                    $grouped = $data1->groupBy('employee_id')->map(function ($row) {
                        return $row->count();
                    });
                    foreach ($grouped as $key => $value) {
                        if ($value > 1) {
                            $emp_id[] = $key;
                        }
                    }
                    $getData = $getData->whereIn('system_inventory.employee_id', $emp_id)->orderBy('employees.display_name', 'ASC');
                } 
                $getData = $getData->orderBy('id', 'desc')
                ->get();

            $system_count = count($getData);

            return $this->sendSuccessResponse(Lang::get('messages.system.list'),200,$getData);
        } catch (\Exception $e) {
            Log::info($e);
            DB::rollBack();
            return $this->sendFailedResponse(Lang::get('messages.general.laravel_error'),500);
        }
    }    

}
