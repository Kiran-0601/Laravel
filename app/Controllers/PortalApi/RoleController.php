<?php

namespace App\Http\Controllers\PortalApi;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Traits\ResponseTrait;
use App\Validators\RoleValidator;
use DB, Str;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    private $roleValidator;
    use ResponseTrait;
    function __construct()
    {
        $this->roleValidator = new RoleValidator();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $except = $request->except;
        $role = Role::all();
        if ($except == 'true') {
            $exceptRole = $role->where('slug', 'administrator')->first();
            $role = Role::all()->except($exceptRole->id);
        }

        return $this->sendSuccessResponse(__('messages.success'), 200, $role);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();
            $organizationId = $this->getCurrentOrganizationId();
            $validation = $this->roleValidator->validateStore($request, $organizationId);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }

            $role = Role::create([
                'name' => $request->get('name'),
                'slug' => Str::slug($request->get('name')),
                'organization_id' => $organizationId
            ]);

            // Assign custom permissions to role
            $permissions = $inputs['permissions'] ?? [];
            if (!empty($permissions)) {
                $role->syncPermissions($permissions);
            }

            // Assign permissions from the inherted role
            $inheritRoles = $inputs['inherit_role_from'] ?? [];
            if (!empty($inheritRoles)) {
                foreach ($inheritRoles as $value) {
                    $assignPemission = Role::find($value);
                    $permissions[] = $assignPemission->permissions->pluck('name');
                }
            }

            $role->syncPermissions($permissions);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.roles_store'), 200, $role);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while add role";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getPermissionsFromRole(Request $request)
    {
        $inputs = $request->all();
        $role = $inputs['role'];
        $role = Role::find($role);
        $permissions = [];

        if (!empty($role)) {
            $permissions = $role->permissions;
        }

        if (!empty($permissions)) {
            $role->permission = $permissions->pluck('id');
        }

        $allPermissions = Permission::all();
        $allPermissions = $allPermissions->groupBy('module_name');
        $role->all_permissions = $allPermissions;
        return $this->sendSuccessResponse(__('messages.success'), 200, $role);
    }

    /**
     * Display the specified resource.
     *
     * @param Role $role
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Role $role)
    {
        return $this->respond([
            'data' => $role
        ], 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param Role $role
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Role $role)
    {
        try {
            DB::beginTransaction();
            $inputs = $request->all();

            $validation = $this->roleValidator->validateUpdate($request, $role);

            if ($validation->fails()) {
                return $this->sendFailResponse($validation->errors(), 422);
            }
            $permissions = $inputs['permissions'];

            if (!empty($inputs['name'])) {
                $role->name = $inputs['name'];
                $role->slug = Str::slug($inputs['name']);
                $role->save();
            }
            $role->syncPermissions($permissions);

            DB::commit();

            return $this->sendSuccessResponse(__('messages.roles_update'), 200, $role);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while edit role";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Role $role
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function destroy(Role $role)
    {
        try {
            DB::beginTransaction();

            $organizationId = $this->getCurrentOrganizationId();

            $users = User::with("roles")->whereHas("roles", function($q) use($role, $organizationId) {
                $q->where("id", $role->id)->where('roles.organization_id', $organizationId);
            })->get();

            if(!empty($users) && count($users) > 0){
                return $this->sendSuccessResponse(__('messages.delete_role_warning'), 422); 
            }

            $role->delete();
            DB::commit();

            return $this->sendSuccessResponse(__('messages.roles_delete'), 200, $role);
        } catch (\Throwable $ex) {
            DB::rollback();
            $logMessage = "Something went wrong while delete role";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }

    public function getRoles()
    {
        $role = Role::select('id', 'name')->get();
        return $this->sendSuccessResponse(__('messages.success'), 200, $role);
    }

    public function getOldRolePermissionData(Request $request){
        DB::beginTransaction();
        try {
            $roles = DB::connection('old_connection')->table('roles')->whereNull('deleted_at')->where('id','>',1)->get();
            $organizationId = $request->organization_id;

            $oldPermissionMap = [['view_dashboard' => 'view_dashboard'],
                                 ['view_employee' => 'view_employee'],
                                 ['edit_employee' => 'edit_employee'],
                                 ['view_employee' => 'delete_employee'],
                                 ['view_employee' => 'export_employee'],
                                 ['view_employee' => 'import_employee'],
                                 ['view_employee' => 'view_import_history'],
                                 ['view_employee' => 'rollback_import'],
                                 ['view_employee' => 'invited_employee_list'],
                                 ['view_employee' => 'delete_invited_employee'],
                                 ['view_roles' => 'view_role'],
                                 ['add_new_role' => 'create_role'],
                                 ['edit_role' => 'edit_role'],
                                 ['edit_role' => 'delete_role'],
                                 ['view_customer' => 'view_customer'],
                                 ['edit_customer' => 'create_customer'],
                                 ['edit_customer' => 'edit_customer'],
                                 ['edit_customer' => 'delete_customer'],
                                 ['view_projects' => 'view_project'],
                                 ['add_new_project' => 'create_project'],
                                 ['edit_project' => 'edit_project'],
                                 ['edit_project' => 'delete_project'],
                                 ['edit_project' => 'assign_project'],
                                 ['timesheet_dashboard' => 'view_timesheet_dashboard'],
                                 ['timesheet' => 'create_manage_timesheet'],
                                 ['missed_employee_hours' => 'create_manage_timesheet'],
                                 ['export_timesheet' => 'create_manage_timesheet'],
                                 ['export_timesheet' => 'invoice_timesheet'],
                                 ['view_work_report' => 'resource_report']
        ];

            $oldPermissionMap = array_merge(...$oldPermissionMap);
            foreach ($roles as $role) {

                $rolePermissions = DB::connection('old_connection')->table('roles_permissions')->where('role_id', $role->id)->get(['permissions_id']);
                $oldPermissions = [];
                foreach($rolePermissions as $permission){
                    $oldPermission = DB::connection('old_connection')->table('permissions')->where('id', $permission->permissions_id)->first(['slug']);
                  
                    $oldPermissions[] = $oldPermission->slug;
                   
                }
               
                $currentRole = Role::where('slug','LIKE', $role->slug)->where('organization_id', $organizationId)->first();
                
                if(!empty($currentRole)){
                    $currentRolePermission = $currentRole->permissions->pluck('name')->toArray();
                    $permissions = [];

                    foreach($oldPermissions as $oldPermission){
                       
                        if(!empty($oldPermissionMap[$oldPermission])){
                            $permissions[] =  $oldPermissionMap[$oldPermission];
                        }
                    }

                    $allPermissions = array_merge($permissions, $currentRolePermission);

                    $currentRole->syncPermissions($allPermissions);

                    $roleModel = DB::connection('old_connection')->table('user_role')->where('role_id', $role->id)->get(['user_id']);

                    foreach($roleModel as $model){
                        $user = DB::connection('old_connection')->table('users')->where('id', $model->user_id)->first(['email']);
                        
                        $user = User::where('email', $user->email)->first();
                        if(!empty($user)){
                            $user->assignRole($currentRole);
                        }
                    
                    }
               }
                
            }

            DB::commit();
            return $this->sendSuccessResponse(__('messages.role_imported'), 200);
        } catch (\Throwable $ex) {
            DB::rollBack();
            $logMessage = "Something went wrong while import customer";
            return $this->sendServerFailResponse(__('messages.exception_msg'), 500, $ex, $logMessage);
        }
    }
}
