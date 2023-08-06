<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\NewCustomerCreate;
use App\Models\Address;
use App\Models\AddressType;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\CustomerContactPerson;
use App\Models\EmailNotification;
use App\Models\EntityType;
use App\Models\Salutation;
use App\Models\Scopes\OrganizationScope;
use App\Models\State;
use App\Models\User;
use App\Traits\ResponseTrait;
use App\Traits\UploadFileTrait;
use App\Validators\CustomerValidator;
use Auth;
use DB;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ResponseTrait, UploadFileTrait;
    private $customerValidator;

    function __construct()
    {
        $this->customerValidator = new CustomerValidator();
    }

    //Get all customers
    public function index(){
       
        
        $user = Auth::user();
        $roles = $user->roles;
        $allRoles =  collect($roles)->map(function ($value) {
            return $value->slug;
        })->toArray();
        $permisions = $user->getAllPermissions()->pluck('name')->toArray();

        $query = Customer::select('id', 'display_name');
        if (!in_array('administrator', $allRoles) && in_array('create_customer', $permisions )) {
            $query = $query->where('sales_manager_id', $user->entity_id);
        }
        
        $data = $query->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $data);
    }


    //Get customer list with pagination and filter
    public function getCustomerList(Request $request)
    {
        try {

            $keyword = $request->keyword ??  '';
            $perPage = $request->perPage ??  '';
            $organizationId = $this->getCurrentOrganizationId();

            $user = Auth::user();
            $roles = $user->roles;
            $allRoles =  collect($roles)->map(function ($value) {
                return $value->slug;
            })->toArray();

            $customerData = Customer::withoutGlobalScope(OrganizationScope::class)->leftJoin('address',function($join){
                $join->on('customers.id','=','address.entity_id');
                $join->where('address.entity_type_id' , EntityType::Customer);
            })->leftJoin('country', 'address.country_id', 'country.id')
             ->select('customers.id', 'customers.uuid', 'customers.display_name', 'customers.contact_email', 'customers.created_at','country.name as country_name');

            $customerData = $customerData->where('customers.organization_id', $organizationId);

            if (!in_array('administrator', $allRoles)) {
                $customerData = $customerData->where('sales_manager_id', $user->entity_id);
            }
            
            $totalRecords = $customerData->get()->count();

            $customerData =  $customerData->where(function ($q1) use ($keyword) {

                if (!empty($keyword)) {
                    $q1->where(function ($q2) use ($keyword) {
                        $q2->where('customers.company_name', "like", '%' . $keyword . '%');
                        $q2->orWhere('customers.first_name', "like", '%' . $keyword . '%');
                        $q2->orWhere('customers.last_name', "like", '%' . $keyword . '%');
                        $q2->orWhere('customers.display_name', "like", '%' . $keyword . '%');
                        $q2->orWhere('customers.contact_email', "like", '%' . $keyword . '%');
                    });
                }
            });

            $customerData = $customerData->orderby('customers.id', 'desc');

            $customerData = $customerData->paginate($perPage);

            foreach ($customerData as $value) {
                $date = convertUTCTimeToUserTime($value->created_at);
                $value->display_date = $date;
            }

            $data['customers'] = $customerData;
            $data['count'] = $totalRecords;

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while list customers";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function show($uuid)
    {

        $customer = Customer::where('uuid', $uuid)->first();

        if ($customer->logo_url) {
            $path = config('constant.customer_logo');
            $customer->logo_url =  getFullImagePath($path.'/'. $customer->logo_url);
        }

        $address = Address::where('entity_id', $customer->id)->where('entity_type_id', EntityType::Customer)->where('address_type_id', AddressType::PRESENT)->first();
        $contacts = CustomerContactPerson::where('customer_id', $customer->id)->select('salutation_id as contact_salutation_id', 'first_name as contact_first_name', 'last_name as contact_last_name', 'contact_email as contact_person_email', 'mobile as contact_mobile')->get();
        $customer['address'] = $address;
        $customer['contacts'] = $contacts;

        return $this->sendSuccessResponse(__('messages.success'), 200, $customer);
    }

    public function store(Request $request)
    {
        try {

            $inputs = $request->all();

            DB::beginTransaction();

            $validation = $this->customerValidator->validateStore($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $user = Auth::user();
            $organizationId = $this->getCurrentOrganizationId();

            $companyName = $inputs['company_name'];
            $salutationId = isset($inputs['salutation_id']) ? $inputs['salutation_id'] : null;
            $firstName = isset($inputs['first_name']) ? $inputs['first_name'] : null;
            $lastName = isset($inputs['last_name']) ? $inputs['last_name'] : null;
            $email = isset($inputs['contact_email']) ? $inputs['contact_email'] : null;
            $displayName = !empty($inputs['display_name']) ? $inputs['display_name'] : '';
            $salesManager = isset($inputs['sales_manager']) ? $inputs['sales_manager'] : null;

            $customer = Customer::create([
                'uuid' => getUuid(),
                'organization_id' => $organizationId,
                'company_name' => $companyName,
                'salutation_id' => $salutationId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'display_name' => $displayName,
                'contact_email' => $email,
                'sales_manager_id' => $salesManager,
                'created_by' => $user->id,
            ]);

            $info = ['company_name' => $inputs['company_name'], 'first_name' => $firstName, 'last_name' => $lastName, 'display_name' => $displayName, 'created_by_name' => $user->display_name, 'email' => $email];

            $data = new NewCustomerCreate($info);

            //Not needed as customer created by sales manager only
            // if(!empty( $inputs['sales_manager'])) {
            //     $newUser = User::where('entity_id', $inputs['sales_manager'])->first(['email', 'entity_id']);

            //     $emailData = ['email' => $newUser['email'], 'email_data' => $data];
    
            //     SendEmailJob::dispatch($emailData);
            // }

            $adminUsers = $this->getAdminUser('create_customer');

            if(!empty($adminUsers) && count($adminUsers) > 0){
               
                $emailData = ['email' => $adminUsers, 'email_data' => $data];
            
                SendEmailJob::dispatch($emailData);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.customer_added'), 200, $customer);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while create customer";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function updateCustomer(Request $request)
    {
        try {

            $inputs = json_decode($request->data, true);

            $customer = Customer::where('uuid', $inputs['uuid'])->first();

            $request->merge($inputs);

            DB::beginTransaction();

            $validation = $this->customerValidator->validateUpdate($request, $customer);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $organizationId = $this->getCurrentOrganizationId();

            if ($request->hasFile('logo_url')) {
                $path = config('constant.customer_logo');
               
                $file = $this->uploadFileOnLocal($request->file('logo_url'), $path);

                $logo_url = $file['file_name'];
            }


            $data = [
                'company_name' => $inputs['company_name'],
                'salutation_id' => !empty($inputs['salutation_id']) ? $inputs['salutation_id'] : null,
                'first_name' => isset($inputs['first_name']) ? $inputs['first_name'] : null,
                'last_name' => isset($inputs['last_name']) ? $inputs['last_name'] : null,
                'display_name' => !empty($inputs['display_name']) ? $inputs['display_name'] : '',
                'contact_email' => isset($inputs['contact_email']) ? $inputs['contact_email'] : null,
                'mobile' => isset($inputs['mobile']) ? $inputs['mobile'] : null,
                'skype_id' => isset($inputs['skype_id']) ? $inputs['skype_id'] : null,
                'website' => isset($inputs['website']) ? $inputs['website'] : null,
                'currency_id' => !empty($inputs['currency_id']) ? $inputs['currency_id'] : null,
                'remarks' => isset($inputs['remarks']) ? $inputs['remarks'] : null,
                'sales_manager_id' => isset($inputs['sales_manager']) ? $inputs['sales_manager'] : null
            ];
            if (!empty($logo_url)) {
                $data['logo_url'] = $logo_url;
            }
            $customer->update($data);

            CustomerContactPerson::where('customer_id', $customer->id)->where('organization_id', $organizationId)->delete();
            if (!empty($inputs['contacts'])) {
                $contacts = $inputs['contacts'];
                foreach ($contacts as $value) {
                    $contactData = [
                        'customer_id' => $customer->id,
                        'organization_id' => $organizationId,
                        'salutation_id' => !empty($value['contact_salutation_id']) ? $value['contact_salutation_id'] : null, 
                        'first_name' =>  $value['contact_first_name'] ?? null,
                        'last_name' => $value['contact_last_name'] ?? null,
                        'mobile' => isset($value['contact_mobile']) ? $value['contact_mobile'] : null,
                        'contact_email' => isset($value['contact_person_email']) ? $value['contact_person_email'] : null,
                    ];

                    CustomerContactPerson::create($contactData);
                }
            }

            $address = Address::where('entity_id', $customer->id)->where('entity_type_id', EntityType::Customer)->where('address_type_id', AddressType::PRESENT)->first();
            if ($address) {
                $address->update([
                    'address' => !empty($inputs['address1']) ? $inputs['address1'] : null,
                    'address2' =>  !empty($inputs['address2']) ? $inputs['address2'] : null,
                    'country_id' => !empty($inputs['country']) ? $inputs['country'] : null,
                    'city_id' => !empty($inputs['city']) ? $inputs['city'] : null,
                    'state_id' => !empty($inputs['state']) ? $inputs['state'] : null,
                    'zipcode' => !empty($inputs['zipcode']) ? $inputs['zipcode'] :  null
                ]);
            } else {
                $address = Address::create([
                    'address' => !empty($inputs['address1']) ? $inputs['address1'] : null,
                    'address2' =>  !empty($inputs['address2']) ? $inputs['address2'] : null,
                    'country_id' => !empty($inputs['country']) ? $inputs['country'] : null,
                    'city_id' => !empty($inputs['city']) ? $inputs['city'] : null,
                    'state_id' => !empty($inputs['state']) ? $inputs['state'] : null,
                    'zipcode' => !empty($inputs['zipcode']) ? $inputs['zipcode'] :  null,
                    'entity_id' => $customer->id,
                    'entity_type_id' => EntityType::Customer,
                    'organization_id' => $organizationId,
                    'address_type_id' => AddressType::PRESENT
                ]);
            }

            $customer['address'] = $address;

            DB::commit();

            return $this->sendSuccessResponse(__('messages.customer_updated'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while update customer";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function destroy($uuid)
    {
        DB::beginTransaction();
        try {

            $customer = Customer::where('uuid', $uuid)->first();

            // remove from customer table
            $customer->delete();

            DB::commit();
            return $this->sendSuccessResponse(__('messages.customer_deleted'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while delete customer";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Import customer
    public function getOldCustomersData(Request $request)
    {
        DB::beginTransaction();
        try {
            $customers = DB::connection('old_connection')->table('customers')->whereNull('deleted_at')->get();

            $organizationId = $request->organization_id;

            foreach ($customers as $input) {

                $main_contact = DB::connection('old_connection')->table('customer_contact_person')->where('customer_id', $input->id)->where('main_contact', 0)->first();
                if(!empty($main_contact->email)){
                    $exist = Customer::where('contact_email', $main_contact->email)->where('organization_id', $organizationId)->first(['id']);
                    if(!empty($exist)){
                        if(end($customers) == $input) {
                            // last iteration
                            GoTo ENDLoop;
                        }
                        continue;
                    }
                } elseif (!empty($input->company_name)) {
                    $exist = Customer::where('company_name', $input->company_name)->where('organization_id', $organizationId)->first(['id']);
                    if (!empty($exist)) {
                        if (end($customers) == $input) {
                            // last iteration
                            goto ENDLoop;
                        }
                        continue;
                    }
                }
                $salutation = '';
                if(!empty($main_contact->salutation)){
                    $salutation = Salutation::where('name', 'LIKE', '%' . $main_contact->salutation . '%')->first(['id']);
                }
                
                $salesManager = '';
                $employee = DB::connection('old_connection')->table('customer_employees')->where('customer_id', $input->id)->first();
                if(!empty($employee)){
                    $employee = DB::connection('old_connection')->table('employees')->where('id', $employee->employee_id)->first(['employee_id']);
                    $salesManager = $employee->employee_id;
                }

                $user = DB::connection('old_connection')->table('users')->where('id', $input->created_by)->first(['email']);
                $userId = 2;
                if (!empty($user->email)) {
                    $user = User::where('email', 'LIKE', $user->email)->where('organization_id', $organizationId)->first(['id']);
                    if(!empty($user)){
                        $userId = $user->id;
                    }
                }
                $companyName = $input->company_name;
                $firstName = isset($main_contact->first_name) ? $main_contact->first_name : null;
                $lastName = isset($main_contact->last_name) ? $main_contact->last_name : null;
                $email = isset($main_contact->email) ? $main_contact->email : null;
                $salutationId = !empty($salutation) ? $salutation->id : null;
                $displayName = !empty($input->display_name) ? $input->display_name : '';
                $salesManager = !empty($salesManager) ? $salesManager : null;
                $mobile = isset($main_contact->mobile) ? $main_contact->mobile : null;
                $website = isset($input->website) ? $input->website : null;
                $currencyId = !empty($input->currency_id) ? $input->currency_id : null;
                $remarks = isset($input->remarks) ? $input->remarks : null;

                $customer = Customer::create([
                    'uuid' => getUuid(),
                    'organization_id' => $organizationId,
                    'company_name' => $companyName,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'salutation_id' => $salutationId,
                    'display_name' => $displayName,
                    'mobile' => $mobile,
                    'website' => $website,
                    'currency_id' => $currencyId,
                    'contact_email' => $email,
                    'sales_manager_id' => $salesManager,
                    'remarks' => $remarks,
                    'created_by' => $userId,
                    'created_at' => $input->created_at
                ]);

                $contacts = DB::connection('old_connection')->table('customer_contact_person')->where('customer_id', $input->id)->where('main_contact',1)->get();

                if (!empty($contacts)) {
                    foreach ($contacts as $contact) {

                        $salutation = Salutation::where('name', 'LIKE', '%' . $contact->salutation . '%')->first(['id']);

                        $contactData = [
                            'customer_id' => $customer->id,
                            'organization_id' => $organizationId,
                            'salutation_id' => !empty($salutation) ? $salutation->id : null,
                            'first_name' => $contact->first_name ?? null,
                            'last_name' => $contact->last_name ?? null,
                            'mobile' => isset($contact->mobile) ? $contact->mobile : null,
                            'contact_email' => isset($contact->email) ? $contact->email : null,
                        ];

                        CustomerContactPerson::create($contactData);
                    }
                }
                
                $address = DB::connection('old_connection')->table('address')->where('entity_id', $input->id)->where('entity_type_id', EntityType::Customer)->first();
                $countryId = null;
                $stateId = null;
                $cityId = null;
                if (!empty($address)) {
                    
                    if (!empty($address->country_id)) {

                        $country = DB::connection('old_connection')->table('country')->where('id', $address->country_id)->first(['name']);

                        
                        if (!empty($country)) {
                            $country = Country::where('name', $country->name)->first(['id']);
                            $countryId = $country->id;
                        }
                    }

                    if (!empty($address->state_id)) {

                        $state = DB::connection('old_connection')->table('state')->where('id', $address->state_id)->first(['state_name']);
                       
                        if (!empty($state)) {
                            $state = State::where('state_name', $state->state_name)->first(['id', 'country_id']);
                            $stateId = $state->id;
                            $countryId = $state->country_id;
                        }
                    }
                   
                    if (!empty($address->city_id)) {
                        $city = DB::connection('old_connection')->table('city')->where('id', $address->city_id)->first(['city_name']);

                       
                        if (!empty($city)) {
                            $city = City::where('city_name', $city->city_name)->first(['id', 'state_id']);

                            $cityId = $city->id;
                            $stateId = $city->state_id;
                        }
                    }

                    $address = Address::create([
                        'address' => $address->address,
                        'address2' => !empty($address->address2) ? $address->address2 : null,
                        'country_id' => !empty($countryId) ? $countryId : null,
                        'city_id' => !empty($cityId) ? $cityId : null,
                        'state_id' => !empty($stateId) ? $stateId : null,
                        'zipcode' => !empty($address->zipcode) ? $address->zipcode : null,
                        'entity_id' => $customer->id,
                        'entity_type_id' => EntityType::Customer,
                        'organization_id' => $organizationId,
                        'address_type_id' => AddressType::PRESENT
                    ]);
                }
            }

            ENDLoop:

            DB::commit();
            return $this->sendSuccessResponse(__('messages.customer_imported'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while import customer";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
