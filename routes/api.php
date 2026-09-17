<?php

use App\Http\Controllers\Api\AsistenciaPersonalBiometricoController;
use Illuminate\Support\Facades\Route;

// Endpoint público que consume el dispositivo biométrico (o su middleware/gateway)
// para notificar marcaciones de entrada/salida del personal. No requiere autenticación
// porque el propio reloj biométrico no puede autenticarse; se identifica al personal
// por el código {codigo} en la URL.
Route::post('/asistencia-personal-biometrico/notificar/{codigo}', [AsistenciaPersonalBiometricoController::class, 'registrar']);
