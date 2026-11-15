<?php

namespace App\Services;

use App\Models\Asistencia_personals;
use App\Models\Personal_ies;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Carga el "Reporte de Asistencia" que exporta el reloj biométrico (.xls / .xlsx) cuando
 * la API en línea no pudo recibir las marcaciones.
 *
 * Formato del reporte:
 *  - Hoja "Reporte de Asistencia".
 *  - Celda C3: "2026-05-01 ~ 2026-05-29" (período).
 *  - Fila 4: número de día de cada columna.
 *  - Por cada persona: una fila con "ID:" en la columna A y el ID en la C, y debajo la fila con
 *    las marcaciones de cada día ("07:25\n15:31").
 *  - El ID del biométrico debe ser el ID del personal en el sistema (el mismo que usa la API).
 */
class ImportadorBiometrico
{
    public const EXISTENTES_OMITIR = 'omitir';

    public const EXISTENTES_COMPLETAR = 'completar';

    public const OBSERVACION = 'Importado del reporte del biométrico';

    /**
     * Lee el archivo y devuelve un resumen sin guardar nada.
     *
     * @return array{error: ?string, periodo?: string, inicio?: string, fin?: string, personas?: int, encontradas?: int, desconocidos?: array<int,int>, dias_con_datos?: int}
     */
    public function analizar(string $ruta): array
    {
        try {
            ['periodo' => $periodo, 'registros' => $registros] = $this->leer($ruta);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            report($e);

            return ['error' => 'No se pudo leer el archivo. Verifica que sea el reporte original del biométrico (.xls o .xlsx).'];
        }

        $ids = array_keys($registros);
        $existentes = Personal_ies::whereIn('id', $ids)->pluck('id')->all();

        return [
            'error' => null,
            'periodo' => $periodo['texto'],
            'inicio' => $periodo['inicio']->toDateString(),
            'fin' => $periodo['fin']->toDateString(),
            'personas' => count($ids),
            'encontradas' => count($existentes),
            'desconocidos' => array_values(array_diff($ids, $existentes)),
            'dias_con_datos' => array_sum(array_map('count', $registros)),
        ];
    }

    /**
     * Guarda las asistencias del archivo en una sola transacción.
     *
     * @return array<string,mixed>
     */
    public function importar(string $ruta, string $existentes = self::EXISTENTES_OMITIR, ?int $registradoPor = null): array
    {
        $cont = [
            'error' => null, 'registrados' => 0, 'completados' => 0, 'ya_existian' => 0, 'sin_personal' => 0,
            'sin_horario' => 0, 'inactivos' => 0, 'manuales' => 0, 'errores' => 0, 'detalle_errores' => [],
        ];

        try {
            ['registros' => $registros] = $this->leer($ruta);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()] + $cont;
        } catch (\Throwable $e) {
            report($e);

            return ['error' => 'No se pudo leer el archivo. Verifica que sea el reporte original del biométrico.'] + $cont;
        }

        $personal = Personal_ies::withTrashed()->whereIn('id', array_keys($registros))->get()->keyBy('id');

        DB::transaction(function () use ($registros, $personal, $existentes, $registradoPor, &$cont) {
            foreach ($registros as $personalId => $dias) {
                $p = $personal->get($personalId);

                if (! $p || $p->trashed()) {
                    $cont['sin_personal'] += count($dias);

                    continue;
                }

                if (! $p->activo) {
                    $cont['inactivos'] += count($dias);

                    continue;
                }

                $registrosDelRango = Asistencia_personals::where('personal_id', $p->id)
                    ->whereDate('fecha', '>=', array_key_first($dias))
                    ->whereDate('fecha', '<=', array_key_last($dias))
                    ->get()
                    ->keyBy(fn ($a) => $a->fecha->toDateString());

                foreach ($dias as $fecha => $tiempos) {
                    $existente = $registrosDelRango->get($fecha);

                    $horario = $p->getHorarioArray($fecha);
                    if (! $horario) {
                        $cont['sin_horario']++;

                        continue;
                    }

                    if ($existente && $existentes !== self::EXISTENTES_COMPLETAR) {
                        $cont['ya_existian']++;

                        continue;
                    }

                    if ($existente && in_array($existente->estado_entrada, ['ausente', 'justificado'], true)) {
                        $cont['manuales']++;

                        continue;
                    }

                    try {
                        $datos = $this->calcularDia($horario, $fecha, $tiempos);
                    } catch (\Throwable $e) {
                        $cont['errores']++;
                        $cont['detalle_errores'][] = "ID {$p->id} / {$fecha}: ".$e->getMessage();

                        continue;
                    }

                    if ($existente) {
                        $faltantes = array_filter(
                            $datos,
                            fn ($valor, $campo) => str_starts_with($campo, 'hora_') && $valor !== null && $existente->{$campo} === null,
                            ARRAY_FILTER_USE_BOTH
                        );

                        if ($faltantes === []) {
                            $cont['ya_existian']++;

                            continue;
                        }

                        $existente->update($this->conSusEstados($faltantes, $datos));
                        $cont['completados']++;
                    } else {
                        Asistencia_personals::create($datos + [
                            'personal_id' => $p->id,
                            'fecha' => $fecha,
                            'observacion' => self::OBSERVACION,
                            'registrado_por' => $registradoPor,
                        ]);
                        $cont['registrados']++;
                    }
                }
            }
        });

