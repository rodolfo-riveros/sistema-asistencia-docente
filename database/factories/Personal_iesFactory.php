<?php

namespace Database\Factories;

use App\Models\Personal_ies;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Personal_ies>
 */
class Personal_iesFactory extends Factory
{
    protected $model = Personal_ies::class;

    public function definition(): array
    {
        return [
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombres' => fake()->firstName(),
            'apellido_paterno' => fake()->lastName(),
            'apellido_materno' => fake()->lastName(),
            'tipo_personal' => 'docente',
            'cargo' => null,
            'fecha_ingreso' => now()->toDateString(),
            'activo' => true,
            'telefono' => null,
        ];
    }
}
