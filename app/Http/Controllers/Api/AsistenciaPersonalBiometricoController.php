<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asistencia_personals;
use App\Models\Personal_ies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * API biométrica de asistencia para personal.
 *
 * REGLAS DE NEGOCIO (sin tolerancia):
 *  - Entrada exacta o antes → PRESENTE
 *  - Entrada después de la hora → TARDANZA (se calculan los minutos exactos)
 *  - Salida en o después de la hora oficial → NORMAL
 *  - Salida antes de la hora oficial → ANTICIPADA
 *
 * VENTANAS DE REGISTRO (solo para que el biométrico acepte el marcado):
 *  - Entrada: desde 90 min antes hasta 90 min después de la hora oficial
 *  - Salida:  desde 15 min antes hasta 90 min después de la hora oficial
 *  Estas ventanas NO afectan el estado; solo determinan si el marcado es válido.
 *
 * TIMESTAMP:
 *  Prioridad: fecha_hora (biométrico) > fecha+hora (biométrico) > fecha_override+hora_override (Postman) > now()
 *  Los campos del biométrico NO activan modo_prueba; los override SÍ.
 */
class AsistenciaPersonalBiometricoController extends Controller
{
    private const COL_ENTRADA = 'hora_entrada_maniana';
    private const COL_SALIDA_MAN = 'hora_salida_maniana';
    private const COL_ENTRADA_TARDE = 'hora_entrada_tarde';
    private const COL_SALIDA = 'hora_salida_tarde';

    private const VENTANA_ANTES_ENTRADA = 90;
    private const VENTANA_DESPUES_ENTRADA = 90;
    private const VENTANA_ANTES_SALIDA = 15;
    private const VENTANA_DESPUES_SALIDA = 90;

    // =========================================================================
    // ENDPOINT PRINCIPAL
    // POST /api/asistencia-personal-biometrico/notificar/{codigo}
    // =========================================================================

    public function registrar(Request $request, string $codigo): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                // ── Campos del dispositivo biométrico ──────────────────────
                'pin' => 'nullable|string',
                'fecha' => 'nullable|date',
                'hora' => 'nullable|date_format:H:i:s',
                'fecha_hora' => 'nullable|date',
                'status' => 'nullable|string',
                'verify' => 'nullable|string',
                'sn_reloj' => 'nullable|string',
                // ── Solo para testing desde Postman ────────────────────────
                'fecha_override' => 'nullable|date',
                'hora_override' => 'nullable|date_format:H:i:s',
                'tipo_registro_forzado' => 'nullable|in:entrada,salida_maniana,entrada_tarde,salida',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            [$fecha, $hora, $esModoPrueba] = $this->resolverTimestamp($request);
            $ahora = Carbon::parse("{$fecha} {$hora}");

            // ── Buscar personal ───────────────────────────────────────────────
            $personal = Personal_ies::find($codigo);

