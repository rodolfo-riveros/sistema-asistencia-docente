<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HorarioPersonals extends Model
{
    use HasFactory;

    protected $table = 'horarios_personal';

    protected $fillable = [
        'personal_id',
        'dia_semana',
        'hora_entrada',
        'hora_salida_maniana',
        'hora_entrada_tarde',
        'hora_salida',
        'tolerancia_minutos',
        'activo',
        'observacion',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'dia_semana' => 'integer',
        'tolerancia_minutos' => 'integer',
    ];

    // ── Constantes ────────────────────────────────────────────────────────────

    const DIAS = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    const DIAS_CORTO = [
        1 => 'Lun',
        2 => 'Mar',
        3 => 'Mié',
        4 => 'Jue',
        5 => 'Vie',
        6 => 'Sáb',
        7 => 'Dom',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function personal()
    {
        return $this->belongsTo(Personal_ies::class, 'personal_id');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getDiaLabelAttribute(): string
    {
        return self::DIAS[$this->dia_semana] ?? "Día {$this->dia_semana}";
    }

    public function getDiaCortoAttribute(): string
    {
        return self::DIAS_CORTO[$this->dia_semana] ?? "D{$this->dia_semana}";
    }

    public function esDobleJornada(): bool
    {
        return ! is_null($this->hora_salida_maniana) && ! is_null($this->hora_entrada_tarde);
    }

    /**
     * Devuelve el horario como array compatible con el controlador biométrico.
     */
    public function toHorarioArray(): array
    {
        $esDoble = $this->esDobleJornada();

        return [
            'hora_entrada' => $this->hora_entrada,
            'tolerancia_entrada' => (int) $this->tolerancia_minutos,
            'hora_salida' => $this->hora_salida,
            'tolerancia_salida' => (int) $this->tolerancia_minutos,
            'es_doble_jornada' => $esDoble,
            'hora_salida_manana' => $esDoble ? $this->hora_salida_maniana : null,
            'hora_entrada_tarde' => $esDoble ? $this->hora_entrada_tarde : null,
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeParaDia($query, int $diaSemana)
    {
        return $query->where('dia_semana', $diaSemana)->where('activo', true);
    }
}