        $cont['detalle_errores'] = array_slice($cont['detalle_errores'], 0, 5);

        return $cont;
    }

    /**
     * Convierte las marcaciones de un día en columnas de asistencia.
     *
     *  - Jornada simple: primera marca = entrada; última (si hay más de una) = salida.
     *  - Doble jornada: se parte en el punto medio entre la salida de la mañana y la entrada de la tarde;
     *    en cada bloque la primera marca es la entrada y la última la salida.
     *  - Estados sin tolerancia: entrada "presente"/"tarde"; salida "normal"/"anticipada".
     *
     * @param  array<int,string>  $tiempos  "HH:MM" ordenados ascendente
     * @return array<string,?string>
     */
    public function calcularDia(array $horario, string $fecha, array $tiempos): array
    {
        if ($tiempos === []) {
            throw new \InvalidArgumentException('sin marcaciones');
        }

        $datos = [];

        if (! $horario['es_doble_jornada']) {
            $datos['hora_entrada_maniana'] = $tiempos[0].':00';
            $datos['estado_entrada'] = $this->estadoEntrada($tiempos[0], $horario['hora_entrada'], $fecha);

            $salida = count($tiempos) > 1 ? end($tiempos) : null;
            if ($salida && $salida !== $tiempos[0]) {
                $datos['hora_salida_tarde'] = $salida.':00';
                $datos['estado_salida'] = $this->estadoSalida($salida, $horario['hora_salida'], $fecha);
            }

            return $datos;
        }

        $salidaManiana = Carbon::parse($fecha.' '.$horario['hora_salida_manana']);
        $entradaTarde = Carbon::parse($fecha.' '.$horario['hora_entrada_tarde']);
        $pivote = $salidaManiana->copy()->addSeconds((int) ($salidaManiana->diffInSeconds($entradaTarde) / 2));

        $manana = array_values(array_filter($tiempos, fn ($t) => Carbon::parse("{$fecha} {$t}:00")->lt($pivote)));
        $tarde = array_values(array_filter($tiempos, fn ($t) => Carbon::parse("{$fecha} {$t}:00")->gte($pivote)));

        if ($manana) {
            $datos['hora_entrada_maniana'] = $manana[0].':00';
            $datos['estado_entrada'] = $this->estadoEntrada($manana[0], $horario['hora_entrada'], $fecha);

            if (count($manana) > 1) {
                $datos['hora_salida_maniana'] = end($manana).':00';
                $datos['estado_salida_maniana'] = $this->estadoSalida(end($manana), $horario['hora_salida_manana'], $fecha);
            }
        }

        if ($tarde) {
            $datos['hora_entrada_tarde'] = $tarde[0].':00';
            $datos['estado_entrada_tarde'] = $this->estadoEntrada($tarde[0], $horario['hora_entrada_tarde'], $fecha);

            if (count($tarde) > 1) {
                $datos['hora_salida_tarde'] = end($tarde).':00';
                $datos['estado_salida'] = $this->estadoSalida(end($tarde), $horario['hora_salida'], $fecha);
            }

            // Solo vino por la tarde: el estado del día se mide con la entrada de la tarde.
            if (! $manana) {
                $datos['estado_entrada'] = $datos['estado_entrada_tarde'];
            }
        }

        return $datos;
    }

    // ── Lectura del Excel ────────────────────────────────────────────────────

    /**
     * @return array{periodo: array{texto:string, inicio:Carbon, fin:Carbon}, registros: array<int,array<string,array<int,string>>>}
     */
    private function leer(string $ruta): array
    {
        $tipo = IOFactory::identify($ruta, [IOFactory::READER_XLS, IOFactory::READER_XLSX]);
        $lector = IOFactory::createReader($tipo);
        $lector->setReadDataOnly(true);

        $hoja = $this->nombreHojaAsistencia($lector->listWorksheetNames($ruta));
        if ($hoja === null) {
            throw new \RuntimeException('No se encontró la hoja "Reporte de Asistencia" en el archivo.');
        }

        $lector->setLoadSheetsOnly([$hoja]);
        $filas = $lector->load($ruta)->getActiveSheet()->toArray(null, true, false, false);

        $textoPeriodo = trim((string) ($filas[2][2] ?? ''));
        if (! preg_match('/(\d{4}-\d{2}-\d{2})\s*~\s*(\d{4}-\d{2}-\d{2})/', $textoPeriodo, $m)) {
            throw new \RuntimeException('No se pudo leer el período (celda C3). Verifica que sea el reporte correcto del biométrico.');
        }

        $inicio = Carbon::parse($m[1])->startOfDay();
        $fin = Carbon::parse($m[2])->startOfDay();

        return [
            'periodo' => ['texto' => $textoPeriodo, 'inicio' => $inicio, 'fin' => $fin],
            'registros' => $this->extraerRegistros($filas, $this->fechasPorColumna($filas[3] ?? [], $inicio, $fin)),
        ];
    }

    /**
     * Fila 4 → fecha de cada columna. Si el período cruza de mes, el número de día vuelve a bajar
     * (… 29, 30, 1, 2 …) y ahí se avanza de mes.
     *
     * @return array<int,string> columna => Y-m-d
     */
    private function fechasPorColumna(array $filaDias, Carbon $inicio, Carbon $fin): array
    {
        $fechas = [];
        $mes = $inicio->copy()->startOfMonth();
        $anterior = 0;

        foreach ($filaDias as $columna => $valor) {
            if (! is_numeric($valor) || $valor < 1 || $valor > 31) {
                continue;
            }

            $dia = (int) $valor;

            if ($dia < $anterior) {
                $mes = $mes->addMonthNoOverflow();
            }
            $anterior = $dia;

            if ($dia > $mes->daysInMonth) {
                continue;
            }

            $fecha = $mes->copy()->day($dia);

            if ($fecha->between($inicio, $fin)) {
                $fechas[$columna] = $fecha->toDateString();
            }
        }

        return $fechas;
    }

    /**
     * @param  array<int,array<int,mixed>>  $filas
     * @param  array<int,string>  $fechas
     * @return array<int,array<string,array<int,string>>> personal_id => fecha => ["HH:MM", ...]
     */
    private function extraerRegistros(array $filas, array $fechas): array
    {
        $resultado = [];
        $idActual = null;

        foreach (array_slice($filas, 4, null, true) as $fila) {
            if (trim((string) ($fila[0] ?? '')) === 'ID:') {
                $idActual = (int) ($fila[2] ?? 0) ?: null;

                if ($idActual) {
                    $resultado[$idActual] ??= [];
                }

                continue;
            }

            if ($idActual === null) {
                continue;
            }

            foreach ($fechas as $columna => $fecha) {
                $celda = (string) ($fila[$columna] ?? '');

                if ($celda === '' || ! preg_match_all('/\d{2}:\d{2}/', $celda, $coincidencias)) {
                    continue;
                }

                $tiempos = array_map(fn ($t) => str_pad($t, 5, '0', STR_PAD_LEFT), $coincidencias[0]);

                $resultado[$idActual][$fecha] = array_merge($resultado[$idActual][$fecha] ?? [], $tiempos);
            }
        }

        foreach ($resultado as &$dias) {
            foreach ($dias as &$tiempos) {
                $tiempos = array_values(array_unique($tiempos));
                sort($tiempos);
            }
            ksort($dias);
        }
        unset($dias, $tiempos);

        // Personas sin ninguna marcación en el período no aportan nada.
        return array_filter($resultado, fn ($dias) => $dias !== []);
    }

    private function nombreHojaAsistencia(array $nombres): ?string
    {
        foreach ($nombres as $nombre) {
            if (mb_strtolower(trim($nombre)) === 'reporte de asistencia') {
                return $nombre;
            }
        }

        foreach ($nombres as $nombre) {
            if (str_contains(mb_strtolower($nombre), 'asistencia')) {
                return $nombre;
            }
        }

        return null;
    }

    // ── Estados ──────────────────────────────────────────────────────────────

    private function estadoEntrada(string $hora, string $oficial, string $fecha): string
    {
        return Carbon::parse("{$fecha} {$hora}:00")->lte(Carbon::parse("{$fecha} {$oficial}")) ? 'presente' : 'tarde';
    }

    private function estadoSalida(string $hora, string $oficial, string $fecha): string
    {
        return Carbon::parse("{$fecha} {$hora}:00")->lt(Carbon::parse("{$fecha} {$oficial}")) ? 'anticipada' : 'normal';
    }

    /**
     * Al completar un registro existente se agregan las horas que faltaban junto con sus estados.
     *
     * @param  array<string,?string>  $horasFaltantes
     * @param  array<string,?string>  $todos
     * @return array<string,?string>
     */
    private function conSusEstados(array $horasFaltantes, array $todos): array
    {
        $estadoDe = [
            'hora_entrada_maniana' => 'estado_entrada',
            'hora_salida_maniana' => 'estado_salida_maniana',
            'hora_entrada_tarde' => 'estado_entrada_tarde',
            'hora_salida_tarde' => 'estado_salida',
        ];

        $salida = [];
        foreach ($horasFaltantes as $campo => $valor) {
            $salida[$campo] = $valor;
            $salida[$estadoDe[$campo]] = $todos[$estadoDe[$campo]] ?? null;
        }

        return $salida;
    }
}