            if (! $personal || $personal->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => "No se encontró personal con código '{$codigo}'.",
                ], 404);
            }

            if (! $personal->activo) {
                return response()->json([
                    'success' => false,
                    'message' => "El personal '{$personal->nombre_completo}' está inactivo.",
                ], 403);
            }

            // ── Horario del día ───────────────────────────────────────────────
            $horario = $personal->getHorarioArray($fecha);

            if (is_null($horario)) {
                return response()->json([
                    'success' => false,
                    'message' => "'{$personal->nombre_completo}' no tiene horario configurado para este día.",
                    'data' => [
                        'personal' => $personal->nombre_completo,
                        'tipo' => $personal->tipo_personal_label,
                        'fecha' => $fecha,
                        'dia' => Carbon::parse($fecha)->locale('es')->isoFormat('dddd'),
                    ],
                ], 422);
            }

            if (empty($horario['hora_entrada'])) {
                return response()->json([
                    'success' => false,
                    'message' => "El horario de '{$personal->nombre_completo}' no tiene hora de entrada.",
                ], 422);
            }

            if (empty($horario['hora_salida'])) {
                return response()->json([
                    'success' => false,
                    'message' => "El horario de '{$personal->nombre_completo}' no tiene hora de salida.",
                ], 422);
            }

            // ── Ventanas y detección de tipo ──────────────────────────────────
            $ventanas = $this->calcularVentanas($horario, $fecha);

            $ventana = $request->filled('tipo_registro_forzado')
                ? $request->tipo_registro_forzado
                : $this->determinarVentana($horario, $ventanas, $ahora);

            $debugInfo = $esModoPrueba
                ? $this->buildDebug($fecha, $hora, $ventana, $horario, $ventanas)
                : [];

            if ($ventana === 'fuera_horario') {
                return $this->respuestaFueraHorario($ahora, $ventanas, $personal, $debugInfo);
            }

            // ── Registro existente del día ────────────────────────────────────
            $asistencia = Asistencia_personals::where('personal_id', $personal->id)
                ->whereDate('fecha', $fecha)
                ->first();

            $tieneEntrada = $asistencia && ! is_null($asistencia->{self::COL_ENTRADA});
            $tieneSalidaMan = $asistencia && ! is_null($asistencia->{self::COL_SALIDA_MAN});
            $tieneEntradaTarde = $asistencia && ! is_null($asistencia->{self::COL_ENTRADA_TARDE});
            $tieneSalida = $asistencia && ! is_null($asistencia->{self::COL_SALIDA});

            // ── Ajuste inteligente por solapamiento de ventanas ───────────────
            // La ventana de entrada (±90 min) puede solaparse con la de salida
            // cuando los horarios son cercanos. Si el tiempo dice 'entrada' pero
            // el personal YA tiene entrada, determinamos qué corresponde según
            // su estado real del día.
            if (! $request->filled('tipo_registro_forzado')) {
                if ($ventana === 'entrada' && $tieneEntrada) {
                    $ventana = $this->ajustarVentanaSegunEstado(
                        $horario, $tieneSalidaMan, $tieneEntradaTarde, $tieneSalida
                    );
                }
                if ($ventana === 'entrada_tarde' && $tieneEntradaTarde && ! $tieneSalida) {
                    $ventana = 'salida';
                }
            }

            // ── ENTRADA MAÑANA ────────────────────────────────────────────────
            if ($ventana === 'entrada') {
                if ($tieneEntrada) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Entrada ya registrada.',
                        'data' => array_merge([
                            'personal' => $personal->nombre_completo,
                            'hora_entrada' => Carbon::parse($asistencia->{self::COL_ENTRADA})->format('H:i'),
                            'estado' => $asistencia->estado_entrada,
                        ], $debugInfo),
                    ], 200);
                }

                return $this->registrarEntrada($personal, $horario, $fecha, $hora, $ahora, $debugInfo);
            }

            // ── ASISTENCIA COMPLETA ───────────────────────────────────────────
            if ($ventana === 'completo') {
                return response()->json([
                    'success' => false,
                    'message' => 'La asistencia del día ya está completa.',
                    'data' => array_merge(['personal' => $personal->nombre_completo], $debugInfo),
                ], 200);
            }

            // ── SALIDA MAÑANA (solo doble jornada) ────────────────────────────
            if ($ventana === 'salida_maniana') {
                if ($tieneSalidaMan) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Salida de mañana ya registrada.',
                        'data' => array_merge([
                            'personal' => $personal->nombre_completo,
                            'hora_salida_maniana' => Carbon::parse($asistencia->{self::COL_SALIDA_MAN})->format('H:i'),
                        ], $debugInfo),
                    ], 200);
                }

                if (! $tieneEntrada) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No puedes registrar salida de mañana sin haber registrado la entrada.',
                        'data' => array_merge(['personal' => $personal->nombre_completo], $debugInfo),
                    ], 422);
                }

                return $this->registrarSalidaManiana($personal, $asistencia, $horario, $ventanas, $fecha, $hora, $debugInfo);
            }

            // ── ENTRADA TARDE (solo doble jornada) ────────────────────────────
            if ($ventana === 'entrada_tarde') {
                if ($tieneEntradaTarde) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Entrada tarde ya registrada.',
                        'data' => array_merge([
                            'personal' => $personal->nombre_completo,
                            'hora_entrada_tarde' => Carbon::parse($asistencia->{self::COL_ENTRADA_TARDE})->format('H:i'),
                        ], $debugInfo),
                    ], 200);
                }

                return $this->registrarEntradaTarde($personal, $asistencia, $horario, $fecha, $hora, $ahora, $debugInfo);
            }

            // ── SALIDA (final del día) ────────────────────────────────────────
            if ($ventana === 'salida') {
                if ($tieneSalida) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Salida ya registrada. Asistencia del día completa.',
                        'data' => array_merge([
                            'personal' => $personal->nombre_completo,
                            'hora_salida' => Carbon::parse($asistencia->{self::COL_SALIDA})->format('H:i'),
                        ], $debugInfo),
                    ], 200);
                }

                $hayEntradaValida = $tieneEntrada || $tieneEntradaTarde;
                if (! $hayEntradaValida) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No puedes registrar salida sin haber registrado entrada.',
                        'data' => array_merge(['personal' => $personal->nombre_completo], $debugInfo),
                    ], 422);
                }

                return $this->registrarSalida($personal, $asistencia, $horario, $fecha, $hora, $debugInfo);
            }

            // ── ENTRE JORNADAS ────────────────────────────────────────────────
            if ($ventana === 'entre_jornadas') {
                return response()->json([
                    'success' => false,
                    'message' => 'Descanso entre jornadas. Entrada tarde desde las '
                        .$ventanas['inicio_entrada_tarde']->format('H:i').'.',
                    'data' => array_merge(['personal' => $personal->nombre_completo], $debugInfo),
                ], 422);
            }

            // ── EN HORAS DE TRABAJO ───────────────────────────────────────────
            if ($ventana === 'entre_ventanas') {
                return response()->json([
                    'success' => false,
                    'message' => 'En horas de trabajo. Salida habilitada desde las '
                        .$ventanas['inicio_salida']->format('H:i').'.',
                    'data' => array_merge(['personal' => $personal->nombre_completo], $debugInfo),
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Ventana horaria no reconocida.',
            ], 422);

        } catch (Throwable $th) {
            Log::error('Asistencia biométrico personal — error: '.$th->getMessage(), [
                'codigo' => $codigo,
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error inesperado: '.$th->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // RESOLVER TIMESTAMP
    //
    // Prioridad:
    //   1. fecha_hora  (biométrico) → timestamp real del marcaje, NO modo_prueba
    //   2. fecha + hora (biométrico) → timestamp real del marcaje, NO modo_prueba
    //   3. fecha_override + hora_override (Postman) → modo_prueba = true
    //   4. now() → fallback
    // =========================================================================

    private function resolverTimestamp(Request $request): array
    {
        if ($request->filled('fecha_hora')) {
            $dt = Carbon::parse($request->fecha_hora);

            return [$dt->toDateString(), $dt->format('H:i:s'), false];
        }

        if ($request->filled('fecha') && $request->filled('hora')) {
            $fecha = Carbon::parse($request->fecha)->toDateString();
            $hora = Carbon::parse($request->hora)->format('H:i:s');

            return [$fecha, $hora, false];
        }

        if ($request->filled('fecha_override') || $request->filled('hora_override') || $request->filled('tipo_registro_forzado')) {
            $fecha = $request->filled('fecha_override')
                ? Carbon::parse($request->fecha_override)->toDateString()
                : now()->toDateString();
            $hora = $request->filled('hora_override')
                ? Carbon::parse($request->hora_override)->format('H:i:s')
                : now()->format('H:i:s');

            return [$fecha, $hora, true];
        }

        return [now()->toDateString(), now()->format('H:i:s'), false];
    }

    // =========================================================================
    // REGISTRO
    // =========================================================================

    private function registrarEntrada(
        Personal_ies $personal,
        array $horario,
        string $fecha,
        string $hora,
        Carbon $ahora,
        array $debugInfo
    ): JsonResponse {
        $horaRef = Carbon::parse("{$fecha} {$horario['hora_entrada']}");
        $estado = $ahora->lte($horaRef) ? 'presente' : 'tarde';
        $minsTardanza = $estado === 'tarde' ? (int) $horaRef->diffInMinutes($ahora) : 0;

        DB::beginTransaction();
        try {
            Asistencia_personals::create([
                'personal_id' => $personal->id,
                'fecha' => $fecha,
                self::COL_ENTRADA => $hora,
                'estado_entrada' => $estado,
                'registrado_por' => null,
            ]);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $estado === 'presente'
                    ? '✓ Entrada registrada — Puntual.'
                    : "✓ Entrada registrada — Tardanza de {$minsTardanza} min.",
                'data' => array_merge([
                    'tipo_registro' => 'entrada',
                    'personal' => $personal->nombre_completo,
                    'tipo_personal' => $personal->tipo_personal_label,
                    'fecha' => $fecha,
                    'hora_marcada' => Carbon::parse($hora)->format('H:i'),
                    'hora_programada' => $horaRef->format('H:i'),
                    'estado' => $estado,
                    'minutos_tardanza' => $minsTardanza,
                ], $debugInfo),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Biométrico [entrada] personal {$personal->id}: ".$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Error al registrar entrada.'], 500);
        }
    }

    private function registrarSalidaManiana(
        Personal_ies $personal,
        Asistencia_personals $asistencia,
        array $horario,
        array $ventanas,
        string $fecha,
        string $hora,
        array $debugInfo
    ): JsonResponse {
        $horaRef = Carbon::parse("{$fecha} {$horario['hora_salida_manana']}");
        $estado = Carbon::parse("{$fecha} {$hora}")->lt($horaRef) ? 'anticipada' : 'normal';

        DB::beginTransaction();
        try {
            $asistencia->update([
                self::COL_SALIDA_MAN => $hora,
                'estado_salida_maniana' => $estado,
            ]);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => '✓ Salida mañana registrada.',
                'data' => array_merge([
                    'tipo_registro' => 'salida_maniana',
                    'personal' => $personal->nombre_completo,
                    'hora_entrada' => Carbon::parse($asistencia->{self::COL_ENTRADA})->format('H:i'),
                    'hora_marcada' => Carbon::parse($hora)->format('H:i'),
                    'hora_programada' => $horaRef->format('H:i'),
                    'estado_salida' => $estado,
                    'siguiente_jornada' => 'Entrada tarde desde '.$ventanas['inicio_entrada_tarde']->format('H:i'),
                ], $debugInfo),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Biométrico [salida_maniana] personal {$personal->id}: ".$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Error al registrar salida mañana.'], 500);
        }
    }

    private function registrarEntradaTarde(
        Personal_ies $personal,
        ?Asistencia_personals $asistencia,
        array $horario,
        string $fecha,
        string $hora,
        Carbon $ahora,
        array $debugInfo
    ): JsonResponse {
        $horaRef = Carbon::parse("{$fecha} {$horario['hora_entrada_tarde']}");
        $estado = $ahora->lte($horaRef) ? 'presente' : 'tarde';
        $minsTardanza = $estado === 'tarde' ? (int) $horaRef->diffInMinutes($ahora) : 0;

        DB::beginTransaction();
        try {
            if ($asistencia) {
                $asistencia->update([
                    self::COL_ENTRADA_TARDE => $hora,
                    'estado_entrada_tarde' => $estado,
                ]);
            } else {
                Asistencia_personals::create([
                    'personal_id' => $personal->id,
                    'fecha' => $fecha,
                    self::COL_ENTRADA_TARDE => $hora,
                    'estado_entrada_tarde' => $estado,
                    'registrado_por' => null,
                ]);
            }
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $estado === 'presente'
                    ? '✓ Entrada tarde registrada — Puntual.'
                    : "✓ Entrada tarde registrada — Tardanza de {$minsTardanza} min.",
                'data' => array_merge([
                    'tipo_registro' => 'entrada_tarde',
                    'personal' => $personal->nombre_completo,
                    'hora_marcada' => Carbon::parse($hora)->format('H:i'),
                    'hora_programada' => $horaRef->format('H:i'),
                    'estado' => $estado,
                    'minutos_tardanza' => $minsTardanza,
                ], $debugInfo),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Biométrico [entrada_tarde] personal {$personal->id}: ".$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Error al registrar entrada tarde.'], 500);
        }
    }

    private function registrarSalida(
        Personal_ies $personal,
        ?Asistencia_personals $asistencia,
        array $horario,
        string $fecha,
        string $hora,
        array $debugInfo
    ): JsonResponse {
        $horaRef = Carbon::parse("{$fecha} {$horario['hora_salida']}");
        $estado = Carbon::parse("{$fecha} {$hora}")->lt($horaRef) ? 'anticipada' : 'normal';

        DB::beginTransaction();
        try {
            if ($asistencia) {
                $asistencia->update([
                    self::COL_SALIDA => $hora,
                    'estado_salida' => $estado,
                ]);
            } else {
                Asistencia_personals::create([
                    'personal_id' => $personal->id,
                    'fecha' => $fecha,
                    self::COL_SALIDA => $hora,
                    'estado_salida' => $estado,
                    'registrado_por' => null,
                ]);
            }
            DB::commit();

            $horaEntradaRaw = $asistencia?->{self::COL_ENTRADA} ?? $asistencia?->{self::COL_ENTRADA_TARDE};
            $estadoEntradaRaw = $asistencia?->estado_entrada ?? $asistencia?->estado_entrada_tarde;

            return response()->json([
                'success' => true,
                'message' => $estado === 'normal'
                    ? '✓ Salida registrada correctamente.'
                    : '✓ Salida anticipada registrada.',
                'data' => array_merge([
                    'tipo_registro' => 'salida',
                    'personal' => $personal->nombre_completo,
                    'hora_entrada' => $horaEntradaRaw ? Carbon::parse($horaEntradaRaw)->format('H:i') : null,
                    'estado_entrada' => $estadoEntradaRaw,
                    'hora_marcada' => Carbon::parse($hora)->format('H:i'),
                    'hora_programada' => $horaRef->format('H:i'),
                    'estado_salida' => $estado,
                ], $debugInfo),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Biométrico [salida] personal {$personal->id}: ".$e->getMessage());

            return response()->json(['success' => false, 'message' => 'Error al registrar salida.'], 500);
        }
    }

    // =========================================================================
    // VENTANAS HORARIAS
    // =========================================================================

    private function calcularVentanas(array $horario, string $fecha): array
    {
        $v = [
            'inicio_entrada' => Carbon::parse("{$fecha} {$horario['hora_entrada']}")->subMinutes(self::VENTANA_ANTES_ENTRADA),
            'fin_entrada' => Carbon::parse("{$fecha} {$horario['hora_entrada']}")->addMinutes(self::VENTANA_DESPUES_ENTRADA),
            'inicio_salida' => Carbon::parse("{$fecha} {$horario['hora_salida']}")->subMinutes(self::VENTANA_ANTES_SALIDA),
            'fin_salida' => Carbon::parse("{$fecha} {$horario['hora_salida']}")->addMinutes(self::VENTANA_DESPUES_SALIDA),
        ];

        if ($horario['es_doble_jornada']) {
            $v['inicio_salida_maniana'] = Carbon::parse("{$fecha} {$horario['hora_salida_manana']}")->subMinutes(self::VENTANA_ANTES_SALIDA);
            $v['fin_salida_maniana'] = Carbon::parse("{$fecha} {$horario['hora_salida_manana']}")->addMinutes(self::VENTANA_DESPUES_SALIDA);
            $v['inicio_entrada_tarde'] = Carbon::parse("{$fecha} {$horario['hora_entrada_tarde']}")->subMinutes(self::VENTANA_ANTES_ENTRADA);
            $v['fin_entrada_tarde'] = Carbon::parse("{$fecha} {$horario['hora_entrada_tarde']}")->addMinutes(self::VENTANA_DESPUES_ENTRADA);
        }

        return $v;
    }

    /**
     * Cuando la detección por tiempo devuelve 'entrada' pero el personal ya tiene
     * entrada registrada, este método determina qué corresponde según el progreso
     * real del día.
     *
     * Orden lógico (doble jornada): entrada → salida_maniana → entrada_tarde → salida
     * Orden lógico (jornada simple): entrada → salida
     */
    private function ajustarVentanaSegunEstado(
        array $horario,
        bool $tieneSalidaMan,
        bool $tieneEntradaTarde,
        bool $tieneSalida
    ): string {
        if ($horario['es_doble_jornada']) {
            if (! $tieneSalidaMan) {
                return 'salida_maniana';
            }
            if (! $tieneEntradaTarde) {
                return 'entrada_tarde';
            }
            if (! $tieneSalida) {
                return 'salida';
            }

            return 'completo';
        }

        return $tieneSalida ? 'completo' : 'salida';
    }

    private function determinarVentana(array $horario, array $v, Carbon $ahora): string
    {
        if ($horario['es_doble_jornada']) {
            if ($ahora->between($v['inicio_entrada'], $v['fin_entrada'])) {
                return 'entrada';
            }
            if ($ahora->gt($v['fin_entrada']) && $ahora->lt($v['inicio_salida_maniana'])) {
                return 'entre_ventanas';
            }
            if ($ahora->between($v['inicio_salida_maniana'], $v['fin_salida_maniana'])) {
                return 'salida_maniana';
            }
            if ($ahora->gt($v['fin_salida_maniana']) && $ahora->lt($v['inicio_entrada_tarde'])) {
                return 'entre_jornadas';
            }
            if ($ahora->between($v['inicio_entrada_tarde'], $v['fin_entrada_tarde'])) {
                return 'entrada_tarde';
            }
            if ($ahora->gt($v['fin_entrada_tarde']) && $ahora->lt($v['inicio_salida'])) {
                return 'entre_ventanas';
            }
            if ($ahora->between($v['inicio_salida'], $v['fin_salida'])) {
                return 'salida';
            }

            return 'fuera_horario';
        }

        // Jornada simple
        if ($ahora->between($v['inicio_entrada'], $v['fin_entrada'])) {
            return 'entrada';
        }
        if ($ahora->between($v['inicio_salida'], $v['fin_salida'])) {
            return 'salida';
        }
        if ($ahora->gt($v['fin_entrada']) && $ahora->lt($v['inicio_salida'])) {
            return 'entre_ventanas';
        }

        return 'fuera_horario';
    }

    private function respuestaFueraHorario(
        Carbon $ahora,
        array $ventanas,
        Personal_ies $personal,
        array $debugInfo
    ): JsonResponse {
        if ($ahora->lt($ventanas['inicio_entrada'])) {
            $mins = (int) $ahora->diffInMinutes($ventanas['inicio_entrada']);

            return response()->json([
                'success' => false,
                'message' => "Registro bloqueado. El biométrico abre a las {$ventanas['inicio_entrada']->format('H:i')} (en {$mins} min).",
                'data' => array_merge(['personal' => $personal->nombre_completo, 'ventana_actual' => 'fuera_horario'], $debugInfo),
            ], 422);
        }

        return response()->json([
            'success' => false,
            'message' => "Registro bloqueado. La ventana cerró a las {$ventanas['fin_salida']->format('H:i')}.",
            'data' => array_merge(['personal' => $personal->nombre_completo, 'ventana_actual' => 'fuera_horario'], $debugInfo),
        ], 422);
    }

    private function buildDebug(
        string $fecha,
        string $hora,
        string $ventana,
        array $horario,
        array $ventanas
    ): array {
        return [
            'modo_prueba' => true,
            'fecha_efectiva' => $fecha,
            'hora_efectiva' => $hora,
            'ventana_calculada' => $ventana,
            'horario' => [
                'es_doble_jornada' => $horario['es_doble_jornada'],
                'hora_entrada' => $horario['hora_entrada'],
                'hora_salida' => $horario['hora_salida'],
                'hora_salida_manana' => $horario['hora_salida_manana'] ?? null,
                'hora_entrada_tarde' => $horario['hora_entrada_tarde'] ?? null,
            ],
            'ventanas' => collect($ventanas)->map(fn (Carbon $c) => $c->format('H:i'))->toArray(),
        ];
    }
}
