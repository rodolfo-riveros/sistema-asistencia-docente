<?php

use App\Models\Asistencia_personals;
use App\Models\Personal_ies;
use App\Models\User;
use App\Services\EstadisticasAsistencia;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // Miércoles 6 de mayo de 2026, 10:00 (hora de Lima).
    $this->travelTo(CarbonImmutable::parse('2026-05-06 10:00:00', 'America/Lima'));
});

function docenteConHorario(array $atributos = []): Personal_ies
{
    $p = Personal_ies::factory()->create($atributos + ['fecha_ingreso' => '2026-01-01']);

    foreach (range(1, 5) as $dia) {
        $p->horarios()->create(['dia_semana' => $dia, 'hora_entrada' => '07:30:00', 'hora_salida' => '15:30:00', 'tolerancia_minutos' => 10, 'activo' => true]);
    }

    return $p;
}

function marcar(Personal_ies $p, string $fecha, string $estado, ?string $entrada = '07:25:00'): void
{
    Asistencia_personals::create(['personal_id' => $p->id, 'fecha' => $fecha, 'hora_entrada_maniana' => $entrada, 'estado_entrada' => $estado]);
}

test('calcula asistencia, puntualidad e inasistencias del periodo', function () {
    $p = docenteConHorario();
    // Ventana de 7 días: jue 30/04, vie 01/05, lun 04/05, mar 05/05 y hoy mié 06/05.
    marcar($p, '2026-04-30', 'presente');
    marcar($p, '2026-05-01', 'presente');
    marcar($p, '2026-05-04', 'tarde', '07:50:00');
    // martes 05/05: sin registro (falta). Hoy: sin marcar y ya pasó la tolerancia.

    $panel = app(EstadisticasAsistencia::class)->panel('7');

    expect($panel['totales']['presente'])->toBe(2)
        ->and($panel['totales']['tarde'])->toBe(1)
        ->and($panel['totales']['sin_registro'])->toBe(2)   // martes + hoy
        ->and($panel['kpis']['asistieron'])->toBe(3)
        ->and($panel['kpis']['tasa_asistencia'])->toBe(60.0)  // 3 de 5
        ->and($panel['kpis']['puntualidad'])->toBe(66.7)      // 2 de 3
        ->and($panel['kpis']['promedio_tarde'])->toBe(20)     // 07:50 contra 07:30
        ->and($panel['hoy']['posibles_faltas'])->toHaveCount(1);
});

test('antes de vencer la tolerancia hoy figura como pendiente y no como falta', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-06 07:35:00', 'America/Lima'));
    docenteConHorario();

    $panel = app(EstadisticasAsistencia::class)->panel('7');

    expect($panel['hoy']['pendiente'])->toBe(1)
        ->and($panel['hoy']['sin_registro'])->toBe(0)
        ->and($panel['totales']['pendiente'])->toBe(1);
});

test('los justificados no restan a la tasa de asistencia', function () {
    $p = docenteConHorario();
    foreach (['2026-04-30', '2026-05-01', '2026-05-04'] as $f) {
        marcar($p, $f, 'presente');
    }
    marcar($p, '2026-05-05', 'justificado', null);
    marcar($p, '2026-05-06', 'presente');

    $panel = app(EstadisticasAsistencia::class)->panel('7');

    expect($panel['kpis']['tasa_asistencia'])->toBe(100.0)->and($panel['totales']['justificado'])->toBe(1);
});

test('ignora personal inactivo, dias sin horario y fechas anteriores al ingreso', function () {
    docenteConHorario(['activo' => false]);
    docenteConHorario(['fecha_ingreso' => '2026-05-06']); // ingresó hoy: solo cuenta hoy

    $panel = app(EstadisticasAsistencia::class)->panel('7');

    expect($panel['totales']['programados'])->toBe(1);
});

test('alerta cuando hoy no llega ninguna marcacion y sugiere cargar el reporte', function () {
    docenteConHorario();

    $alertas = app(EstadisticasAsistencia::class)->panel('7')['alertas'];

    expect($alertas[0]['nivel'])->toBe('critico')->and($alertas[0]['accion']['clave'])->toBe('importar');
});

test('detecta personal sin horario y tardanza recurrente', function () {
    Personal_ies::factory()->create(['fecha_ingreso' => '2026-01-01']);
    $p = docenteConHorario();
    foreach (['2026-04-30', '2026-05-01', '2026-05-04', '2026-05-05'] as $f) {
        marcar($p, $f, 'tarde', '07:50:00');
    }
    marcar($p, '2026-05-06', 'presente');

    $titulos = collect(app(EstadisticasAsistencia::class)->panel('7')['alertas'])->pluck('titulo')->implode(' | ');

    expect($titulos)->toContain('sin horario')->and($titulos)->toContain('tardanza recurrente');
});

test('el ranking de puntuales exige al menos 3 asistencias', function () {
    $p = docenteConHorario();
    marcar($p, '2026-05-05', 'presente');

    expect(app(EstadisticasAsistencia::class)->panel('7')['rankings']['puntuales'])->toBe([]);
});

test('el periodo mes anterior cubre todo el mes calendario', function () {
    docenteConHorario();

    $panel = app(EstadisticasAsistencia::class)->panel('mes_anterior');

    expect($panel['rango'])->toBe(['inicio' => '2026-04-01', 'fin' => '2026-04-30'])
        ->and($panel['dias'])->toHaveCount(30);
});

test('sin personal ni horarios el panel no falla y avisa que no hay datos', function () {
    $panel = app(EstadisticasAsistencia::class)->panel('mes');

    expect($panel['sin_datos'])->toBeTrue()->and($panel['kpis']['tasa_asistencia'])->toBeNull();
});

test('la pagina del panel se muestra con datos y respeta los permisos', function () {
    $p = docenteConHorario();
    marcar($p, '2026-05-05', 'presente');

    $admin = User::factory()->create();
    $admin->assignRole('Administrador');

    Livewire::actingAs($admin)->test('pages::dashboard')
        ->assertSee('Tasa de asistencia')
        ->assertSee('Resumen y decisiones sugeridas')
        ->set('periodo', '30')
        ->assertSee('Últimos 30 días')
        ->set('periodo', 'invalido')
        ->assertSet('periodo', 'mes');

    // Sin permisos de asistencia ve solo accesos, nunca las estadísticas.
    $sinPermisos = User::factory()->create();
    Livewire::actingAs($sinPermisos)->test('pages::dashboard')
        ->assertDontSee('Tasa de asistencia')
        ->assertSee('no tiene permisos');
});

test('sin registros en el periodo anterior no se muestra comparacion', function () {
    $p = docenteConHorario();
    marcar($p, '2026-05-05', 'presente');

    $panel = app(EstadisticasAsistencia::class)->panel('7');

    expect($panel['kpis']['delta_asistencia'])->toBeNull()->and($panel['kpis']['delta_puntualidad'])->toBeNull();
});
