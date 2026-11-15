<?php

use App\Models\HorarioPersonals;
use App\Models\Personal_ies;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Personal')] class extends Component {
    public ?int $personalId = null;

    public bool $isEditMode = false;

    public string $dni = '';

    public string $nombres = '';

    public string $apellido_paterno = '';

    public string $apellido_materno = '';

    public string $tipo_personal = 'docente';

    public string $cargo = '';

    public string $fecha_ingreso = '';

    public string $telefono = '';

    public array $tipos = [
        'docente' => 'Docente',
        'auxiliar' => 'Auxiliar',
        'administrativo' => 'Administrativo',
        'limpieza' => 'Limpieza',
        'vigilancia' => 'Vigilancia',
        'directivo' => 'Directivo',
        'otro' => 'Otro',
    ];

    // Horario semanal indexado por día ISO (1=Lunes .. 7=Domingo)
    public array $horarios = [];

    public function rules(): array
    {
        $uniqueDni = 'unique:personal_ies,dni'.($this->personalId ? ",{$this->personalId}" : '');

        return [
            'dni' => "required|string|max:20|{$uniqueDni}",
            'nombres' => 'required|string|max:100',
            'apellido_paterno' => 'required|string|max:100',
            'apellido_materno' => 'nullable|string|max:100',
            'tipo_personal' => 'required|in:docente,auxiliar,administrativo,limpieza,vigilancia,directivo,otro',
            'cargo' => 'nullable|string|max:150',
            'fecha_ingreso' => 'nullable|date',
            'telefono' => 'nullable|string|max:20',
        ];
    }

    public function mount(?int $id = null): void
    {
        foreach (HorarioPersonals::DIAS as $dia => $label) {
            $this->horarios[$dia] = [
                'activo' => false,
                'doble' => false,
                'hora_entrada' => '',
                'hora_salida_maniana' => '',
                'hora_entrada_tarde' => '',
                'hora_salida' => '',
                'tolerancia_minutos' => 10,
            ];
        }

        if ($id) {
            $this->isEditMode = true;
            $this->personalId = $id;
            $this->loadPersonal();
        } else {
            $this->fecha_ingreso = now()->format('Y-m-d');
        }
    }

    private function loadPersonal(): void
    {
        $p = Personal_ies::with('horarios')->findOrFail($this->personalId);

        $this->dni = $p->dni;
        $this->nombres = $p->nombres;
        $this->apellido_paterno = $p->apellido_paterno;
        $this->apellido_materno = $p->apellido_materno ?? '';
        $this->tipo_personal = $p->tipo_personal;
        $this->cargo = $p->cargo ?? '';
        $this->fecha_ingreso = $p->fecha_ingreso?->format('Y-m-d') ?? '';
        $this->telefono = $p->telefono ?? '';

        foreach ($p->horarios as $h) {
            if (! isset($this->horarios[$h->dia_semana])) {
                continue;
            }

            $this->horarios[$h->dia_semana] = [
                'activo' => $h->activo,
                'doble' => $h->esDobleJornada(),
                'hora_entrada' => $h->hora_entrada ? substr($h->hora_entrada, 0, 5) : '',
                'hora_salida_maniana' => $h->hora_salida_maniana ? substr($h->hora_salida_maniana, 0, 5) : '',
                'hora_entrada_tarde' => $h->hora_entrada_tarde ? substr($h->hora_entrada_tarde, 0, 5) : '',
                'hora_salida' => $h->hora_salida ? substr($h->hora_salida, 0, 5) : '',
                'tolerancia_minutos' => $h->tolerancia_minutos,
            ];
        }
    }

    public function updatedHorarios($value, $key): void
    {
        $parts = explode('.', $key);
        if (count($parts) === 2 && $parts[1] === 'doble' && ! $value) {
            $dia = (int) $parts[0];
            $this->horarios[$dia]['hora_salida_maniana'] = '';
            $this->horarios[$dia]['hora_entrada_tarde'] = '';
        }
    }

    /**
     * Copia el horario del día indicado a los demás días laborables (lunes a viernes),
     * para evitar tener que repetir la misma configuración día por día.
     */
    public function copiarALaborables(int $diaOrigen): void
    {
        $origen = $this->horarios[$diaOrigen];

        foreach ([1, 2, 3, 4, 5] as $dia) {
            if ($dia === $diaOrigen) {
                continue;
            }
            $this->horarios[$dia] = $origen;
        }

        Flux::toast(variant: 'success', text: __('Horario copiado de lunes a viernes.'));
    }

    /** Horario general que se aplica de una sola vez a los días marcados. */
    public array $general = [
        'doble' => false,
        'hora_entrada' => '',
        'hora_salida_maniana' => '',
        'hora_entrada_tarde' => '',
        'hora_salida' => '',
        'tolerancia_minutos' => 10,
    ];

    public function toggleDia(int $dia): void
    {
        $this->horarios[$dia]['activo'] = ! $this->horarios[$dia]['activo'];

        // Al marcar un día vacío, hereda el horario general si ya lo definieron.
        if ($this->horarios[$dia]['activo'] && empty($this->horarios[$dia]['hora_entrada']) && ! empty($this->general['hora_entrada'])) {
            $this->horarios[$dia] = ['activo' => true] + $this->general;
        }
    }

    public function seleccionarDias(string $preset): void
    {
        $dias = match ($preset) {
            'lv' => [1, 2, 3, 4, 5],
            'lvs' => [1, 2, 3, 4, 5, 6],
            'todos' => [1, 2, 3, 4, 5, 6, 7],
            default => [],
        };

        foreach ($this->horarios as $dia => $h) {
            $debeEstarActivo = in_array($dia, $dias, true);

            if ($debeEstarActivo && ! $h['activo'] && ! empty($this->general['hora_entrada'])) {
                $this->horarios[$dia] = ['activo' => true] + $this->general;
            } else {
                $this->horarios[$dia]['activo'] = $debeEstarActivo;
            }
        }
    }

    public function updatedGeneral($value, $key): void
    {
        if ($key === 'doble' && ! $value) {
            $this->general['hora_salida_maniana'] = '';
            $this->general['hora_entrada_tarde'] = '';
        }
    }

    public function aplicarGeneral(): void
    {
        if (empty($this->general['hora_entrada']) || empty($this->general['hora_salida'])) {
            Flux::toast(variant: 'danger', text: __('Define la hora de entrada y de salida del horario general.'));

            return;
        }

        if ($this->general['doble'] && (empty($this->general['hora_salida_maniana']) || empty($this->general['hora_entrada_tarde']))) {
            Flux::toast(variant: 'danger', text: __('En doble jornada completa también la salida de mañana y la entrada de tarde.'));

            return;
        }

        $aplicados = 0;
        foreach ($this->horarios as $dia => $h) {
            if ($h['activo']) {
                $this->horarios[$dia] = ['activo' => true] + $this->general;
                $aplicados++;
            }
        }

        if ($aplicados === 0) {
            Flux::toast(variant: 'danger', text: __('Marca primero los días que trabaja.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Horario aplicado a :n día(s).', ['n' => $aplicados]));
    }

    public function consultarDni(): void
    {
        $this->validate(['dni' => 'required|digits:8']);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('services.dni_api.token'),
                'Accept' => 'application/json',
            ])
                ->get('https://api.apis.net.pe/v1/dni', [
                    'numero' => $this->dni,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->nombres = $data['nombres'] ?? '';
                $this->apellido_paterno = $data['apellidoPaterno'] ?? '';
                $this->apellido_materno = $data['apellidoMaterno'] ?? '';
                Flux::toast(variant: 'success', text: __('Datos encontrados.'));
            } else {
                Flux::toast(variant: 'danger', text: __('No se encontró información para el DNI ingresado.'));
            }
        } catch (\Exception $e) {
            Flux::toast(variant: 'danger', text: __('Error al conectar con la API de DNI.'));
        }
    }

    public function save(): void
    {
        $this->validate();

        foreach ($this->horarios as $dia => $h) {
            if (! $h['activo']) {
                continue;
            }
            if (empty($h['hora_entrada']) || empty($h['hora_salida'])) {
                $this->addError("horarios.{$dia}.hora_entrada", __('Hora de entrada y salida son obligatorias para el :dia.', ['dia' => HorarioPersonals::DIAS[$dia]]));

                return;
            }
            if ($h['doble'] && (empty($h['hora_salida_maniana']) || empty($h['hora_entrada_tarde']))) {
                $this->addError("horarios.{$dia}.hora_salida_maniana", __('En doble jornada, la salida de mañana y entrada de tarde son obligatorias (:dia).', ['dia' => HorarioPersonals::DIAS[$dia]]));

                return;
            }
        }

        DB::transaction(function () {
            $data = [
                'dni' => $this->dni,
                'nombres' => $this->nombres,
                'apellido_paterno' => $this->apellido_paterno,
                'apellido_materno' => $this->apellido_materno ?: null,
                'tipo_personal' => $this->tipo_personal,
                'cargo' => $this->cargo ?: null,
                'fecha_ingreso' => $this->fecha_ingreso ?: null,
                'telefono' => $this->telefono ?: null,
            ];

            if ($this->isEditMode) {
                $personal = Personal_ies::findOrFail($this->personalId);
                $personal->update($data);
            } else {
                $personal = Personal_ies::create($data + ['activo' => true]);
                $this->personalId = $personal->id;
            }

            foreach ($this->horarios as $dia => $h) {
                if (! $h['activo']) {
                    HorarioPersonals::where('personal_id', $personal->id)
                        ->where('dia_semana', $dia)
                        ->update(['activo' => false]);

                    continue;
                }

                $personal->horarios()->updateOrCreate(
                    ['dia_semana' => $dia],
                    [
                        'hora_entrada' => $h['hora_entrada'].':00',
                        'hora_salida_maniana' => $h['doble'] ? $h['hora_salida_maniana'].':00' : null,
                        'hora_entrada_tarde' => $h['doble'] ? $h['hora_entrada_tarde'].':00' : null,
                        'hora_salida' => $h['hora_salida'].':00',
                        'tolerancia_minutos' => (int) $h['tolerancia_minutos'],
                        'activo' => true,
                    ]
                );
            }
        });

        Flux::toast(variant: 'success', text: $this->isEditMode ? __('Personal actualizado correctamente.') : __('Personal registrado correctamente.'));

        $this->redirect(route('personal.index'), navigate: true);
    }
}; ?>

    <div>
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white tracking-tight">
                {{ $isEditMode ? __('Editar personal') : __('Nuevo personal') }}
            </h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-300 mt-0.5">
                {{ __('Registra los datos y el horario semanal para el control biométrico de asistencia.') }}
            </p>
        </div>

        <form wire:submit="save" class="space-y-6">

            {{-- Datos personales --}}
            <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-5 flex items-center gap-2">
                    <flux:icon.user class="w-5 h-5 text-emerald-500" />
                    {{ __('Datos personales') }}
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('DNI') }} <span class="text-red-500">*</span></label>
                        <div class="flex gap-2">
                            <input wire:model="dni" type="text" maxlength="8" placeholder="00000000"
                                   class="w-full px-3 py-2.5 rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm focus:ring-2 focus:ring-emerald-500 transition">
                            <button type="button" wire:click="consultarDni" wire:loading.attr="disabled" wire:target="consultarDni"
                                    class="px-3 py-2 bg-zinc-800 text-white rounded-lg text-xs font-medium hover:bg-zinc-700 transition flex items-center justify-center whitespace-nowrap">
                                <span wire:loading.remove wire:target="consultarDni">{{ __('Buscar') }}</span>
                                <flux:icon.loading wire:loading wire:target="consultarDni" variant="mini" class="w-4 h-4" />
                            </button>
                        </div>
                        @error('dni') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Nombres') }} <span class="text-red-500">*</span></label>
                        <input wire:model="nombres" type="text"
                               class="w-full px-3 py-2.5 rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm focus:ring-2 focus:ring-emerald-500 transition">
                        @error('nombres') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Apellido paterno') }} <span class="text-red-500">*</span></label>
                        <input wire:model="apellido_paterno" type="text"
                               class="w-full px-3 py-2.5 rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm focus:ring-2 focus:ring-emerald-500 transition">
                        @error('apellido_paterno') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Apellido materno') }}</label>
                        <input wire:model="apellido_materno" type="text"
                               class="w-full px-3 py-2.5 rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm focus:ring-2 focus:ring-emerald-500 transition">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Teléfono') }}</label>
                        <input wire:model="telefono" type="text"
                               class="w-full px-3 py-2.5 rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm focus:ring-2 focus:ring-emerald-500 transition">
                    </div>
                </div>
            </div>

            {{-- Datos laborales --}}
            <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6">
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white mb-5 flex items-center gap-2">
                    <flux:icon.briefcase class="w-5 h-5 text-emerald-500" />
                    {{ __('Datos laborales') }}
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Tipo de personal') }} <span class="text-red-500">*</span></label>
                        <select wire:model="tipo_personal"
                                class="w-full px-3 py-2.5 rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm focus:ring-2 focus:ring-emerald-500 transition">
                            @foreach ($tipos as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('tipo_personal') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Cargo específico') }}</label>
                        <input wire:model="cargo" type="text" placeholder="{{ __('Ej: Profesor de Matemática, Secretaria, etc.') }}"
                               class="w-full px-3 py-2.5 rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm focus:ring-2 focus:ring-emerald-500 transition">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">{{ __('Fecha de ingreso') }}</label>
                        <input wire:model="fecha_ingreso" type="date"
                               class="w-full px-3 py-2.5 rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-sm focus:ring-2 focus:ring-emerald-500 transition">
                    </div>
                </div>
            </div>

            {{-- Horario --}}
            <div id="horarios" class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6">
                <div class="flex items-center justify-between gap-3 mb-1">
                    <div class="flex items-center gap-2">
                        <flux:icon.clock class="size-5 text-emerald-500" />
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ __('Horario de trabajo') }}</h2>
                    </div>
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-semibold bg-zinc-100 dark:bg-zinc-700 text-zinc-700 dark:text-zinc-300">
                        {{ trans_choice(':count día activo|:count días activos', collect($horarios)->where('activo', true)->count(), ['count' => collect($horarios)->where('activo', true)->count()]) }}
                    </span>
                </div>
                <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-5">{{ __('Paso 1: marca los días que trabaja. Paso 2: define el horario general y aplícalo. Paso 3 (opcional): ajusta un día en particular.') }}</p>

                {{-- Paso 1: días --}}
                <div class="mb-5">
                    <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('1. Días que trabaja') }}</span>
                        <div class="flex items-center gap-1.5 text-xs">
                            <button type="button" wire:click="seleccionarDias('lv')" class="px-2.5 py-1 rounded-md bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 transition">{{ __('Lun–Vie') }}</button>
                            <button type="button" wire:click="seleccionarDias('lvs')" class="px-2.5 py-1 rounded-md bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 transition">{{ __('Lun–Sáb') }}</button>
                            <button type="button" wire:click="seleccionarDias('ninguno')" class="px-2.5 py-1 rounded-md bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 transition">{{ __('Limpiar') }}</button>
                        </div>
                    </div>
                    <div class="grid grid-cols-7 gap-1.5 sm:gap-2">
                        @foreach (\App\Models\HorarioPersonals::DIAS_CORTO as $dia => $corto)
                            <button type="button" wire:key="chip-{{ $dia }}" wire:click="toggleDia({{ $dia }})"
                                    aria-pressed="{{ $horarios[$dia]['activo'] ? 'true' : 'false' }}"
                                    @class([
                                        'flex flex-col items-center justify-center rounded-lg border py-2.5 text-xs sm:text-sm font-semibold transition cursor-pointer',
                                        'bg-emerald-600 border-emerald-600 text-white shadow-sm' => $horarios[$dia]['activo'],
                                        'bg-white dark:bg-zinc-800 border-zinc-300 dark:border-zinc-700 text-zinc-600 dark:text-zinc-300 hover:border-emerald-400' => ! $horarios[$dia]['activo'],
                                    ])>
                                <span>{{ $corto }}</span>
                                @if ($horarios[$dia]['activo'])
                                    <flux:icon.check class="w-3.5 h-3.5" />
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Paso 2: horario general --}}
                <div class="rounded-lg border border-emerald-200 dark:border-emerald-900/60 bg-emerald-50/60 dark:bg-emerald-950/20 p-4 mb-5">
                    <div class="flex items-center justify-between flex-wrap gap-2 mb-3">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('2. Horario general (para los días marcados)') }}</span>
                        <flux:checkbox wire:model.live="general.doble" label="{{ __('Doble jornada') }}" />
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                        <flux:input wire:model="general.hora_entrada" type="time" label="{{ __('Entrada') }}" />
                        @if ($general['doble'])
                            <flux:input wire:model="general.hora_salida_maniana" type="time" label="{{ __('Salida mañana') }}" />
                            <flux:input wire:model="general.hora_entrada_tarde" type="time" label="{{ __('Entrada tarde') }}" />
                        @endif
                        <flux:input wire:model="general.hora_salida" type="time" label="{{ __('Salida') }}" />
                        <flux:input wire:model="general.tolerancia_minutos" type="number" min="0" max="120" label="{{ __('Tolerancia (min)') }}" />
                    </div>
                    <button type="button" wire:click="aplicarGeneral"
                            class="mt-3 inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold shadow transition">
                        <flux:icon.arrow-down-circle class="w-4 h-4" />
                        {{ __('Aplicar a los días marcados') }}
                    </button>
                </div>

                {{-- Paso 3: detalle por día --}}
                <div class="space-y-3">
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('3. Detalle por día') }}</span>

                    @php $hayActivos = collect($horarios)->where('activo', true)->isNotEmpty(); @endphp
                    @unless ($hayActivos)
                        <p class="text-sm text-zinc-400 py-3">{{ __('Aún no hay días marcados. Toca los días de arriba para activarlos.') }}</p>
                    @endunless

                    @foreach (\App\Models\HorarioPersonals::DIAS as $dia => $label)
                        @if ($horarios[$dia]['activo'])
                            <div wire:key="dia-{{ $dia }}" class="border border-zinc-100 dark:border-zinc-700 rounded-lg p-4 bg-zinc-50 dark:bg-zinc-900/50">
                                <div class="flex items-center gap-3 mb-3 flex-wrap">
                                    <span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $label }}</span>
                                    <flux:checkbox wire:model.live="horarios.{{ $dia }}.doble" label="{{ __('Doble jornada') }}" />
                                    <button type="button" wire:click="toggleDia({{ $dia }})" title="{{ __('Quitar este día') }}"
                                            class="ml-auto p-1.5 rounded-lg text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30 transition">
                                        <flux:icon.x-mark class="w-4 h-4" />
                                    </button>
                                </div>

                                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                                    <flux:input wire:model="horarios.{{ $dia }}.hora_entrada" type="time" label="{{ __('Entrada') }}" />

                                    @if ($horarios[$dia]['doble'])
                                        <flux:input wire:model="horarios.{{ $dia }}.hora_salida_maniana" type="time" label="{{ __('Salida mañana') }}" />
                                        <flux:input wire:model="horarios.{{ $dia }}.hora_entrada_tarde" type="time" label="{{ __('Entrada tarde') }}" />
                                    @endif

                                    <flux:input wire:model="horarios.{{ $dia }}.hora_salida" type="time" label="{{ __('Salida') }}" />
                                    <flux:input wire:model="horarios.{{ $dia }}.tolerancia_minutos" type="number" min="0" max="120" label="{{ __('Tolerancia (min)') }}" />
                                </div>
                                <flux:error name="horarios.{{ $dia }}.hora_entrada" />
                                <flux:error name="horarios.{{ $dia }}.hora_salida_maniana" />
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            {{-- Acciones --}}
            <div class="flex items-center justify-between gap-3">
                <a href="{{ route('personal.index') }}" wire:navigate
                   class="px-5 py-2.5 rounded-lg border border-zinc-300 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium hover:bg-zinc-50 dark:hover:bg-zinc-800 transition">
                    {{ __('Cancelar') }}
                </a>
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold rounded-lg shadow transition disabled:opacity-70 flex items-center gap-2">
                    <flux:icon.loading wire:loading wire:target="save" variant="mini" class="w-4 h-4" />
                    <span wire:loading.remove wire:target="save">{{ $isEditMode ? __('Actualizar personal') : __('Guardar personal') }}</span>
                    <span wire:loading wire:target="save">{{ __('Guardando...') }}</span>
                </button>
            </div>

        </form>
    </div>
