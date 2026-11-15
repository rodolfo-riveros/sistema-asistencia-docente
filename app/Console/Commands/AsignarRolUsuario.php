<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class AsignarRolUsuario extends Command
{
    protected $signature = 'usuario:rol {email : Correo del usuario} {rol=Super Admin : Nombre del rol a asignar}';

    protected $description = 'Asigna un rol a un usuario existente (reemplaza los roles que tuviera).';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No existe un usuario con ese correo.');

            return self::FAILURE;
        }

        $rol = Role::where('name', $this->argument('rol'))->first();

        if (! $rol) {
            $this->error('El rol no existe. Roles disponibles: '.Role::pluck('name')->implode(', '));

            return self::FAILURE;
        }

        $user->syncRoles([$rol->name]);

        $this->info("Listo: {$user->email} ahora tiene el rol \"{$rol->name}\".");

        return self::SUCCESS;
    }
}
