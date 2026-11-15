<?php

use App\Services\ImportadorBiometrico;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Cargar reporte del biométrico')] class extends Component {
    use WithFileUploads;

    public $archivo;

    public string $existentes = ImportadorBiometrico::EXISTENTES_OMITIR;

    public ?array $vista = null;

    public ?array $resultado = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('asistencia.gestionar'), 403);
    }

    public function updatedArchivo(): void
    {
        $this->resultado = null;
        $this->vista = null;

        abort_unless(auth()->user()->can('asistencia.gestionar'), 403);

        $this->validate(
            ['archivo' => 'required|file|extensions:xls,xlsx|max:10240'],
            [
                'archivo.extensions' => __('El archivo debe ser el reporte del biométrico en formato .xls o .xlsx.'),
                'archivo.max' => __('El archivo no puede pesar más de 10 MB.'),
            ]
        );

        $this->vista = app(ImportadorBiometrico::class)->analizar($this->archivo->getRealPath());
    }

    public function importar(): void
    {
        abort_unless(auth()->user()->can('asistencia.gestionar'), 403);

        $this->validate(['archivo' => 'required|file|extensions:xls,xlsx|max:10240']);

        $this->resultado = app(ImportadorBiometrico::class)->importar(
            $this->archivo->getRealPath(),
            $this->existentes,
            auth()->id(),
        );

        $this->reset(['archivo', 'vista']);

        if (! $this->resultado['error']) {
            Flux::toast(variant: 'success', text: __('Carga terminada.'));
        }
    }

    public function limpiar(): void
    {
        $this->reset(['archivo', 'vista', 'resultado']);
    }
}; ?>

