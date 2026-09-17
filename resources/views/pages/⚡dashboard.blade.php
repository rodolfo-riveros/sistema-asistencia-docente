<?php

use App\Models\Asistencia_personals;
use App\Models\Personal_ies;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Panel')] class extends Component {
    #[Computed]
    public function resumen(): array
    {
        $totalActivo = Personal_ies::where('activo', true)->count();
        $hoy = now()->toDateString();

        $asistenciasHoy = Asistencia_personals::whereDate('fecha', $hoy)
            ->selectRaw('estado_entrada, COUNT(*) as total')
            ->groupBy('estado_entrada')
            ->pluck('total', 'estado_entrada');

        return [
            'total' => $totalActivo,
            'presente' => $asistenciasHoy->get('presente', 0),
            'tarde' => $asistenciasHoy->get('tarde', 0),
            'ausente' => $asistenciasHoy->get('ausente', 0),
            'sin_registro' => $totalActivo - $asistenciasHoy->sum(),
        ];
    }

    #[Computed]
    public function ultimosRegistros()
    {
        return Asistencia_personals::with('personal')
            ->whereDate('fecha', now()->toDateString())
            ->latest('updated_at')
            ->limit(6)
            ->get();
    }
}; ?>

<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white tracking-tight">{{ __('Panel') }}</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-300 mt-0.5">
            {{ __('Hola, :nombre. Hoy es :fecha.', ['nombre' => auth()->user()->name, 'fecha' => now()->locale('es')->isoFormat('dddd D [de] MMMM')]) }}
        </p>
    </div>

    @can('asistencia.ver')
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
            @php
                $cards = [
                    ['label' => __('Personal activo'), 'value' => $this->resumen['total'], 'icon' => 'user-group', 'color' => 'zinc'],
                    ['label' => __('Presentes hoy'), 'value' => $this->resumen['presente'], 'icon' => 'check-circle', 'color' => 'green'],
                    ['label' => __('Tardanzas hoy'), 'value' => $this->resumen['tarde'], 'icon' => 'clock', 'color' => 'yellow'],
                    ['label' => __('Faltas hoy'), 'value' => $this->resumen['ausente'], 'icon' => 'x-circle', 'color' => 'red'],
                    ['label' => __('Sin registro'), 'value' => $this->resumen['sin_registro'], 'icon' => 'minus-circle', 'color' => 'zinc'],
                ];
            @endphp
            @foreach ($cards as $card)
                <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm p-4 flex items-center gap-3">
                    <div @class([
                        'w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0',
                        'bg-zinc-100 dark:bg-zinc-800' => $card['color'] === 'zinc',
                        'bg-green-100 dark:bg-green-900/30' => $card['color'] === 'green',
                        'bg-yellow-100 dark:bg-yellow-900/30' => $card['color'] === 'yellow',
                        'bg-red-100 dark:bg-red-900/30' => $card['color'] === 'red',
                    ])>
                        <flux:icon :name="$card['icon']" @class([
                            'w-5 h-5',
                            'text-zinc-500 dark:text-zinc-400' => $card['color'] === 'zinc',
                            'text-green-600 dark:text-green-400' => $card['color'] === 'green',
                            'text-yellow-600 dark:text-yellow-400' => $card['color'] === 'yellow',
                            'text-red-600 dark:text-red-400' => $card['color'] === 'red',
                        ]) />
                    </div>
                    <div>
                        <div class="text-xl font-bold text-zinc-900 dark:text-white leading-none">{{ $card['value'] }}</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $card['label'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @endcan

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        @can('asistencia.ver')
            <div class="lg:col-span-2 bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                    <flux:icon.clock class="w-5 h-5 text-indigo-500" />
                    {{ __('Últimas marcaciones de hoy') }}
                </h2>

                @forelse ($this->ultimosRegistros as $registro)
                    <div wire:key="reg-{{ $registro->id }}" class="flex items-center justify-between py-2.5 border-b border-zinc-100 dark:border-zinc-800 last:border-0">
                        <div class="flex items-center gap-3">
                            <flux:avatar :name="$registro->personal->nombre_completo" size="xs" color="auto" />
                            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ $registro->personal->nombre_completo }}</span>
                        </div>
                        <span @class([
                            'inline-flex px-2 py-0.5 rounded-full text-xs font-semibold',
                            'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300' => $registro->estado_dia === 'presente',
                            'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300' => $registro->estado_dia === 'tarde',
                            'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' => $registro->estado_dia === 'ausente',
                            'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' => $registro->estado_dia === 'justificado',
                        ])>
                            {{ ucfirst($registro->estado_dia) }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-zinc-400 py-6 text-center">{{ __('Aún no hay marcaciones registradas hoy.') }}</p>
                @endforelse
            </div>
        @endcan

        <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                <flux:icon.bolt class="w-5 h-5 text-teal-500" />
                {{ __('Accesos rápidos') }}
            </h2>
            <div class="flex flex-col gap-2">
                @can('personal.ver')
                    <a href="{{ route('personal.index') }}" wire:navigate class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        <flux:icon.user-group class="w-4 h-4 text-teal-500" /> {{ __('Gestionar personal') }}
                    </a>
                @endcan
                @can('asistencia.ver')
                    <a href="{{ route('asistencia-personal.index') }}" wire:navigate class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        <flux:icon.finger-print class="w-4 h-4 text-indigo-500" /> {{ __('Ver asistencia de hoy') }}
                    </a>
                @endcan
                @can('reportes.ver')
                    <a href="{{ route('asistencia-personal.reporte') }}" wire:navigate class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        <flux:icon.document-chart-bar class="w-4 h-4 text-indigo-500" /> {{ __('Generar reporte') }}
                    </a>
                @endcan
                @can('usuarios.gestionar')
                    <a href="{{ route('usuarios.index') }}" wire:navigate class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        <flux:icon.users class="w-4 h-4 text-zinc-500" /> {{ __('Usuarios') }}
                    </a>
                @endcan
                @can('roles.gestionar')
                    <a href="{{ route('roles.index') }}" wire:navigate class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        <flux:icon.key class="w-4 h-4 text-zinc-500" /> {{ __('Roles y permisos') }}
                    </a>
                @endcan
            </div>
        </div>
    </div>
</div>
