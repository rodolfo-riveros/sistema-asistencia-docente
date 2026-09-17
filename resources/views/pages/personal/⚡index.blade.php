<?php

use App\Models\Personal_ies;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Personal')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $tipo = '';

    #[Url(except: '')]
    public string $activo = '';

    public array $tipos = [
        'docente' => 'Docente',
        'auxiliar' => 'Auxiliar',
        'administrativo' => 'Administrativo',
        'limpieza' => 'Personal de Limpieza',
        'vigilancia' => 'Vigilancia / Portería',
        'directivo' => 'Directivo',
        'otro' => 'Otro',
    ];

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'tipo', 'activo'])) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'tipo', 'activo']);
        $this->resetPage();
    }

    public function toggleActivo(int $id): void
    {
        $personal = Personal_ies::findOrFail($id);
        $personal->update(['activo' => ! $personal->activo]);
    }

    public function delete(int $id): void
    {
        Personal_ies::findOrFail($id)->delete();
    }

    #[Computed]
    public function personal()
    {
        return Personal_ies::query()
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('nombres', 'like', "%{$this->search}%")
                    ->orWhere('apellido_paterno', 'like', "%{$this->search}%")
                    ->orWhere('apellido_materno', 'like', "%{$this->search}%")
                    ->orWhere('dni', 'like', "%{$this->search}%");
            }))
            ->when($this->tipo, fn ($q) => $q->where('tipo_personal', $this->tipo))
            ->when($this->activo !== '', fn ($q) => $q->where('activo', (bool) $this->activo))
            ->orderBy('apellido_paterno')
            ->orderBy('nombres')
            ->paginate(15);
    }

    #[Computed]
    public function totalActivo(): int
    {
        return Personal_ies::where('activo', true)->count();
    }
}; ?>

