<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\AddressType;
use App\Models\EntityType;
use App\Models\Organization;
use App\Models\OrganizationBilling;
use App\Traits\ResponseTrait;
use App\Traits\UploadFileTrait;
use App\Validators\OrganizationValidator;
use Auth;
use DB;
use Illuminate\Http\Request;
use Storage;

class OrganizationController extends Controller
{
    private $organizationValidator;
    use ResponseTrait, UploadFileTrait;

    public function __construct()
    {
        $this->middleware('auth');
        $this->organizationValidator = new OrganizationValidator();
    }

    // update organization billing detail
    public function updateOrganizationBilling(Request $request)
    {
        try {
             DB::beginTransaction();
             $inputs = json_decode($request->data,true);

             $request->merge($inputs);

            //validate request start
            $billingValidate = $this->organizationValidator->validateBilling($request);
            if ($billingValidate->fails()) {
                return $this->sendFailResponse($billingValidate->errors()->first(), 422);
            }

            //validate request end
            $name = $request->name;
            $userInfo = $request->user();
            
            $organizationBilling = OrganizationBilling::where('organization_id' , $userInfo->organization_id)->first();
            
            if ($request->hasFile('organization_logo')) {
                    $path = config('constant.avatar');
                    $file = $this->uploadFileOnLocal($request->file('organization_logo'), $path);
                    $logo_url = $file['file_name'];

                    Organization::where('id', $userInfo->organization_id)->update(['organization_logo' =>  $logo_url]);
            }
            
          
            if (isset($inputs['address1'])) {
                $address = Address::where('entity_id', $userInfo->entity_id)->where('entity_type_id', EntityType::Admin)->where('address_type_id', AddressType::BILLINGADDRESS)->first();
                if ($address) {
                    $address->update([
                        'address' => $inputs['address1'],
                        'address2' =>  !empty($inputs['address2']) ? $inputs['address2'] : null,
                        'country_id' => !empty($inputs['country']) ? $inputs['country'] : null,
                        'city_id' => !empty($inputs['city']) ? $inputs['city'] : null,
                        'state_id' => !empty($inputs['state']) ? $inputs['state'] : null,
                        'zipcode' => !empty($inputs['zipcode']) ? $inputs['zipcode'] :  null
                    ]);
                } else {
                    $address = Address::create([
                        'address' => $inputs['address1'],
                        'address2' =>  !empty($inputs['address2']) ? $inputs['address2'] : null,
                        'country_id' => !empty($inputs['country']) ? $inputs['country'] : null,
                        'city_id' => !empty($inputs['city']) ? $inputs['city'] : null,
                        'state_id' => !empty($inputs['state']) ? $inputs['state'] : null,
                        'zipcode' => !empty($inputs['zipcode']) ? $inputs['zipcode'] :  null,
                        'entity_id' =>  $userInfo->entity_id,
                        'entity_type_id' => EntityType::Admin,
                        'organization_id' => $userInfo->organization_id,
                        'address_type_id' => AddressType::BILLINGADDRESS
                    ]);
                }
            }

            if(!empty($organizationBilling)){
                $organizationBilling->update(['billing_name' => $name,'billing_address_id' => $address->id]);
            }else{
                OrganizationBilling::create(['billing_name' => $name, 'billing_address_id' => $address->id, 'organization_id' => $userInfo->organization_id]);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.billing_success'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while update organization billing detail";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Get billing detail of organization
    public function getOrganizationBillingDetail()
    {
        try {
            $userInfo = Auth::user();
            $organizationBilling = OrganizationBilling::with(['organization:organization_logo,id','billing_address:address,address2,city_id,state_id,country_id,zipcode,id'])->where('organization_id', $userInfo->organization_id)->first();

            return $this->sendSuccessResponse(__('messages.success'), 200, $organizationBilling);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while update organization billing detail";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function uploadOrganizationLogo(Request $request)
    {
        try {
            //validate request start
            $logoValidate = $this->organizationValidator->validateLogo($request);
            if ($logoValidate->fails()) {
                return $this->sendFailResponse($logoValidate->errors()->first(), 422);
            }

            //validate request end
            $userInfo = $request->user();
            DB::beginTransaction();
            if ($request->hasFile('organization_logo'))
            {
                $organization = Organization::find($userInfo->organization_id);
                $path = config('constant.avatar');
                if (Storage::disk('public')->exists($path . '/' . $organization->organization_logo)) {
                    $this->removeFileOnLocal($organization->organization_logo, $path);
                }
                
                $file = $this->uploadFileOnLocal($request->file('organization_logo'), $path);
                $logo_url = $file['file_name'];
               
                // Update the logo field in the organization record
                Organization::where('id', $userInfo->organization_id)->update(['organization_logo' =>  $logo_url]);
            }
            DB::commit();
            return $this->sendSuccessResponse(__('Logo Updated'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while update organization billing detail";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
    public function deleteOrganizationLogo()
    {
        try {
            $organizationId = $this->getCurrentOrganizationId();
            
            DB::beginTransaction();
            if (!empty($organizationId)) {
                $path = config('constant.avatar');
                $organization = Organization::where('id', $organizationId)->select('organization_logo')->first();
               
                if (Storage::disk('public')->exists($path . '/' . $organization->organization_logo)) {
                    
                    $this->removeFileOnLocal($organization->organization_logo, $path);
                    Organization::where('id', $organizationId)->update(['organization_logo' => null]);
                }
            }
            DB::commit();
            return $this->sendSuccessResponse(__('messages.logo_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete Logo";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
