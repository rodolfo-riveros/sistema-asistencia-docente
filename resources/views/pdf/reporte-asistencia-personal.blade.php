<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de asistencia de personal</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #1f2937; margin: 24px; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .subtitle { color: #6b7280; margin-bottom: 20px; }
        .persona { page-break-inside: avoid; margin-bottom: 28px; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px; }
        .persona-header { display: flex; justify-content: space-between; margin-bottom: 8px; }
        .persona-header h2 { font-size: 14px; margin: 0; }
        .persona-header .meta { color: #6b7280; font-size: 11px; }
        .detalle { display: flex; gap: 24px; margin: 8px 0 4px; padding: 8px 10px; background: #f9fafb; border-radius: 6px; font-size: 11px; flex-wrap: wrap; }
        .detalle dl { margin: 0; display: grid; grid-template-columns: auto auto; gap: 2px 8px; }
        .detalle dt { color: #6b7280; }
        .detalle dd { margin: 0; }
        .horarios { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .horario-chip { background: #e5e7eb; border-radius: 10px; padding: 2px 8px; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #e5e7eb; padding: 4px 6px; text-align: left; }
        th { background: #f3f4f6; }
        .resumen { display: flex; gap: 14px; margin-top: 8px; font-size: 11px; }
        .resumen span strong { display: block; font-size: 13px; }
        .estado-presente { color: #16a34a; }
        .estado-tarde { color: #d97706; }
        .estado-ausente { color: #dc2626; }
        .estado-justificado { color: #2563eb; }
        .estado-sin_registro { color: #9ca3af; }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body onload="window.print()">
    <button class="no-print" onclick="window.print()">Imprimir / Guardar como PDF</button>

    <h1>Reporte de asistencia de personal</h1>
    <p class="subtitle">{{ $rango }}</p>

    @forelse ($datosReporte as $persona)
        <div class="persona">
            <div class="persona-header">
                <h2>{{ $persona['personal'] }} — {{ $persona['tipo'] }}</h2>
                <div class="meta">DNI {{ $persona['dni'] }} @if($persona['cargo']) · {{ $persona['cargo'] }} @endif</div>
            </div>

            <div class="detalle">
                <dl>
                    <dt>DNI</dt><dd>{{ $persona['dni'] }}</dd>
                    <dt>Teléfono</dt><dd>{{ $persona['telefono'] ?: '—' }}</dd>
                    <dt>Ingreso</dt><dd>{{ $persona['fecha_ingreso'] ?: '—' }}</dd>
                </dl>
                <div class="horarios">
                    @forelse ($persona['horarios'] as $h)
                        <span class="horario-chip">
                            <strong>{{ $h['dia'] }}</strong>
                            {{ $h['entrada'] }}–{{ $h['entrada_tarde'] ? $h['salida_manana'] : $h['salida'] }}
                            @if ($h['entrada_tarde']) / {{ $h['entrada_tarde'] }}–{{ $h['salida'] }} @endif
                        </span>
                    @empty
                        <span class="horario-chip">Sin horario configurado</span>
                    @endforelse
                </div>
            </div>

            <table>
                <thead>
                <tr>
                    <th>Día</th>
                    <th>Hora oficial</th>
                    <th>Entrada</th>
                    <th>Salida</th>
                    <th>Estado</th>
                    <th>Tardanza (min)</th>
                    <th>Observación</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($persona['filas'] as $fila)
                    <tr>
                        <td>{{ $fila['dia_label'] }}</td>
                        <td>{{ $fila['hora_oficial_entrada'] }}</td>
                        <td>{{ $fila['hora_entrada'] ?? '—' }}</td>
                        <td>{{ $fila['hora_salida'] ?? '—' }}</td>
                        <td class="estado-{{ $fila['estado'] }}">{{ ucfirst(str_replace('_', ' ', $fila['estado'])) }}</td>
                        <td>{{ $fila['minutos_tardanza'] ?: '—' }}</td>
                        <td>{{ $fila['observacion'] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div class="resumen">
                <span>Presentes<strong>{{ $persona['resumen']['presentes'] }}</strong></span>
                <span>Tardanzas<strong>{{ $persona['resumen']['tardanzas'] }}</strong></span>
                <span>Faltas<strong>{{ $persona['resumen']['faltas'] }}</strong></span>
                <span>Justificados<strong>{{ $persona['resumen']['justificados'] }}</strong></span>
                <span>Sin registro<strong>{{ $persona['resumen']['sin_registro'] }}</strong></span>
                <span>Min. tardanza acumulados<strong>{{ $persona['resumen']['min_tardanza'] }}</strong></span>
            </div>
        </div>
    @empty
        <p>No hay datos de asistencia para el rango y filtros seleccionados.</p>
    @endforelse
</body>
</html>