<div>
    {{-- Encabezado --}}
    <div class="relative overflow-hidden rounded-2xl
            bg-gradient-to-r from-zinc-100 via-zinc-50 to-teal-100
            dark:from-zinc-900 dark:via-zinc-800 dark:to-teal-950
            p-6 mb-6 shadow-xl border border-zinc-200 dark:border-transparent">

        <div class="absolute inset-0 overflow-hidden">
            <div class="absolute -right-10 -top-10 h-32 w-32 rounded-full bg-teal-400/20 dark:bg-teal-500/10 blur-2xl"></div>
            <div class="absolute -bottom-10 -left-10 h-32 w-32 rounded-full bg-teal-400/20 dark:bg-teal-500/10 blur-2xl"></div>
        </div>

        <div class="relative z-10 flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-4">
                <div class="flex items-center justify-center w-12 h-12 rounded-xl
                        bg-teal-100 dark:bg-teal-500/20
                        backdrop-blur-md
                        border border-teal-300 dark:border-teal-400/30
                        shadow-lg">
                    <flux:icon.user-group class="w-6 h-6 text-teal-600 dark:text-teal-300" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white tracking-tight">{{ __('Personal de la IE') }}</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-300 mt-0.5">
                        {{ __('Gestiona docentes, auxiliares y todo el personal') }} · {{ trans_choice(':count activo|:count activos', $this->totalActivo, ['count' => $this->totalActivo]) }}
                    </p>
                </div>
            </div>

            <a href="{{ route('personal.create') }}" wire:navigate
                class="flex items-center gap-2 px-4 py-2.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white font-semibold shadow-xl hover:shadow-2xl transition-all duration-200 hover:scale-105">
                <flux:icon.plus class="w-4 h-4" />
                {{ __('Nuevo personal') }}
            </a>
        </div>
    </div>

    {{-- Filtros --}}
    <div class="w-full bg-white dark:bg-zinc-900 rounded-xl shadow-md p-5 border border-zinc-200 dark:border-zinc-800 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Buscar') }}</label>
                <div class="relative">
                    <flux:icon.magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-400" />
                    <input wire:model.live.debounce.300ms="search" type="text"
                           placeholder="{{ __('Nombre, apellido o DNI...') }}"
                           class="w-full pl-9 pr-4 py-2.5 bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-lg text-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500 transition">
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Tipo de personal') }}</label>
                <select wire:model.live="tipo"
                        class="w-full px-4 py-2.5 bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-lg text-sm focus:ring-2 focus:ring-teal-500 transition">
                    <option value="">{{ __('Todos') }}</option>
                    @foreach ($tipos as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Estado') }}</label>
                <div class="flex gap-2">
                    <select wire:model.live="activo"
                            class="w-full px-4 py-2.5 bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-lg text-sm focus:ring-2 focus:ring-teal-500 transition">
                        <option value="">{{ __('Todos') }}</option>
                        <option value="1">{{ __('Activo') }}</option>
                        <option value="0">{{ __('Inactivo') }}</option>
                    </select>
                    @if ($search || $tipo || $activo !== '')
                        <button wire:click="clearFilters" type="button" title="{{ __('Limpiar filtros') }}"
                                class="p-2.5 bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 font-medium rounded-lg transition text-sm flex items-center justify-center flex-shrink-0">
                            <flux:icon.x-mark class="w-5 h-5" />
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Tabla --}}
    <div class="w-full bg-white dark:bg-zinc-900 rounded-xl shadow-md border border-zinc-200 dark:border-zinc-800 overflow-hidden"
         wire:loading.class="opacity-50" wire:target="search,tipo,activo,gotoPage,previousPage,nextPage">
        @if ($this->personal->count() > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Personal') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('DNI') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Tipo') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Cargo') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Estado') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->personal as $p)
                            <tr wire:key="personal-{{ $p->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $p->nombre_completo }}</div>
                                    @if ($p->telefono)
                                        <div class="text-xs text-zinc-400">{{ $p->telefono }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400 font-mono text-xs">{{ $p->dni }}</td>
                                <td class="px-4 py-3">
                                    <span @class([
                                        'inline-flex px-2 py-0.5 rounded-full text-xs font-semibold',
                                        'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' => $p->tipo_personal === 'docente',
                                        'bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300' => $p->tipo_personal === 'auxiliar',
                                        'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300' => $p->tipo_personal === 'directivo',
                                        'bg-zinc-100 dark:bg-zinc-700 text-zinc-700 dark:text-zinc-300' => ! in_array($p->tipo_personal, ['docente', 'auxiliar', 'directivo']),
                                    ])>
                                        {{ $p->tipo_personal_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400 text-xs">{{ $p->cargo ?? '—' }}</td>
                                <td class="px-4 py-3 text-center">
                                    <button wire:click="toggleActivo({{ $p->id }})"
                                            @class([
                                                'inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold cursor-pointer transition',
                                                'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 hover:bg-green-200' => $p->activo,
                                                'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 hover:bg-red-200' => ! $p->activo,
                                            ])>
                                        <span class="w-1.5 h-1.5 rounded-full {{ $p->activo ? 'bg-green-500' : 'bg-red-500' }}"></span>
                                        {{ $p->activo ? __('Activo') : __('Inactivo') }}
                                    </button>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <a href="{{ route('personal.edit', $p->id) }}" wire:navigate
                                           class="p-1.5 rounded-lg text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/30 transition">
                                            <flux:icon.pencil-square class="w-4 h-4" />
                                        </a>
                                        <button wire:click="delete({{ $p->id }})"
                                                wire:confirm="{{ __('¿Eliminar a :nombre? Esta acción no se puede deshacer.', ['nombre' => $p->nombre_completo]) }}"
                                                class="p-1.5 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition">
                                            <flux:icon.trash class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($this->personal->hasPages())
                <div class="px-6 py-4 border-t border-zinc-100 dark:border-zinc-800">
                    {{ $this->personal->links() }}
                </div>
            @endif
        @else
            <div class="px-6 py-16 text-center">
                <flux:icon.user-group class="w-12 h-12 text-zinc-300 dark:text-zinc-600 mx-auto mb-3" />
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-1">{{ __('Sin resultados') }}</h3>
                <p class="text-sm text-zinc-500">
                    {{ $search || $tipo || $activo !== '' ? __('No se encontró personal con los filtros aplicados.') : __('Empieza registrando a tu primer colaborador.') }}
                </p>
            </div>
        @endif
    </div>
</div>
