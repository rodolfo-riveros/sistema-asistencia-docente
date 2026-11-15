<?php

use App\Models\HorarioPersonals;
use App\Models\Personal_ies;
use App\Models\User;
use App\Services\ImportadorPersonal;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function csvTemporal(string $contenido): string
{
    $ruta = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($ruta, $contenido);

    return $ruta;
}

function adminImport(): User
{
    $u = User::factory()->create();
    $u->assignRole('Registrador');

    return $u;
}

const CABECERA = "DNI;Apellido paterno;Apellido materno;Nombres;Tipo;Cargo;Teléfono;Fecha de ingreso;Estado;Tolerancia (min);Lunes;Martes;Miércoles;Jueves;Viernes;Sábado;Domingo\n";

test('crea personal nuevo con horario simple y doble jornada', function () {
    $ruta = csvTemporal(
        CABECERA.
        "70112233;Mamani;Quispe;Luis Alberto;Docente;Matemática;987654321;01/03/2023;Activo;15;07:30-15:30;07:30-15:30;;;;;\n".
        "04561234;Torres;;María;Administrativo;Secretaria;;;;;08:00-13:00 / 14:30-17:30;;;;;;\n"
    );

    $r = app(ImportadorPersonal::class)->importar($ruta, 'completo', true, false);

    expect($r['creados'])->toBe(2)->and($r['con_errores'])->toBe(0)->and($r['dias_horario'])->toBe(3);

    $luis = Personal_ies::where('dni', '70112233')->first();
    expect($luis->tipo_personal)->toBe('docente')
        ->and($luis->fecha_ingreso->toDateString())->toBe('2023-03-01')
        ->and($luis->horarios()->count())->toBe(2)
        ->and($luis->horarios()->first()->tolerancia_minutos)->toBe(15);

    $maria = Personal_ies::where('dni', '04561234')->first();
    $h = $maria->horarios()->first();
    expect($h->hora_entrada)->toBe('08:00:00')
        ->and($h->hora_salida_maniana)->toBe('13:00:00')
        ->and($h->hora_entrada_tarde)->toBe('14:30:00')
        ->and($h->hora_salida)->toBe('17:30:00')
        ->and($h->esDobleJornada())->toBeTrue();
});

test('completa con ceros el dni que excel recorta y acepta coma como separador y ansi', function () {
    $contenido = mb_convert_encoding(
        "DNI,Apellido paterno,Nombres,Tipo\n4561234,Núñez,José,Docente\n",
        'Windows-1252',
        'UTF-8'
    );

    $r = app(ImportadorPersonal::class)->importar(csvTemporal($contenido), 'completo', true, false);

    expect($r['creados'])->toBe(1);
    expect(Personal_ies::where('dni', '04561234')->first()->apellido_paterno)->toBe('Núñez');
});

test('detecta errores por fila sin guardar nada de esas filas', function () {
    $ruta = csvTemporal(
        CABECERA.
        "70112233;Mamani;;Luis;Docente;;;;;;07:30-15:30;;;;;;\n".
        "123;Sin;;Dni;Docente;;;;;;;;;;;;\n".
        "70112244;Hora;;Mala;Docente;;;;;;15:30-07:30;;;;;;\n".
        "70112255;Tipo;;Raro;Marciano;;;;;;;;;;;;\n".
        "70112233;Repetido;;Dni;Docente;;;;;;;;;;;;\n".
        "70112266;Fecha;;Mala;Docente;;;32/13/2023;;;;;;;;\n"
    );

    $analisis = app(ImportadorPersonal::class)->analizar($ruta);

    expect($analisis['resumen']['crear'])->toBe(1)->and($analisis['resumen']['error'])->toBe(5);

    $r = app(ImportadorPersonal::class)->importar($ruta, 'completo', true, false);

    expect($r['creados'])->toBe(1)->and($r['con_errores'])->toBe(5)->and(Personal_ies::count())->toBe(1);
});

test('actualiza existentes, respeta la opcion de omitirlos y restaura eliminados', function () {
    $existente = Personal_ies::factory()->create(['dni' => '70112233', 'nombres' => 'Viejo', 'cargo' => 'Antiguo']);
    $eliminado = Personal_ies::factory()->create(['dni' => '70112244']);
    $eliminado->delete();

    $ruta = csvTemporal("DNI;Apellido paterno;Nombres;Tipo\n70112233;Nuevo;Nombre;Auxiliar\n70112244;Vuelve;Otra;Docente\n");

    $omitir = app(ImportadorPersonal::class)->importar($ruta, 'completo', false, false);
    expect($omitir['omitidos'])->toBe(2)->and($existente->fresh()->nombres)->toBe('Viejo');

    $r = app(ImportadorPersonal::class)->importar($ruta, 'completo', true, false);
    expect($r['actualizados'])->toBe(2);
    expect($existente->fresh()->nombres)->toBe('Nombre')
        ->and($existente->fresh()->cargo)->toBe('Antiguo')
        ->and($existente->fresh()->tipo_personal)->toBe('auxiliar')
        ->and(Personal_ies::find($eliminado->id))->not->toBeNull();
});

