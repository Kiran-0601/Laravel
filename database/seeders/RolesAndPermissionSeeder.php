<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

class RolesAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        $permissions = [
            'view-role','add-role','edit-role','delete-role','view-user','add-user','edit-user','delete-user','view-feedback-type',
            'add-feedback-type','edit-feedback-type','delete-feedback-type','view-admin-user','add-admin-user','edit-admin-user','delete-admin-user',
        ];
        $roles = [
            'Admin',
        ];
        foreach ($permissions as $permissionName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName]);
            foreach ($roles as $roleName) {
                $role = Role::firstOrCreate(['guard_name' => 'web', 'name' => $roleName]);
                $role->givePermissionTo($permission);
            }
        }
        $user = User::where('email', 'admin@gmail.com')->first();
        if ($user) {
            $user->assignRole('Admin');
        }
    }
}
