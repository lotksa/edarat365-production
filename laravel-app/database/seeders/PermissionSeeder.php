<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::all() as $key => $meta) {
            Permission::query()->updateOrCreate(['key' => $key], [
                'group_key' => $meta['group_key'],
                'group_name_ar' => $meta['group_name_ar'],
                'group_name_en' => $meta['group_name_en'],
                'name_ar' => $meta['name_ar'],
                'name_en' => $meta['name_en'],
            ]);
        }

        foreach (PermissionCatalog::defaultRoles() as $roleData) {
            $role = Role::query()->updateOrCreate(['key' => $roleData['key']], [
                'name_ar' => $roleData['name_ar'],
                'name_en' => $roleData['name_en'],
                'description_ar' => $roleData['description_ar'] ?? null,
                'description_en' => $roleData['description_en'] ?? null,
                'color' => $roleData['color'] ?? '#021B4A',
                'is_system' => $roleData['is_system'] ?? false,
                'is_active' => true,
            ]);
            $role->syncPermissionKeys(array_values($roleData['permissions']));
        }

        // Link existing admin user to super_admin role
        $superAdmin = Role::where('key', 'super_admin')->first();
        if ($superAdmin) {
            $admin = User::where('role', 'admin')->orWhere('email', 'admin@edarat365.local')->first();
            if ($admin) {
                $admin->role_id = $superAdmin->id;
                $admin->is_active = true;
                $admin->save();
            }
        }
    }
}
