<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\DeleteDevice;
use App\Mail\DeviceAssignedToUser;
use App\Mail\NewDeviceCreate;
use App\Models\Comment;
use App\Models\CommentType;
use App\Models\Device;
use App\Models\DeviceInventoryType;
use App\Models\EmailNotification;
use App\Models\Employee;
use App\Models\EntityType;
use App\Models\MobileDevice;
use App\Models\MobileType;
use App\Models\Permission;
use App\Models\Prefix;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Traits\ResponseTrait;
use App\Validators\DeviceValidator;
use Auth;
use DB;
use Illuminate\Http\Request;

class DeviceController extends Controller
{

    use ResponseTrait;
    private $deviceValidator;

    private $commentType;

    function __construct()
    {
        $this->deviceValidator = new DeviceValidator();

        $this->commentType = CommentType::select('id', 'type')->get()->pluck('id','type')->toArray();
    }

    public function getDeviceOldData()
    {
        DB::beginTransaction();
        try {
            $devices = DB::connection('old_connection')->table('testing_device')->get();

            $organizationId = 1;

            $data = [
                'module' => Prefix::Device,
                'organization_id' => $organizationId,
                'name' => 'CL-DEV',
                'series' => 1
            ];

            $data = Prefix::create($data);

            $deviceTypeAndroid = DeviceInventoryType::create([
                'name' => 'Android',
                'organization_id' => $organizationId
            ]);

            $deviceTypeIphone = DeviceInventoryType::create([
                'name' => 'iPhone',
                'organization_id' => $organizationId
            ]);

            if (!empty($devices)) {
                foreach ($devices as $input) {
                    $exist = Device::where('name', $input->name)->where('organization_id', $organizationId)->first(['id']);
                    if(!empty($exist)){
                        if(end($devices) == $input) {
                            // last iteration
                            GoTo ENDLoop;
                        }
                        continue;
                    }
                    $employeeId = null;
                    if(!empty($input->employee_id)){
                        $employee = DB::connection('old_connection')->table('employees')->where('id', $input->employee_id)->first(['employee_id']);
                        $employeeId = $employee->employee_id;
                    }

                  
                    //With script
                    $vendorId = null;
                    if(!empty($input->vendor_id)){
                        $vendor = DB::connection('old_connection')->table('it_vendor')->where('id', $input->vendor_id)->first(['name']);
                        if(!empty($vendor)){
                            $vendor= Vendor::where('name', 'LIKE', $vendor->name)->where('organization_id',$organizationId)->first(['id']);
                            $vendorId = $vendor->id;
                        }
                    }

                    $deviceTypeId = 1;
                    if(!empty($input->type) && $input->type == 'Android'){
                        $deviceTypeId = $deviceTypeAndroid->id;
                    }

                    if(!empty($input->type) && $input->type == 'iPhone'){
                        $deviceTypeId = $deviceTypeIphone->id;
                    }

                 
                    $deviceData = [
                        'name' => $input->name,
                        'uuid' => getUuid(),
                        'organization_id' => $organizationId,
                        'employee_id' => $employeeId ?? null,
                        'device_inventory_type_id' => $deviceTypeId ?? null,
                        'vendor_id' => $vendorId ?? null,
                        'purchase_date' => date("Y-m-d", strtotime($input->purchase_date)) ?? getUtcDate(),
                        'is_second_hand' => $input->new_old ?? 0,
                        'device_info_url' => $input->device_info_url ?? null,
                        'manufacturer_company' => $input->manufacturer_company ?? null,
                        'model' => $input->model ?? null,
                        'description' => $input->description ?? null,
                        'os' => $input->os ?? null,
                        'created_at' => $input->created_at ?? null,
                        'updated_at' => $input->updated_at ?? null
                    ];

                    Device::create($deviceData);

                    Prefix::where('module',Prefix::Device)->where('organization_id', $organizationId)->increment('series',1);
                }
            }

            ENDLoop:
            
            DB::commit();
            return $this->sendSuccessResponse(__('messages.device_imported'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while device imported";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function index(Request $request)
    {
        $inputs = $request->all();

        $perPage = !empty($request->perPage) ? $request->perPage : 50;
        $commentPerPage = !empty($request->commentPerPage) ? $request->commentPerPage : 10;

        $organizationId = $this->getCurrentOrganizationId();

        $user = $request->user();

        $permission = Permission::where('name', 'manage_device')->first();
        $prefix = Prefix::where('module',Prefix::Device)->first(['name']);

        $query = Device::withoutGlobalScopes([OrganizationScope::class])->join('vendors', 'vendors.id', '=', 'devices.vendor_id')
            ->leftJoin('employees', function ($join) use ($organizationId) {
                $join->on('devices.employee_id', '=',  'employees.id');
                $join->where('employees.organization_id', $organizationId);
            })        
            ->leftJoin('device_inventory_types','devices.device_inventory_type_id', 'device_inventory_types.id')
            ->select(
                'devices.uuid',
                'devices.id',
                'devices.name',
                'devices.is_second_hand',
                'device_inventory_types.name AS device_type_name',
                'manufacturer_company',
                'device_info_url',
                'vendors.name AS vendor_name',
                'purchase_date',
                'model',
                'os',
                'employees.display_name',
                'employees.id as employee_id'
            );


        if (!empty($inputs['deviceStatus']) && $inputs['deviceStatus'] == 'old') {
            $query = $query->where('devices.is_second_hand', 1);
        }elseif(!empty($inputs['deviceStatus']) && $inputs['deviceStatus'] == 'new'){
            $query = $query->where('devices.is_second_hand', 0);
        }

        if (!empty($inputs['type'])) {
            $deviceType = $inputs['type'];
            $query->whereIn('device_inventory_type_id', $deviceType);
        }

        if (!empty($inputs['keyword'])) {
            $filter = $inputs['keyword'];
            $query = $query->where(function ($q) use ($filter, $prefix) {
                $q->whereRaw('LOWER(employees.display_name) like "%' . $filter . '%"')->orWhereRaw('LOWER(devices.name) like "%' . $filter . '%"')->orWhereRaw('"'.$prefix->name.'"  like "%'. $filter . '%"')->orWhereRaw('concat("'.$prefix->name.'-", devices.name)  like "%'. $filter . '%"');
            });
        }

        if (!$user->hasPermissionTo($permission->id)) {
            $query->where('employee_id', $user->entity_id);
        }
        if (!empty($inputs['check_assign'])){
            $query = $query->whereNull('devices.employee_id');
        }

        $query = $query->where('devices.organization_id', $organizationId);

        $count = $query->count();
 
        $data = $query->latest('devices.id')->simplePaginate($perPage);

        if(!empty($data) && !$user->hasPermissionTo($permission->id)){

            foreach($data as $device){

                $comments = Comment::where('comment_typeid',$this->commentType['Device'])
                        ->where('comment_typeid_id',$device->id)
                        ->select('id','comment', 'created_at')
                        ->orderBy('id', 'desc')->simplePaginate($commentPerPage);

                $device->comments = $comments;
            }
        }
        
     

        $response = [
            'data' => $data,
            'prefix' => $prefix,
            'device_count' => $count
        ];
        
        return $this->sendSuccessResponse(__('messages.success'), 200, $response);
    
    }

    public function getDeviceInformation(Request $request)
    {
        try {
            
            $organizationId = $this->getCurrentOrganizationId();

            $includeModule = !empty($request->include_module) ? $request->include_module : "all";

            $prefix = Prefix::where('module',Prefix::Device)->first(['name', 'series']);
            
            $response = [];

            if ($includeModule == 'all' || in_array('employees', $includeModule)) {

                $employees = Employee::withoutGlobalScopes([OrganizationScope::class])->active()->select('employees.id', 'employees.uuid', 'employees.display_name as name')->where('employees.organization_id', $organizationId)->get();
                $response['employees'] = $employees;
            }

            if ($includeModule == 'all' || in_array('device_types', $includeModule)) {
                $deviceInventory = DeviceInventoryType::select('id as value', 'name as label')->latest('id')->get();
                $response['device_inventory'] = $deviceInventory;
            }

            // if ($includeModule == 'all' || in_array('mobile_types', $includeModule)) {
            //     $mobileTypes = MobileType::select('id', 'name')->latest('id')->get();
            //     $response['mobile_types'] = $mobileTypes;
            // }

            if ($includeModule == 'all' || in_array('vendors', $includeModule)) {

                $vendors = Vendor::join('vendor_types', 'vendors.vendor_type_id', 'vendor_types.id')->where('vendor_types.type', VendorType::IT)->select('vendors.id', 'vendors.name')->latest('vendors.id')->get();
                $response['vendors'] = $vendors;
            }

            $response['prefix'] = $prefix;

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while device information";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }


    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->deviceValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $deviceData = [
                'name' => $inputs['name'],
                'uuid' => getUuid(),
                'organization_id' => $organizationId,
                'employee_id' => $inputs['employee_id'] ?? null,
                'device_inventory_type_id' => $inputs['device_inventory_type_id'] ?? null,
                'vendor_id' => $inputs['vendor_id'] ?? null,
                'purchase_date' => date("Y-m-d", strtotime($inputs['purchase_date'])) ?? getUtcDate(),
                'is_second_hand' => $inputs['is_second_hand'] ?? 0,
                'device_info_url' => $inputs['device_info_url'] ?? null,
                'manufacturer_company' => $inputs['manufacturer_company'] ?? null,
                'model' => $inputs['model'] ?? null,
                'description' => $inputs['description'] ?? null,
                'os' => $inputs['os'] ?? null
            ];

            $device = Device::create($deviceData);

            if(!empty($inputs['employee_id'])){
                $employee = Employee::where('id', $inputs['employee_id'])->select('display_name')->first();

                Comment::create([
                                'comment' => "Assigned to " . $employee->display_name,
                                'organization_id' => $organizationId,
                                'comment_typeid' => $this->commentType['Device'],
                                'comment_typeid_id' => $device->id,
                                'user_id' => $request->user()->id
                            ]);
            }

            Prefix::where('module',Prefix::Device)->where('organization_id', $organizationId)->increment('series',1);

            $info = $deviceData;

            $data = new NewDeviceCreate($info);

            $adminUsers = $this->getAdminUser('create_device');

            if(!empty($adminUsers) && count($adminUsers) > 0){

                $emailData = ['email' => $adminUsers, 'email_data' => $data];

                SendEmailJob::dispatch($emailData);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.device_store'), 200, $device);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add device";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function show($device)
    {
        $device = Device::where('uuid', $device)->first();
        $prefix = Prefix::where('module',Prefix::Device)->first(['name']);
        $device->prefix = $prefix->name;
        return $this->sendSuccessResponse(__('messages.success'), 200, $device);
    }

    public function update(Request $request, $device)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $device = Device::where('uuid', $device)->first();

            $validation = $this->deviceValidator->validateUpdate($request, $organizationId, $device->id);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $this->assignDevice($device, $request, $organizationId);

            $device->update([
                'name' => $inputs['name'],
                'employee_id' => $inputs['employee_id'] ?? null,
                'device_inventory_type_id' => $inputs['device_inventory_type_id'] ?? null,
                'vendor_id' => $inputs['vendor_id'] ?? null,
                'purchase_date' => date("Y-m-d", strtotime($inputs['purchase_date'])) ?? getUtcDate(),
                'is_second_hand' => $inputs['is_second_hand'] ?? 0,
                'device_info_url' => $inputs['device_info_url'] ?? null,
                'manufacturer_company' => $inputs['manufacturer_company'] ?? null,
                'model' => $inputs['model'] ?? null,
                'description' => $inputs['description'] ?? null,
                'os' => $inputs['os'] ?? null
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.device_update'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update system";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    //Assign device to employee or return to admin
    public function assignDeviceToEmployee(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();

            $device = Device::where('uuid', $request->device)->first();
            
            $this->assignDevice($device, $request, $organizationId);

            $device->update([
                'employee_id' => $inputs['employee_id'] ?? null,
            ]);

            DB::commit();

            if(!empty($request->employee_id)){
                $message = __('messages.device_assign');
            }else{
                $message = __('messages.device_return');
            }

            return $this->sendSuccessResponse($message, 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while assign device";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function assignDevice($device, $currentRequest, $organizationId)
    {
        $previousAssignedEmployee = !empty($device->employee_id) ? $device->employee_id : null;

        if(!empty($previousAssignedEmployee) && empty($currentRequest->employee_id)){

            $existingEmployee = Employee::where('id', $previousAssignedEmployee)->select('display_name','id')->first();

            if($currentRequest->user()->id == $existingEmployee->id){
                $commentLine = $currentRequest->user()->display_name." returned device to inventory ";
            }else{
                $commentLine = $currentRequest->user()->display_name. " returned  device of ".$existingEmployee->display_name." to inventory ";
            }

            Comment::create([
                            'comment' => $commentLine,
                            'organization_id' => $organizationId,
                            'comment_typeid' => $this->commentType['Device'],
                            'comment_typeid_id' => $device->id,
                            'user_id' => $currentRequest->user()->id
                        ]);
        }

        $prefix = Prefix::where('module',Prefix::Device)->first(['name']);
        $deviceName = $prefix->name.'-'. $device->name;
        if(!empty($currentRequest->employee_id) && $previousAssignedEmployee != $currentRequest->employee_id){

            $currentUser = $currentRequest->user()->display_name;
            $subject = $currentUser. ' has assigned device '.  $deviceName." to you"; 
            $description = $currentUser. ' has assigned device '.  $deviceName." to you in FOVERO.";

            $employee = Employee::where('id', $currentRequest->employee_id)->select('display_name','id')->first();
            Comment::create([
                            'comment' => $currentUser." has assigned device to " . $employee->display_name,
                            'organization_id' => $organizationId,
                            'comment_typeid' => $this->commentType['Device'],
                            'comment_typeid_id' => $device->id,
                            'user_id' => $currentRequest->user()->id
                        ]);

            $newUser = User::where('entity_id', $employee->id)->whereIn('entity_type_id', [EntityType::Employee, EntityType::Admin])->first(['email', 'entity_id', 'id']);

            $notifications = EmailNotification::where('user_id',$newUser->id)->first(['allow_all_notifications','assign_device']);

            if($notifications->allow_all_notifications == true && $notifications->assign_device == true){
                $info = ['device_name' =>  $deviceName, 'display_name' => $employee->display_name,'subject' => $subject,'description' => $description];
    
                $data = new DeviceAssignedToUser($info);
        
                $emailData = ['email' => $newUser['email'], 'email_data' => $data];
        
                SendEmailJob::dispatch($emailData);
            }
        }

        if(empty($currentRequest->employee_id) && !empty($previousAssignedEmployee)){
            $data = [];
            $users = User::select('id','email','entity_id')->get();
            $permission = Permission::where('name', 'manage_device')->first();

            $currentUser = Employee::where('id', Auth::user()->entity_id)->select('display_name','id')->first();
            $existingEmployee = Employee::where('id', $previousAssignedEmployee)->select('display_name','id')->first();

            if($currentUser->id == $existingEmployee->id) {
                $subject = $currentUser->display_name. ' has returned device '.  $deviceName." to inventory";
                $description = $currentUser->display_name. ' has returned device '.  $deviceName." to inventory in FOVERO";
            }else{
                $subject = $currentUser->display_name. ' has returned device of '.$existingEmployee->display_name .' '. $deviceName." to inventory";
                $description = $currentUser->display_name. ' has returned device of '.$existingEmployee->display_name.' '.  $deviceName." to inventory in FOVERO.";
            }

            foreach ($users as $user) {
          
                if ($user->hasPermissionTo($permission->id)) {
                    $notifications = EmailNotification::where('user_id',$user->id)->first(['allow_all_notifications','assign_device']);

                    if($notifications->allow_all_notifications == true && $notifications->assign_device == true){

                        $info = ['device_name' =>  $deviceName, 'display_name' => $user->display_name, 'subject' => $subject, 'description' => $description];
                
                        $data = new DeviceAssignedToUser($info);
                
                        $emailData = ['email' => $user->email, 'email_data' => $data];
                
                        SendEmailJob::dispatch($emailData);

                    }

                }
            }
        }

        return true;
    }

    public function destroy($device){
        try {
            DB::beginTransaction();

            $user = Auth::user();

            $device = Device::whereNull('employee_id')->where('uuid',$device)->first(['id','uuid','name']);

            if(empty($device)){
                return $this->sendFailResponse(__('messages.delete_system_warning'), 422);
            }
            
            $device->delete();
            
            $info = ['deleted_by' => $user->display_name, 'name' => $device->name];

            $data = new DeleteDevice($info);

            $adminUsers = $this->getAdminUser('delete_device');

            if(!empty($adminUsers) && count($adminUsers) > 0){

                $emailData = ['email' => $adminUsers, 'email_data' => $data];

                SendEmailJob::dispatch($emailData);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.device_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete device";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function storeDeviceType(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->deviceValidator->validateDeviceTypeStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $deviceType = DeviceInventoryType::create([
                'name' => $inputs['name'],
                'organization_id' => $organizationId
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.device_type_store'), 200, $deviceType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add device type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function deleteDeviceType(DeviceInventoryType $deviceType){
        try {
            DB::beginTransaction();

            $device = Device::where('device_inventory_type_id', $deviceType->id)->first();

            if(empty($device)){

                $deviceType->delete();
            }else{
                return $this->sendFailResponse(__('messages.delete_device_warning'), 422);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.device_type_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete device";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }


    public function storeMobileType(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->deviceValidator->validateMobileTypeStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $mobileType = MobileType::create([
                'name' => $inputs['name'],
                'organization_id' => $organizationId
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.mobile_type_store'), 200, $mobileType);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add mobile type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function deleteMobileType(MobileType $mobileType){
        try {
            DB::beginTransaction();

            $device = Device::leftJoin('mobile_devices','devices.id', 'mobile_devices.device_id')->where('mobile_type_id', $mobileType->id)->first();

            if(empty($device)){

                $mobileType->delete();
            }else{
                return $this->sendFailResponse(__('messages.delete_device_warning'), 422);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.mobile_type_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete mobile type";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
