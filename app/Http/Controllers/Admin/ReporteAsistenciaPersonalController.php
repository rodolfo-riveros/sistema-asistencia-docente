<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Personal_ies;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

class ReporteAsistenciaPersonalController extends Controller
{
    public function pdf(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
        ]);

        $fechaInicio = $request->fecha_inicio;
        $fechaFin = $request->fecha_fin;
        $tipoPersonal = $request->tipo_personal;
        $personalId = $request->personal_id;

        $query = Personal_ies::query()
            ->with(['asistencias' => fn ($q) => $q
                ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                ->orderBy('fecha')
            ])
            ->where('activo', true)
            ->when($tipoPersonal, fn ($q) => $q->where('tipo_personal', $tipoPersonal))
            ->when($personalId, fn ($q) => $q->where('id', $personalId))
            ->orderBy('tipo_personal')
            ->orderBy('apellido_paterno');

        $listaPersonal = $query->get();
        $periodo = CarbonPeriod::create($fechaInicio, $fechaFin);
        $diasRango = collect($periodo)->map(fn ($d) => $d->format('Y-m-d'))->values()->toArray();

        $datosReporte = [];

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
                    'dia_label' => Carbon::parse($fecha)->locale('es')->isoFormat('ddd D/MM'),
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

            $datosReporte[] = [
                'personal' => $personal->nombre_completo,
                'dni' => $personal->dni,
                'tipo' => $personal->tipo_personal_label,
                'cargo' => $personal->cargo ?? '',
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

        $rango = Carbon::parse($fechaInicio)->locale('es')->isoFormat('D [de] MMMM [de] YYYY')
            .' al '
            .Carbon::parse($fechaFin)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');

        $html = view('pdf.reporte-asistencia-personal', compact('datosReporte', 'rango', 'fechaInicio', 'fechaFin'))->render();

        return response($html)
            ->header('Content-Type', 'text/html')
            ->header('Content-Disposition', 'inline; filename="reporte-asistencia-'.$fechaInicio.'-al-'.$fechaFin.'.html"');
    }
}
