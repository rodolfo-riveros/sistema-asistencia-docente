<?php

use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

new #[Title('Roles y Permisos')] class extends Component {
    public bool $showRoleModal = false;

    public ?int $editingRoleId = null;

    public string $roleName = '';

    public array $selectedPermissions = [];

    public string $nuevoPermiso = '';

    public function rules(): array
    {
        $uniqueName = 'unique:roles,name'.($this->editingRoleId ? ",{$this->editingRoleId}" : '');

        return [
            'roleName' => "required|string|max:60|{$uniqueName}",
        ];
    }

    public function abrirNuevoRol(): void
    {
        $this->resetValidation();
        $this->editingRoleId = null;
        $this->roleName = '';
        $this->selectedPermissions = [];
        $this->showRoleModal = true;
    }

    public function editarRol(int $id): void
    {
        $this->resetValidation();
        $role = Role::with('permissions')->findOrFail($id);
        $this->editingRoleId = $role->id;
        $this->roleName = $role->name;
        $this->selectedPermissions = $role->permissions->pluck('name')->toArray();
        $this->showRoleModal = true;
    }

    public function guardarRol(): void
    {
        $this->validate();

        $role = $this->editingRoleId
            ? Role::findOrFail($this->editingRoleId)
            : Role::create(['name' => $this->roleName, 'guard_name' => 'web']);

        if ($this->editingRoleId) {
            $role->update(['name' => $this->roleName]);
        }

        $role->syncPermissions($this->selectedPermissions);

        $this->showRoleModal = false;
        Flux::toast(variant: 'success', text: __('Rol guardado correctamente.'));
    }

    public function eliminarRol(int $id): void
    {
        $role = Role::findOrFail($id);

        if ($role->name === config('permission.super_admin_role', 'Super Admin')) {
            Flux::toast(variant: 'danger', text: __('No puedes eliminar el rol de Super Admin.'));

            return;
        }

        $role->delete();
        Flux::toast(variant: 'success', text: __('Rol eliminado.'));
    }

    public function agregarPermiso(): void
    {
        $this->validate(['nuevoPermiso' => 'required|string|max:100|regex:/^[a-z0-9._-]+$/i|unique:permissions,name']);

        Permission::create(['name' => $this->nuevoPermiso, 'guard_name' => 'web']);
        $this->nuevoPermiso = '';

        Flux::toast(variant: 'success', text: __('Permiso creado.'));
    }

    public function eliminarPermiso(int $id): void
    {
        Permission::findOrFail($id)->delete();
        Flux::toast(variant: 'success', text: __('Permiso eliminado.'));
    }

    public function with(): array
    {
        return [
            'roles' => Role::withCount('permissions')->orderBy('name')->get(),
            'permisos' => Permission::orderBy('name')->get(),
            'permisosAgrupados' => Permission::orderBy('name')->get()->groupBy(fn ($p) => str($p->name)->before('.')->toString()),
        ];
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white tracking-tight">{{ __('Roles y Permisos') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-300 mt-0.5">{{ __('Define qué puede hacer cada rol dentro del sistema.') }}</p>
        </div>
        <button wire:click="abrirNuevoRol" type="button"
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white font-semibold shadow-xl hover:shadow-2xl transition-all duration-200 hover:scale-105">
            <flux:icon.plus class="w-4 h-4" />
            {{ __('Nuevo rol') }}
        </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Roles --}}
        <div class="lg:col-span-2 bg-white dark:bg-zinc-900 rounded-xl shadow-md border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Rol') }}</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Permisos') }}</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Acciones') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($roles as $role)
                        <tr wire:key="role-{{ $role->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                            <td class="px-4 py-3">
                                <div class="font-semibold text-zinc-900 dark:text-white">{{ $role->name }}</div>
                                @if ($role->name === config('permission.super_admin_role', 'Super Admin'))
                                    <div class="text-xs text-teal-600 dark:text-teal-400">{{ __('Acceso total al sistema') }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300">
                                    {{ $role->permissions_count }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-center gap-1">
                                    <button wire:click="editarRol({{ $role->id }})" class="p-1.5 rounded-lg text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/30 transition">
                                        <flux:icon.pencil-square class="w-4 h-4" />
                                    </button>
                                    @if ($role->name !== config('permission.super_admin_role', 'Super Admin'))
                                        <button wire:click="eliminarRol({{ $role->id }})"
                                                wire:confirm="{{ __('¿Eliminar el rol :nombre?', ['nombre' => $role->name]) }}"
                                                class="p-1.5 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition">
                                            <flux:icon.trash class="w-4 h-4" />
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

        {{-- Permisos dinámicos --}}
        <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                <flux:icon.key class="w-5 h-5 text-teal-500" />
                {{ __('Permisos') }}
            </h2>

            <form wire:submit="agregarPermiso" class="flex gap-2 mb-4">
                <input wire:model="nuevoPermiso" type="text" placeholder="{{ __('ej: reportes.exportar') }}"
                       class="w-full px-3 py-2 rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm focus:ring-2 focus:ring-teal-500 transition">
                <button type="submit" class="px-3 py-2 bg-zinc-800 text-white rounded-lg text-sm hover:bg-zinc-700 transition flex-shrink-0">
                    <flux:icon.plus class="w-4 h-4" />
                </button>
            </form>
            @error('nuevoPermiso') <p class="text-xs text-red-500 -mt-3 mb-3">{{ $message }}</p> @enderror

            <div class="space-y-1 max-h-96 overflow-y-auto">
                @foreach ($permisos as $permiso)
                    <div wire:key="permiso-{{ $permiso->id }}" class="flex items-center justify-between px-2 py-1.5 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800/60 group">
                        <span class="text-xs font-mono text-zinc-600 dark:text-zinc-400">{{ $permiso->name }}</span>
                        <button wire:click="eliminarPermiso({{ $permiso->id }})"
                                wire:confirm="{{ __('¿Eliminar este permiso? Se quitará de todos los roles.') }}"
                                class="opacity-0 group-hover:opacity-100 transition text-red-500 hover:text-red-600">
                            <flux:icon.x-mark class="w-3.5 h-3.5" />
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Modal crear/editar rol --}}
    <flux:modal wire:model="showRoleModal" class="w-full max-w-[500px]" wire:key="modal-rol">
        <div class="space-y-6">
            <flux:heading size="lg">{{ $editingRoleId ? __('Editar rol') : __('Nuevo rol') }}</flux:heading>

            <flux:input wire:model="roleName" :label="__('Nombre del rol')" placeholder="{{ __('ej: Administrativo') }}" />

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ __('Permisos') }}</label>
                <flux:checkbox.group wire:model="selectedPermissions" class="space-y-3 max-h-64 overflow-y-auto border border-zinc-200 dark:border-zinc-700 rounded-lg p-3">
                    @foreach ($permisosAgrupados as $grupo => $items)
                        <div>
                            <div class="text-xs font-semibold text-zinc-400 uppercase tracking-wide mb-1">{{ $grupo }}</div>
                            <div class="grid grid-cols-2 gap-1">
                                @foreach ($items as $permiso)
                                    <flux:checkbox value="{{ $permiso->name }}" label="{{ $permiso->name }}" />
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </flux:checkbox.group>
            </div>

            <div class="flex gap-3 justify-end">
                <flux:button variant="ghost" wire:click="$set('showRoleModal', false)">{{ __('Cancelar') }}</flux:button>
                <flux:button variant="primary" wire:click="guardarRol">{{ __('Guardar') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
