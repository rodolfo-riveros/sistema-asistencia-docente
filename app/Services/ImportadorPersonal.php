<?php

namespace App\Services;

use App\Models\HorarioPersonals;
use App\Models\Personal_ies;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa personal y/o horarios desde un CSV (el mismo formato que genera la descarga masiva).
 *
 * El archivo primero se analiza (sin tocar la base) y recién se aplica si el usuario confirma.
 */
class ImportadorPersonal
{
    public const MODO_COMPLETO = 'completo';

    public const MODO_HORARIOS = 'horarios';

    public const MAX_FILAS = 2000;

    private const COLUMNAS = [
        // El ID interno (código del biométrico) viene en la descarga masiva; se reconoce pero no se importa.
        'id' => ['id', 'codigo', 'id biometrico', 'codigo biometrico'],
        'dni' => ['dni', 'documento', 'nro documento', 'numero de documento'],
        'apellido_paterno' => ['apellido paterno', 'ap paterno', 'paterno'],
        'apellido_materno' => ['apellido materno', 'ap materno', 'materno'],
        'nombres' => ['nombres', 'nombre'],
        'tipo' => ['tipo', 'tipo de personal', 'tipo personal'],
        'cargo' => ['cargo'],
        'telefono' => ['telefono', 'celular', 'cel'],
        'fecha_ingreso' => ['fecha de ingreso', 'fecha ingreso', 'ingreso'],
        'estado' => ['estado'],
        'tolerancia' => ['tolerancia', 'tolerancia min', 'tolerancia minutos'],
        'dia_1' => ['lunes', 'lun'],
        'dia_2' => ['martes', 'mar'],
        'dia_3' => ['miercoles', 'mie'],
        'dia_4' => ['jueves', 'jue'],
        'dia_5' => ['viernes', 'vie'],
        'dia_6' => ['sabado', 'sab'],
        'dia_7' => ['domingo', 'dom'],
    ];

    private const TIPOS = [
        'docente' => 'docente',
        'auxiliar' => 'auxiliar',
        'administrativo' => 'administrativo',
        'limpieza' => 'limpieza',
        'personal de limpieza' => 'limpieza',
        'vigilancia' => 'vigilancia',
        'porteria' => 'vigilancia',
        'vigilancia porteria' => 'vigilancia',
        'directivo' => 'directivo',
        'otro' => 'otro',
    ];

