<?php

use App\Http\Controllers\Admin\ReporteAsistenciaPersonalController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    // Personal
    Route::middleware('permission:personal.ver')->group(function () {
        Route::livewire('personal', 'pages::personal.index')->name('personal.index');
    });
    Route::middleware('permission:personal.gestionar')->group(function () {
        Route::livewire('personal/nuevo', 'pages::personal.form')->name('personal.create');
        Route::livewire('personal/{id}/editar', 'pages::personal.form')->name('personal.edit');
    });

    // Asistencia de personal
    Route::middleware('permission:asistencia.ver')->group(function () {
        Route::livewire('asistencia-personal', 'pages::asistencia-personal.index')->name('asistencia-personal.index');
    });
    Route::middleware('permission:reportes.ver')->group(function () {
        Route::livewire('asistencia-personal/reporte', 'pages::asistencia-personal.reporte')->name('asistencia-personal.reporte');
        Route::get('asistencia-personal/reporte/pdf', [ReporteAsistenciaPersonalController::class, 'pdf'])
            ->name('reporte-asistencia-personal.pdf');
    });

    // Administración
    Route::middleware('permission:usuarios.gestionar')->group(function () {
        Route::livewire('usuarios', 'pages::usuarios.index')->name('usuarios.index');
    });
    Route::middleware('permission:roles.gestionar')->group(function () {
        Route::livewire('roles', 'pages::roles.index')->name('roles.index');
    });
    Route::middleware('permission:configuracion.gestionar')->group(function () {
        Route::livewire('configuracion', 'pages::configuracion.index')->name('configuracion.index');
    });
});

require __DIR__.'/settings.php';
