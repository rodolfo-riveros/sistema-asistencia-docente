<?php

use App\Models\Asistencia_personals;
use App\Models\Personal_ies;
use Carbon\Carbon;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Asistencia de personal')] class extends Component {
    use WithPagination;

    public string $fecha = '';

    public string $tipo = '';

    public string $search = '';

    public string $estado = '';

    public bool $showModal = false;

    public ?int $personalId = null;

    public ?int $editId = null;

    public string $modalNombre = '';

    public string $modalEstado = 'presente';

    public string $modalHoraEntrada = '';

    public string $modalSalidaManiana = '';

    public string $modalEntradaTarde = '';

    public string $modalSalidaTarde = '';

    public string $modalObservacion = '';

    public array $tipos = [
        'docente' => 'Docente',
        'auxiliar' => 'Auxiliar',
        'administrativo' => 'Administrativo',
        'limpieza' => 'Personal de Limpieza',
        'vigilancia' => 'Vigilancia / Portería',
        'directivo' => 'Directivo',
        'otro' => 'Otro',
    ];

    public function mount(): void
    {
        $this->fecha = now()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['fecha', 'tipo', 'search', 'estado'])) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['tipo', 'search', 'estado']);
        $this->fecha = now()->toDateString();
        $this->resetPage();
    }

    public function irADia(int $dias): void
    {
        $this->fecha = Carbon::parse($this->fecha)->addDays($dias)->toDateString();
        $this->resetPage();
    }

    public function irAHoy(): void
    {
        $this->fecha = now()->toDateString();
        $this->resetPage();
    }

    public function filtrarPorEstado(string $estado): void
    {
        $this->estado = $this->estado === $estado ? '' : $estado;
        $this->resetPage();
    }

    public function openModal(int $personalId, string $nombre, ?int $asistenciaId = null): void
    {
        $this->resetValidation();
        $this->personalId = $personalId;
        $this->modalNombre = $nombre;
        $this->editId = $asistenciaId;
        $this->modalObservacion = '';

        if ($asistenciaId) {
            $a = Asistencia_personals::findOrFail($asistenciaId);
            $this->modalEstado = $a->estado_entrada ?? 'presente';
            $this->modalHoraEntrada = $a->hora_entrada_maniana ? substr($a->hora_entrada_maniana, 0, 5) : '';
            $this->modalSalidaManiana = $a->hora_salida_maniana ? substr($a->hora_salida_maniana, 0, 5) : '';
            $this->modalEntradaTarde = $a->hora_entrada_tarde ? substr($a->hora_entrada_tarde, 0, 5) : '';
            $this->modalSalidaTarde = $a->hora_salida_tarde ? substr($a->hora_salida_tarde, 0, 5) : '';
            $this->modalObservacion = $a->observacion ?? '';
        } else {
            $this->modalEstado = 'presente';
            $this->modalHoraEntrada = now()->format('H:i');
            $this->modalSalidaManiana = '';
            $this->modalEntradaTarde = '';
            $this->modalSalidaTarde = '';
        }

        $this->showModal = true;
    }

    public function saveManual(): void
    {
        $this->validate([
            'personalId' => 'required|exists:personal_ies,id',
            'modalEstado' => 'required|in:presente,tarde,ausente,justificado',
            'modalHoraEntrada' => 'nullable|date_format:H:i',
            'modalSalidaManiana' => 'nullable|date_format:H:i',
            'modalEntradaTarde' => 'nullable|date_format:H:i',
            'modalSalidaTarde' => 'nullable|date_format:H:i',
            'modalObservacion' => 'nullable|string|max:500',
        ]);

        Asistencia_personals::updateOrCreate(
            ['personal_id' => $this->personalId, 'fecha' => $this->fecha],
            [
                'hora_entrada_maniana' => $this->modalHoraEntrada ? $this->modalHoraEntrada.':00' : null,
                'hora_salida_maniana' => $this->modalSalidaManiana ? $this->modalSalidaManiana.':00' : null,
                'hora_entrada_tarde' => $this->modalEntradaTarde ? $this->modalEntradaTarde.':00' : null,
                'hora_salida_tarde' => $this->modalSalidaTarde ? $this->modalSalidaTarde.':00' : null,
                'estado_entrada' => $this->modalEstado,
                'observacion' => $this->modalObservacion ?: null,
                'registrado_por' => auth()->id(),
            ]
        );

        $this->showModal = false;
        Flux::toast(variant: 'success', text: __('Asistencia guardada correctamente.'));
    }

    public function deleteAsistencia(int $id): void
    {
        Asistencia_personals::findOrFail($id)->delete();
        Flux::toast(variant: 'success', text: __('Registro eliminado.'));
    }

    #[Computed]
    public function personal()
    {
        $query = Personal_ies::query()
            ->with(['asistencias' => fn ($q) => $q->whereDate('fecha', $this->fecha)])
            ->when($this->tipo, fn ($q) => $q->where('tipo_personal', $this->tipo))
            ->when($this->search, fn ($q) => $q->where(function ($q) {
                $q->where('nombres', 'like', "%{$this->search}%")
                    ->orWhere('apellido_paterno', 'like', "%{$this->search}%")
                    ->orWhere('dni', 'like', "%{$this->search}%");
            }))
            ->where('activo', true)
            ->orderBy('tipo_personal')
            ->orderBy('apellido_paterno');

        if ($this->estado !== '') {
            if ($this->estado === 'sin_registro') {
                $query->whereDoesntHave('asistencias', fn ($q) => $q->whereDate('fecha', $this->fecha));
            } else {
                $query->whereHas('asistencias', fn ($q) => $q
                    ->whereDate('fecha', $this->fecha)
                    ->where('estado_entrada', $this->estado));
            }
        }

        return $query->paginate(20);
    }

    #[Computed]
    public function resumen(): array
    {
        $totalActivo = Personal_ies::where('activo', true)->count();
        $asistenciasHoy = Asistencia_personals::whereDate('fecha', $this->fecha)
            ->selectRaw('estado_entrada, COUNT(*) as total')
            ->groupBy('estado_entrada')
            ->pluck('total', 'estado_entrada');

        return [
            'total' => $totalActivo,
            'presente' => $asistenciasHoy->get('presente', 0),
            'tarde' => $asistenciasHoy->get('tarde', 0),
            'ausente' => $asistenciasHoy->get('ausente', 0),
            'justificado' => $asistenciasHoy->get('justificado', 0),
            'sin_registro' => $totalActivo - $asistenciasHoy->sum(),
        ];
    }
}; ?>

