<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Permisos base del sistema, agrupados por módulo.
     * El panel de Roles y Permisos permite crear más permisos sin tocar código.
     */
    private const PERMISOS = [
        'personal.ver',
        'personal.gestionar',
        'asistencia.ver',
        'asistencia.gestionar',
        'reportes.ver',
        'usuarios.gestionar',
        'roles.gestionar',
        'configuracion.gestionar',
    ];

    public function run(): void
    {
        foreach (self::PERMISOS as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        $superAdminRole = config('permission.super_admin_role', 'Super Admin');

        $superAdmin = Role::findOrCreate($superAdminRole, 'web');
        $superAdmin->syncPermissions(Permission::all());

        $admin = User::firstOrNew(['email' => 'admin@gmail.com']);
        $admin->fill([
            'name' => 'Super Administrador',
            'password' => Hash::make('987654321'),
            'email_verified_at' => now(),
        ])->save();

        if (! $admin->hasRole($superAdminRole)) {
            $admin->assignRole($superAdminRole);
        }
    }
}
