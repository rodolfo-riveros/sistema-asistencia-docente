<?php

namespace App\Services;

use App\Models\Asistencia_personals;
use App\Models\HorarioPersonals;
use App\Models\Personal_ies;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Indicadores del panel de control: tasas de asistencia y puntualidad, tendencias,
 * rankings y alertas con acciones sugeridas para la toma de decisiones.
 *
 * Criterios (para que los números sean interpretables):
 *  - "Programado" = persona activa con horario ese día de la semana (y ya ingresada a la institución).
 *  - Sin registro en un día pasado = no hay marcación ni registro manual (falta o falla del biométrico).
 *  - Hoy, sin registro solo cuenta como "posible falta" cuando ya pasó la hora de entrada + tolerancia;
 *    antes de eso la persona figura como "pendiente".
 *  - Tasa de asistencia = (presentes + tardanzas) / (programados - justificados - pendientes).
 *  - Puntualidad = presentes / (presentes + tardanzas).
 */
class EstadisticasAsistencia
{
    public const META_ASISTENCIA = 95.0;

    public const META_PUNTUALIDAD = 90.0;

    public const PERIODOS = ['7' => 'Últimos 7 días', '30' => 'Últimos 30 días', 'mes' => 'Este mes', 'mes_anterior' => 'Mes anterior'];

    private const ESTADOS = ['presente', 'tarde', 'ausente', 'justificado', 'sin_registro', 'pendiente'];

    private const BUCKETS_LLEGADA = [
        ['etiqueta' => '+15 min antes', 'min' => -INF, 'max' => -15, 'color' => 'bg-emerald-500'],
        ['etiqueta' => '15–5 antes', 'min' => -15, 'max' => -5, 'color' => 'bg-emerald-400'],
        ['etiqueta' => '5 antes–hora', 'min' => -5, 'max' => 0, 'color' => 'bg-green-400'],
        ['etiqueta' => '1–5 tarde', 'min' => 0, 'max' => 5, 'color' => 'bg-yellow-400'],
        ['etiqueta' => '6–15 tarde', 'min' => 5, 'max' => 15, 'color' => 'bg-amber-500'],
        ['etiqueta' => '16–30 tarde', 'min' => 15, 'max' => 30, 'color' => 'bg-orange-500'],
        ['etiqueta' => '+30 tarde', 'min' => 30, 'max' => INF, 'color' => 'bg-red-500'],
    ];

    private ?Collection $personal = null;

    /** @var array<int,array<int,HorarioPersonals>> personal_id => dia ISO => horario */
    private array $horarios = [];

    /** @var array<string,Asistencia_personals> "personal_id|Y-m-d" => registro */
    private array $registros = [];

    public function __construct(private ?CarbonImmutable $ahora = null) {}

    /**
     * @return array<string,mixed>
     */
    public function panel(string $periodo = 'mes'): array
    {
        [$inicio, $fin, $inicioPrevio, $finPrevio, $etiqueta] = $this->rango($periodo);

        $this->cargar($inicioPrevio, $fin);

        $actual = $this->agregar($inicio, $fin);
        $previo = $this->agregar($inicioPrevio, $finPrevio);
        $hoy = $this->situacionHoy();

        $kpis = $this->kpis($actual['totales']);
        // Sin ningún registro en el período anterior (el sistema aún no se usaba) no hay con qué comparar.
        $pt = $previo['totales'];
        $kpisPrevio = ($pt['presente'] + $pt['tarde'] + $pt['ausente'] + $pt['justificado']) > 0
            ? $this->kpis($pt)
            : ['tasa_asistencia' => null, 'puntualidad' => null] + $this->kpis($pt);

        $tipos = $this->tablaTipos($actual['tipos']);
        $personas = $this->personasConTasas($actual['personas']);

        return [
            'periodo' => $periodo,
            'etiqueta' => $etiqueta,
            'rango' => ['inicio' => $inicio->toDateString(), 'fin' => $fin->toDateString()],
            'hoy' => $hoy,
            'totales' => $actual['totales'],
            'kpis' => $kpis + [
                'delta_asistencia' => $this->delta($kpis['tasa_asistencia'], $kpisPrevio['tasa_asistencia']),
                'delta_puntualidad' => $this->delta($kpis['puntualidad'], $kpisPrevio['puntualidad']),
                'meta_asistencia' => self::META_ASISTENCIA,
                'meta_puntualidad' => self::META_PUNTUALIDAD,
            ],
            'dias' => array_values($actual['dias']),
            'tipos' => $tipos,
            'semana' => $this->tablaSemana($actual['semana']),
            'llegada' => $this->tablaLlegada($actual['llegada']),
            'rankings' => $this->rankings($personas),
            'alertas' => $this->alertas($actual, $kpis, $kpisPrevio, $hoy, $tipos, $personas),
            'resumen' => $this->resumenEjecutivo($etiqueta, $kpis, $kpisPrevio, $actual['totales']),
            'sin_datos' => $actual['totales']['programados'] === 0,
        ];
    }