    /**
     * Lee el archivo y devuelve la estructura de columnas/filas ya normalizada.
     *
     * @return array{columnas: array<int,string>, ignoradas: array<int,string>, filas: array<int,array{linea:int,valores:array<string,string>}>, error: ?string}
     */
    public function leer(string $ruta): array
    {
        $contenido = @file_get_contents($ruta);

        if ($contenido === false || trim($contenido) === '') {
            return $this->vacio('El archivo está vacío o no se pudo leer.');
        }

        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        $primera = strtok($contenido, "\n") ?: '';
        $delimitador = collect([';' => substr_count($primera, ';'), ',' => substr_count($primera, ','), "\t" => substr_count($primera, "\t")])
            ->sortDesc()
            ->keys()
            ->first();

        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        $cabecera = fgetcsv($flujo, 0, $delimitador, '"', '');

        if (! $cabecera) {
            fclose($flujo);

            return $this->vacio('No se encontró la fila de encabezados.');
        }

        $columnas = [];
        $ignoradas = [];

        foreach ($cabecera as $indice => $titulo) {
            $campo = $this->campoDeEncabezado((string) $titulo);

            if ($campo && ! in_array($campo, $columnas, true)) {
                $columnas[$indice] = $campo;
            } elseif (trim((string) $titulo) !== '') {
                $ignoradas[] = trim((string) $titulo);
            }
        }

        $filas = [];
        $linea = 1;

        while (($fila = fgetcsv($flujo, 0, $delimitador, '"', '')) !== false) {
            $linea++;

            if (count(array_filter($fila, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }

            if (count($filas) >= self::MAX_FILAS) {
                fclose($flujo);

                return $this->vacio('El archivo supera el máximo de '.self::MAX_FILAS.' filas. Divídelo en partes.');
            }

            $valores = [];
            foreach ($columnas as $indice => $campo) {
                $valores[$campo] = trim((string) ($fila[$indice] ?? ''));
            }

            $filas[] = ['linea' => $linea, 'valores' => $valores];
        }

        fclose($flujo);

        return ['columnas' => $columnas, 'ignoradas' => $ignoradas, 'filas' => $filas, 'error' => null];
    }

    /**
     * Valida todas las filas sin guardar nada.
     *
     * @return array{errores_globales: array<int,string>, ignoradas: array<int,string>, filas: array<int,array<string,mixed>>, resumen: array<string,int>}
     */
    public function analizar(string $ruta, string $modo = self::MODO_COMPLETO, bool $actualizar = true): array
    {
        $lectura = $this->leer($ruta);
        $globales = [];

        if ($lectura['error']) {
            return $this->resultado([$lectura['error']], [], []);
        }

        $presentes = array_values($lectura['columnas']);
        $columnasDias = array_values(array_filter($presentes, fn ($c) => str_starts_with($c, 'dia_')));

        $obligatorias = $modo === self::MODO_HORARIOS ? ['dni'] : ['dni', 'nombres', 'apellido_paterno'];
        foreach ($obligatorias as $campo) {
            if (! in_array($campo, $presentes, true)) {
                $globales[] = 'Falta la columna obligatoria: '.$this->nombreColumna($campo).'.';
            }
        }

        if ($modo === self::MODO_HORARIOS && $columnasDias === []) {
            $globales[] = 'El archivo no tiene columnas de días (Lunes, Martes, ...).';
        }

        if ($lectura['filas'] === []) {
            $globales[] = 'El archivo no tiene filas de datos debajo de los encabezados.';
        }

        if ($globales) {
            return $this->resultado($globales, $lectura['ignoradas'], []);
        }

        $filas = array_map(fn ($f) => $this->analizarFila($f, $modo, $columnasDias), $lectura['filas']);

        $existentes = Personal_ies::withTrashed()
            ->whereIn('dni', collect($filas)->pluck('dni')->filter()->unique()->all())
            ->get()
            ->keyBy('dni');

        $vistos = [];

        foreach ($filas as &$fila) {
            $this->resolverAccion($fila, $existentes->get($fila['dni']), $modo, $actualizar, $vistos);
        }
        unset($fila);

        return $this->resultado([], $lectura['ignoradas'], $filas);
    }

    /**
     * Analiza y guarda las filas válidas en una sola transacción.
     *
     * @return array<string,mixed>
     */
    public function importar(string $ruta, string $modo, bool $actualizar, bool $reemplazarHorarios): array
    {
        $analisis = $this->analizar($ruta, $modo, $actualizar);

        $contadores = ['creados' => 0, 'actualizados' => 0, 'omitidos' => 0, 'con_errores' => 0, 'dias_horario' => 0];

        if ($analisis['errores_globales']) {
            return $contadores + ['errores_globales' => $analisis['errores_globales']];
        }

        DB::transaction(function () use ($analisis, $reemplazarHorarios, &$contadores) {
            foreach ($analisis['filas'] as $fila) {
                if ($fila['errores']) {
                    $contadores['con_errores']++;

                    continue;
                }

                switch ($fila['accion']) {
                    case 'crear':
                        $personal = Personal_ies::create($fila['datos'] + ['activo' => true]);
                        $contadores['creados']++;
                        $contadores['dias_horario'] += $this->aplicarHorarios($personal, $fila, false);
                        break;

                    case 'actualizar':
                        $personal = Personal_ies::withTrashed()->where('dni', $fila['dni'])->firstOrFail();
                        if ($personal->trashed()) {
                            $personal->restore();
                        }
                        $personal->update($fila['datos']);
                        $contadores['actualizados']++;
                        $contadores['dias_horario'] += $this->aplicarHorarios($personal, $fila, $reemplazarHorarios);
                        break;

                    case 'horario':
                        $personal = Personal_ies::where('dni', $fila['dni'])->firstOrFail();
                        $contadores['actualizados']++;
                        $contadores['dias_horario'] += $this->aplicarHorarios($personal, $fila, $reemplazarHorarios);
                        break;

                    default:
                        $contadores['omitidos']++;
                }
            }
        });

        return $contadores + ['errores_globales' => []];
    }

    /** Contenido de la plantilla descargable: una fila de ejemplo por cada caso soportado. */
    public static function plantilla(): array
    {
        return [
            ['DNI', 'Apellido paterno', 'Apellido materno', 'Nombres', 'Tipo', 'Cargo', 'Teléfono', 'Fecha de ingreso', 'Estado', 'Tolerancia (min)', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'],
            ['70112233', 'Mamani', 'Quispe', 'Luis Alberto', 'Docente', 'Profesor de Matemática', '987654321', '01/03/2023', 'Activo', '10', '07:30-15:30', '07:30-15:30', '07:30-15:30', '07:30-15:30', '07:30-15:30', '', ''],
            ['04561234', 'Torres', 'Huamán', 'María Elena', 'Administrativo', 'Secretaria', '', '15/04/2022', 'Activo', '5', '08:00-13:00 / 14:30-17:30', '08:00-13:00 / 14:30-17:30', '08:00-13:00 / 14:30-17:30', '08:00-13:00 / 14:30-17:30', '08:00-13:00 / 14:30-17:30', '', ''],
            ['45678901', 'Ccori', '', 'José', 'Vigilancia', 'Portero', '', '', 'Activo', '', '06:00-14:00', '06:00-14:00', '06:00-14:00', '06:00-14:00', '06:00-14:00', '06:00-14:00', ''],
        ];
    }

    // ── Análisis de filas ────────────────────────────────────────────────────

    private function analizarFila(array $fila, string $modo, array $columnasDias): array
    {
        $v = $fila['valores'];
        $errores = [];
        $datos = [];

        $dni = $this->normalizarDni($v['dni'] ?? '');
        if ($dni === null) {
            $errores[] = 'DNI inválido (debe tener 8 dígitos).';
        }

        if ($modo === self::MODO_COMPLETO) {
            foreach (['nombres' => 100, 'apellido_paterno' => 100, 'apellido_materno' => 100, 'cargo' => 150, 'telefono' => 20] as $campo => $max) {
                if (($v[$campo] ?? '') === '') {
                    continue;
                }
                if (mb_strlen($v[$campo]) > $max) {
                    $errores[] = $this->nombreColumna($campo)." supera {$max} caracteres.";

                    continue;
                }
                $datos[$campo] = $v[$campo];
            }

            if (($v['tipo'] ?? '') !== '') {
                $tipo = self::TIPOS[$this->clave($v['tipo'])] ?? null;
                $tipo ? $datos['tipo_personal'] = $tipo : $errores[] = 'Tipo no reconocido: "'.$v['tipo'].'".';
            }

            if (($v['fecha_ingreso'] ?? '') !== '') {
                $fecha = $this->parsearFecha($v['fecha_ingreso']);
                $fecha ? $datos['fecha_ingreso'] = $fecha : $errores[] = 'Fecha de ingreso inválida (usa dd/mm/aaaa).';
            }

            if (($v['estado'] ?? '') !== '') {
                $estado = match ($this->clave($v['estado'])) {
                    'activo', '1', 'si', 'true' => true,
                    'inactivo', '0', 'no', 'false' => false,
                    default => null,
                };
                $estado === null ? $errores[] = 'Estado debe ser Activo o Inactivo.' : $datos['activo'] = $estado;
            }
        }

        $tolerancia = null;
        if (($v['tolerancia'] ?? '') !== '') {
            if (ctype_digit($v['tolerancia']) && (int) $v['tolerancia'] <= 120) {
                $tolerancia = (int) $v['tolerancia'];
            } else {
                $errores[] = 'Tolerancia debe ser un número entre 0 y 120.';
            }
        }

        $horarios = [];
        foreach ($columnasDias as $campoDia) {
            $dia = (int) substr($campoDia, 4);
            $texto = $v[$campoDia] ?? '';

            try {
                $horarios[$dia] = $this->parsearHorario($texto);
            } catch (\InvalidArgumentException $e) {
                $errores[] = HorarioPersonals::DIAS[$dia].': '.$e->getMessage();
            }
        }

        $nombre = trim(($v['apellido_paterno'] ?? '').' '.($v['apellido_materno'] ?? '').' '.($v['nombres'] ?? ''));

        return [
            'linea' => $fila['linea'],
            'dni' => $dni,
            'dni_original' => $v['dni'] ?? '',
            'nombre' => $nombre,
            'accion' => 'pendiente',
            'errores' => $errores,
            'avisos' => [],
            'datos' => $dni ? ['dni' => $dni] + $datos : $datos,
            'horarios' => $horarios,
            'tolerancia' => $tolerancia,
        ];
    }

    private function resolverAccion(array &$fila, ?Personal_ies $existente, string $modo, bool $actualizar, array &$vistos): void
    {
        if ($fila['dni']) {
            if (isset($vistos[$fila['dni']])) {
                $fila['errores'][] = 'DNI repetido en el archivo (ya aparece en la línea '.$vistos[$fila['dni']].').';
            }
            $vistos[$fila['dni']] ??= $fila['linea'];
        }

        if ($fila['errores']) {
            $fila['accion'] = 'error';

            return;
        }

        if ($modo === self::MODO_HORARIOS) {
            if (! $existente || $existente->trashed()) {
                $fila['errores'][] = 'No existe personal activo con ese DNI (en este modo solo se actualizan horarios).';
                $fila['accion'] = 'error';

                return;
            }

            $fila['nombre'] = $existente->nombre_completo;
            $fila['accion'] = 'horario';

            return;
        }

        if ($existente) {
            if (! $actualizar) {
                $fila['accion'] = 'omitir';
                $fila['avisos'][] = 'Ya existe: se omite (activa "actualizar existentes" para modificarlo).';

                return;
            }

            if ($existente->trashed()) {
                $fila['avisos'][] = 'Estaba eliminado: se restaurará.';
            }

            $fila['accion'] = 'actualizar';

            return;
        }

        foreach (['nombres' => 'Nombres', 'apellido_paterno' => 'Apellido paterno', 'tipo_personal' => 'Tipo'] as $campo => $titulo) {
            if (empty($fila['datos'][$campo])) {
                $fila['errores'][] = "{$titulo} es obligatorio para crear personal nuevo.";
            }
        }

        $fila['accion'] = $fila['errores'] ? 'error' : 'crear';
    }

    // ── Aplicar horarios ─────────────────────────────────────────────────────

    private function aplicarHorarios(Personal_ies $personal, array $fila, bool $reemplazar): int
    {
        $escritos = 0;

        foreach ($fila['horarios'] as $dia => $horario) {
            if ($horario === null) {
                if ($reemplazar) {
                    HorarioPersonals::where('personal_id', $personal->id)->where('dia_semana', $dia)->update(['activo' => false]);
                }

                continue;
            }

            $atributos = $horario + ['activo' => true];
            if ($fila['tolerancia'] !== null) {
                $atributos['tolerancia_minutos'] = $fila['tolerancia'];
            }

            $personal->horarios()->updateOrCreate(['dia_semana' => $dia], $atributos);
            $escritos++;
        }

        return $escritos;
    }

    // ── Utilidades de parseo ─────────────────────────────────────────────────

    /**
     * @return array<string,?string>|null null = día libre
     */
    private function parsearHorario(string $texto): ?array
    {
        $limpio = $this->clave($texto);

        if ($limpio === '' || in_array($limpio, ['libre', 'descanso', 'no', 'x', 'nulo'], true) || preg_match('/^-+$/', trim($texto))) {
            return null;
        }

        preg_match_all('/(\d{1,2})\s*[:.]\s*(\d{2})/', $texto, $coincidencias, PREG_SET_ORDER);

        if (! in_array(count($coincidencias), [2, 4], true)) {
            throw new \InvalidArgumentException('formato de horario inválido ("'.$texto.'"). Usa 07:30-15:30 o 08:00-13:00 / 14:30-17:30.');
        }

        $minutos = [];
        $horas = [];
        foreach ($coincidencias as $c) {
            $h = (int) $c[1];
            $m = (int) $c[2];
            if ($h > 23 || $m > 59) {
                throw new \InvalidArgumentException('hora inválida en "'.$texto.'".');
            }
            $minutos[] = $h * 60 + $m;
            $horas[] = sprintf('%02d:%02d:00', $h, $m);
        }

        for ($i = 1; $i < count($minutos); $i++) {
            if ($minutos[$i] <= $minutos[$i - 1]) {
                throw new \InvalidArgumentException('las horas deben ir en orden creciente ("'.$texto.'").');
            }
        }

        if (count($horas) === 2) {
            return ['hora_entrada' => $horas[0], 'hora_salida_maniana' => null, 'hora_entrada_tarde' => null, 'hora_salida' => $horas[1]];
        }

        return ['hora_entrada' => $horas[0], 'hora_salida_maniana' => $horas[1], 'hora_entrada_tarde' => $horas[2], 'hora_salida' => $horas[3]];
    }

    /** Excel suele quitar los ceros a la izquierda del DNI; se completan hasta 8 dígitos. */
    private function normalizarDni(string $valor): ?string
    {
        $valor = preg_replace('/\s+/', '', $valor);

        if (preg_match('/^\d{6,8}$/', $valor)) {
            return str_pad($valor, 8, '0', STR_PAD_LEFT);
        }

        return null;
    }

    private function parsearFecha(string $valor): ?string
    {
        foreach (['j/n/Y', 'j-n-Y', 'Y-m-d', 'j/n/y'] as $formato) {
            $fecha = DateTime::createFromFormat('!'.$formato, $valor);
            $errores = DateTime::getLastErrors();

            if ($fecha && (! $errores || ($errores['warning_count'] === 0 && $errores['error_count'] === 0))) {
                return Carbon::instance($fecha)->toDateString();
            }
        }

        return null;
    }

    private function campoDeEncabezado(string $titulo): ?string
    {
        $clave = $this->clave($titulo);

        foreach (self::COLUMNAS as $campo => $alias) {
            if (in_array($clave, $alias, true)) {
                return $campo;
            }
        }

        return null;
    }

    private function clave(string $texto): string
    {
        return (string) Str::of($texto)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim();
    }

    private function nombreColumna(string $campo): string
    {
        return [
            'dni' => 'DNI', 'nombres' => 'Nombres', 'apellido_paterno' => 'Apellido paterno',
            'apellido_materno' => 'Apellido materno', 'cargo' => 'Cargo', 'telefono' => 'Teléfono',
        ][$campo] ?? $campo;
    }

    private function vacio(string $error): array
    {
        return ['columnas' => [], 'ignoradas' => [], 'filas' => [], 'error' => $error];
    }

    private function resultado(array $globales, array $ignoradas, array $filas): array
    {
        $resumen = ['total' => count($filas), 'crear' => 0, 'actualizar' => 0, 'horario' => 0, 'omitir' => 0, 'error' => 0];

        foreach ($filas as $f) {
            $resumen[$f['accion']] = ($resumen[$f['accion']] ?? 0) + 1;
        }

        return ['errores_globales' => $globales, 'ignoradas' => $ignoradas, 'filas' => $filas, 'resumen' => $resumen];
    }
}
