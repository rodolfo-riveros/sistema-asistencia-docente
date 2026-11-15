<?php

use App\Models\Asistencia_personals;
use App\Models\Personal_ies;
use App\Models\User;
use App\Services\ImportadorBiometrico;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * Arma un archivo con el mismo diseño que exporta el biométrico.
 *
 * @param  array<int,array<int,string>>  $personas  id => [día => "07:25\n15:31"]
 * @param  array<int,int>  $dias  número de día de cada columna (A, B, C…)
 */
function reporteBiometrico(array $personas, string $periodo = '2026-05-04 ~ 2026-05-08', array $dias = [4, 5, 6, 7, 8], string $formato = 'Xlsx', string $hoja = 'Reporte de Asistencia'): string
{
    $libro = new Spreadsheet;
    $ws = $libro->getActiveSheet();
    $ws->setTitle($hoja);
    $ws->setCellValue('A1', 'Reporte de Eventos de Asistencia');
    $ws->setCellValue('A3', 'Periodo:');
    $ws->setCellValue('C3', $periodo);

    foreach ($dias as $i => $dia) {
        $ws->setCellValue([$i + 1, 4], $dia);
    }

    $fila = 5;
    foreach ($personas as $id => $marcas) {
        $ws->setCellValue([1, $fila], 'ID:');
        $ws->setCellValue([3, $fila], $id);
        $ws->setCellValue([9, $fila], 'Nombre:');
        $fila++;

        foreach ($dias as $i => $dia) {
            if (isset($marcas[$dia])) {
                $ws->setCellValueExplicit([$i + 1, $fila], $marcas[$dia], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        $fila++;
    }

    $ruta = tempnam(sys_get_temp_dir(), 'bio').'.'.strtolower($formato);
    IOFactory::createWriter($libro, $formato)->save($ruta);

    return $ruta;
}

function docenteLunesAViernes(array $atributos = [], bool $doble = false): Personal_ies
{
    $p = Personal_ies::factory()->create($atributos);

    foreach (range(1, 5) as $dia) {
        $p->horarios()->create($doble
            ? ['dia_semana' => $dia, 'hora_entrada' => '07:30:00', 'hora_salida_maniana' => '12:00:00', 'hora_entrada_tarde' => '14:00:00', 'hora_salida' => '17:30:00', 'activo' => true]
            : ['dia_semana' => $dia, 'hora_entrada' => '07:30:00', 'hora_salida' => '15:30:00', 'activo' => true]);
    }

    return $p;
}

test('jornada simple: primera marca es la entrada y la ultima la salida, con duplicados', function () {
    $p = docenteLunesAViernes();

    $ruta = reporteBiometrico([
        $p->id => [4 => "07:25\n07:26\n15:31", 5 => "07:45\n15:00", 6 => '07:20'],
    ]);

    $r = app(ImportadorBiometrico::class)->importar($ruta);

    expect($r['error'])->toBeNull()->and($r['registrados'])->toBe(3);

    $lunes = Asistencia_personals::whereDate('fecha', '2026-05-04')->first();
    expect($lunes->hora_entrada_maniana)->toBe('07:25:00')
        ->and($lunes->estado_entrada)->toBe('presente')
        ->and($lunes->hora_salida_tarde)->toBe('15:31:00')
        ->and($lunes->estado_salida)->toBe('normal')
        ->and($lunes->observacion)->toBe(ImportadorBiometrico::OBSERVACION);

    $martes = Asistencia_personals::whereDate('fecha', '2026-05-05')->first();
    expect($martes->estado_entrada)->toBe('tarde')->and($martes->estado_salida)->toBe('anticipada');

    $miercoles = Asistencia_personals::whereDate('fecha', '2026-05-06')->first();
    expect($miercoles->hora_entrada_maniana)->toBe('07:20:00')->and($miercoles->hora_salida_tarde)->toBeNull();
});

test('lee las marcas pegadas sin separador como las entrega el reloj real', function () {
    $p = docenteLunesAViernes();

    // Así vienen en el .xls del biométrico: "07:2507:2615:31" en una sola celda.
    $ruta = reporteBiometrico([$p->id => [4 => '07:2507:2615:31']]);

    app(ImportadorBiometrico::class)->importar($ruta);

    $a = Asistencia_personals::first();
    expect($a->hora_entrada_maniana)->toBe('07:25:00')->and($a->hora_salida_tarde)->toBe('15:31:00');
});

test('doble jornada: separa mañana y tarde por el punto medio del descanso', function () {
    $p = docenteLunesAViernes(doble: true);

    $ruta = reporteBiometrico([$p->id => [4 => "07:28\n12:01\n13:55\n17:35"]]);

    app(ImportadorBiometrico::class)->importar($ruta);

    $a = Asistencia_personals::first();
    expect($a->hora_entrada_maniana)->toBe('07:28:00')->and($a->estado_entrada)->toBe('presente')
        ->and($a->hora_salida_maniana)->toBe('12:01:00')->and($a->estado_salida_maniana)->toBe('normal')
        ->and($a->hora_entrada_tarde)->toBe('13:55:00')->and($a->estado_entrada_tarde)->toBe('presente')
        ->and($a->hora_salida_tarde)->toBe('17:35:00')->and($a->estado_salida)->toBe('normal');
});

test('funciona con archivos .xls (formato antiguo que entrega el reloj)', function () {
    $p = docenteLunesAViernes();

    $ruta = reporteBiometrico([$p->id => [4 => "07:25\n15:31"]], formato: 'Xls');

    $r = app(ImportadorBiometrico::class)->importar($ruta);

    expect($r['registrados'])->toBe(1);
});

test('omite lo que ya existe y con la opcion completar solo agrega las marcas faltantes', function () {
    $p = docenteLunesAViernes();
    $existente = Asistencia_personals::create([
        'personal_id' => $p->id, 'fecha' => '2026-05-04',
        'hora_entrada_maniana' => '07:40:00', 'estado_entrada' => 'tarde',
    ]);

    $ruta = reporteBiometrico([$p->id => [4 => "07:25\n15:31"]]);

    $omitir = app(ImportadorBiometrico::class)->importar($ruta);
    expect($omitir['ya_existian'])->toBe(1)->and($existente->fresh()->hora_salida_tarde)->toBeNull();

    $completar = app(ImportadorBiometrico::class)->importar($ruta, ImportadorBiometrico::EXISTENTES_COMPLETAR);
    expect($completar['completados'])->toBe(1);

    $existente->refresh();
    expect($existente->hora_entrada_maniana)->toBe('07:40:00')   // no se pisa lo que ya había
        ->and($existente->estado_entrada)->toBe('tarde')
        ->and($existente->hora_salida_tarde)->toBe('15:31:00')
        ->and($existente->estado_salida)->toBe('normal');
});

test('no toca los dias marcados manualmente como falta o justificado', function () {
    $p = docenteLunesAViernes();
    Asistencia_personals::create(['personal_id' => $p->id, 'fecha' => '2026-05-04', 'estado_entrada' => 'justificado']);

    $r = app(ImportadorBiometrico::class)->importar(
        reporteBiometrico([$p->id => [4 => "07:25\n15:31"]]),
        ImportadorBiometrico::EXISTENTES_COMPLETAR
    );

    expect($r['manuales'])->toBe(1)->and(Asistencia_personals::first()->hora_entrada_maniana)->toBeNull();
});

test('cuenta por separado ids desconocidos, personal inactivo y dias sin horario', function () {
    $activo = docenteLunesAViernes();
    $inactivo = docenteLunesAViernes(['activo' => false]);

    $ruta = reporteBiometrico([
        $activo->id => [4 => '07:25', 9 => '07:25'],   // el 9 no está en las columnas; el sábado 2026-05-09 no existe en el rango
        $inactivo->id => [4 => '07:25'],
        9999 => [4 => '07:25'],
    ], '2026-05-04 ~ 2026-05-09', [4, 5, 6, 7, 8, 9]);

    $r = app(ImportadorBiometrico::class)->importar($ruta);

    expect($r['registrados'])->toBe(1)      // lunes
        ->and($r['sin_horario'])->toBe(1)   // sábado sin horario
        ->and($r['inactivos'])->toBe(1)
        ->and($r['sin_personal'])->toBe(1);
});

test('un periodo que cruza de mes asigna bien las fechas', function () {
    $p = Personal_ies::factory()->create();
    foreach ([3, 4, 5] as $dia) { // jueves 30/04, viernes... ver fechas abajo
        $p->horarios()->create(['dia_semana' => $dia, 'hora_entrada' => '07:30:00', 'hora_salida' => '15:30:00', 'activo' => true]);
    }
    foreach ([1, 2, 6, 7] as $dia) {
        $p->horarios()->create(['dia_semana' => $dia, 'hora_entrada' => '07:30:00', 'hora_salida' => '15:30:00', 'activo' => true]);
    }

    $ruta = reporteBiometrico([$p->id => [29 => '07:20', 30 => '07:21', 1 => '07:22', 2 => '07:23']], '2026-04-29 ~ 2026-05-02', [29, 30, 1, 2]);

    app(ImportadorBiometrico::class)->importar($ruta);

    expect(Asistencia_personals::orderBy('fecha')->get()->map(fn ($a) => $a->fecha->toDateString().' '.$a->hora_entrada_maniana)->all())
        ->toBe(['2026-04-29 07:20:00', '2026-04-30 07:21:00', '2026-05-01 07:22:00', '2026-05-02 07:23:00']);
});

test('el analisis informa el periodo y los id desconocidos sin guardar nada', function () {
    $p = docenteLunesAViernes();

    $analisis = app(ImportadorBiometrico::class)->analizar(
        reporteBiometrico([$p->id => [4 => '07:25', 5 => '07:25'], 9999 => [4 => '07:30']])
    );

    expect($analisis['error'])->toBeNull()
        ->and($analisis['periodo'])->toBe('2026-05-04 ~ 2026-05-08')
        ->and($analisis['personas'])->toBe(2)
        ->and($analisis['encontradas'])->toBe(1)
        ->and($analisis['desconocidos'])->toBe([9999])
        ->and($analisis['dias_con_datos'])->toBe(3)
        ->and(Asistencia_personals::count())->toBe(0);
});

test('rechaza archivos que no son el reporte del biometrico', function () {
    $sinHoja = app(ImportadorBiometrico::class)->analizar(reporteBiometrico([], hoja: 'Otra hoja'));
    expect($sinHoja['error'])->toContain('Reporte de Asistencia');

    $texto = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($texto, 'esto no es un excel');
    expect(app(ImportadorBiometrico::class)->analizar($texto)['error'])->not->toBeNull();
});

test('la pagina muestra la vista previa y carga las asistencias', function () {
    $p = docenteLunesAViernes();
    $registrador = User::factory()->create();
    $registrador->assignRole('Registrador');

    $archivo = UploadedFile::fake()->createWithContent(
        'reporte.xlsx',
        file_get_contents(reporteBiometrico([$p->id => [4 => "07:25\n15:31"]]))
    );

    Livewire::actingAs($registrador)
        ->test('pages::asistencia-personal.importar')
        ->set('archivo', $archivo)
        ->assertSet('vista.personas', 1)
        ->call('importar')
        ->assertSet('resultado.registrados', 1);

    expect(Asistencia_personals::first()->registrado_por)->toBe($registrador->id);
});

test('solo quien puede gestionar asistencia accede a la carga', function () {
    $consulta = User::factory()->create();
    $consulta->assignRole('Consulta');

    $this->actingAs($consulta)->get(route('asistencia-personal.importar'))->assertForbidden();
});
