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
     * Permisos base del sistema. El panel de Roles y Permisos permite crear más sin tocar código.
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

    /**
     * Roles iniciales. Solo se crean si no existen: lo que luego se edite desde el panel no se pisa.
     */
    private const ROLES_BASE = [
        'Administrador' => self::PERMISOS,
        'Registrador' => ['personal.ver', 'personal.gestionar', 'asistencia.ver', 'asistencia.gestionar', 'reportes.ver'],
        'Consulta' => ['personal.ver', 'asistencia.ver', 'reportes.ver'],
    ];

    public function run(): void
    {
        foreach (self::PERMISOS as $permiso) {
            Permission::findOrCreate($permiso, 'web');
        }

        $superAdminRole = config('permission.super_admin_role', 'Super Admin');

        $superAdmin = Role::findOrCreate($superAdminRole, 'web');
        $superAdmin->syncPermissions(Permission::all());

        foreach (self::ROLES_BASE as $nombre => $permisos) {
            $rol = Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);

            if ($rol->wasRecentlyCreated) {
                $rol->syncPermissions($permisos);
            }
        }

        // La contraseña inicial solo se define al crear la cuenta; volver a sembrar no la restablece.
        $admin = User::firstOrNew(['email' => 'admin@gmail.com']);

        if (! $admin->exists) {
            $admin->fill([
                'name' => 'Super Administrador',
                'password' => Hash::make('987654321'),
                'email_verified_at' => now(),
            ])->save();
        }

        if (! $admin->hasRole($superAdminRole)) {
            $admin->assignRole($superAdminRole);
        }
    }
}