<div>
    {{-- Encabezado --}}
    <div class="relative overflow-hidden rounded-2xl
            bg-gradient-to-r from-zinc-100 via-zinc-50 to-indigo-100
            dark:from-zinc-900 dark:via-zinc-800 dark:to-indigo-950
            p-6 mb-6 shadow-xl border border-zinc-200 dark:border-transparent">

        <div class="absolute inset-0 overflow-hidden">
            <div class="absolute -right-10 -top-10 h-32 w-32 rounded-full bg-indigo-400/20 dark:bg-indigo-500/10 blur-2xl"></div>
            <div class="absolute -bottom-10 -left-10 h-32 w-32 rounded-full bg-indigo-400/20 dark:bg-indigo-500/10 blur-2xl"></div>
        </div>

        <div class="relative z-10 flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-4">
                <div class="flex items-center justify-center w-12 h-12 rounded-xl
                        bg-indigo-100 dark:bg-indigo-500/20
                        backdrop-blur-md
                        border border-indigo-300 dark:border-indigo-400/30
                        shadow-lg">
                    <flux:icon.clipboard-document-check class="w-6 h-6 text-indigo-600 dark:text-indigo-300" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white tracking-tight">{{ __('Asistencia del Personal') }}</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-300 mt-0.5">
                        {{ Carbon::parse($fecha)->locale('es')->isoFormat('dddd, D [de] MMMM') }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3 flex-wrap">
                <a href="{{ route('personal.index') }}" wire:navigate
                    class="flex items-center gap-2 px-4 py-2 rounded-lg bg-white/80 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-white dark:hover:bg-zinc-700 transition">
                    <flux:icon.user-group class="w-4 h-4" />
                    {{ __('Gestionar personal') }}
                </a>
                <a href="{{ route('asistencia-personal.reporte') }}" wire:navigate
                    class="flex items-center gap-2 px-4 py-2.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-semibold shadow-xl hover:shadow-2xl transition-all duration-200 hover:scale-105">
                    <flux:icon.document-chart-bar class="w-4 h-4" />
                    {{ __('Generar reporte') }}
                </a>
            </div>
        </div>
    </div>

    {{-- Resumen del día --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        @php
            $statCards = [
                'total' => ['label' => __('Total personal'), 'value' => $this->resumen['total'], 'color' => 'zinc', 'icon' => 'user-group', 'clickable' => false],
                'presente' => ['label' => __('Presentes'), 'value' => $this->resumen['presente'], 'color' => 'green', 'icon' => 'check-circle', 'clickable' => true],
                'tarde' => ['label' => __('Tardanzas'), 'value' => $this->resumen['tarde'], 'color' => 'yellow', 'icon' => 'clock', 'clickable' => true],
                'ausente' => ['label' => __('Faltas'), 'value' => $this->resumen['ausente'], 'color' => 'red', 'icon' => 'x-circle', 'clickable' => true],
                'justificado' => ['label' => __('Justificados'), 'value' => $this->resumen['justificado'], 'color' => 'blue', 'icon' => 'document-check', 'clickable' => true],
                'sin_registro' => ['label' => __('Sin registro'), 'value' => $this->resumen['sin_registro'], 'color' => 'zinc', 'icon' => 'minus-circle', 'clickable' => true],
            ];
        @endphp
        @foreach ($statCards as $key => $stat)
            <button
                type="button"
                @if ($stat['clickable']) wire:click="filtrarPorEstado('{{ $key }}')" @endif
                @unless ($stat['clickable']) disabled @endunless
                class="text-left bg-white dark:bg-zinc-900 rounded-xl border shadow-sm p-4 flex items-center gap-3 transition
                    {{ $estado === $key ? 'border-indigo-400 ring-1 ring-indigo-400' : 'border-zinc-200 dark:border-zinc-800' }}
                    {{ $stat['clickable'] ? 'cursor-pointer hover:border-indigo-300' : 'cursor-default' }}"
            >
                <div @class([
                    'w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0',
                    'bg-zinc-100 dark:bg-zinc-800' => $stat['color'] === 'zinc',
                    'bg-green-100 dark:bg-green-900/30' => $stat['color'] === 'green',
                    'bg-yellow-100 dark:bg-yellow-900/30' => $stat['color'] === 'yellow',
                    'bg-red-100 dark:bg-red-900/30' => $stat['color'] === 'red',
                    'bg-blue-100 dark:bg-blue-900/30' => $stat['color'] === 'blue',
                ])>
                    <flux:icon :name="$stat['icon']" @class([
                        'w-5 h-5',
                        'text-zinc-500 dark:text-zinc-400' => $stat['color'] === 'zinc',
                        'text-green-600 dark:text-green-400' => $stat['color'] === 'green',
                        'text-yellow-600 dark:text-yellow-400' => $stat['color'] === 'yellow',
                        'text-red-600 dark:text-red-400' => $stat['color'] === 'red',
                        'text-blue-600 dark:text-blue-400' => $stat['color'] === 'blue',
                    ]) />
                </div>
                <div>
                    <div class="text-xl font-bold text-zinc-900 dark:text-white leading-none">{{ $stat['value'] }}</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $stat['label'] }}</div>
                </div>
            </button>
        @endforeach
    </div>

    {{-- Filtros --}}
    <div class="w-full bg-white dark:bg-zinc-900 rounded-xl shadow-sm p-5 border border-zinc-200 dark:border-zinc-800 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Fecha') }}</label>
                <div class="flex gap-1">
                    <button type="button" wire:click="irADia(-1)" title="{{ __('Día anterior') }}"
                            class="px-2 rounded-lg border border-zinc-300 dark:border-zinc-700 text-zinc-500 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition">
                        <flux:icon.chevron-left class="w-4 h-4" />
                    </button>
                    <input wire:model.live="fecha" type="date"
                           class="w-full px-3 py-2.5 bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 transition">
                    <button type="button" wire:click="irADia(1)" title="{{ __('Día siguiente') }}"
                            class="px-2 rounded-lg border border-zinc-300 dark:border-zinc-700 text-zinc-500 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition">
                        <flux:icon.chevron-right class="w-4 h-4" />
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Tipo') }}</label>
                <select wire:model.live="tipo"
                        class="w-full px-3 py-2.5 bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 transition">
                    <option value="">{{ __('Todos') }}</option>
                    @foreach ($tipos as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Estado') }}</label>
                <select wire:model.live="estado"
                        class="w-full px-3 py-2.5 bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 transition">
                    <option value="">{{ __('Todos') }}</option>
                    <option value="presente">{{ __('Presente') }}</option>
                    <option value="tarde">{{ __('Tardanza') }}</option>
                    <option value="ausente">{{ __('Falta') }}</option>
                    <option value="justificado">{{ __('Justificado') }}</option>
                    <option value="sin_registro">{{ __('Sin registro') }}</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Buscar') }}</label>
                <div class="relative">
                    <flux:icon.magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-400" />
                    <input wire:model.live.debounce.300ms="search" type="text"
                           placeholder="{{ __('Nombre o DNI...') }}"
                           class="w-full pl-9 pr-4 py-2.5 bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 transition">
                </div>
            </div>

            <div class="flex items-end justify-end gap-2">
                @if ($fecha !== now()->toDateString())
                    <button wire:click="irAHoy" type="button"
                            class="px-3 py-2.5 bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 font-medium rounded-lg transition text-sm">
                        {{ __('Hoy') }}
                    </button>
                @endif
                @if ($tipo || $search || $estado)
                    <button wire:click="clearFilters" type="button" title="{{ __('Limpiar filtros') }}"
                            class="p-2.5 bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 font-medium rounded-lg transition text-sm flex items-center justify-center">
                        <flux:icon.x-mark class="w-5 h-5" />
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Tabla de asistencia --}}
    <div class="w-full bg-white dark:bg-zinc-900 rounded-xl shadow-md border border-zinc-200 dark:border-zinc-800 overflow-hidden"
         wire:loading.class="opacity-50" wire:target="fecha,search,tipo,estado">
        @if ($this->personal->count() > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Personal') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Tipo / Cargo') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Estado') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Entrada mañana') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Salida mañana') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Entrada tarde') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Salida tarde') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Acciones') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->personal as $p)
                            @php $asis = $p->asistencias->first(); @endphp
                            <tr wire:key="asistencia-{{ $p->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $p->nombre_completo }}</div>
                                    <div class="text-xs text-zinc-400 font-mono">{{ $p->dni }}</div>
                                </td>

                                <td class="px-4 py-3">
                                    <div class="text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ $p->tipo_personal_label }}</div>
                                    @if ($p->cargo)
                                        <div class="text-xs text-zinc-400">{{ $p->cargo }}</div>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-center">
                                    @if ($asis)
                                        @php $ed = $asis->estado_dia; @endphp
                                        <span @class([
                                            'inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold',
                                            'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300' => $ed === 'presente',
                                            'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300' => $ed === 'tarde',
                                            'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' => $ed === 'ausente',
                                            'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' => $ed === 'justificado',
                                        ])>
                                            <span @class([
                                                'w-1.5 h-1.5 rounded-full',
                                                'bg-green-500' => $ed === 'presente',
                                                'bg-yellow-500' => $ed === 'tarde',
                                                'bg-red-500' => $ed === 'ausente',
                                                'bg-blue-500' => $ed === 'justificado',
                                            ])></span>
                                            {{ ucfirst($ed) }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400">
                                            <span class="w-1.5 h-1.5 rounded-full bg-zinc-400"></span>
                                            {{ __('Sin registro') }}
                                        </span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-center">
                                    @if ($asis && $asis->hora_entrada_maniana)
                                        <div class="font-mono text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ Carbon::parse($asis->hora_entrada_maniana)->format('H:i') }}</div>
                                        @if ($asis->estado_entrada === 'tarde')
                                            <div class="text-[10px] text-yellow-600 dark:text-yellow-400 font-semibold">{{ __('TARDANZA') }}</div>
                                        @endif
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-center">
                                    @if ($asis && $asis->hora_salida_maniana)
                                        <div class="font-mono text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ Carbon::parse($asis->hora_salida_maniana)->format('H:i') }}</div>
                                        @if ($asis->estado_salida_maniana === 'anticipada')
                                            <div class="text-[10px] text-orange-500 font-semibold">{{ __('ANTICIPADA') }}</div>
                                        @endif
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-center">
                                    @if ($asis && $asis->hora_entrada_tarde)
                                        <div class="font-mono text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ Carbon::parse($asis->hora_entrada_tarde)->format('H:i') }}</div>
                                        @if ($asis->estado_entrada_tarde === 'tarde')
                                            <div class="text-[10px] text-yellow-600 dark:text-yellow-400 font-semibold">{{ __('TARDANZA') }}</div>
                                        @endif
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-center">
                                    @if ($asis && $asis->hora_salida_tarde)
                                        <div class="font-mono text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ Carbon::parse($asis->hora_salida_tarde)->format('H:i') }}</div>
                                        @if ($asis->estado_salida === 'anticipada')
                                            <div class="text-[10px] text-orange-500 font-semibold">{{ __('ANTICIPADA') }}</div>
                                        @endif
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        <button wire:click="openModal({{ $p->id }}, '{{ addslashes($p->nombre_completo) }}', {{ $asis?->id ?? 'null' }})"
                                                title="{{ $asis ? __('Editar asistencia') : __('Registrar asistencia manual') }}"
                                                class="p-1.5 rounded-lg text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/30 transition">
                                            @if ($asis)
                                                <flux:icon.pencil-square class="w-4 h-4" />
                                            @else
                                                <flux:icon.plus-circle class="w-4 h-4" />
                                            @endif
                                        </button>

                                        @if ($asis)
                                            <button wire:click="deleteAsistencia({{ $asis->id }})"
                                                    wire:confirm="{{ __('¿Eliminar el registro de asistencia de :nombre del :fecha?', ['nombre' => $p->nombre_completo, 'fecha' => Carbon::parse($fecha)->format('d/m/Y')]) }}"
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

            @if ($this->personal->hasPages())
                <div class="px-6 py-4 border-t border-zinc-100 dark:border-zinc-800">
                    {{ $this->personal->links() }}
                </div>
            @endif
        @else
            <div class="px-6 py-16 text-center">
                <flux:icon.clipboard-document-check class="w-12 h-12 text-zinc-300 dark:text-zinc-600 mx-auto mb-3" />
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-1">{{ __('Sin resultados') }}</h3>
                <p class="text-sm text-zinc-500">{{ __('No se encontró personal con los filtros aplicados.') }}</p>
            </div>
        @endif
    </div>

    {{-- Modal: Registro / edición manual de asistencia --}}
    <flux:modal wire:model="showModal" class="w-[450px]" wire:key="modal-registro">
        <div class="space-y-6">
            <flux:heading size="lg" class="flex items-center gap-2">
                <flux:icon.clipboard-document-check class="w-5 h-5 text-indigo-500" />
                {{ $editId ? __('Editar asistencia') : __('Registrar asistencia manual') }}
            </flux:heading>

            <div class="flex items-center gap-3 p-3 bg-zinc-50 dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700">
                <div class="w-9 h-9 rounded-full bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center flex-shrink-0">
                    <flux:icon.user class="w-4 h-4 text-indigo-600 dark:text-indigo-300" />
                </div>
                <div>
                    <div class="font-semibold text-sm text-zinc-900 dark:text-white">{{ $modalNombre }}</div>
                    <div class="text-xs text-zinc-500">{{ Carbon::parse($fecha)->locale('es')->isoFormat('D MMM YYYY') }}</div>
                </div>
            </div>

            <div class="space-y-4">
                <flux:select wire:model="modalEstado" label="{{ __('Estado de entrada') }}">
                    <flux:select.option value="presente">{{ __('Presente') }}</flux:select.option>
                    <flux:select.option value="tarde">{{ __('Tardanza') }}</flux:select.option>
                    <flux:select.option value="ausente">{{ __('Ausente / Falta') }}</flux:select.option>
                    <flux:select.option value="justificado">{{ __('Justificado') }}</flux:select.option>
                </flux:select>

                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="modalHoraEntrada" label="{{ __('Entrada mañana') }}" type="time" />
                    <flux:input wire:model="modalSalidaManiana" label="{{ __('Salida mañana') }}" type="time" />
                    <flux:input wire:model="modalEntradaTarde" label="{{ __('Entrada tarde') }}" type="time" />
                    <flux:input wire:model="modalSalidaTarde" label="{{ __('Salida tarde') }}" type="time" />
                </div>

                <flux:textarea wire:model="modalObservacion" label="{{ __('Observación') }}" rows="2" placeholder="{{ __('Justificación, permiso, etc.') }}" />
            </div>

            <div class="flex gap-3 justify-end mt-6">
                <flux:button variant="ghost" wire:click="$set('showModal', false)">{{ __('Cancelar') }}</flux:button>
                <flux:button wire:click="saveManual" variant="primary" wire:loading.attr="disabled">
                    {{ __('Guardar') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
