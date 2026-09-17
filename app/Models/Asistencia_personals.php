<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asistencia_personals extends Model
{
    use HasFactory;

    protected $fillable = [
        'personal_id',
        'fecha',
        'hora_entrada_maniana',
        'estado_entrada',
        'hora_salida_maniana',
        'estado_salida_maniana',
        'hora_entrada_tarde',
        'estado_entrada_tarde',
        'hora_salida_tarde',
        'estado_salida',
        'observacion',
        'registrado_por',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function personal()
    {
        return $this->belongsTo(Personal_ies::class, 'personal_id');
    }

    public function registrador()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    /**
     * Estado general del día para mostrar en la interfaz.
     */
    public function getEstadoDiaAttribute(): string
    {
        if ($this->estado_entrada === 'ausente') {
            return 'ausente';
        }
        if ($this->estado_entrada === 'justificado') {
            return 'justificado';
        }
        if ($this->estado_entrada === 'tarde') {
            return 'tarde';
        }

        return 'presente';
    }
}
