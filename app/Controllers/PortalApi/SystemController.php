<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailJob;
use App\Mail\DeleteDevice;
use App\Mail\DeviceAssignedToUser;
use App\Mail\NewSystemCreate;
use App\Models\Comment;
use App\Models\CommentType;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Prefix;
use App\Models\Scopes\OrganizationScope;
use App\Models\System;
use App\Models\SystemHDDType;
use App\Models\SystemInventoryType;
use App\Models\SystemMonitorType;
use App\Models\SystemOSType;
use App\Models\SystemProcessorType;
use App\Models\SystemRAMType;
use App\Models\SystemSSDType;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Traits\ResponseTrait;
use App\Validators\PrefixValidator;
use App\Validators\SystemValidator;
use Auth;
use DB;
use Illuminate\Http\Request;

class SystemController extends Controller
{
    use ResponseTrait;
    private $systemValidator;
    private $prefixValidator;

    private $commentType;

    function __construct()
    {
        $this->systemValidator = new SystemValidator();
        $this->prefixValidator = new PrefixValidator();

        $this->commentType = CommentType::select('id', 'type')->get()->pluck('id','type')->toArray();
    }

    public function getSystemOldData()
    {
        DB::beginTransaction();
        try {
            $systems = DB::connection('old_connection')->table('system_inventory')->get();

            $organizationId = 1;

            $data = [
                'module' => Prefix::System,
                'organization_id' => $organizationId,
                'name' => 'CL-SYS',
                'series' => 1
            ];

            $data = Prefix::create($data);

            $vendors = DB::connection('old_connection')->table('it_vendor')->get();

            if(!empty($vendors)){
                foreach($vendors as $vendor){

                    Vendor::create([
                        'uuid' => getUuid(),
                        'name' => $vendor->name,
                        'email' => $vendor->email,
                        'address' => $vendor->address ?? null,
                        'gst_no' => $vendor->gst_no ?? null,
                        'vendor_type_id' => 2,
                        'organization_id' => $organizationId
                    ]);
                }
            }

            if (!empty($systems)) {
                foreach ($systems as $input) {
                    $exist = System::where('name', $input->computer_name)->where('organization_id', $organizationId)->first(['id']);
                    if(!empty($exist)){
                        if(end($systems) == $input) {
                            // last iteration
                            GoTo ENDLoop;
                        }
                        continue;
                    }
 
                    $typeName = DB::connection('old_connection')->table('system_inventory_type')->where('id', $input->system_inventory_type_id)->first(['name']);
                    if (!empty($typeName)) {
                        $inventoryType = SystemInventoryType::where('name', 'LIKE', $typeName->name)->where('organization_id',$organizationId)->first(['id']);
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

                    
                    $processorId = null;
                    if(!empty($input->system_inventory_processor_id)){
                        $processor = DB::connection('old_connection')->table('system_inventory_processor')->where('id', $input->system_inventory_processor_id)->first(['name']);
                        if(!empty($processor)){
                            $processor= SystemProcessorType::where('name', 'LIKE', $processor->name)->where('organization_id',$organizationId)->first(['id']);
                            $processorId = $processor->id;
                        }
                    }


                    $ramId = null;
                    if(!empty($input->system_inventory_ram_id)){
                        $ram = DB::connection('old_connection')->table('system_inventory_ram')->where('id', $input->system_inventory_ram_id)->first(['name']);
                        if(!empty($ram)){
                            $ram = SystemRAMType::where('name', 'LIKE', $ram->name)->where('organization_id',$organizationId)->first(['id']);
                            $ramId = $ram->id;
                        }
                    }

                    $hddId = null;
                    if(!empty($input->system_inventory_hdd_id)){
                        $hdd = DB::connection('old_connection')->table('system_inventory_hdd')->where('id', $input->system_inventory_hdd_id)->first(['name']);
                        if(!empty($hdd)){
                            $hdd = SystemHDDType::where('name', 'LIKE', $hdd->name)->where('organization_id',$organizationId)->first(['id']);
                            $hddId = $hdd->id;
                        }
                    }

                    $ssdId = null;
                    if(!empty($input->system_inventory_ssd_id)){
                        $ssd = DB::connection('old_connection')->table('system_inventory_ssd')->where('id', $input->system_inventory_ssd_id)->first(['name']);
                        if(!empty($ssd)){
                            $ssd = SystemSSDType::where('name', 'LIKE', $ssd->name)->where('organization_id',$organizationId)->first(['id']);
                            $ssdId = $ssd->id;
                        }
                    }

                    $osId = null;
                    if(!empty($input->system_inventory_operating_system_id)){
                        $os = DB::connection('old_connection')->table('system_inventory_operating_system')->where('id', $input->system_inventory_operating_system_id)->first(['name']);
                        if(!empty($os)){
                            $os = SystemOSType::where('name', 'LIKE', $os->name)->where('organization_id',$organizationId)->first(['id']);
                            $osId = $os->id;
                        }
                    }

                    $monitorId = null;
                    if(!empty($input->system_inventory_monitor_id)){
                        $monitor = DB::connection('old_connection')->table('system_inventory_monitor')->where('id', $input->system_inventory_monitor_id)->first(['name']);
                        if(!empty($monitor)){
                            $monitor = SystemMonitorType::where('name', 'LIKE', $monitor->name)->where('organization_id',$organizationId)->first(['id']);
                            $monitorId = $monitor->id;
                        }
                    }

                    $name = str_replace('CLSS-', '', $input->computer_name);

                    $systemData = [
                        'name' => $name,
                        'uuid' => getUuid(),
                        'organization_id' => $organizationId,
                        'employee_id' => $employeeId ?? null,
                        'system_inventory_type_id' =>  $inventoryType->id ?? null,
                        'vendor_id' => $vendorId ?? null,
                        'purchase_date' => date("Y-m-d", strtotime($input->purchase_date)) ?? getUtcDate(),
                        'mother_board' => $input->mother_board ?? null,
                        'cabinet' => $input->cabinet ?? null,
                        'system_processor_type_id' => $processorId ?? null,
                        'system_ram_type_id' => $ramId ?? null,
                        'system_hdd_type_id' => $hddId ?? null,
                        'system_ssd_type_id' => $ssdId  ?? null,
                        'system_os_type_id' => $osId ?? null,
                        'ms_office' => !empty($input->ms_office) ? 1 : 0,
                        'visual_studio' => !empty($input->visual_studio) ? 1 : 0,
                        'keyboard' => !empty($input->system_inventory_keyboard_id) ? 1 : 0,
                        'mouse' => !empty($input->system_inventory_mouse_id) ? 1 : 0,
                        'using_temporary' => $input->using_temporary ?? 0,
                        'is_second_hand' => $input->new_old == 1 ? 0 : 1,
                        'system_monitor_type_id' => $monitorId ?? null,
                        'created_at' => $input->created_at,
                        'updated_at' => $input->updated_at
                    ];

                    $system = System::create($systemData);

                    Prefix::where('module',Prefix::System)->where('organization_id', $organizationId)->increment('series',1);

                    $comments = DB::connection('old_connection')->table('system_comment')->where('system_id', $input->id)->get();

                    foreach($comments as $comment){

                       $comment = DB::connection('old_connection')->table('comment')->where('id', $comment->comment_id)->first();

                       $userId = 2;
                        if(!empty($comment->user_id)){
                            $user = DB::connection('old_connection')->table('users')->where('id', $comment->user_id)->first(['email']);

                            $newUser = User::where('email', $user->email)->first('id');
                           
                            $userId = $newUser->id;
                        }

                        if(!empty($comment->comment_text)){
                            Comment::create([
                                'comment' => $comment->comment_text ?? null,
                                'organization_id' => $organizationId,
                                'comment_typeid' => 1,
                                'comment_typeid_id' => $system->id,
                                'user_id' => $userId,
                                'created_at' => $comment->created_at,
                                'updated_at' => $comment->updated_at
                            ]);
                        }

                    }
                }
            }

            ENDLoop:
            
            DB::commit();
            return $this->sendSuccessResponse(__('messages.system_imported'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while system imported";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function index(Request $request)
    {
        $inputs = $request->all();

        $perPage = !empty($request->perPage) ? $request->perPage : 50;

        $organizationId = $this->getCurrentOrganizationId();

        $user = $request->user();

        $permission = Permission::where('name', 'manage_system')->first();
        $prefix = Prefix::where('module',Prefix::System)->first(['name']);

        $query = System::withoutGlobalScopes([OrganizationScope::class])->join('vendors', 'vendors.id', '=', 'systems.vendor_id')
            ->leftJoin('employees', function ($join) use ($organizationId) {
                $join->on('systems.employee_id', '=',  'employees.id');
                $join->where('employees.organization_id', $organizationId);
            })        
            ->leftJoin('system_inventory_types','systems.system_inventory_type_id', 'system_inventory_types.id')
            ->leftJoin('system_processor_types', 'systems.system_processor_type_id', 'system_processor_types.id')
            ->leftJoin('system_ram_types', 'systems.system_ram_type_id', '=', 'system_ram_types.id')
            ->leftJoin('system_hdd_types', 'systems.system_hdd_type_id', '=', 'system_hdd_types.id')
            ->leftJoin('system_ssd_types', 'systems.system_ssd_type_id', '=', 'system_ssd_types.id')
            ->leftJoin('system_os_types', 'systems.system_os_type_id', '=', 'system_os_types.id')
            ->leftJoin('system_monitor_types', 'systems.system_monitor_type_id', '=', 'system_monitor_types.id')
            ->select(
                'systems.id',
                'systems.uuid',
                'systems.employee_id',
                'systems.name',
                'systems.is_second_hand',
                'system_inventory_types.name AS system_type_name',
                'system_processor_types.name AS system_processor_type_name',
                'system_ram_types.name AS system_ram_type_name',
                'system_hdd_types.name AS system_hdd_type_name',
                'system_ssd_types.name AS system_ssd_type_name',
                'system_os_types.name AS system_os_type_name',
                'system_monitor_types.name AS system_monitor_type_name',
                'keyboard',
                'mouse',
                'vendors.name AS vendor_name',
                'purchase_date',
                'mother_board',
                'using_temporary',
                'ms_office',
                'visual_studio',
                'cabinet',
                'employees.display_name',
                DB::raw('(CASE WHEN systems.employee_id IS NULL OR systems.using_temporary = 1 THEN "'.System::AVIALBLESYSTEMCOLOR.'" ELSE "" END) AS bgcolor')

            );
        if (!empty($inputs['available'])) {
            $query = $query->where(function ($q) {
                $q->where('systems.employee_id', '=', null)->orWhere('systems.using_temporary', '=', 1);
            });
        }

        if (!empty($inputs['type'])) {
            $systemType = $inputs['type'];
            $query->whereIn('system_inventory_type_id', $systemType);
        }

        if (!empty($inputs['systemStatus']) && $inputs['systemStatus'] == 'old') {
            $query = $query->where('systems.is_second_hand', 1);
        }elseif(!empty($inputs['systemStatus']) && $inputs['systemStatus'] == 'new'){
            $query = $query->where('systems.is_second_hand', 0);
        }

        if (!empty($inputs['keyword'])) {
            $filter = $inputs['keyword'];
            $query = $query->where(function ($q) use ($filter, $prefix) {
                $q->whereRaw('LOWER(employees.display_name) like "%' . $filter . '%"')->orWhereRaw('LOWER(systems.name) like "%' . $filter . '%"')->orWhereRaw('"'.$prefix->name.'"  like "%'. $filter . '%"')->orWhereRaw('concat("'.$prefix->name.'-", systems.name)  like "%'. $filter . '%"');
            });
        }

        if (!$user->hasPermissionTo($permission->id)) {
            $query->where('employee_id', $user->entity_id);
        }

        $query = $query->where('systems.organization_id',$organizationId)->orderBy('systems.id', 'desc');
        if (!empty($inputs['multiple_systems'])) {
            $currentEmployee = clone $query;
            $employeeList = clone $query;
            $currentEmployee = $currentEmployee->groupBy('employee_id')->having( DB::raw('COUNT(employee_id)'),'>',1)->get()->pluck('employee_id');
            $employeeList->whereIn('systems.employee_id', $currentEmployee)->orderBy('employees.display_name', 'ASC');
            $count = $employeeList->count();
            $data = $employeeList->simplePaginate($perPage);
        } else {
            $count = $query->count();
            $data = $query->simplePaginate($perPage);
        }

        if(!empty($data) && !$user->hasPermissionTo($permission->id)){

            foreach($data as $sys){
                $comments = Comment::where('comment_typeid', $this->commentType['System'])
                        ->where('comment_typeid_id',$sys->id)
                        ->select('id','comment', 'created_at')
                        ->orderBy('id', 'desc')->get();

                $sys->comments = $comments;
            }
        }

        $response = [
            'data' => $data,
            'prefix' => $prefix,
            'system_count' => $count
        ];
        
        return $this->sendSuccessResponse(__('messages.success'), 200, $response);
    
    }

    public function getSystemInformation(Request $request)
    {
        try {
            $organizationId = $this->getCurrentOrganizationId();

            $includeModule = !empty($request->include_module) ? $request->include_module : "all";

            $prefix = Prefix::where('module',Prefix::System)->first(['name', 'series']);
            
            $response = [];

            if($includeModule == 'all' || in_array('employees',$includeModule)){
                $employees = Employee::withoutGlobalScopes([OrganizationScope::class])->active()->select('employees.id', 'employees.uuid', 'employees.display_name as name')->where('employees.organization_id', $organizationId)->get();
                $response['employees'] = $employees;
            }

            if ($includeModule == 'all' || in_array('inventory_types', $includeModule)) {
            
                $inventoryType = SystemInventoryType::select('id as value', 'name as label')->latest('id')->get();
                $response['inventory_type'] = $inventoryType;
            }

            if ($includeModule == 'all' || in_array('vendors', $includeModule)) {
                $vendors = Vendor::join('vendor_types', 'vendors.vendor_type_id', 'vendor_types.id')->where('vendor_types.type', VendorType::IT)->select('vendors.id', 'vendors.name')->latest('vendors.id')->get();
                $response['vendors'] = $vendors;
            }

            if ($includeModule == 'all' || in_array('processors', $includeModule)) {
                $processors = SystemProcessorType::select('id as value', 'name as label')->latest('id')->get();
                $response['processors'] = $processors;
            }

            if ($includeModule == 'all' || in_array('rams', $includeModule)) {
                $rams = SystemRAMType::select('id as value', 'name as label')->latest('id')->get();
                $response['rams'] = $rams;
            }

            if ($includeModule == 'all' || in_array('hdd', $includeModule)) {
                $hdd = SystemHDDType::select('id as value', 'name as label')->latest('id')->get();
                $response['hdd'] = $hdd;
            }

            if ($includeModule == 'all' || in_array('ssd', $includeModule)) {
                $ssd = SystemSSDType::select('id as value', 'name as label')->latest('id')->get();
                $response['ssd'] = $ssd;
            }

            if ($includeModule == 'all' || in_array('os', $includeModule)) {
                $operatingSystem = SystemOSType::select('id as value', 'name as label')->latest('id')->get();
                $response['operating_system'] = $operatingSystem;
            }

            if ($includeModule == 'all' || in_array('monitor', $includeModule)) {
                $monitors = SystemMonitorType::select('id as value', 'name as label')->latest('id')->get();
                $response['monitors'] = $monitors;
            }

            $response['prefix'] = $prefix;

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while system information";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function storePrefix(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->prefixValidator->validateStore($request);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $module = '';
            if($inputs['module'] == Prefix::System){
                $module = Prefix::System;
            }

            if($inputs['module'] == Prefix::Device){
                $module = Prefix::Device;
            }

            $data = [
                'module' => $module,
                'organization_id' => $organizationId,
                'name' => $inputs['name'],
                'series' => 1
            ];

            $data = Prefix::create($data);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add system";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getItadminSettings(Request $request)
    {
        try {

            $systemPrefix = Prefix::where('module',Prefix::System)->first(['name']);
            $devicePrefix = Prefix::where('module',Prefix::Device)->first(['name']);

            $data['system_prefix'] = !empty($systemPrefix->name) ? $systemPrefix->name : '';
            $data['device_prefix'] = !empty($devicePrefix->name) ? $devicePrefix->name : '';

            return $this->sendSuccessResponse(__('messages.success'), 200, $data);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while get IT Admin settings";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
       
    }

    public function updatePrefix(Request $request)
    {
        try {

            DB::beginTransaction();

            $organizationId = $this->getCurrentOrganizationId();

            if(!empty($request->device_prefix)){

                $request->merge(['name' => $request->device_prefix]);

                $validation = $this->prefixValidator->validateStore($request);

                if ($validation->fails()) {
                    return $this->sendFailResponse($validation->errors(), 422);
                }
                
                $prefix = Prefix::where('module', Prefix::Device)->first();
                if(!empty($prefix)){
                    Prefix::where('module', Prefix::Device)->update(['name' => $request->device_prefix]);
                }else{
                    $data = [
                        'module' => Prefix::Device,
                        'organization_id' => $organizationId,
                        'name' => $request->device_prefix,
                        'series' => 1
                    ];
        
                    $data = Prefix::create($data);
                }
            }

            if(!empty($request->system_prefix)){
                
                $request->merge(['name' => $request->system_prefix]);

                $validation = $this->prefixValidator->validateStore($request);

                if ($validation->fails()) {
                    return $this->sendFailResponse($validation->errors(), 422);
                }

                $prefix = Prefix::where('module', Prefix::System)->first();
                if(!empty($prefix)){
                    Prefix::where('module', Prefix::System)->update(['name' => $request->system_prefix]);
                }else{
                    $data = [
                        'module' => Prefix::System,
                        'organization_id' => $organizationId,
                        'name' => $request->system_prefix,
                        'series' => 1
                    ];
        
                    $data = Prefix::create($data);
                }
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.success'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while update prefix";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
       
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();
            
            $validation = $this->systemValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $systemData = [
                'name' => $inputs['name'],
                'uuid' => getUuid(),
                'organization_id' => $organizationId,
                'employee_id' => $inputs['employee_id'] ?? null,
                'system_inventory_type_id' => $inputs['system_inventory_type_id'] ?? null,
                'vendor_id' => $inputs['vendor_id'] ?? null,
                'purchase_date' => date("Y-m-d", strtotime($inputs['purchase_date'])) ?? getUtcDate(),
                'mother_board' => $inputs['mother_board'] ?? null,
                'cabinet' => $inputs['cabinet'] ?? null,
                'system_processor_type_id' => $inputs['system_processor_type_id'] ?? null,
                'system_ram_type_id' => $inputs['system_ram_type_id'] ?? null,
                'system_hdd_type_id' => $inputs['system_hdd_type_id'] ?? null,
                'system_ssd_type_id' => $inputs['system_ssd_type_id'] ?? null,
                'system_os_type_id' => $inputs['system_os_type_id'] ?? null,
                'ms_office' => $inputs['ms_office'] ?? 0,
                'visual_studio' => $inputs['visual_studio'] ?? 0,
                'keyboard' => $inputs['keyboard'] ?? 0,
                'mouse' => $inputs['mouse'] ?? 0,
                'using_temporary' => $inputs['using_temporary'] ?? 0,
                'is_second_hand' => $inputs['is_second_hand'] ?? 0,
                'system_monitor_type_id' => $inputs['system_monitor_type_id'] ?? null,
                'description' => $inputs['description'] ?? null
            ];

            $system = System::create($systemData);

            if(!empty($inputs['employee_id'])){
                $employee = Employee::where('id', $inputs['employee_id'])->select('display_name')->first();

                Comment::create([
                                'comment' => "Assigned to " . $employee->display_name,
                                'organization_id' => $organizationId,
                                'comment_typeid' => $this->commentType['System'],
                                'comment_typeid_id' => $system->id,
                                'user_id' => $request->user()->id
                            ]);
            }

            Prefix::where('module',Prefix::System)->where('organization_id', $organizationId)->increment('series',1);

            $info = $systemData;

            $data = new NewSystemCreate($info);

            $adminUsers = $this->getAdminUser('create_system');

            if(!empty($adminUsers) && count($adminUsers) > 0){  

                $emailData = ['email' => $adminUsers, 'email_data' => $data];

                SendEmailJob::dispatch($emailData);
            }

            DB::commit();

            return $this->sendSuccessResponse(__('messages.system_store'), 200, $system);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add system";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function show($system)
    {
        $system = System::where('uuid', $system)->first();
        $prefix = Prefix::where('module',Prefix::System)->first(['name']);
        $system->prefix = $prefix->name;
        return $this->sendSuccessResponse(__('messages.success'), 200, $system);
    }

    public function update(Request $request, $system)
    {
        try {
            DB::beginTransaction();

            $inputs = $request->all();
           
            $organizationId = $this->getCurrentOrganizationId();

            $system = System::where('uuid', $system)->first();
            
            $validation = $this->systemValidator->validateUpdate($request, $organizationId, $system->id);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            //Assign system to employee
            $this->assignSystem($system, $request, $organizationId);

            $system->update([
                'name' => $inputs['name'],
                'employee_id' => $inputs['employee_id'] ?? null,
                'system_inventory_type_id' => $inputs['system_inventory_type_id'] ?? null,
                'vendor_id' => $inputs['vendor_id'] ?? null,
                'purchase_date' => date("Y-m-d", strtotime($inputs['purchase_date'])) ?? getUtcDate(),
                'mother_board' => $inputs['mother_board'] ?? null,
                'cabinet' => $inputs['cabinet'] ?? null,
                'system_processor_type_id' => $inputs['system_processor_type_id'] ?? null,
                'system_ram_type_id' => $inputs['system_ram_type_id'] ?? null,
                'system_hdd_type_id' => $inputs['system_hdd_type_id'] ?? null,
                'system_ssd_type_id' => $inputs['system_ssd_type_id'] ?? null,
                'system_os_type_id' => $inputs['system_os_type_id'] ?? null,
                'ms_office' => $inputs['ms_office'] ?? 0,
                'visual_studio' => $inputs['visual_studio'] ?? 0,
                'keyboard' => $inputs['keyboard'] ?? 0,
                'mouse' => $inputs['mouse'] ?? 0,
                'using_temporary' => $inputs['using_temporary'] ?? 0,
                'is_second_hand' => $inputs['is_second_hand'] ?? 0,
                'system_monitor_type_id' => $inputs['system_monitor_type_id'] ?? null,
                'description' => $inputs['description'] ?? null
            ]);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.system_update'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while update system";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function assignSystem($system, $currentRequest, $organizationId)
    {
        $previousAssignedEmployee = $system->employee_id;

        if(!empty($previousAssignedEmployee) && empty($currentRequest->employee_id)){

            $existingEmployee = Employee::where('id', $previousAssignedEmployee)->select('display_name','id')->first();

            Comment::create([
                            'comment' => $currentRequest->user()->display_name. " returned ".$existingEmployee->display_name."'s system to inventory ",
                            'organization_id' => $organizationId,
                            'comment_typeid' => $this->commentType['System'],
                            'comment_typeid_id' => $system->id,
                            'user_id' => $currentRequest->user()->id
                        ]);
        }

        $prefix = Prefix::where('module',Prefix::System)->first(['name']);
        $systemName = $prefix->name.'-'. $system->name;
        if(!empty($currentRequest->employee_id) && $previousAssignedEmployee != $currentRequest->employee_id){

            $currentUser = $currentRequest->user()->display_name;

            $employee = Employee::where('id', $currentRequest->employee_id)->select('display_name', 'id')->first();
            $subject = $currentUser. ' has assigned system '.  $systemName." to you"; 
            $description = $currentUser. ' has assigned system '.  $systemName." to you in FOVERO.";

            Comment::create([
                            'comment' => $currentUser." has assigned system to " . $employee->display_name,
                            'organization_id' => $organizationId,
                            'comment_typeid' => $this->commentType['System'],
                            'comment_typeid_id' => $system->id,
                            'user_id' => $currentRequest->user()->id
                        ]);
         
            $newUser = User::where('entity_id', $employee->id)->first(['email', 'entity_id']);

            $info = ['device_name' => $systemName, 'display_name' => $employee->display_name, 'subject' => $subject,'description' => $description];
    
            $data = new DeviceAssignedToUser($info);
    
            $emailData = ['email' => $newUser['email'], 'email_data' => $data];
    
            SendEmailJob::dispatch($emailData);
        }

        if(empty($currentRequest->employee_id) && !empty($previousAssignedEmployee)){
            $data = [];
            $users = User::select('id','email','entity_id')->get();
            $permission = Permission::where('name', 'manage_system')->first();
            $currentUser = Employee::where('id', Auth::user()->entity_id)->select('display_name','id')->first();
            $existingEmployee = Employee::where('id', $previousAssignedEmployee)->select('display_name','id')->first();
            
            $subject = $currentUser->display_name. ' has returned '.$existingEmployee->display_name.' \'s system '.  $systemName." to inventory";
            $description = $currentUser->display_name. ' has returned '.$existingEmployee->display_name.' \'s system '.  $systemName." to inventory in FOVERO.";
            foreach ($users as $user) {
        
                if ($user->hasPermissionTo($permission->id)) {

                    $info = ['device_name' => $systemName, 'display_name' => $user->display_name, 'subject' => $subject,'description' => $description];
            
                    $data = new DeviceAssignedToUser($info);
            
                    $emailData = ['email' => $user->email, 'email_data' => $data];
            
                    SendEmailJob::dispatch($emailData);

                }
            }
        }

        return true;
    }

    public function destroy($system){
        try {
            DB::beginTransaction();

            $user = Auth::user();

            $system = System::whereNull('employee_id')->where('uuid',$system)->first(['id','uuid','name']);

            if(empty($system)){
                return $this->sendFailResponse(__('messages.delete_system_warning'), 422);
            }
            
            $system->delete();
            
            $info = ['deleted_by' => $user->display_name, 'name' => $system->name];

            $data = new DeleteDevice($info);

            $adminUsers = $this->getAdminUser('delete_system');

            if(!empty($adminUsers) && count($adminUsers) > 0){

                $emailData = ['email' => $adminUsers, 'email_data' => $data];

                SendEmailJob::dispatch($emailData);
            }

            DB::commit();
            return $this->sendSuccessResponse(__('messages.system_delete'), 200);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete system";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getEmployeesWithoutSystem(Request $request)
    {
        try {
            $organizationId = $this->getCurrentOrganizationId();
            $employees = Employee::withoutGlobalScopes([OrganizationScope::class])->active()
            ->leftJoin('systems', function($join) use($organizationId){
                $join->on('systems.employee_id', '=', 'employees.id');
                $join->where('systems.organization_id', $organizationId);
            })
            ->select('display_name')
            ->whereNull('systems.id')
            ->where('employees.organization_id',$organizationId)
            ->groupBy('employees.id')
            ->get();

            return $this->sendSuccessResponse(__('messages.success'), 200, $employees);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while fetch employees without system";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
       
    }

    public function getItadminDashboard(Request $request)
    {
        try {
            $user = $request->user(); 

            $systemPermission = Permission::where('name', 'manage_system')->first();
    
            $query =  System::query();

            if (!$user->hasPermissionTo($systemPermission->id)) {
                $query->where('employee_id', $user->entity_id);
            }

            $systemCount = $query->count();

            $devicePermission = Permission::where('name', 'manage_device')->first();
            
            $query = Device::query();

            if (!$user->hasPermissionTo($devicePermission->id)) {
                $query->where('employee_id', $user->entity_id);
            }

            $deviceCount = $query->count();

            $response = ['system' => $systemCount, 'device' => $deviceCount];

            return $this->sendSuccessResponse(__('messages.success'), 200, $response);
        } catch (\Throwable $ex) {
            $logMessage = "Something went wrong while fetch it admin dashboard";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
