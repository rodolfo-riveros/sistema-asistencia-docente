<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HorarioPersonals;
use App\Models\Personal_ies;
use App\Services\ImportadorPersonal;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descargas masivas en CSV (se abre directo en Excel: UTF-8 con BOM y separador ";").
 */
class ExportacionController extends Controller
{
    /** Listado de personal con su horario semanal. */
    public function personal(Request $request): StreamedResponse
    {
        $personal = Personal_ies::query()
            ->with(['horarios' => fn ($q) => $q->activos()->orderBy('dia_semana')])
            ->when($request->search, fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('nombres', 'like', "%{$s}%")
                    ->orWhere('apellido_paterno', 'like', "%{$s}%")
                    ->orWhere('apellido_materno', 'like', "%{$s}%")
                    ->orWhere('dni', 'like', "%{$s}%");
            }))
            ->when($request->tipo, fn ($q, $t) => $q->where('tipo_personal', $t))
            ->when($request->filled('activo'), fn ($q) => $q->where('activo', (bool) $request->activo))
            ->orderBy('apellido_paterno')
            ->orderBy('nombres')
            ->get();

        $cabecera = ['ID', 'DNI', 'Apellido paterno', 'Apellido materno', 'Nombres', 'Tipo', 'Cargo', 'Teléfono', 'Fecha de ingreso', 'Estado'];
        foreach (HorarioPersonals::DIAS as $dia) {
            $cabecera[] = $dia;
        }

        return $this->csv('personal-'.now()->format('Y-m-d').'.csv', $cabecera, function () use ($personal) {
            foreach ($personal as $p) {
                $fila = [
                    $p->id,
                    $p->dni,
                    $p->apellido_paterno,
                    $p->apellido_materno,
                    $p->nombres,
                    $p->tipo_personal_label,
                    $p->cargo,
                    $p->telefono,
                    $p->fecha_ingreso?->format('d/m/Y'),
                    $p->activo ? 'Activo' : 'Inactivo',
                ];

                foreach (array_keys(HorarioPersonals::DIAS) as $dia) {
                    $h = $p->horarios->firstWhere('dia_semana', $dia);
                    $fila[] = $h ? $this->textoHorario($h) : '';
                }

                yield $fila;
            }
        });
    }

    /** Plantilla de ejemplo para la carga masiva de personal y horarios. */
    public function plantilla(): StreamedResponse
    {
        $filas = ImportadorPersonal::plantilla();
        $cabecera = array_shift($filas);

        return $this->csv('plantilla-importar-personal.csv', $cabecera, function () use ($filas) {
            yield from $filas;
        });
    }

    /** Asistencia detallada día por día (todo el personal con horario) en un rango de fechas. */
    public function asistencia(Request $request): StreamedResponse
    {
        $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
        ]);

        $inicio = $request->fecha_inicio;
        $fin = $request->fecha_fin;

        $personal = Personal_ies::query()
            ->with([
                'asistencias' => fn ($q) => $q->whereBetween('fecha', [$inicio, $fin]),
                'horarios' => fn ($q) => $q->activos(),
            ])
            ->where('activo', true)
            ->when($request->tipo_personal, fn ($q, $t) => $q->where('tipo_personal', $t))
            ->when($request->personal_id, fn ($q, $id) => $q->where('id', $id))
            ->orderBy('apellido_paterno')
            ->orderBy('nombres')
            ->get();

        $dias = collect(CarbonPeriod::create($inicio, $fin))->map(fn ($d) => $d->format('Y-m-d'));

        $cabecera = [
            'Fecha', 'Día', 'DNI', 'Personal', 'Tipo', 'Cargo', 'Horario oficial',
            'Estado', 'Entrada mañana', 'Salida mañana', 'Entrada tarde', 'Salida tarde',
            'Min. tardanza', 'Observación',
        ];

        return $this->csv("asistencia-{$inicio}-al-{$fin}.csv", $cabecera, function () use ($personal, $dias) {
            foreach ($dias as $fecha) {
                $carbon = Carbon::parse($fecha);

                foreach ($personal as $p) {
                    $horario = $p->horarios->firstWhere('dia_semana', $carbon->isoWeekday());
                    if (! $horario) {
                        continue;
                    }

                    $a = $p->asistencias->first(fn ($x) => $x->fecha->format('Y-m-d') === $fecha);
                    $estado = $a?->estado_entrada ?? 'sin_registro';

                    $tardanza = '';
                    if ($a?->hora_entrada_maniana && $estado === 'tarde') {
                        $oficial = Carbon::parse("{$fecha} {$horario->hora_entrada}");
                        $real = Carbon::parse("{$fecha} {$a->hora_entrada_maniana}");
                        $tardanza = max(0, (int) $oficial->diffInMinutes($real, false));
                    }

                    yield [
                        $carbon->format('d/m/Y'),
                        ucfirst($carbon->locale('es')->isoFormat('dddd')),
                        $p->dni,
                        $p->nombre_completo,
                        $p->tipo_personal_label,
                        $p->cargo,
                        $this->textoHorario($horario),
                        ucfirst(str_replace('_', ' ', $estado)),
                        $this->hora($a?->hora_entrada_maniana),
                        $this->hora($a?->hora_salida_maniana),
                        $this->hora($a?->hora_entrada_tarde),
                        $this->hora($a?->hora_salida_tarde),
                        $tardanza,
                        $a?->observacion,
                    ];
                }
            }
        });
    }

    private function textoHorario(HorarioPersonals $h): string
    {
        $entrada = substr($h->hora_entrada, 0, 5);
        $salida = substr($h->hora_salida, 0, 5);

        if ($h->esDobleJornada()) {
            return $entrada.'-'.substr($h->hora_salida_maniana, 0, 5).' / '.substr($h->hora_entrada_tarde, 0, 5).'-'.$salida;
        }

        return $entrada.'-'.$salida;
    }

    private function hora(?string $valor): string
    {
        return $valor ? substr($valor, 0, 5) : '';
    }

    private function csv(string $nombre, array $cabecera, callable $filas): StreamedResponse
    {
        return response()->streamDownload(function () use ($cabecera, $filas) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $cabecera, ';');

            foreach ($filas() as $fila) {
                fputcsv($salida, $this->protegerFormulas($fila), ';');
            }

            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Evita que un valor que empiece con = + - @ se ejecute como fórmula al abrirlo en Excel. */
    private function protegerFormulas(array $fila): array
    {
        return array_map(function ($valor) {
            if (is_string($valor) && $valor !== '' && str_contains('=+-@', $valor[0]) && ! preg_match('/^-?\d+([.,]\d+)?$/', $valor)) {
                return "'".$valor;
            }

            return $valor;
        }, $fila);
    }
}