    // ── Rango de fechas ──────────────────────────────────────────────────────

    /**
     * @return array{0:CarbonImmutable,1:CarbonImmutable,2:CarbonImmutable,3:CarbonImmutable,4:string}
     */
    private function rango(string $periodo): array
    {
        $hoy = $this->ahora()->startOfDay();

        switch ($periodo) {
            case '7':
            case '30':
                $dias = (int) $periodo;
                $inicio = $hoy->subDays($dias - 1);

                return [$inicio, $hoy, $inicio->subDays($dias), $inicio->subDay(), self::PERIODOS[$periodo]];

            case 'mes_anterior':
                $inicio = $hoy->subMonthNoOverflow()->startOfMonth();
                $fin = $inicio->endOfMonth()->startOfDay();
                $inicioPrevio = $inicio->subMonthNoOverflow()->startOfMonth();

                return [$inicio, $fin, $inicioPrevio, $inicioPrevio->endOfMonth()->startOfDay(), ucfirst($inicio->locale('es')->isoFormat('MMMM YYYY'))];

            default:
                $inicio = $hoy->startOfMonth();
                $inicioPrevio = $inicio->subMonthNoOverflow()->startOfMonth();
                // Mismo tramo del mes anterior (p. ej. días 1–14 contra días 1–14) para comparar parejo.
                $finPrevio = $inicioPrevio->addDays($hoy->day - 1)->min($inicioPrevio->endOfMonth()->startOfDay());

                return [$inicio, $hoy, $inicioPrevio, $finPrevio, ucfirst($hoy->locale('es')->isoFormat('MMMM YYYY')).' (a la fecha)'];
        }
    }

    // ── Carga de datos ───────────────────────────────────────────────────────