test('modo solo horarios actualiza horarios de personal existente y rechaza dni desconocidos', function () {
    $p = Personal_ies::factory()->create(['dni' => '70112233', 'nombres' => 'Intacto']);
    $p->horarios()->create(['dia_semana' => 1, 'hora_entrada' => '08:00:00', 'hora_salida' => '12:00:00', 'activo' => true]);
    $p->horarios()->create(['dia_semana' => 2, 'hora_entrada' => '08:00:00', 'hora_salida' => '12:00:00', 'activo' => true]);

    $ruta = csvTemporal("DNI;Lunes;Martes\n70112233;07:00-14:00;\n99999999;07:00-14:00;\n");

    // Sin reemplazar: el martes en blanco no se toca.
    $r = app(ImportadorPersonal::class)->importar($ruta, 'horarios', true, false);
    expect($r['actualizados'])->toBe(1)->and($r['con_errores'])->toBe(1);
    expect($p->horarios()->where('dia_semana', 1)->first()->hora_entrada)->toBe('07:00:00')
        ->and($p->horarios()->where('dia_semana', 2)->first()->activo)->toBeTrue()
        ->and($p->fresh()->nombres)->toBe('Intacto');

    // Reemplazando: el martes en blanco queda inactivo.
    app(ImportadorPersonal::class)->importar($ruta, 'horarios', true, true);
    expect($p->horarios()->where('dia_semana', 2)->first()->activo)->toBeFalse();
});

test('la descarga masiva se puede volver a importar sin cambios', function () {
    $p = Personal_ies::factory()->create(['dni' => '70112233', 'tipo_personal' => 'vigilancia']);
    $p->horarios()->create(['dia_semana' => 3, 'hora_entrada' => '07:30:00', 'hora_salida_maniana' => '12:00:00', 'hora_entrada_tarde' => '14:00:00', 'hora_salida' => '17:00:00', 'activo' => true]);

    $csv = $this->actingAs(adminImport())->get(route('personal.exportar'))->streamedContent();

    $analisis = app(ImportadorPersonal::class)->analizar(csvTemporal($csv));

    expect($analisis['errores_globales'])->toBe([])
        ->and($analisis['filas'][0]['errores'])->toBe([])
        ->and($analisis['filas'][0]['accion'])->toBe('actualizar')
        ->and($analisis['filas'][0]['horarios'][3]['hora_entrada_tarde'])->toBe('14:00:00');
});

test('avisa cuando faltan columnas obligatorias o el archivo esta vacio', function () {
    $sinNombres = app(ImportadorPersonal::class)->analizar(csvTemporal("DNI;Cargo\n70112233;X\n"));
    expect($sinNombres['errores_globales'])->not->toBeEmpty();

    $vacio = app(ImportadorPersonal::class)->analizar(csvTemporal(''));
    expect($vacio['errores_globales'])->not->toBeEmpty();
});

test('la plantilla se descarga y se puede importar tal cual', function () {
    $csv = $this->actingAs(adminImport())->get(route('personal.plantilla'))->streamedContent();

    $r = app(ImportadorPersonal::class)->importar(csvTemporal($csv), 'completo', true, false);

    expect($r['creados'])->toBe(3)->and($r['con_errores'])->toBe(0)
        ->and(HorarioPersonals::count())->toBe(16);
});

test('la pagina de importacion analiza el archivo y confirma la importacion', function () {
    $archivo = UploadedFile::fake()->createWithContent('personal.csv', CABECERA."70112233;Mamani;;Luis;Docente;;;;;;07:30-15:30;;;;;;\n");

    Livewire::actingAs(adminImport())
        ->test('pages::personal.importar')
        ->set('archivo', $archivo)
        ->assertSet('vista.resumen.crear', 1)
        ->call('importar')
        ->assertSet('resultado.creados', 1);

    expect(Personal_ies::where('dni', '70112233')->exists())->toBeTrue();
});

test('la pagina de importacion rechaza archivos que no son csv', function () {
    Livewire::actingAs(adminImport())
        ->test('pages::personal.importar')
        ->set('archivo', UploadedFile::fake()->create('personal.pdf', 10, 'application/pdf'))
        ->assertHasErrors('archivo');
});

test('solo quien puede gestionar personal accede a la importacion', function () {
    $consulta = User::factory()->create();
    $consulta->assignRole('Consulta');

    $this->actingAs($consulta)->get(route('personal.importar'))->assertForbidden();
    $this->actingAs($consulta)->get(route('personal.plantilla'))->assertForbidden();
});
