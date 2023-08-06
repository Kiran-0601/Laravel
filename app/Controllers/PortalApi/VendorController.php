<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Traits\ResponseTrait;
use App\Validators\VendorTypeValidator;
use App\Validators\VendorValidator;
use DB;
use Illuminate\Http\Request;

class VendorController extends Controller
{
    use ResponseTrait;
    private $vendorValidator;

    function __construct()
    {
        $this->vendorValidator = new VendorValidator();
    }

    //List vendor types
    public function vendorTypes()
    {
        $data = VendorType::select('id', 'type')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }

    // List vendors
    public function index(Request $request)
    {
        $perPage = $request->perPage ??  10;
        $keyword = $request->keyword ??  '';
        $vendorType = $request->vendor_type ?? 0;

        $query = Vendor::join('vendor_types', 'vendors.vendor_type_id', 'vendor_types.id')
                        ->select('vendors.uuid', 'vendors.name','vendors.email', 'vendors.address', 'vendors.gst_no','vendor_types.type as vendor_type');
    
        $query =  $query->where(function ($q1) use ($vendorType, $keyword) {
            if (!empty($vendorType)) {
                $q1->where('vendor_types.id', $vendorType);
            }

            if (!empty($keyword)) {
                $q1->where(function($q2) use($keyword){
                    $q2->where('vendors.name', "like", '%'.$keyword.'%');
                    $q2->orWhere('vendors.email', "like", '%'.$keyword.'%');
                    
                });
            }
        });

        $count = $query->count();

        $data = $query->latest('vendors.id')->simplePaginate($perPage);

        $response = [
            "data" => $data,
            "total_record" => $count,
        ];
        return $this->sendSuccessResponse(__('messages.success'), 200, $response);
    }

    //Add new vendor
    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->vendorValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $vendorType = Vendor::create([
                'uuid' => getUuid(),
                'name' => $inputs['name'],
                'email' => $inputs['email'],
                'address' => $inputs['address'] ?? null,
                'gst_no' => $inputs['gst_no'] ?? null,
                'vendor_type_id' => $inputs['vendor_type_id'],
                'organization_id' => $organizationId
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.vendor_store'), 200, $vendorType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add vendor";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get vendor detail
    public function show($vendor)
    {
        $vendor = Vendor::where('uuid', $vendor)->first();
        return $this->sendSuccessResponse(__('messages.success'), 200, $vendor);
    }


    //Update vendor detail
    public function update(Request $request, $vendor)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();

            $vendor = Vendor::where('uuid', $vendor)->first();
            
            $validation = $this->vendorValidator->validateUpdate($request, $organizationId, $vendor->id);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $vendor->update([
                'name' => $inputs['name'],
                'vendor_type_id' => $inputs['vendor_type_id'],
                'email' => $inputs['email'] ?? null,
                'address' => $inputs['address'] ?? null,
                'gst_no' => $inputs['gst_no'] ?? null
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.vendor_update'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update vendor";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Delete vendor
    public function destroy($vendor){
        try {
            DB::beginTransaction();

            //ToDo check vendor is assigned to any record then return warning

            Vendor::where('uuid',$vendor)->delete();

            DB::commit();

            return $this->sendSuccessResponse(__('messages.vendor_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete vendor";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