<div>
    {{-- Encabezado --}}
    <div class="relative overflow-hidden rounded-2xl
            bg-gradient-to-r from-zinc-100 via-zinc-50 to-emerald-100
            dark:from-zinc-900 dark:via-zinc-800 dark:to-emerald-950
            p-6 mb-6 shadow-xl border border-zinc-200 dark:border-transparent">
        <div class="absolute inset-0 overflow-hidden">
            <div class="absolute -right-10 -top-10 h-32 w-32 rounded-full bg-emerald-400/20 dark:bg-emerald-500/10 blur-2xl"></div>
            <div class="absolute -bottom-10 -left-10 h-32 w-32 rounded-full bg-emerald-400/20 dark:bg-emerald-500/10 blur-2xl"></div>
        </div>
        <div class="relative z-10 flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-4">
                <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-emerald-100 dark:bg-emerald-500/20 border border-emerald-300 dark:border-emerald-400/30 shadow-lg">
                    <flux:icon.finger-print class="w-6 h-6 text-emerald-600 dark:text-emerald-300" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white tracking-tight">{{ __('Cargar reporte del biométrico') }}</h1>
                    <p class="text-sm text-zinc-500 dark:text-zinc-300 mt-0.5">{{ __('Respaldo para cuando la API en línea no pudo recibir las marcaciones') }}</p>
                </div>
            </div>
            <a href="{{ route('asistencia-personal.index') }}" wire:navigate
               class="flex items-center gap-2 px-4 py-2 rounded-lg bg-white/80 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-white dark:hover:bg-zinc-700 transition">
                <flux:icon.arrow-left class="w-4 h-4" />
                {{ __('Volver') }}
            </a>
        </div>
    </div>

    <div class="max-w-3xl">
        {{-- Error al procesar --}}
        @if ($resultado && $resultado['error'])
            <div class="mb-6 rounded-xl border border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 p-4 flex items-start gap-3">
                <flux:icon.exclamation-circle class="w-5 h-5 text-red-500 shrink-0 mt-0.5" />
                <p class="text-sm font-medium text-red-700 dark:text-red-300">{{ $resultado['error'] }}</p>
            </div>
        @endif

        {{-- Resultado --}}
        @if ($resultado && ! $resultado['error'])
            @php $nuevos = $resultado['registrados'] + $resultado['completados']; @endphp
            <div class="mb-6 rounded-2xl border p-6 {{ $nuevos > 0 ? 'bg-green-50 dark:bg-green-900/20 border-green-300 dark:border-green-700' : 'bg-amber-50 dark:bg-amber-900/20 border-amber-300 dark:border-amber-700' }}">
                <p class="font-bold mb-4 {{ $nuevos > 0 ? 'text-green-800 dark:text-green-200' : 'text-amber-800 dark:text-amber-200' }}">
                    {{ $nuevos > 0 ? __('Carga completada') : __('No hubo registros nuevos') }}
                </p>

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="bg-white dark:bg-zinc-900/50 rounded-xl p-4 text-center border border-green-200 dark:border-green-800">
                        <p class="text-3xl font-bold text-green-600 dark:text-green-400">{{ $resultado['registrados'] }}</p>
                        <p class="text-xs text-zinc-500 mt-1">{{ __('Asistencias nuevas') }}</p>
                    </div>
                    <div class="bg-white dark:bg-zinc-900/50 rounded-xl p-4 text-center border border-blue-200 dark:border-blue-800">
                        <p class="text-3xl font-bold text-blue-600 dark:text-blue-400">{{ $resultado['completados'] }}</p>
                        <p class="text-xs text-zinc-500 mt-1">{{ __('Registros completados') }}</p>
                    </div>
                </div>

                <div class="space-y-1.5 text-sm text-zinc-600 dark:text-zinc-400">
                    @foreach ([
                        'ya_existian' => 'Ya tenían asistencia registrada (omitidos)',
                        'manuales' => 'Marcados manualmente como falta/justificado (omitidos)',
                        'sin_personal' => 'ID no encontrado en el sistema',
                        'sin_horario' => 'Sin horario configurado ese día',
                        'inactivos' => 'Personal inactivo',
                        'errores' => 'Errores al guardar',
                    ] as $clave => $texto)
                        @if ($resultado[$clave] > 0)
                            <div class="flex justify-between {{ $clave === 'errores' ? 'text-red-600 dark:text-red-400' : '' }}">
                                <span>– {{ __($texto) }}</span>
                                <span class="font-semibold">{{ $resultado[$clave] }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>

                @if (! empty($resultado['detalle_errores']))
                    <div class="mt-3 rounded-lg bg-red-50 dark:bg-red-900/20 p-3">
                        @foreach ($resultado['detalle_errores'] as $e)
                            <p class="text-xs font-mono text-red-500">{{ $e }}</p>
                        @endforeach
                    </div>
                @endif

                <a href="{{ route('asistencia-personal.index') }}" wire:navigate class="inline-flex items-center gap-1 mt-4 text-sm font-medium text-emerald-700 dark:text-emerald-300 hover:underline">
                    {{ __('Ver asistencia') }} <flux:icon.arrow-right class="w-4 h-4" />
                </a>
            </div>
        @endif

        <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6 mb-6">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-2">{{ __('1. Exporta el reporte desde el biométrico') }}</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">
                {{ __('Usa el archivo Excel "Reporte de Asistencia" (.xls o .xlsx) tal como lo entrega el software del reloj, sin modificarlo.') }}
                {{ __('El ID de cada persona en el reloj debe ser el mismo ID que tiene en el sistema (columna ID en Personal): es el mismo código que usa la API.') }}
            </p>

            <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-3">{{ __('2. Elige cómo tratar lo que ya está registrado') }}</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-5">
                @foreach ([
                    ImportadorBiometrico::EXISTENTES_OMITIR => ['Omitir días ya registrados', 'No toca ninguna asistencia que ya exista (lo más seguro).'],
                    ImportadorBiometrico::EXISTENTES_COMPLETAR => ['Completar registros parciales', 'Si la API alcanzó a guardar solo la entrada, agrega las marcas que faltan sin cambiar las existentes.'],
                ] as $valor => [$titulo, $descripcion])
                    <label class="cursor-pointer rounded-lg border p-3 transition {{ $existentes === $valor ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/30 ring-1 ring-emerald-500' : 'border-zinc-200 dark:border-zinc-700 hover:border-emerald-300' }}">
                        <div class="flex items-center gap-2">
                            <input type="radio" wire:model.live="existentes" value="{{ $valor }}" class="text-emerald-600 focus:ring-emerald-500">
                            <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __($titulo) }}</span>
                        </div>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __($descripcion) }}</p>
                    </label>
                @endforeach
            </div>

            <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-3">{{ __('3. Sube el archivo') }}</h2>
            <input type="file" wire:model="archivo" accept=".xls,.xlsx"
                   class="block w-full text-sm text-zinc-500 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-zinc-100 dark:file:bg-zinc-800 file:text-zinc-700 dark:file:text-zinc-300 hover:file:bg-zinc-200 dark:hover:file:bg-zinc-700">
            @error('archivo') <p class="mt-2 text-xs text-red-500">{{ $message }}</p> @enderror
            <p wire:loading wire:target="archivo" class="mt-2 text-xs text-emerald-600">{{ __('Leyendo archivo...') }}</p>
        </div>

        {{-- Vista previa --}}
        @if ($vista)
            @if ($vista['error'])
                <div class="rounded-xl border border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-900/20 p-4 flex items-start gap-3">
                    <flux:icon.exclamation-circle class="w-5 h-5 text-red-500 shrink-0 mt-0.5" />
                    <p class="text-sm font-medium text-red-700 dark:text-red-300">{{ $vista['error'] }}</p>
                </div>
            @else
                <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-md border border-zinc-200 dark:border-zinc-800 p-6">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-4">{{ __('Revisa antes de importar') }}</h2>

                    <dl class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center mb-4">
                        <div class="rounded-lg bg-zinc-50 dark:bg-zinc-800/60 p-3 col-span-2">
                            <dt class="text-xs text-zinc-500">{{ __('Período') }}</dt>
                            <dd class="font-semibold text-zinc-800 dark:text-zinc-100 text-sm">{{ $vista['periodo'] }}</dd>
                        </div>
                        <div class="rounded-lg bg-zinc-50 dark:bg-zinc-800/60 p-3">
                            <dt class="text-xs text-zinc-500">{{ __('Personas con marcas') }}</dt>
                            <dd class="text-xl font-bold text-zinc-800 dark:text-zinc-100">{{ $vista['personas'] }}</dd>
                        </div>
                        <div class="rounded-lg bg-zinc-50 dark:bg-zinc-800/60 p-3">
                            <dt class="text-xs text-zinc-500">{{ __('Días con datos') }}</dt>
                            <dd class="text-xl font-bold text-zinc-800 dark:text-zinc-100">{{ $vista['dias_con_datos'] }}</dd>
                        </div>
                    </dl>

                    @if ($vista['desconocidos'])
                        <div class="mb-4 rounded-lg border border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-900/20 p-3 text-sm text-amber-800 dark:text-amber-300">
                            {{ __(':n ID del reporte no existen en el sistema y se omitirán:', ['n' => count($vista['desconocidos'])]) }}
                            <span class="font-mono">{{ implode(', ', array_slice($vista['desconocidos'], 0, 15)) }}{{ count($vista['desconocidos']) > 15 ? '…' : '' }}</span>
                        </div>
                    @endif

                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <button type="button" wire:click="limpiar" class="px-4 py-2.5 rounded-lg border border-zinc-300 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium hover:bg-zinc-50 dark:hover:bg-zinc-800 transition">
                            {{ __('Cancelar') }}
                        </button>
                        <button type="button" wire:click="importar" wire:loading.attr="disabled" wire:target="importar"
                                @disabled($vista['encontradas'] === 0)
                                class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold rounded-lg shadow transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
                            <flux:icon.loading wire:loading wire:target="importar" variant="mini" class="w-4 h-4" />
                            <span wire:loading.remove wire:target="importar">{{ __('Importar asistencias') }}</span>
                            <span wire:loading wire:target="importar">{{ __('Importando...') }}</span>
                        </button>
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>
