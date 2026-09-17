<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class DniService
{
    private string $url = 'https://ww1.sunat.gob.pe/ol-ti-itfisdenreg/itfisdenreg.htm';

    /**
     * Consulta datos de una persona por DNI en SUNAT.
     *
     * @throws \Exception
     */
    public function consultarDni(string $dni): array
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0',
            'Accept' => 'application/json',
        ])->get($this->url, [
            'accion' => 'obtenerDatosDni',
            'numDocumento' => $dni,
        ]);

        if ($response->failed()) {
            throw new \Exception('Error al conectar con el servicio de SUNAT.', $response->status());
        }

        $data = $response->json();

        if (($data['message'] ?? '') !== 'success' || empty($data['lista'])) {
            throw new \Exception("DNI {$dni} no encontrado en SUNAT.");
        }

        // Formato: "APELLIDO_PATERNO APELLIDO_MATERNO, NOMBRES"
        $cadena = $data['lista'][0]['nombresapellidos'] ?? '';

        [$apellidosRaw, $nombresRaw] = array_pad(explode(',', $cadena, 2), 2, '');

        $apellidoArray = explode(' ', trim($apellidosRaw));

        return [
            'success' => true,
            'dni' => $dni,
            'apellido_paterno' => strtoupper($apellidoArray[0] ?? null),
            'apellido_materno' => strtoupper($apellidoArray[1] ?? null),
            'nombres' => trim($nombresRaw),
        ];
    }
}