    private function cargar(CarbonImmutable $desde, CarbonImmutable $hasta): void
    {
        $this->personal = Personal_ies::query()
            ->where('activo', true)
            ->with(['horarios' => fn ($q) => $q->where('activo', true)])
            ->get();

        $this->horarios = [];
        foreach ($this->personal as $p) {
            foreach ($p->horarios as $h) {
                $this->horarios[$p->id][$h->dia_semana] = $h;
            }
        }

        $this->registros = [];
        Asistencia_personals::query()
            ->whereIn('personal_id', $this->personal->pluck('id'))
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString())
            ->get()
            ->each(function ($r) {
                $this->registros[$r->personal_id.'|'.$r->fecha->toDateString()] = $r;
            });
    }

    // ── Agregación ───────────────────────────────────────────────────────────

    /**
     * @return array{estado:string, minutos_tarde:int, desvio:?int}|null null si el día no cuenta
     */
    private function clasificar(Personal_ies $p, HorarioPersonals $h, CarbonImmutable $dia, CarbonImmutable $hoy): ?array
    {
        $registro = $this->registros[$p->id.'|'.$dia->toDateString()] ?? null;

        if ($registro) {
            $estado = $registro->estado_entrada
                ?: ($registro->estado_entrada_tarde === 'tarde' ? 'tarde' : 'presente');
            if (! in_array($estado, ['presente', 'tarde', 'ausente', 'justificado'], true)) {
                $estado = 'presente';
            }

            $real = $registro->hora_entrada_maniana ?? $registro->hora_entrada_tarde;
            $oficial = $registro->hora_entrada_maniana ? $h->hora_entrada : ($h->hora_entrada_tarde ?? $h->hora_entrada);
            $desvio = ($real && in_array($estado, ['presente', 'tarde'], true)) ? $this->minutos($real) - $this->minutos($oficial) : null;

            return [
                'estado' => $estado,
                'minutos_tarde' => ($estado === 'tarde' && $desvio !== null) ? max(0, $desvio) : 0,
                'desvio' => $desvio,
            ];
        }

        if ($dia->lt($hoy)) {
            return ['estado' => 'sin_registro', 'minutos_tarde' => 0, 'desvio' => null];
        }

        if ($dia->equalTo($hoy)) {
            $limite = $hoy->setTimeFromTimeString($h->hora_entrada)->addMinutes((int) $h->tolerancia_minutos);

            return ['estado' => $this->ahora()->gt($limite) ? 'sin_registro' : 'pendiente', 'minutos_tarde' => 0, 'desvio' => null];
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function agregar(CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        $hoy = $this->ahora()->startOfDay();
        $vacio = array_fill_keys(self::ESTADOS, 0) + ['programados' => 0, 'minutos_tarde' => 0];

        $resultado = [
            'dias' => [],
            'totales' => $vacio,
            'personas' => [],
            'tipos' => [],
            'semana' => array_fill(1, 7, ['programados' => 0, 'asistieron' => 0, 'tarde' => 0]),
            'llegada' => array_fill(0, count(self::BUCKETS_LLEGADA), 0),
        ];

        for ($dia = $inicio; $dia->lte($fin); $dia = $dia->addDay()) {
            $iso = $dia->dayOfWeekIso;
            $fila = $vacio + ['fecha' => $dia->toDateString(), 'dia' => $dia->day, 'dia_semana' => $iso, 'es_hoy' => $dia->equalTo($hoy)];

            foreach ($this->personal as $p) {
                $horario = $this->horarios[$p->id][$iso] ?? null;

                if (! $horario || ($p->fecha_ingreso && $dia->lt($p->fecha_ingreso->startOfDay()))) {
                    continue;
                }

                $c = $this->clasificar($p, $horario, $dia, $hoy);
                if (! $c) {
                    continue;
                }

                $estado = $c['estado'];
                $tipo = $p->tipo_personal_label;

                foreach ([&$fila, &$resultado['totales']] as &$destino) {
                    $destino['programados']++;
                    $destino[$estado]++;
                    $destino['minutos_tarde'] += $c['minutos_tarde'];
                }
                unset($destino);

                $resultado['personas'][$p->id] ??= $vacio + ['id' => $p->id, 'nombre' => $p->nombre_completo, 'tipo' => $tipo, 'cargo' => $p->cargo];
                $resultado['personas'][$p->id]['programados']++;
                $resultado['personas'][$p->id][$estado]++;
                $resultado['personas'][$p->id]['minutos_tarde'] += $c['minutos_tarde'];

                $resultado['tipos'][$tipo] ??= $vacio;
                $resultado['tipos'][$tipo]['programados']++;
                $resultado['tipos'][$tipo][$estado]++;
                $resultado['tipos'][$tipo]['minutos_tarde'] += $c['minutos_tarde'];

                if ($estado !== 'pendiente') {
                    $resultado['semana'][$iso]['programados']++;
                }
                if (in_array($estado, ['presente', 'tarde'], true)) {
                    $resultado['semana'][$iso]['asistieron']++;
                }
                if ($estado === 'tarde') {
                    $resultado['semana'][$iso]['tarde']++;
                }

                if ($c['desvio'] !== null) {
                    $resultado['llegada'][$this->bucketLlegada($c['desvio'])]++;
                }
            }

            $resultado['dias'][$fila['fecha']] = $fila;
        }

        return $resultado;
    }

    private function bucketLlegada(int $desvio): int
    {
        foreach (self::BUCKETS_LLEGADA as $i => $b) {
            if ($desvio > $b['min'] && $desvio <= $b['max']) {
                return $i;
            }
        }

        return $desvio <= self::BUCKETS_LLEGADA[0]['max'] ? 0 : count(self::BUCKETS_LLEGADA) - 1;
    }

    /**
     * Foto del día de hoy: quién falta, quién llegó tarde y las últimas marcaciones.
     *
     * @return array<string,mixed>
     */
    private function situacionHoy(): array
    {
        $hoy = $this->ahora()->startOfDay();
        $iso = $hoy->dayOfWeekIso;
        $ahora = $this->ahora();

        $res = array_fill_keys(self::ESTADOS, 0) + [
            'activos' => $this->personal->count(), 'programados' => 0, 'registros' => 0,
            'posibles_faltas' => [], 'primera_entrada' => null,
        ];

        foreach ($this->personal as $p) {
            $horario = $this->horarios[$p->id][$iso] ?? null;

            if (! $horario || ($p->fecha_ingreso && $hoy->lt($p->fecha_ingreso->startOfDay()))) {
                continue;
            }

            $c = $this->clasificar($p, $horario, $hoy, $hoy);
            $res['programados']++;
            $res[$c['estado']]++;

            if (isset($this->registros[$p->id.'|'.$hoy->toDateString()])) {
                $res['registros']++;
            }

            if ($c['estado'] === 'sin_registro') {
                $res['posibles_faltas'][] = $p->nombre_completo;
            }

            $entrada = $hoy->setTimeFromTimeString($horario->hora_entrada);
            $res['primera_entrada'] = $res['primera_entrada'] === null || $entrada->lt($res['primera_entrada']) ? $entrada : $res['primera_entrada'];
        }

        $res['primera_entrada'] = $res['primera_entrada']?->format('H:i');
        $res['hora_actual'] = $ahora->format('H:i');

        return $res;
    }

    // ── Indicadores derivados ────────────────────────────────────────────────

    /**
     * @param  array<string,int>  $t
     * @return array<string,?float>
     */
    private function kpis(array $t): array
    {
        $asistieron = $t['presente'] + $t['tarde'];
        $base = $asistieron + $t['ausente'] + $t['sin_registro'];

        return [
            'tasa_asistencia' => $base > 0 ? round($asistieron / $base * 100, 1) : null,
            'puntualidad' => $asistieron > 0 ? round($t['presente'] / $asistieron * 100, 1) : null,
            'asistieron' => $asistieron,
            'inasistencias' => $t['ausente'] + $t['sin_registro'],
            'promedio_tarde' => $t['tarde'] > 0 ? (int) round($t['minutos_tarde'] / $t['tarde']) : 0,
            'horas_perdidas' => round($t['minutos_tarde'] / 60, 1),
        ];
    }

    private function delta(?float $actual, ?float $previo): ?float
    {
        return ($actual === null || $previo === null) ? null : round($actual - $previo, 1);
    }

    /**
     * @param  array<string,array<string,int>>  $tipos
     * @return array<int,array<string,mixed>>
     */
    private function tablaTipos(array $tipos): array
    {
        $filas = [];

        foreach ($tipos as $nombre => $t) {
            $k = $this->kpis($t);
            $filas[] = ['tipo' => $nombre, 'programados' => $t['programados'] - $t['pendiente']] + $k + ['tarde' => $t['tarde']];
        }

        usort($filas, fn ($a, $b) => $b['programados'] <=> $a['programados']);

        return $filas;
    }

    /**
     * @param  array<int,array<string,int>>  $semana
     * @return array<int,array<string,mixed>>
     */
    private function tablaSemana(array $semana): array
    {
        $nombres = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
        $filas = [];

        foreach ($semana as $iso => $s) {
            $filas[] = [
                'dia' => $nombres[$iso],
                'programados' => $s['programados'],
                'tarde' => $s['tarde'],
                'tasa_tardanza' => $s['asistieron'] > 0 ? round($s['tarde'] / $s['asistieron'] * 100, 1) : null,
            ];
        }

        return $filas;
    }

    /**
     * @param  array<int,int>  $llegada
     * @return array<int,array<string,mixed>>
     */
    private function tablaLlegada(array $llegada): array
    {
        $total = array_sum($llegada);

        return array_map(fn ($b, $i) => [
            'etiqueta' => $b['etiqueta'],
            'color' => $b['color'],
            'cantidad' => $llegada[$i],
            'porcentaje' => $total > 0 ? round($llegada[$i] / $total * 100, 1) : 0,
        ], self::BUCKETS_LLEGADA, array_keys(self::BUCKETS_LLEGADA));
    }

    /**
     * @param  array<int,array<string,mixed>>  $personas
     * @return array<int,array<string,mixed>>
     */
    private function personasConTasas(array $personas): array
    {
        return array_map(fn ($p) => $p + $this->kpis($p), array_values($personas));
    }

    /**
     * @param  array<int,array<string,mixed>>  $personas
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function rankings(array $personas): array
    {
        $conDatos = collect($personas)->filter(fn ($p) => $p['asistieron'] >= 3);

        return [
            'puntuales' => $conDatos
                ->filter(fn ($p) => $p['puntualidad'] !== null)
                ->sort(fn ($a, $b) => [$b['puntualidad'], $b['tasa_asistencia']] <=> [$a['puntualidad'], $a['tasa_asistencia']])
                ->take(5)->values()->all(),
            'tardanzas' => collect($personas)->filter(fn ($p) => $p['tarde'] > 0)
                ->sort(fn ($a, $b) => [$b['tarde'], $b['minutos_tarde']] <=> [$a['tarde'], $a['minutos_tarde']])
                ->take(5)->values()->all(),
            'inasistencias' => collect($personas)->filter(fn ($p) => $p['inasistencias'] > 0)
                ->sort(fn ($a, $b) => $b['inasistencias'] <=> $a['inasistencias'])
                ->take(5)->values()->all(),
        ];
    }

    // ── Alertas y decisiones sugeridas ───────────────────────────────────────

    /**
     * @return array<int,array<string,mixed>>
     */
    private function alertas(array $actual, array $kpis, array $kpisPrevio, array $hoy, array $tipos, array $personas): array
    {
        $a = [];
        $t = $actual['totales'];

        // 1. El biométrico no está enviando datos hoy.
        $yaDeberianHaber = $hoy['primera_entrada'] && $hoy['hora_actual'] >= $this->sumarMinutos($hoy['primera_entrada'], 30);
        if ($hoy['programados'] > 0 && $hoy['registros'] === 0 && $yaDeberianHaber) {
            $a[] = $this->alerta('critico', 'Hoy no se ha recibido ninguna marcación',
                "Hay {$hoy['programados']} personas con horario hoy y el sistema no tiene ningún registro (hora actual {$hoy['hora_actual']}). Puede ser una falla del reloj biométrico o de la conexión con la API.",
                ['clave' => 'importar', 'texto' => 'Cargar reporte del biométrico'],
                ['Revisa que el reloj esté encendido y con internet.', 'Si ya tienes el reporte del reloj, cárgalo aquí para no perder el día.']);
        } elseif (count($hoy['posibles_faltas']) > 0) {
            $a[] = $this->alerta('advertencia', count($hoy['posibles_faltas']).' persona(s) sin marcación pasada su hora de entrada',
                'Ya venció la hora de entrada más la tolerancia y no tienen registro hoy.',
                ['clave' => 'asistencia', 'texto' => 'Revisar asistencia de hoy'],
                array_slice($hoy['posibles_faltas'], 0, 6));
        }

        // 2. Personal activo sin horario: la API no podrá registrarlo.
        $sinHorario = $this->personal->filter(fn ($p) => $p->horarios->isEmpty());
        if ($sinHorario->isNotEmpty()) {
            $a[] = $this->alerta('advertencia', $sinHorario->count().' persona(s) activas sin horario configurado',
                'Sin horario el biométrico rechaza su marcación y no aparecen en reportes ni en este tablero.',
                ['clave' => 'personal', 'texto' => 'Configurar horarios'],
                $sinHorario->take(6)->map->nombre_completo->values()->all());
        }

        // 3. Tardanza recurrente.
        $recurrentes = collect($personas)->filter(fn ($p) => $p['tarde'] >= 3 && $p['asistieron'] > 0 && $p['tarde'] / $p['asistieron'] >= 0.25)
            ->sortByDesc('tarde');
        if ($recurrentes->isNotEmpty()) {
            $a[] = $this->alerta('advertencia', $recurrentes->count().' persona(s) con tardanza recurrente',
                'Llegaron tarde en al menos 3 días y en un cuarto o más de sus asistencias del período. Conviene conversar con ellos o revisar si su horario es adecuado.',
                ['clave' => 'reporte', 'texto' => 'Ver reporte detallado'],
                $recurrentes->take(5)->map(fn ($p) => "{$p['nombre']} — {$p['tarde']} tardanzas ({$p['minutos_tarde']} min)")->values()->all());
        }

        // 4. Inasistencias acumuladas.
        $faltantes = collect($personas)->filter(fn ($p) => $p['inasistencias'] >= 3)->sortByDesc('inasistencias');
        if ($faltantes->isNotEmpty()) {
            $nivel = $faltantes->first()['inasistencias'] >= 5 ? 'critico' : 'advertencia';
            $a[] = $this->alerta($nivel, $faltantes->count().' persona(s) con 3 o más inasistencias',
                'Incluye faltas y días sin registro. Verifica si corresponden a permisos o licencias que falte justificar, o a marcaciones que no llegaron.',
                ['clave' => 'asistencia', 'texto' => 'Justificar o corregir registros'],
                $faltantes->take(5)->map(fn ($p) => "{$p['nombre']} — {$p['inasistencias']} día(s)")->values()->all());
        }

        // 5. Metas.
        if ($kpis['tasa_asistencia'] !== null && $kpis['tasa_asistencia'] < self::META_ASISTENCIA) {
            $falta = round(self::META_ASISTENCIA - $kpis['tasa_asistencia'], 1);
            $a[] = $this->alerta('advertencia', "La asistencia está {$falta} puntos por debajo de la meta ({$this->pct(self::META_ASISTENCIA)})",
                "Asistencia actual: {$this->pct($kpis['tasa_asistencia'])}. Revisa las inasistencias del período y el estado del biométrico.", null);
        }

        if ($kpis['puntualidad'] !== null && $kpis['puntualidad'] < self::META_PUNTUALIDAD) {
            $a[] = $this->alerta('advertencia', 'La puntualidad está por debajo de la meta ('.$this->pct(self::META_PUNTUALIDAD).')',
                "Puntualidad actual: {$this->pct($kpis['puntualidad'])}. Se perdieron {$kpis['horas_perdidas']} horas por tardanzas (promedio {$kpis['promedio_tarde']} min por tardanza).", null);
        }

        // 6. Tendencia contra el período anterior.
        $caida = $this->delta($kpis['tasa_asistencia'], $kpisPrevio['tasa_asistencia']);
        if ($caida !== null && $caida <= -3) {
            $a[] = $this->alerta('advertencia', 'La asistencia bajó '.abs($caida).' puntos respecto al período anterior',
                "Antes: {$this->pct($kpisPrevio['tasa_asistencia'])}, ahora: {$this->pct($kpis['tasa_asistencia'])}.", null);
        }

        // 7. Grupos con baja puntualidad.
        $bajos = collect($tipos)->filter(fn ($x) => $x['asistieron'] >= 5 && $x['puntualidad'] !== null && $x['puntualidad'] < self::META_PUNTUALIDAD)->sortBy('puntualidad');
        if ($bajos->isNotEmpty()) {
            $a[] = $this->alerta('info', 'Grupos con puntualidad por debajo de la meta',
                'Si el problema se concentra en un grupo, suele ser un tema de horario o de organización y no individual.', null,
                $bajos->take(4)->map(fn ($x) => "{$x['tipo']} — {$this->pct($x['puntualidad'])} de puntualidad")->values()->all());
        }

        // 8. ¿La tolerancia está bien dimensionada?
        if ($t['tarde'] >= 5) {
            $leves = $actual['llegada'][3];
            $tardias = $actual['llegada'][3] + $actual['llegada'][4] + $actual['llegada'][5] + $actual['llegada'][6];
            if ($tardias > 0 && $leves / $tardias >= 0.4) {
                $porc = round($leves / $tardias * 100);
                $a[] = $this->alerta('info', "{$porc}% de las tardanzas son de 5 minutos o menos",
                    'Muchas llegadas apenas pasan la hora. Evalúa si conviene ajustar la tolerancia o el horario de entrada en lugar de contarlas como tardanza.',
                    ['clave' => 'personal', 'texto' => 'Revisar tolerancia en horarios']);
            }
        }

        // 9. Día de la semana crítico.
        $global = ($t['presente'] + $t['tarde']) > 0 ? $t['tarde'] / ($t['presente'] + $t['tarde']) * 100 : 0;
        $peor = collect($this->tablaSemana($actual['semana']))->filter(fn ($d) => $d['programados'] >= 10 && $d['tasa_tardanza'] !== null)->sortByDesc('tasa_tardanza')->first();
        if ($peor && $global > 0 && $peor['tasa_tardanza'] >= $global * 1.5 && $peor['tasa_tardanza'] >= 15) {
            $a[] = $this->alerta('info', "El día {$peor['dia']} concentra más tardanzas ({$this->pct($peor['tasa_tardanza'])})",
                "Es mucho más alto que el promedio del período ({$this->pct(round($global, 1))}). Puede haber un factor recurrente ese día (transporte, reuniones, etc.).", null);
        }

        if ($a === [] && ! ($t['programados'] === 0)) {
            $a[] = $this->alerta('ok', 'Todo en orden', 'No hay alertas: la asistencia y la puntualidad están dentro de las metas y no hay casos que requieran atención.', null);
        }

        $orden = ['critico' => 0, 'advertencia' => 1, 'info' => 2, 'ok' => 3];
        usort($a, fn ($x, $y) => $orden[$x['nivel']] <=> $orden[$y['nivel']]);

        return $a;
    }

    /**
     * @param  array{clave:string,texto:string}|null  $accion
     * @param  array<int,string>  $items
     * @return array<string,mixed>
     */
    private function alerta(string $nivel, string $titulo, string $detalle, ?array $accion, array $items = []): array
    {
        return compact('nivel', 'titulo', 'detalle', 'accion', 'items');
    }

    private function resumenEjecutivo(string $etiqueta, array $k, array $kPrevio, array $t): string
    {
        if ($t['programados'] === 0) {
            return 'Aún no hay jornadas programadas en este período. Registra personal con horarios para empezar a ver indicadores.';
        }

        $texto = "En {$etiqueta} la asistencia fue de {$this->pct($k['tasa_asistencia'])}";
        $texto .= $k['tasa_asistencia'] !== null && $k['tasa_asistencia'] >= self::META_ASISTENCIA ? ' (cumple la meta)' : ' (meta '.$this->pct(self::META_ASISTENCIA).')';

        $d = $this->delta($k['tasa_asistencia'], $kPrevio['tasa_asistencia']);
        if ($d !== null && $d != 0.0) {
            $texto .= ', '.($d > 0 ? 'mejor' : 'peor').' que el período anterior en '.abs($d).' puntos';
        }

        $texto .= ". La puntualidad fue {$this->pct($k['puntualidad'])}";
        $texto .= $t['tarde'] > 0 ? " con {$t['tarde']} tardanzas (promedio {$k['promedio_tarde']} min; {$k['horas_perdidas']} h perdidas)" : ' sin tardanzas';
        $texto .= $k['inasistencias'] > 0 ? " y {$k['inasistencias']} inasistencias." : ' y sin inasistencias.';

        return $texto;
    }

    // ── Utilidades ───────────────────────────────────────────────────────────

    private function pct(?float $v): string
    {
        return $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.').'%';
    }

    private function minutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $h * 60 + $m;
    }

    private function sumarMinutos(string $hora, int $minutos): string
    {
        $total = $this->minutos($hora) + $minutos;

        return sprintf('%02d:%02d', intdiv($total, 60) % 24, $total % 60);
    }

    private function ahora(): CarbonImmutable
    {
        return $this->ahora ?? CarbonImmutable::now();
    }
}
