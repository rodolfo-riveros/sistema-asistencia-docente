<?php

use App\Models\HorarioPersonals;
use App\Services\ImportadorPersonal;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Importar personal')] class extends Component {
    use WithFileUploads;

    public $archivo;

    public string $modo = ImportadorPersonal::MODO_COMPLETO;

    public bool $actualizar = true;

    public bool $reemplazarHorarios = false;

    /** Vista previa ya resumida: errores globales, resumen y filas. */
    public ?array $vista = null;

    public ?array $resultado = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('personal.gestionar'), 403);
    }

    public function updatedArchivo(): void
    {
        $this->resultado = null;
        $this->analizar();
    }

    public function updatedModo(): void
    {
        $this->resultado = null;
        $this->archivo ? $this->analizar() : null;
    }

    public function updatedActualizar(): void
    {
        $this->archivo ? $this->analizar() : null;
    }

    private function validarArchivo(): bool
    {
        try {
            $this->validate(
                ['archivo' => 'required|file|extensions:csv,txt|max:5120'],
                [
                    'archivo.extensions' => __('El archivo debe ser .csv (en Excel: Guardar como → CSV).'),
                    'archivo.max' => __('El archivo no puede pesar más de 5 MB.'),
                    'archivo.required' => __('Selecciona un archivo.'),
                ]
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->vista = null;

            throw $e;
        }

        return true;
    }

    public function analizar(): void
    {
        abort_unless(auth()->user()->can('personal.gestionar'), 403);
        $this->validarArchivo();

        $analisis = app(ImportadorPersonal::class)->analizar($this->archivo->getRealPath(), $this->modo, $this->actualizar);

        $this->vista = [
            'errores_globales' => $analisis['errores_globales'],
            'ignoradas' => $analisis['ignoradas'],
            'resumen' => $analisis['resumen'],
            'filas' => array_map(fn ($f) => [
                'linea' => $f['linea'],
                'dni' => $f['dni'] ?: $f['dni_original'],
                'nombre' => $f['nombre'],
                'accion' => $f['accion'],
                'errores' => $f['errores'],
                'avisos' => $f['avisos'],
                'horario' => collect($f['horarios'])
                    ->filter()
                    ->map(fn ($h, $dia) => HorarioPersonals::DIAS_CORTO[$dia].' '.substr($h['hora_entrada'], 0, 5).'–'.substr($h['hora_salida'], 0, 5))
                    ->implode(' · '),
            ], $analisis['filas']),
        ];
    }

    public function importar(): void
    {
        abort_unless(auth()->user()->can('personal.gestionar'), 403);
        $this->validarArchivo();

        $this->resultado = app(ImportadorPersonal::class)->importar(
            $this->archivo->getRealPath(),
            $this->modo,
            $this->actualizar,
            $this->reemplazarHorarios,
        );

        $this->reset(['archivo', 'vista']);

        Flux::toast(variant: 'success', text: __('Importación terminada.'));
    }

    public function limpiar(): void
    {
        $this->reset(['archivo', 'vista', 'resultado']);
    }

    public function getImportablesProperty(): int
    {
        $r = $this->vista['resumen'] ?? [];

        return ($r['crear'] ?? 0) + ($r['actualizar'] ?? 0) + ($r['horario'] ?? 0);
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
                <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-teal-100 dark:bg-teal-500/20 border border-teal-300 dark:border-teal-400/30 shadow-lg">
                    <flux:icon.arrow-up-tray class="w-6 h-6 text-teal-600 dark:text-teal-300" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white tracking-tight">{{ __('Importar personal y horarios') }}</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-300 mt-0.5">{{ __('Carga masiva desde un archivo CSV (Excel)') }}</p>
                </div>
            </div>
            <a href="{{ route('personal.index') }}" wire:navigate
               class="flex items-center gap-2 px-4 py-2 rounded-lg bg-white/80 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-white dark:hover:bg-zinc-700 transition">
                <flux:icon.arrow-left class="w-4 h-4" />
                {{ __('Volver') }}
            </a>
        </div>
    </div>

    {{-- Resultado de la última importación --}}
    @if ($resultado)
        <div class="mb-6 rounded-xl border border-green-200 dark:border-green-900/50 bg-green-50 dark:bg-green-900/20 p-5">
            <div class="flex items-center gap-2 mb-3">
                <flux:icon.check-circle class="w-5 h-5 text-green-600 dark:text-green-400" />
                <h2 class="font-semibold text-green-800 dark:text-green-300">{{ __('Importación completada') }}</h2>
            </div>
            @if (! empty($resultado['errores_globales']))
                <ul class="text-sm text-red-700 dark:text-red-300 list-disc pl-5">
                    @foreach ($resultado['errores_globales'] as $e) <li>{{ $e }}</li> @endforeach
                </ul>
            @else
                <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-center">
                    @foreach ([
                        ['Creados', $resultado['creados'], 'text-green-700 dark:text-green-300'],
                        ['Actualizados', $resultado['actualizados'], 'text-blue-700 dark:text-blue-300'],
                        ['Días de horario', $resultado['dias_horario'], 'text-teal-700 dark:text-teal-300'],
                        ['Omitidos', $resultado['omitidos'], 'text-zinc-600 dark:text-zinc-300'],
                        ['Con errores', $resultado['con_errores'], 'text-red-700 dark:text-red-300'],
                    ] as [$etiqueta, $valor, $color])
                        <div class="rounded-lg bg-white/70 dark:bg-zinc-900/50 p-3">
                            <div class="text-2xl font-bold {{ $color }}">{{ $valor }}</div>
                            <div class="text-xs text-zinc-500">{{ __($etiqueta) }}</div>
                        </div>
                    @endforeach
                </div>
                <a href="{{ route('personal.index') }}" wire:navigate class="inline-flex items-center gap-1 mt-4 text-sm font-medium text-teal-700 dark:text-teal-300 hover:underline">
                    {{ __('Ver personal') }} <flux:icon.arrow-right class="w-4 h-4" />
                </a>
            @endif
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        {{-- Paso 1 --}}
        <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-3 flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-teal-600 text-xs font-bold text-white">1</span>
                {{ __('Prepara el archivo') }}
            </h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">
                {{ __('Descarga la plantilla, llénala en Excel y guárdala como CSV. También puedes usar la descarga masiva de personal, editarla y volver a subirla.') }}
            </p>
            <div class="flex flex-col gap-2">
                <a href="{{ route('personal.plantilla') }}"
                   class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-semibold shadow transition">
                    <flux:icon.arrow-down-tray class="w-4 h-4" /> {{ __('Descargar plantilla') }}
                </a>
                <a href="{{ route('personal.exportar') }}"
                   class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium transition">
                    <flux:icon.table-cells class="w-4 h-4" /> {{ __('Descargar personal actual') }}
                </a>
            </div>
            <div class="mt-4 rounded-lg bg-zinc-50 dark:bg-zinc-800/60 p-3 text-xs text-zinc-500 dark:text-zinc-400 space-y-1">
                <p><strong class="text-zinc-700 dark:text-zinc-300">{{ __('Horario:') }}</strong> <code>07:30-15:30</code></p>
                <p><strong class="text-zinc-700 dark:text-zinc-300">{{ __('Doble jornada:') }}</strong> <code>08:00-13:00 / 14:30-17:30</code></p>
                <p><strong class="text-zinc-700 dark:text-zinc-300">{{ __('Día libre:') }}</strong> {{ __('celda vacía') }}</p>
                <p><strong class="text-zinc-700 dark:text-zinc-300">{{ __('Fecha:') }}</strong> <code>dd/mm/aaaa</code></p>
            </div>
        </div>

        {{-- Paso 2 --}}
        <div class="lg:col-span-2 bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-teal-600 text-xs font-bold text-white">2</span>
                {{ __('Elige qué importar y sube el archivo') }}
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                @foreach ([
                    ImportadorPersonal::MODO_COMPLETO => ['Personal y horarios', 'Crea o actualiza personas y, si el archivo trae días, también sus horarios.'],
                    ImportadorPersonal::MODO_HORARIOS => ['Solo horarios', 'Solo actualiza los horarios de personal que ya existe (por DNI).'],
                ] as $valor => [$titulo, $descripcion])
                    <label class="cursor-pointer rounded-lg border p-3 transition {{ $modo === $valor ? 'border-teal-500 bg-teal-50 dark:bg-teal-950/30 ring-1 ring-teal-500' : 'border-zinc-200 dark:border-zinc-700 hover:border-teal-300' }}">
                        <div class="flex items-center gap-2">
                            <input type="radio" wire:model.live="modo" value="{{ $valor }}" class="text-teal-600 focus:ring-teal-500">
                            <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __($titulo) }}</span>
                        </div>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __($descripcion) }}</p>
                    </label>
                @endforeach
            </div>

            <div class="flex flex-col gap-2 mb-4 text-sm text-zinc-700 dark:text-zinc-300">
                @if ($modo === \App\Services\ImportadorPersonal::MODO_COMPLETO)
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model.live="actualizar" class="rounded text-teal-600 focus:ring-teal-500">
                        {{ __('Actualizar los datos de personal que ya existe (mismo DNI)') }}
                    </label>
                @endif
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model="reemplazarHorarios" class="rounded text-teal-600 focus:ring-teal-500">
                    {{ __('Reemplazar horarios existentes (los días en blanco quedan como libres)') }}
                </label>
            </div>

            <input type="file" wire:model="archivo" accept=".csv,.txt,text/csv"
                   class="block w-full text-sm text-zinc-500 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-zinc-100 dark:file:bg-zinc-800 file:text-zinc-700 dark:file:text-zinc-300 hover:file:bg-zinc-200 dark:hover:file:bg-zinc-700">
            @error('archivo') <p class="mt-2 text-xs text-red-500">{{ $message }}</p> @enderror
            <p wire:loading wire:target="archivo" class="mt-2 text-xs text-teal-600">{{ __('Leyendo archivo...') }}</p>
        </div>
    </div>

    {{-- Paso 3: vista previa --}}
    @if ($vista)
        <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-md border border-zinc-200 dark:border-zinc-800 overflow-hidden">
            <div class="p-6 border-b border-zinc-100 dark:border-zinc-800">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-teal-600 text-xs font-bold text-white">3</span>
                    {{ __('Revisa y confirma') }}
                </h2>

                @if ($vista['errores_globales'])
                    <div class="rounded-lg border border-red-200 dark:border-red-900/50 bg-red-50 dark:bg-red-900/20 p-4 text-sm text-red-700 dark:text-red-300">
                        <ul class="list-disc pl-5 space-y-1">
                            @foreach ($vista['errores_globales'] as $e) <li>{{ $e }}</li> @endforeach
                        </ul>
                    </div>
                @else
                    @php $r = $vista['resumen']; @endphp
                    <div class="flex flex-wrap gap-2 text-xs font-semibold">
                        <span class="px-2.5 py-1 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300">{{ __(':n filas', ['n' => $r['total']]) }}</span>
                        @if ($r['crear']) <span class="px-2.5 py-1 rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300">{{ __(':n nuevos', ['n' => $r['crear']]) }}</span> @endif
                        @if ($r['actualizar']) <span class="px-2.5 py-1 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">{{ __(':n a actualizar', ['n' => $r['actualizar']]) }}</span> @endif
                        @if ($r['horario']) <span class="px-2.5 py-1 rounded-full bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-300">{{ __(':n horarios', ['n' => $r['horario']]) }}</span> @endif
                        @if ($r['omitir']) <span class="px-2.5 py-1 rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300">{{ __(':n omitidos', ['n' => $r['omitir']]) }}</span> @endif
                        @if ($r['error']) <span class="px-2.5 py-1 rounded-full bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300">{{ __(':n con errores (no se importarán)', ['n' => $r['error']]) }}</span> @endif
                    </div>

                    @if ($vista['ignoradas'])
                        <p class="mt-3 text-xs text-zinc-400">{{ __('Columnas no reconocidas (se ignoran):') }} {{ implode(', ', $vista['ignoradas']) }}</p>
                    @endif
                @endif
            </div>

            @if (! $vista['errores_globales'])
                <div class="overflow-auto max-h-[28rem]">
                    <table class="w-full text-sm">
                        <thead class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 sticky top-0">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Línea') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('DNI') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Personal') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Horario') }}</th>
                                <th class="px-4 py-2 text-left text-xs font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Resultado') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($vista['filas'] as $fila)
                                <tr wire:key="fila-{{ $fila['linea'] }}" class="{{ $fila['accion'] === 'error' ? 'bg-red-50/60 dark:bg-red-950/20' : '' }}">
                                    <td class="px-4 py-2 font-mono text-xs text-zinc-400">{{ $fila['linea'] }}</td>
                                    <td class="px-4 py-2 font-mono text-xs text-zinc-600 dark:text-zinc-300">{{ $fila['dni'] }}</td>
                                    <td class="px-4 py-2 text-zinc-800 dark:text-zinc-100">{{ $fila['nombre'] ?: '—' }}</td>
                                    <td class="px-4 py-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $fila['horario'] ?: '—' }}</td>
                                    <td class="px-4 py-2 text-xs">
                                        <span @class([
                                            'inline-flex px-2 py-0.5 rounded-full font-semibold',
                                            'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300' => $fila['accion'] === 'crear',
                                            'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300' => $fila['accion'] === 'actualizar',
                                            'bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-300' => $fila['accion'] === 'horario',
                                            'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300' => $fila['accion'] === 'omitir',
                                            'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' => $fila['accion'] === 'error',
                                        ])>
                                            {{ match ($fila['accion']) { 'crear' => __('Nuevo'), 'actualizar' => __('Actualiza'), 'horario' => __('Horario'), 'omitir' => __('Omitido'), default => __('Error') } }}
                                        </span>
                                        @foreach ($fila['errores'] as $e)
                                            <div class="mt-1 text-red-600 dark:text-red-400">{{ $e }}</div>
                                        @endforeach
                                        @foreach ($fila['avisos'] as $a)
                                            <div class="mt-1 text-amber-600 dark:text-amber-400">{{ $a }}</div>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="p-4 flex items-center justify-between gap-3 border-t border-zinc-100 dark:border-zinc-800 flex-wrap">
                <button type="button" wire:click="limpiar" class="px-4 py-2.5 rounded-lg border border-zinc-300 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium hover:bg-zinc-50 dark:hover:bg-zinc-800 transition">
                    {{ __('Cancelar') }}
                </button>
                <button type="button" wire:click="importar" wire:loading.attr="disabled" wire:target="importar"
                        @disabled($this->importables === 0)
                        class="px-6 py-2.5 bg-teal-600 hover:bg-teal-500 text-white text-sm font-semibold rounded-lg shadow transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
                    <flux:icon.loading wire:loading wire:target="importar" variant="mini" class="w-4 h-4" />
                    <span wire:loading.remove wire:target="importar">{{ __('Importar :n filas válidas', ['n' => $this->importables]) }}</span>
                    <span wire:loading wire:target="importar">{{ __('Importando...') }}</span>
                </button>
            </div>
        </div>
    @endif
</div>
