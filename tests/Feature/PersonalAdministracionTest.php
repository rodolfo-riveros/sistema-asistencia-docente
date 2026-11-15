<?php

use App\Models\HorarioPersonals;
use App\Models\Personal_ies;
use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function usuarioConRol(string $rol): User
{
    $user = User::factory()->create();
    $user->assignRole($rol);

    return $user;
}

test('el super admin puede subir un logo png y se guarda con una url relativa al host', function () {
    Storage::fake('public');

    Livewire::actingAs(usuarioConRol('Super Admin'))
        ->test('pages::configuracion.index')
        ->set('logo', UploadedFile::fake()->image('logo.png', 200, 200))
        ->assertHasNoErrors();

    $settings = SiteSetting::current();

    expect($settings->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($settings->logo_path);
    expect($settings->logo_url)->toEndWith('/storage/'.$settings->logo_path);
});

test('el logo acepta svg y rechaza archivos que no son imagen', function () {
    Storage::fake('public');

    $component = Livewire::actingAs(usuarioConRol('Super Admin'))->test('pages::configuracion.index');

    $component->set('logo', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'))
        ->assertHasNoErrors();

    $component->set('logo', UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf'))
        ->assertHasErrors('logo');
});

test('un usuario sin permiso de configuracion recibe 403', function () {
    $this->actingAs(usuarioConRol('Consulta'))
        ->get(route('configuracion.index'))
        ->assertForbidden();
});

test('el rol consulta no puede borrar ni desactivar personal aunque llame la accion', function () {
    $personal = Personal_ies::factory()->create();

    Livewire::actingAs(usuarioConRol('Consulta'))
        ->test('pages::personal.index')
        ->call('delete', $personal->id)
        ->assertForbidden();

    expect(Personal_ies::find($personal->id))->not->toBeNull();
});

test('se marcan los dias, se aplica el horario general y se guarda en la base', function () {
    Livewire::actingAs(usuarioConRol('Registrador'))
        ->test('pages::personal.form')
        ->set('dni', '70112233')
        ->set('nombres', 'Luis')
        ->set('apellido_paterno', 'Mamani')
        ->call('seleccionarDias', 'lv')
        ->set('general.hora_entrada', '07:30')
        ->set('general.hora_salida', '15:30')
        ->call('aplicarGeneral')
        ->call('save')
        ->assertHasNoErrors();

    $personal = Personal_ies::where('dni', '70112233')->firstOrFail();

    expect($personal->horarios()->pluck('dia_semana')->all())->toBe([1, 2, 3, 4, 5])
        ->and(HorarioPersonals::where('personal_id', $personal->id)->where('hora_entrada', '07:30:00')->count())->toBe(5);
});

test('aplicar el horario general sin dias marcados no guarda nada', function () {
    Livewire::actingAs(usuarioConRol('Registrador'))
        ->test('pages::personal.form')
        ->set('general.hora_entrada', '07:30')
        ->set('general.hora_salida', '15:30')
        ->call('aplicarGeneral')
        ->assertSet('horarios.1.hora_entrada', '');
});

test('la descarga masiva de personal devuelve un csv con los horarios', function () {
    $personal = Personal_ies::factory()->create(['dni' => '70112233']);
    $personal->horarios()->create(['dia_semana' => 1, 'hora_entrada' => '07:30:00', 'hora_salida' => '15:30:00', 'activo' => true]);

    $respuesta = $this->actingAs(usuarioConRol('Consulta'))->get(route('personal.exportar'));

    $respuesta->assertOk();
    expect($respuesta->streamedContent())->toContain('70112233')->toContain('07:30-15:30');
});

test('la descarga masiva de asistencia exige el permiso de reportes', function () {
    $sinReportes = User::factory()->create();
    $sinReportes->givePermissionTo('personal.ver');

    $this->actingAs($sinReportes)
        ->get(route('reporte-asistencia-personal.exportar', ['fecha_inicio' => '2026-11-09', 'fecha_fin' => '2026-11-13']))
        ->assertForbidden();
});
