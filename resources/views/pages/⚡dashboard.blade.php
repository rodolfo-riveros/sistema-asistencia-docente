<?php

use App\Models\Asistencia_personals;
use App\Services\EstadisticasAsistencia;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Panel')] class extends Component {
    #[Url(as: 'periodo')]
    public string $periodo = 'mes';

    public function updatedPeriodo(): void
    {
        if (! array_key_exists($this->periodo, EstadisticasAsistencia::PERIODOS)) {
            $this->periodo = 'mes';
        }
    }

    #[Computed]
    public function panel(): array
    {
        abort_unless(auth()->user()->can('asistencia.ver'), 403);

        $periodo = array_key_exists($this->periodo, EstadisticasAsistencia::PERIODOS) ? $this->periodo : 'mes';

        return app(EstadisticasAsistencia::class)->panel($periodo);
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

<div wire:poll.120s class="space-y-6">
    @php
        $usuario = auth()->user();
        $verStats = $usuario->can('asistencia.ver');
        $hora = (int) now()->format('G');
        $saludo = $hora < 12 ? __('Buenos días') : ($hora < 19 ? __('Buenas tardes') : __('Buenas noches'));
        $rutasAccion = [
            'importar' => ['ruta' => 'asistencia-personal.importar', 'permiso' => 'asistencia.gestionar'],
            'personal' => ['ruta' => 'personal.index', 'permiso' => 'personal.ver'],
            'asistencia' => ['ruta' => 'asistencia-personal.index', 'permiso' => 'asistencia.ver'],
            'reporte' => ['ruta' => 'asistencia-personal.reporte', 'permiso' => 'reportes.ver'],
        ];
        $estiloNivel = [
            'critico' => ['borde' => 'border-red-200 dark:border-red-900/60', 'fondo' => 'bg-red-50 dark:bg-red-950/30', 'icono' => 'text-red-600 dark:text-red-400', 'chip' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300', 'etiqueta' => __('Crítico'), 'ic' => 'exclamation-circle'],
            'advertencia' => ['borde' => 'border-amber-200 dark:border-amber-900/60', 'fondo' => 'bg-amber-50 dark:bg-amber-950/30', 'icono' => 'text-amber-600 dark:text-amber-400', 'chip' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300', 'etiqueta' => __('Atención'), 'ic' => 'exclamation-triangle'],
            'info' => ['borde' => 'border-sky-200 dark:border-sky-900/60', 'fondo' => 'bg-sky-50 dark:bg-sky-950/30', 'icono' => 'text-sky-600 dark:text-sky-400', 'chip' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300', 'etiqueta' => __('Sugerencia'), 'ic' => 'light-bulb'],
            'ok' => ['borde' => 'border-emerald-200 dark:border-emerald-900/60', 'fondo' => 'bg-emerald-50 dark:bg-emerald-950/30', 'icono' => 'text-emerald-600 dark:text-emerald-400', 'chip' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300', 'etiqueta' => __('Bien'), 'ic' => 'check-circle'],
        ];
    @endphp

    {{-- Encabezado --}}
    <div class="rounded-2xl bg-gradient-to-br from-emerald-700 via-emerald-600 to-green-500 text-white p-5 sm:p-6 shadow-sm">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <p class="text-emerald-100 text-sm">{{ ucfirst(now()->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY')) }} · {{ now()->format('H:i') }}</p>
                <h1 class="text-2xl sm:text-3xl font-bold tracking-tight mt-1">{{ $saludo }}, {{ \Illuminate\Support\Str::of($usuario->name)->before(' ') }}</h1>
                <p class="text-emerald-100 text-sm mt-1">{{ __('Panel de control de asistencia del personal') }}</p>
            </div>

            @if ($verStats)
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex rounded-xl bg-white/15 p-1 backdrop-blur" role="tablist" aria-label="{{ __('Período') }}">
                        @foreach (\App\Services\EstadisticasAsistencia::PERIODOS as $clave => $nombre)
                            <button type="button" role="tab" wire:click="$set('periodo', '{{ $clave }}')" aria-selected="{{ $periodo === (string) $clave ? 'true' : 'false' }}"
                                @class([
                                    'px-3 py-1.5 rounded-lg text-xs sm:text-sm font-medium transition cursor-pointer',
                                    'bg-white text-emerald-700 shadow-sm' => $periodo === (string) $clave,
                                    'text-white/90 hover:bg-white/10' => $periodo !== (string) $clave,
                                ])>{{ $nombre }}</button>
                        @endforeach
                    </div>
                    <span wire:loading class="text-xs text-emerald-100 inline-flex items-center gap-1"><flux:icon.loading class="size-4" /> {{ __('Actualizando…') }}</span>
                </div>
            @endif
        </div>
    </div>

    @if ($verStats)
        @php $p = $this->panel; @endphp
        @php $h = $p['hoy']; @endphp
        @php $k = $p['kpis']; @endphp

        {{-- Hoy --}}
        <section aria-labelledby="hoy-titulo">
            <div class="flex items-end justify-between mb-3">
                <h2 id="hoy-titulo" class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Hoy en tiempo real') }}</h2>
                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Se actualiza cada 2 minutos') }}</span>
            </div>
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-4">
                @php
                    $tarjetasHoy = [
                        ['t' => __('Con horario hoy'), 'v' => $h['programados'], 'sub' => __(':n activos en total', ['n' => $h['activos']]), 'ic' => 'user-group', 'c' => 'zinc'],
                        ['t' => __('Presentes'), 'v' => $h['presente'], 'sub' => __('Llegaron a tiempo'), 'ic' => 'check-circle', 'c' => 'green'],
                        ['t' => __('Tardanzas'), 'v' => $h['tarde'], 'sub' => __('Llegaron con retraso'), 'ic' => 'clock', 'c' => 'yellow'],
                        ['t' => __('Por llegar'), 'v' => $h['pendiente'], 'sub' => __('Aún dentro de tolerancia'), 'ic' => 'calendar-days', 'c' => 'sky'],
                        ['t' => __('Sin marcación'), 'v' => $h['sin_registro'] + $h['ausente'], 'sub' => $h['justificado'] ? __(':n justificado(s)', ['n' => $h['justificado']]) : __('Posibles faltas'), 'ic' => 'x-circle', 'c' => 'red'],
                    ];
                @endphp
                @foreach ($tarjetasHoy as $t)
                    <div @class(['rounded-xl border bg-white dark:bg-zinc-900 shadow-sm p-4 border-zinc-200 dark:border-zinc-800', 'col-span-2 lg:col-span-1' => $loop->first])>
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $t['t'] }}</span>
                            <span @class([
                                'w-8 h-8 rounded-lg flex items-center justify-center',
                                'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-300' => $t['c'] === 'zinc',
                                'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400' => $t['c'] === 'green',
                                'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-600 dark:text-yellow-400' => $t['c'] === 'yellow',
                                'bg-sky-100 dark:bg-sky-900/30 text-sky-600 dark:text-sky-400' => $t['c'] === 'sky',
                                'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400' => $t['c'] === 'red',
                            ])><flux:icon :name="$t['ic']" class="size-4" /></span>
                        </div>
                        <div class="text-3xl font-bold text-zinc-900 dark:text-white mt-2 leading-none tabular-nums">{{ $t['v'] }}</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1.5">{{ $t['sub'] }}</div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Indicadores del período --}}
        <section aria-labelledby="periodo-titulo">
            <div class="flex items-end justify-between mb-3">
                <h2 id="periodo-titulo" class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Indicadores del período') }}</h2>
                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $p['etiqueta'] }} · {{ \Carbon\Carbon::parse($p['rango']['inicio'])->format('d/m') }} – {{ \Carbon\Carbon::parse($p['rango']['fin'])->format('d/m/Y') }}</span>
            </div>

            @if ($p['sin_datos'])
                <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-8 text-center">
                    <flux:icon.chart-bar class="size-8 mx-auto text-zinc-400" />
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ $p['resumen'] }}</p>
                </div>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 sm:gap-4">
                    @foreach ([
                        ['t' => __('Tasa de asistencia'), 'v' => $k['tasa_asistencia'], 'meta' => $k['meta_asistencia'], 'delta' => $k['delta_asistencia'], 'sub' => __(':a asistencias de :b programadas', ['a' => $k['asistieron'], 'b' => $k['asistieron'] + $k['inasistencias']])],
                        ['t' => __('Puntualidad'), 'v' => $k['puntualidad'], 'meta' => $k['meta_puntualidad'], 'delta' => $k['delta_puntualidad'], 'sub' => __(':n tardanzas en el período', ['n' => $p['totales']['tarde']])],
                    ] as $m)
                        @php $ok = $m['v'] !== null && $m['v'] >= $m['meta']; @endphp
                        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm p-4">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $m['t'] }}</span>
                                @if ($m['delta'] !== null && $m['delta'] != 0)
                                    <span @class(['inline-flex items-center gap-0.5 text-xs font-semibold', 'text-emerald-600 dark:text-emerald-400' => $m['delta'] > 0, 'text-red-600 dark:text-red-400' => $m['delta'] < 0])
                                        title="{{ __('Frente al período anterior') }}">
                                        <flux:icon :name="$m['delta'] > 0 ? 'arrow-trending-up' : 'arrow-trending-down'" class="size-3.5" />
                                        {{ $m['delta'] > 0 ? '+' : '' }}{{ $m['delta'] }} {{ __('pts') }}
                                    </span>
                                @endif
                            </div>
                            <div class="mt-2 flex items-baseline gap-2">
                                <span @class(['text-3xl font-bold tabular-nums leading-none', 'text-emerald-600 dark:text-emerald-400' => $ok, 'text-amber-600 dark:text-amber-400' => ! $ok && $m['v'] !== null, 'text-zinc-400' => $m['v'] === null])>{{ $m['v'] !== null ? rtrim(rtrim(number_format($m['v'], 1), '0'), '.').'%' : '—' }}</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('meta :m%', ['m' => (int) $m['meta']]) }}</span>
                            </div>
                            <div class="relative mt-3 h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden" role="progressbar" aria-valuenow="{{ (int) ($m['v'] ?? 0) }}" aria-valuemin="0" aria-valuemax="100">
                                <div @class(['h-full rounded-full', 'bg-emerald-500' => $ok, 'bg-amber-500' => ! $ok]) style="width: {{ min(100, max(0, $m['v'] ?? 0)) }}%"></div>
                                <div class="absolute top-0 h-full w-0.5 bg-zinc-900/60 dark:bg-white/70" style="left: {{ $m['meta'] }}%" title="{{ __('Meta') }}"></div>
                            </div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-2">{{ $m['sub'] }}</div>
                        </div>
                    @endforeach

                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm p-4">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Inasistencias') }}</span>
                        <div class="mt-2 text-3xl font-bold tabular-nums leading-none text-zinc-900 dark:text-white">{{ $k['inasistencias'] }}</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-3">
                            {{ __(':a faltas · :b sin registro · :c justificadas', ['a' => $p['totales']['ausente'], 'b' => $p['totales']['sin_registro'], 'c' => $p['totales']['justificado']]) }}
                        </div>
                    </div>

                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm p-4">
                        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Tiempo perdido por tardanzas') }}</span>
                        <div class="mt-2 flex items-baseline gap-1">
                            <span class="text-3xl font-bold tabular-nums leading-none text-zinc-900 dark:text-white">{{ $k['horas_perdidas'] }}</span>
                            <span class="text-sm text-zinc-500">h</span>
                        </div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-3">{{ __('Promedio de :n min por tardanza', ['n' => $k['promedio_tarde']]) }}</div>
                    </div>
                </div>
            @endif
        </section>

        @unless ($p['sin_datos'])
            {{-- Resumen ejecutivo + decisiones --}}
            <section class="grid grid-cols-1 xl:grid-cols-3 gap-4 sm:gap-6" aria-labelledby="decisiones-titulo">
                <div class="xl:col-span-2 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm">
                    <div class="p-5 border-b border-zinc-100 dark:border-zinc-800">
                        <h2 id="decisiones-titulo" class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                            <flux:icon.sparkles class="size-5 text-emerald-500" /> {{ __('Resumen y decisiones sugeridas') }}
                        </h2>
                        <p class="text-sm text-zinc-600 dark:text-zinc-300 mt-2 leading-relaxed">{{ $p['resumen'] }}</p>
                    </div>

                    <ul class="p-4 space-y-3">
                        @foreach ($p['alertas'] as $alerta)
                            @php $e = $estiloNivel[$alerta['nivel']]; @endphp
                            <li wire:key="alerta-{{ $loop->index }}" class="rounded-lg border {{ $e['borde'] }} {{ $e['fondo'] }} p-4">
                                <div class="flex items-start gap-3">
                                    <flux:icon :name="$e['ic']" class="size-5 mt-0.5 shrink-0 {{ $e['icono'] }}" />
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded {{ $e['chip'] }}">{{ $e['etiqueta'] }}</span>
                                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $alerta['titulo'] }}</h3>
                                        </div>
                                        <p class="text-sm text-zinc-600 dark:text-zinc-300 mt-1">{{ $alerta['detalle'] }}</p>

                                        @if ($alerta['items'])
                                            <ul class="mt-2 text-sm text-zinc-700 dark:text-zinc-200 list-disc ps-5 space-y-0.5">
                                                @foreach ($alerta['items'] as $item)
                                                    <li>{{ $item }}</li>
                                                @endforeach
                                            </ul>
                                        @endif

                                        @if ($alerta['accion'] && ($destino = $rutasAccion[$alerta['accion']['clave']] ?? null) && $usuario->can($destino['permiso']))
                                            <a href="{{ route($destino['ruta']) }}" wire:navigate
                                               class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-emerald-700 dark:text-emerald-300 hover:underline">
                                                {{ $alerta['accion']['texto'] }} <flux:icon.arrow-right class="size-4" />
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>

                {{-- Distribución --}}
                @php
                    $t = $p['totales'];
                    $segmentos = [
                        ['n' => __('Presentes'), 'v' => $t['presente'], 'c' => '#10b981'],
                        ['n' => __('Tardanzas'), 'v' => $t['tarde'], 'c' => '#f59e0b'],
                        ['n' => __('Faltas'), 'v' => $t['ausente'], 'c' => '#ef4444'],
                        ['n' => __('Sin registro'), 'v' => $t['sin_registro'], 'c' => '#a1a1aa'],
                        ['n' => __('Justificados'), 'v' => $t['justificado'], 'c' => '#3b82f6'],
                    ];
                    $totalSeg = max(1, array_sum(array_column($segmentos, 'v')));
                    $acum = 0; $partes = [];
                    foreach ($segmentos as $s) {
                        if ($s['v'] > 0) {
                            $ini = $acum / $totalSeg * 100; $acum += $s['v']; $fin = $acum / $totalSeg * 100;
                            $partes[] = "{$s['c']} {$ini}% {$fin}%";
                        }
                    }
                    $gradiente = $partes ? implode(', ', $partes) : '#e4e4e7 0% 100%';
                @endphp
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm p-5">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon.chart-bar class="size-5 text-emerald-500" /> {{ __('Distribución de asistencias') }}
                    </h2>
                    <div class="mt-5 flex flex-col items-center gap-5">
                        <div class="relative size-44 rounded-full" style="background: conic-gradient({{ $gradiente }})" role="img" aria-label="{{ __('Gráfico de distribución de asistencias') }}">
                            <div class="absolute inset-5 rounded-full bg-white dark:bg-zinc-900 flex flex-col items-center justify-center">
                                <span class="text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $k['tasa_asistencia'] !== null ? rtrim(rtrim(number_format($k['tasa_asistencia'], 1), '0'), '.').'%' : '—' }}</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('asistencia') }}</span>
                            </div>
                        </div>
                        <ul class="w-full space-y-2 text-sm">
                            @foreach ($segmentos as $s)
                                <li class="flex items-center justify-between gap-3">
                                    <span class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300"><span class="size-2.5 rounded-full" style="background: {{ $s['c'] }}"></span>{{ $s['n'] }}</span>
                                    <span class="tabular-nums text-zinc-900 dark:text-white font-medium">{{ $s['v'] }} <span class="text-xs font-normal text-zinc-500">({{ number_format($s['v'] / $totalSeg * 100, 1) }}%)</span></span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </section>

            {{-- Tendencia + llegada --}}
            <section class="grid grid-cols-1 xl:grid-cols-3 gap-4 sm:gap-6">
                @php
                    $maxDia = max(1, collect($p['dias'])->max('programados'));
                    $cada = count($p['dias']) > 16 ? 5 : 1;
                @endphp
                <div class="xl:col-span-2 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                            <flux:icon.arrow-trending-up class="size-5 text-emerald-500" /> {{ __('Tendencia diaria') }}
                        </h2>
                        <div class="flex flex-wrap gap-3 text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="flex items-center gap-1"><span class="size-2 rounded-sm bg-emerald-500"></span>{{ __('Presentes') }}</span>
                            <span class="flex items-center gap-1"><span class="size-2 rounded-sm bg-amber-500"></span>{{ __('Tardanzas') }}</span>
                            <span class="flex items-center gap-1"><span class="size-2 rounded-sm bg-red-500"></span>{{ __('Faltas / sin registro') }}</span>
                            <span class="flex items-center gap-1"><span class="size-2 rounded-sm bg-blue-500"></span>{{ __('Justificados') }}</span>
                        </div>
                    </div>

                    <div class="mt-4 flex items-end gap-1 h-44 border-b border-zinc-200 dark:border-zinc-700" role="img" aria-label="{{ __('Barras apiladas de asistencia por día') }}">
                        @foreach ($p['dias'] as $d)
                            @php $faltas = $d['ausente'] + $d['sin_registro']; @endphp
                            <div class="flex-1 min-w-0 h-full flex flex-col justify-end group relative" wire:key="dia-{{ $d['fecha'] }}">
                                @if ($d['programados'] > 0)
                                    <div class="flex flex-col-reverse w-full rounded-t overflow-hidden {{ $d['es_hoy'] ? 'ring-1 ring-emerald-400' : '' }}" style="height: {{ $d['programados'] / $maxDia * 100 }}%">
                                        <div class="bg-emerald-500" style="flex: {{ $d['presente'] }}"></div>
                                        <div class="bg-amber-500" style="flex: {{ $d['tarde'] }}"></div>
                                        <div class="bg-blue-500" style="flex: {{ $d['justificado'] }}"></div>
                                        <div class="bg-red-500" style="flex: {{ $faltas }}"></div>
                                        <div class="bg-zinc-200 dark:bg-zinc-700" style="flex: {{ $d['pendiente'] }}"></div>
                                    </div>
                                    <div class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1 z-10 hidden group-hover:block whitespace-nowrap rounded-md bg-zinc-900 text-white text-xs px-2 py-1 shadow-lg">
                                        {{ \Carbon\Carbon::parse($d['fecha'])->locale('es')->isoFormat('ddd D MMM') }}:
                                        {{ $d['presente'] }} {{ __('pres.') }} · {{ $d['tarde'] }} {{ __('tarde') }} · {{ $faltas }} {{ __('faltas') }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1 flex gap-1 text-[10px] text-zinc-500 dark:text-zinc-400">
                        @foreach ($p['dias'] as $d)
                            <div class="flex-1 min-w-0 text-center truncate">{{ $loop->index % $cada === 0 ? $d['dia'] : '' }}</div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm p-5">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon.clock class="size-5 text-emerald-500" /> {{ __('¿A qué hora llegan?') }}
                    </h2>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">{{ __('Llegada respecto a la hora oficial de entrada') }}</p>
                    @php $maxLl = max(1, collect($p['llegada'])->max('porcentaje')); @endphp
                    <ul class="mt-4 space-y-2.5">
                        @foreach ($p['llegada'] as $b)
                            <li class="flex items-center gap-3 text-xs">
                                <span class="w-24 shrink-0 text-zinc-600 dark:text-zinc-300">{{ $b['etiqueta'] }}</span>
                                <div class="flex-1 h-3 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                    <div class="h-full rounded-full {{ $b['color'] }}" style="width: {{ $b['porcentaje'] / $maxLl * 100 }}%"></div>
                                </div>
                                <span class="w-14 text-right tabular-nums text-zinc-700 dark:text-zinc-200">{{ $b['cantidad'] }} <span class="text-zinc-400">· {{ round($b['porcentaje']) }}%</span></span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>

            {{-- Por grupo y por día --}}
            <section class="grid grid-cols-1 xl:grid-cols-3 gap-4 sm:gap-6">
                <div class="xl:col-span-2 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm">
                    <div class="p-5 pb-3">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                            <flux:icon.user-group class="size-5 text-emerald-500" /> {{ __('Desempeño por tipo de personal') }}
                        </h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400 border-y border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-800/30">
                                    <th class="px-5 py-2 font-medium">{{ __('Tipo') }}</th>
                                    <th class="px-3 py-2 font-medium text-right">{{ __('Jornadas') }}</th>
                                    <th class="px-3 py-2 font-medium min-w-36">{{ __('Asistencia') }}</th>
                                    <th class="px-3 py-2 font-medium min-w-36">{{ __('Puntualidad') }}</th>
                                    <th class="px-5 py-2 font-medium text-right">{{ __('Tardanzas') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($p['tipos'] as $tipo)
                                    <tr wire:key="tipo-{{ $loop->index }}">
                                        <td class="px-5 py-3 font-medium text-zinc-800 dark:text-zinc-100">{{ $tipo['tipo'] }}</td>
                                        <td class="px-3 py-3 text-right tabular-nums text-zinc-600 dark:text-zinc-300">{{ $tipo['programados'] }}</td>
                                        @foreach ([['tasa_asistencia', \App\Services\EstadisticasAsistencia::META_ASISTENCIA], ['puntualidad', \App\Services\EstadisticasAsistencia::META_PUNTUALIDAD]] as [$campo, $meta])
                                            @php $v = $tipo[$campo]; @endphp
                                            <td class="px-3 py-3">
                                                @if ($v === null)
                                                    <span class="text-zinc-400">—</span>
                                                @else
                                                    <div class="flex items-center gap-2">
                                                        <div class="flex-1 h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden"><div @class(['h-full rounded-full', 'bg-emerald-500' => $v >= $meta, 'bg-amber-500' => $v < $meta && $v >= $meta - 10, 'bg-red-500' => $v < $meta - 10]) style="width: {{ $v }}%"></div></div>
                                                        <span class="w-12 text-right tabular-nums text-xs text-zinc-700 dark:text-zinc-200">{{ rtrim(rtrim(number_format($v, 1), '0'), '.') }}%</span>
                                                    </div>
                                                @endif
                                            </td>
                                        @endforeach
                                        <td class="px-5 py-3 text-right tabular-nums text-zinc-600 dark:text-zinc-300">{{ $tipo['tarde'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm p-5">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon.calendar-days class="size-5 text-emerald-500" /> {{ __('Tardanzas por día de la semana') }}
                    </h2>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">{{ __('% de asistencias que llegaron tarde') }}</p>
                    @php $maxSem = max(1, collect($p['semana'])->max(fn ($d) => $d['tasa_tardanza'] ?? 0)); @endphp
                    <div class="mt-4 flex items-end justify-between gap-2 h-36">
                        @foreach ($p['semana'] as $d)
                            <div class="flex-1 h-full flex flex-col justify-end items-center gap-1" title="{{ $d['dia'] }}: {{ $d['tarde'] }} {{ __('tardanzas de') }} {{ $d['programados'] }} {{ __('jornadas') }}">
                                <span class="text-[10px] tabular-nums text-zinc-500 dark:text-zinc-400">{{ $d['tasa_tardanza'] !== null ? round($d['tasa_tardanza']).'%' : '' }}</span>
                                <div class="w-full rounded-t bg-amber-500/80" style="height: {{ $d['tasa_tardanza'] ? max(4, $d['tasa_tardanza'] / $maxSem * 100 * 0.8) : 0 }}%"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1 flex justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400 border-t border-zinc-200 dark:border-zinc-700 pt-1">
                        @foreach ($p['semana'] as $d)
                            <div class="flex-1 text-center">{{ $d['dia'] }}</div>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- Rankings --}}
            <section class="grid grid-cols-1 md:grid-cols-2 2xl:grid-cols-3 gap-4 sm:gap-6">
                @foreach ([
                    ['clave' => 'puntuales', 't' => __('Más puntuales'), 'ic' => 'trophy', 'col' => 'text-emerald-500', 'dato' => fn ($x) => rtrim(rtrim(number_format($x['puntualidad'], 1), '0'), '.').'%', 'vacio' => __('Se necesitan al menos 3 asistencias por persona.')],
                    ['clave' => 'tardanzas', 't' => __('Más tardanzas'), 'ic' => 'clock', 'col' => 'text-amber-500', 'dato' => fn ($x) => $x['tarde'].' · '.$x['minutos_tarde'].' min', 'vacio' => __('Sin tardanzas en el período.')],
                    ['clave' => 'inasistencias', 't' => __('Más inasistencias'), 'ic' => 'x-circle', 'col' => 'text-red-500', 'dato' => fn ($x) => $x['inasistencias'].' '.__('día(s)'), 'vacio' => __('Sin inasistencias en el período.')],
                ] as $r)
                    <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm p-5">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                            <flux:icon :name="$r['ic']" class="size-5 {{ $r['col'] }}" /> {{ $r['t'] }}
                        </h2>
                        <ol class="mt-3 space-y-1">
                            @forelse ($p['rankings'][$r['clave']] as $x)
                                <li wire:key="rank-{{ $r['clave'] }}-{{ $x['id'] }}" class="flex items-center gap-3 py-1.5">
                                    <span class="w-5 text-xs font-semibold text-zinc-400 tabular-nums">{{ $loop->iteration }}</span>
                                    <flux:avatar :name="$x['nombre']" size="xs" color="auto" />
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium text-zinc-800 dark:text-zinc-100 truncate">{{ $x['nombre'] }}</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400 truncate">{{ $x['tipo'] }}</div>
                                    </div>
                                    <span class="text-sm font-semibold tabular-nums text-zinc-700 dark:text-zinc-200 whitespace-nowrap">{{ $r['dato']($x) }}</span>
                                </li>
                            @empty
                                <li class="text-sm text-zinc-400 py-6 text-center">{{ $r['vacio'] }}</li>
                            @endforelse
                        </ol>
                    </div>
                @endforeach
            </section>
        @endunless

        {{-- Marcaciones y accesos --}}
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
            <div class="lg:col-span-2 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm p-5">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-3 flex items-center gap-2">
                    <flux:icon.finger-print class="size-5 text-emerald-500" /> {{ __('Últimas marcaciones de hoy') }}
                </h2>
                @forelse ($this->ultimosRegistros as $registro)
                    <div wire:key="reg-{{ $registro->id }}" class="flex items-center justify-between gap-3 py-2.5 border-b border-zinc-100 dark:border-zinc-800 last:border-0">
                        <div class="flex items-center gap-3 min-w-0">
                            <flux:avatar :name="$registro->personal->nombre_completo" size="xs" color="auto" />
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-zinc-800 dark:text-zinc-200 truncate">{{ $registro->personal->nombre_completo }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $registro->hora_entrada_maniana ? __('Entrada :h', ['h' => substr($registro->hora_entrada_maniana, 0, 5)]) : __('Sin hora de entrada') }}</div>
                            </div>
                        </div>
                        <span @class([
                            'inline-flex px-2 py-0.5 rounded-full text-xs font-semibold shrink-0',
                            'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300' => $registro->estado_dia === 'presente',
                            'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300' => $registro->estado_dia === 'tarde',
                            'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' => $registro->estado_dia === 'ausente',
                            'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' => $registro->estado_dia === 'justificado',
                        ])>{{ ucfirst($registro->estado_dia) }}</span>
                    </div>
                @empty
                    <p class="text-sm text-zinc-400 py-6 text-center">{{ __('Aún no hay marcaciones registradas hoy.') }}</p>
                @endforelse
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm p-5">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-3 flex items-center gap-2">
                    <flux:icon.bolt class="size-5 text-emerald-500" /> {{ __('Accesos rápidos') }}
                </h2>
                <div class="flex flex-col gap-1">
                    @foreach ([
                        ['permiso' => 'personal.ver', 'ruta' => 'personal.index', 'ic' => 'user-group', 'col' => 'text-emerald-500', 't' => __('Gestionar personal')],
                        ['permiso' => 'asistencia.ver', 'ruta' => 'asistencia-personal.index', 'ic' => 'finger-print', 'col' => 'text-emerald-500', 't' => __('Ver asistencia de hoy')],
                        ['permiso' => 'asistencia.gestionar', 'ruta' => 'asistencia-personal.importar', 'ic' => 'arrow-up-tray', 'col' => 'text-emerald-500', 't' => __('Cargar reporte del biométrico')],
                        ['permiso' => 'reportes.ver', 'ruta' => 'asistencia-personal.reporte', 'ic' => 'document-chart-bar', 'col' => 'text-emerald-500', 't' => __('Generar reporte')],
                        ['permiso' => 'usuarios.gestionar', 'ruta' => 'usuarios.index', 'ic' => 'users', 'col' => 'text-zinc-500', 't' => __('Usuarios')],
                        ['permiso' => 'roles.gestionar', 'ruta' => 'roles.index', 'ic' => 'key', 'col' => 'text-zinc-500', 't' => __('Roles y permisos')],
                    ] as $a)
                        @can($a['permiso'])
                            <a href="{{ route($a['ruta']) }}" wire:navigate class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                <flux:icon :name="$a['ic']" class="size-4 {{ $a['col'] }}" /> {{ $a['t'] }}
                            </a>
                        @endcan
                    @endforeach
                </div>
            </div>
        </section>
    @else
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-sm p-6">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-3 flex items-center gap-2">
                <flux:icon.bolt class="size-5 text-emerald-500" /> {{ __('Accesos rápidos') }}
            </h2>
            <div class="flex flex-col gap-1">
                @can('personal.ver')
                    <a href="{{ route('personal.index') }}" wire:navigate class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 text-sm font-medium text-zinc-700 dark:text-zinc-300"><flux:icon.user-group class="size-4 text-emerald-500" /> {{ __('Gestionar personal') }}</a>
                @endcan
                @can('usuarios.gestionar')
                    <a href="{{ route('usuarios.index') }}" wire:navigate class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 text-sm font-medium text-zinc-700 dark:text-zinc-300"><flux:icon.users class="size-4 text-zinc-500" /> {{ __('Usuarios') }}</a>
                @endcan
                @cannot('personal.ver')
                    @cannot('usuarios.gestionar')
                        <p class="text-sm text-zinc-500">{{ __('Tu cuenta aún no tiene permisos asignados. Pide a un administrador que te asigne un rol.') }}</p>
                    @endcannot
                @endcannot
            </div>
        </div>
    @endif
</div>
