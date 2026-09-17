<?php

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

new #[Title('Usuarios')] class extends Component {
    use ProfileValidationRules, WithPagination;

    public string $search = '';

    public bool $showModal = false;

    public ?int $editingUserId = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $role = '';

    public function rules(): array
    {
        $rules = $this->profileRules($this->editingUserId);
        $rules['role'] = 'required|exists:roles,name';
        $rules['password'] = $this->editingUserId
            ? 'nullable|string|min:8|confirmed'
            : 'required|string|min:8|confirmed';

        return $rules;
    }

    public function abrirNuevo(): void
    {
        $this->resetValidation();
        $this->reset(['editingUserId', 'name', 'email', 'password', 'password_confirmation', 'role']);
        $this->showModal = true;
    }

    public function editarUsuario(int $id): void
    {
        $this->resetValidation();
        $user = User::findOrFail($id);
        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->password_confirmation = '';
        $this->role = $user->roles->first()?->name ?? '';
        $this->showModal = true;
    }

    public function guardarUsuario(): void
    {
        $this->validate();

        $data = ['name' => $this->name, 'email' => $this->email];
        if ($this->password) {
            $data['password'] = Hash::make($this->password);
        }

        if ($this->editingUserId) {
            $user = User::findOrFail($this->editingUserId);
            $user->update($data);
        } else {
            $data['email_verified_at'] = now();
            $user = User::create($data);
        }

        $user->syncRoles([$this->role]);

        $this->showModal = false;
        Flux::toast(variant: 'success', text: __('Usuario guardado correctamente.'));
    }

    public function eliminarUsuario(int $id): void
    {
        if ($id === auth()->id()) {
            Flux::toast(variant: 'danger', text: __('No puedes eliminar tu propio usuario.'));

            return;
        }

        User::findOrFail($id)->delete();
        Flux::toast(variant: 'success', text: __('Usuario eliminado.'));
    }

    public function with(): array
    {
        return [
            'usuarios' => User::query()
                ->with('roles')
                ->when($this->search, fn ($q) => $q->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%");
                }))
                ->orderBy('name')
                ->paginate(15),
            'rolesDisponibles' => Role::orderBy('name')->pluck('name'),
        ];
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between flex-wrap gap-4">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white tracking-tight">{{ __('Usuarios') }}</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-300 mt-0.5">{{ __('Administra las cuentas que pueden ingresar al sistema.') }}</p>
        </div>
        <button wire:click="abrirNuevo" type="button"
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white font-semibold shadow-xl hover:shadow-2xl transition-all duration-200 hover:scale-105">
            <flux:icon.plus class="w-4 h-4" />
            {{ __('Nuevo usuario') }}
        </button>
    </div>

    <div class="w-full bg-white dark:bg-zinc-900 rounded-xl shadow-md p-5 border border-zinc-200 dark:border-zinc-800 mb-6">
        <div class="relative max-w-sm">
            <flux:icon.magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-400" />
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('Nombre o correo...') }}"
                   class="w-full pl-9 pr-4 py-2.5 bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-lg text-sm focus:ring-2 focus:ring-teal-500 transition">
        </div>
    </div>

    <div class="w-full bg-white dark:bg-zinc-900 rounded-xl shadow-md border border-zinc-200 dark:border-zinc-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Nombre') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Correo') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Rol') }}</th>
                        <th class="px-4 py-3 text-center text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Acciones') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($usuarios as $usuario)
                        <tr wire:key="usuario-{{ $usuario->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <flux:avatar :name="$usuario->name" size="xs" color="auto" />
                                    <span class="font-semibold text-zinc-900 dark:text-white">{{ $usuario->name }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">{{ $usuario->email }}</td>
                            <td class="px-4 py-3">
                                @foreach ($usuario->roles as $rol)
                                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-300">
                                        {{ $rol->name }}
                                    </span>
                                @endforeach
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-center gap-1">
                                    <button wire:click="editarUsuario({{ $usuario->id }})" class="p-1.5 rounded-lg text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/30 transition">
                                        <flux:icon.pencil-square class="w-4 h-4" />
                                    </button>
                                    @if ($usuario->id !== auth()->id())
                                        <button wire:click="eliminarUsuario({{ $usuario->id }})"
                                                wire:confirm="{{ __('¿Eliminar a :nombre?', ['nombre' => $usuario->name]) }}"
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

        @if ($usuarios->hasPages())
            <div class="px-6 py-4 border-t border-zinc-100 dark:border-zinc-800">
                {{ $usuarios->links() }}
            </div>
        @endif
    </div>

    <flux:modal wire:model="showModal" class="w-[450px]" wire:key="modal-usuario">
        <div class="space-y-6">
            <flux:heading size="lg">{{ $editingUserId ? __('Editar usuario') : __('Nuevo usuario') }}</flux:heading>

            <div class="space-y-4">
                <flux:input wire:model="name" :label="__('Nombre')" />
                <flux:input wire:model="email" :label="__('Correo electrónico')" type="email" />

                <flux:select wire:model="role" :label="__('Rol')">
                    <flux:select.option value="">{{ __('Selecciona un rol') }}</flux:select.option>
                    @foreach ($rolesDisponibles as $rolDisponible)
                        <flux:select.option :value="$rolDisponible">{{ $rolDisponible }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="password" :label="__('Contraseña')" type="password" :placeholder="$editingUserId ? __('Dejar en blanco para no cambiar') : ''" />
                <flux:input wire:model="password_confirmation" :label="__('Confirmar contraseña')" type="password" />
            </div>

            <div class="flex gap-3 justify-end">
                <flux:button variant="ghost" wire:click="$set('showModal', false)">{{ __('Cancelar') }}</flux:button>
                <flux:button variant="primary" wire:click="guardarUsuario">{{ __('Guardar') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
