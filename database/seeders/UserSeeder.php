<?php

namespace Database\Seeders;

use App\Models\Auth\Permission;
use App\Models\Auth\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Model::unguard();
        $guard = 'web';

        $roles = [
            'Administrator' => [
                'Access Reports',
                'Assume Users',
                'Manage Users',
                'Manage Roles and Permissions',
                'Manage Settings',
                // more permissions
            ],
            'User' => []
        ];

        $createdPermissions = [];
        foreach($roles as $role => $permissions) {
            $rolePermissions = [];
            foreach($permissions as $permission) {
                if(!isset($createdPermissions[$permission])) {
                    $createdPermissions[$permission] = Permission::updateOrCreate([
                        'name'       => $permission,
                        'guard_name' => $guard,
                    ], []);
                }
                $rolePermissions[] = $createdPermissions[$permission];
            }

            $createdRole = Role::updateOrCreate([
                'name'       => $role,
                'guard_name' => $guard,
            ]);

            $createdRole->permissions()->sync(collect($rolePermissions)->pluck('id'));

            if($role != 'Administrator') continue;

            $user = User::updateOrCreate([
                'username' => $role,
            ], [
                'name'              => $role,
                'email'             => strtolower($role) . '@example.com',
                'password'          => bcrypt(strtolower($role)),
                'email_verified_at' => now(),
            ]);

            $user->assignRole($createdRole);
        }

        Model::reguard();
    }
}
