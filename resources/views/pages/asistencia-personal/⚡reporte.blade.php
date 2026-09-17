<?php

use App\Models\HorarioPersonals;
use App\Models\Personal_ies;
use Carbon\Carbon;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reporte de asistencia')] class extends Component {
    public string $fecha_inicio = '';

    public string $fecha_fin = '';

    public string $tipo_personal = '';

    public string $personal_id = '';

    public bool $mostrandoVista = false;

    public array $datosReporte = [];

    public array $resumenReporte = [];

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
        $this->fecha_inicio = now()->startOfMonth()->format('Y-m-d');
        $this->fecha_fin = now()->endOfMonth()->format('Y-m-d');
    }

    public function generarReporte(): void
    {
        $this->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
        ]);

        $query = Personal_ies::query()
            ->with([
                'asistencias' => fn ($q) => $q
                    ->whereBetween('fecha', [$this->fecha_inicio, $this->fecha_fin])
                    ->orderBy('fecha'),
                'horarios' => fn ($q) => $q->activos()->orderBy('dia_semana'),
            ])
            ->where('activo', true)
            ->when($this->tipo_personal, fn ($q) => $q->where('tipo_personal', $this->tipo_personal))
            ->when($this->personal_id, fn ($q) => $q->where('id', $this->personal_id))
            ->orderBy('tipo_personal')
            ->orderBy('apellido_paterno');

        $listaPersonal = $query->get();

        $diasRango = collect(\Carbon\CarbonPeriod::create($this->fecha_inicio, $this->fecha_fin))
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->values()
            ->toArray();

        $this->datosReporte = [];
        $totalTardanzas = $totalFaltas = $totalJustificados = $totalPresentes = 0;

        foreach ($listaPersonal as $personal) {
            $filas = [];
            $pTardanzas = $pFaltas = $pJustificados = $pPresentes = $pMinutosTarde = 0;

            foreach ($diasRango as $fecha) {
                $diaSemana = (int) Carbon::parse($fecha)->isoWeekday();
                $horarioDia = $personal->getHorarioParaDia($diaSemana);

                if (! $horarioDia) {
                    continue;
                }

                $asistencia = $personal->asistencias
                    ->firstWhere(fn ($a) => $a->fecha->format('Y-m-d') === $fecha);

                $estadoEntrada = $asistencia?->estado_entrada ?? 'sin_registro';

                $minutosTardanza = 0;
                if ($asistencia && $asistencia->hora_entrada_maniana && $estadoEntrada === 'tarde') {
                    $horaOficial = Carbon::parse("{$fecha} {$horarioDia->hora_entrada}");
                    $horaReal = Carbon::parse("{$fecha} {$asistencia->hora_entrada_maniana}");
                    $minutosTardanza = max(0, (int) $horaOficial->diffInMinutes($horaReal, false));
                }

                $filas[] = [
                    'fecha' => $fecha,
                    'dia_label' => Carbon::parse($fecha)->locale('es')->isoFormat('ddd D MMM'),
                    'hora_entrada' => $asistencia?->hora_entrada_maniana ? Carbon::parse($asistencia->hora_entrada_maniana)->format('H:i') : null,
                    'hora_salida' => $asistencia?->hora_salida_tarde ? Carbon::parse($asistencia->hora_salida_tarde)->format('H:i') : null,
                    'hora_oficial_entrada' => substr($horarioDia->hora_entrada, 0, 5),
                    'estado' => $estadoEntrada,
                    'minutos_tardanza' => $minutosTardanza,
                    'observacion' => $asistencia?->observacion,
                ];

                match ($estadoEntrada) {
                    'presente' => $pPresentes++,
                    'tarde' => ($pTardanzas++ && ($pMinutosTarde += $minutosTardanza)),
                    'ausente' => $pFaltas++,
                    'justificado' => $pJustificados++,
                    default => null,
                };
            }

            if (empty($filas)) {
                continue;
            }

            $totalTardanzas += $pTardanzas;
            $totalFaltas += $pFaltas;
            $totalJustificados += $pJustificados;
            $totalPresentes += $pPresentes;

            $horarioSemanal = $personal->horarios->map(fn ($h) => [
                'dia' => HorarioPersonals::DIAS[$h->dia_semana] ?? $h->dia_semana,
                'entrada' => substr($h->hora_entrada, 0, 5),
                'salida_manana' => $h->hora_salida_maniana ? substr($h->hora_salida_maniana, 0, 5) : null,
                'entrada_tarde' => $h->hora_entrada_tarde ? substr($h->hora_entrada_tarde, 0, 5) : null,
                'salida' => substr($h->hora_salida, 0, 5),
                'tolerancia' => $h->tolerancia_minutos,
            ])->values()->toArray();

            $this->datosReporte[] = [
                'personal' => $personal->nombre_completo,
                'dni' => $personal->dni,
                'tipo' => $personal->tipo_personal_label,
                'cargo' => $personal->cargo,
                'telefono' => $personal->telefono,
                'fecha_ingreso' => $personal->fecha_ingreso?->locale('es')->isoFormat('D MMM YYYY'),
                'personal_id' => $personal->id,
                'horarios' => $horarioSemanal,
                'filas' => $filas,
                'resumen' => [
                    'presentes' => $pPresentes,
                    'tardanzas' => $pTardanzas,
                    'faltas' => $pFaltas,
                    'justificados' => $pJustificados,
                    'sin_registro' => count($filas) - $pPresentes - $pTardanzas - $pFaltas - $pJustificados,
                    'min_tardanza' => $pMinutosTarde,
                    'total_dias' => count($filas),
                ],
            ];
        }

        $this->resumenReporte = [
            'total_personal' => count($this->datosReporte),
            'total_presentes' => $totalPresentes,
            'total_tardanzas' => $totalTardanzas,
            'total_faltas' => $totalFaltas,
            'total_justificados' => $totalJustificados,
            'rango' => Carbon::parse($this->fecha_inicio)->locale('es')->isoFormat('D MMM YYYY')
                .' al '
                .Carbon::parse($this->fecha_fin)->locale('es')->isoFormat('D MMM YYYY'),
        ];

        $this->mostrandoVista = true;
    }

    public function limpiar(): void
    {
        $this->mostrandoVista = false;
        $this->datosReporte = [];
        $this->resumenReporte = [];
    }

    public function getPdfUrlProperty(): string
    {
        return route('reporte-asistencia-personal.pdf', [
            'fecha_inicio' => $this->fecha_inicio,
            'fecha_fin' => $this->fecha_fin,
            'tipo_personal' => $this->tipo_personal,
            'personal_id' => $this->personal_id,
        ]);
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
                        <flux:icon.document-chart-bar class="w-6 h-6 text-indigo-600 dark:text-indigo-300" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white tracking-tight">{{ __('Reporte de asistencia') }}</h1>
                        <p class="text-sm text-zinc-500 dark:text-zinc-300 mt-0.5">{{ __('Calcula tardanzas y faltas por rango de fechas') }}</p>
                    </div>
                </div>

                <a href="{{ route('asistencia-personal.index') }}" wire:navigate
                    class="flex items-center gap-2 px-4 py-2 rounded-lg bg-white/80 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-white dark:hover:bg-zinc-700 transition">
                    <flux:icon.arrow-left class="w-4 h-4" />
                    {{ __('Volver') }}
                </a>
            </div>
        </div>

        {{-- Filtros --}}
        <div class="w-full bg-white dark:bg-zinc-900 rounded-xl shadow-sm p-5 border border-zinc-200 dark:border-zinc-800 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Desde') }}</label>
                    <input wire:model="fecha_inicio" type="date"
                           class="w-full px-3 py-2.5 bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 transition">
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Hasta') }}</label>
                    <input wire:model="fecha_fin" type="date"
                           class="w-full px-3 py-2.5 bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 transition">
                </div>
                <div>
                    <label class="block text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Tipo de personal') }}</label>
                    <select wire:model="tipo_personal"
                            class="w-full px-3 py-2.5 bg-white dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-700 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 transition">
                        <option value="">{{ __('Todos') }}</option>
                        @foreach ($tipos as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2">
                    <button wire:click="generarReporte" type="button" wire:loading.attr="disabled" wire:target="generarReporte"
                            class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold rounded-lg shadow transition disabled:opacity-70">
                        <flux:icon.loading wire:loading wire:target="generarReporte" variant="mini" class="w-4 h-4" />
                        <span wire:loading.remove wire:target="generarReporte">{{ __('Generar') }}</span>
                        <span wire:loading wire:target="generarReporte">{{ __('Generando...') }}</span>
                    </button>
                    @if ($mostrandoVista)
                        <a href="{{ $this->pdfUrl }}" target="_blank" title="{{ __('Abrir para imprimir') }}"
                           class="px-3 py-2.5 bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 rounded-lg transition flex items-center justify-center">
                            <flux:icon.printer class="w-5 h-5" />
                        </a>
                        <button wire:click="limpiar" type="button" title="{{ __('Limpiar') }}"
                                class="px-3 py-2.5 bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 rounded-lg transition flex items-center justify-center">
                            <flux:icon.x-mark class="w-5 h-5" />
                        </button>
                    @endif
                </div>
            </div>
        </div>

        @if ($mostrandoVista)
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">{{ $resumenReporte['rango'] }}</p>

            {{-- Resumen general --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                @php
                    $reportStats = [
                        ['label' => __('Personal'), 'value' => $resumenReporte['total_personal'], 'class' => 'text-zinc-900 dark:text-white'],
                        ['label' => __('Presentes'), 'value' => $resumenReporte['total_presentes'], 'class' => 'text-green-600 dark:text-green-400'],
                        ['label' => __('Tardanzas'), 'value' => $resumenReporte['total_tardanzas'], 'class' => 'text-yellow-600 dark:text-yellow-400'],
                        ['label' => __('Faltas'), 'value' => $resumenReporte['total_faltas'], 'class' => 'text-red-600 dark:text-red-400'],
                        ['label' => __('Justificados'), 'value' => $resumenReporte['total_justificados'], 'class' => 'text-blue-600 dark:text-blue-400'],
                    ];
                @endphp
                @foreach ($reportStats as $stat)
                    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm p-4 text-center">
                        <div class="text-2xl font-extrabold {{ $stat['class'] }}">{{ $stat['value'] }}</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $stat['label'] }}</div>
                    </div>
                @endforeach
            </div>

            {{-- Bloques por persona --}}
            <div class="space-y-4">
                @forelse ($datosReporte as $persona)
                    @php
                        $r = $persona['resumen'];
                        $totalDias = max($r['total_dias'], 1);
                    @endphp
                    <details class="group bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden" wire:key="persona-{{ $persona['personal_id'] }}">
                        <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-4 p-4 bg-zinc-50 dark:bg-zinc-800/60 border-b border-zinc-200 dark:border-zinc-700 group-open:border-b">
                            <div>
                                <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $persona['personal'] }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">{{ $persona['tipo'] }} · DNI {{ $persona['dni'] }}</div>
                            </div>

                            <div class="flex items-center gap-4">
                                <div class="hidden sm:flex h-2 w-40 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                    <div class="bg-green-500" style="width: {{ $r['presentes'] / $totalDias * 100 }}%"></div>
                                    <div class="bg-yellow-500" style="width: {{ $r['tardanzas'] / $totalDias * 100 }}%"></div>
                                    <div class="bg-red-500" style="width: {{ $r['faltas'] / $totalDias * 100 }}%"></div>
                                    <div class="bg-blue-500" style="width: {{ $r['justificados'] / $totalDias * 100 }}%"></div>
                                </div>
                                <div class="flex gap-1.5">
                                    <span class="stat-pill px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300">{{ $r['presentes'] }}</span>
                                    <span class="stat-pill px-2 py-0.5 rounded-full text-xs font-semibold bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300">{{ $r['tardanzas'] }}</span>
                                    <span class="stat-pill px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300">{{ $r['faltas'] }}</span>
                                </div>
                                <flux:icon.chevron-down class="size-4 text-zinc-400 transition-transform group-open:rotate-180" />
                            </div>
                        </summary>

                        <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <div class="text-xs font-semibold text-zinc-400 uppercase tracking-wide mb-2">{{ __('Datos del personal') }}</div>
                                <dl class="grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
                                    <dt class="text-zinc-400">{{ __('DNI') }}</dt>
                                    <dd class="text-zinc-700 dark:text-zinc-300 font-mono">{{ $persona['dni'] }}</dd>
                                    <dt class="text-zinc-400">{{ __('Tipo') }}</dt>
                                    <dd class="text-zinc-700 dark:text-zinc-300">{{ $persona['tipo'] }}</dd>
                                    <dt class="text-zinc-400">{{ __('Cargo') }}</dt>
                                    <dd class="text-zinc-700 dark:text-zinc-300">{{ $persona['cargo'] ?: '—' }}</dd>
                                    <dt class="text-zinc-400">{{ __('Teléfono') }}</dt>
                                    <dd class="text-zinc-700 dark:text-zinc-300">{{ $persona['telefono'] ?: '—' }}</dd>
                                    <dt class="text-zinc-400">{{ __('Ingreso') }}</dt>
                                    <dd class="text-zinc-700 dark:text-zinc-300">{{ $persona['fecha_ingreso'] ?: '—' }}</dd>
                                </dl>
                            </div>
                            <div>
                                <div class="text-xs font-semibold text-zinc-400 uppercase tracking-wide mb-2">{{ __('Horario semanal') }}</div>
                                @if (empty($persona['horarios']))
                                    <p class="text-xs text-zinc-400">{{ __('Sin horario configurado.') }}</p>
                                @else
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($persona['horarios'] as $h)
                                            <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300"
                                                  title="{{ __('Tolerancia: :min min', ['min' => $h['tolerancia']]) }}">
                                                <span class="font-semibold">{{ $h['dia'] }}</span>
                                                <span class="font-mono">{{ $h['entrada'] }}–{{ $h['entrada_tarde'] ? $h['salida_manana'] : $h['salida'] }}</span>
                                                @if ($h['entrada_tarde'])
                                                    <span class="font-mono">/ {{ $h['entrada_tarde'] }}–{{ $h['salida'] }}</span>
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-zinc-50 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Día') }}</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Hora oficial') }}</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Entrada') }}</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Estado') }}</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Tardanza (min)') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($persona['filas'] as $fila)
                                        <tr wire:key="fila-{{ $persona['personal_id'] }}-{{ $fila['fecha'] }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                                            <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">{{ $fila['dia_label'] }}</td>
                                            <td class="px-4 py-2 font-mono text-xs text-zinc-500">{{ $fila['hora_oficial_entrada'] }}</td>
                                            <td class="px-4 py-2 font-mono text-xs font-medium text-zinc-800 dark:text-zinc-200">{{ $fila['hora_entrada'] ?? '—' }}</td>
                                            <td class="px-4 py-2">
                                                <span @class([
                                                    'inline-flex px-2 py-0.5 rounded-full text-xs font-semibold',
                                                    'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300' => $fila['estado'] === 'presente',
                                                    'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300' => $fila['estado'] === 'tarde',
                                                    'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' => $fila['estado'] === 'ausente',
                                                    'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' => $fila['estado'] === 'justificado',
                                                    'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400' => $fila['estado'] === 'sin_registro',
                                                ])>
                                                    {{ ucfirst(str_replace('_', ' ', $fila['estado'])) }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2 text-red-600 dark:text-red-400 font-semibold text-xs">{{ $fila['minutos_tardanza'] ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @empty
                    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 px-6 py-16 text-center">
                        <flux:icon.document-magnifying-glass class="w-12 h-12 text-zinc-300 dark:text-zinc-600 mx-auto mb-3" />
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-1">{{ __('Sin resultados') }}</h3>
                        <p class="text-sm text-zinc-500">{{ __('No hay datos de asistencia para el rango y filtros seleccionados.') }}</p>
                    </div>
                @endforelse
            </div>
        @endif
    </div>
