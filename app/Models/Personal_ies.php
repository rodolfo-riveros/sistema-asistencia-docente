<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Personal_ies extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'dni',
        'nombres',
        'apellido_paterno',
        'apellido_materno',
        'tipo_personal',
        'cargo',
        'fecha_ingreso',
        'activo',
        'tolerancia_minutos',
        'telefono',
    ];

    protected $casts = [
        'fecha_ingreso' => 'date',
        'activo' => 'boolean',
        'tolerancia_minutos' => 'integer',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────────

    public function horarios()
    {
        return $this->hasMany(HorarioPersonals::class, 'personal_id');
    }

    public function asistencias()
    {
        return $this->hasMany(Asistencia_personals::class, 'personal_id');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->nombres} {$this->apellido_paterno} {$this->apellido_materno}");
    }

    public function getTipoPersonalLabelAttribute(): string
    {
        return match ($this->tipo_personal) {
            'docente' => 'Docente',
            'auxiliar' => 'Auxiliar',
            'administrativo' => 'Administrativo',
            'limpieza' => 'Personal de Limpieza',
            'vigilancia' => 'Vigilancia / Portería',
            'directivo' => 'Directivo',
            'otro' => 'Otro',
            default => ucfirst($this->tipo_personal),
        };
    }

    // ── Horario ───────────────────────────────────────────────────────────────

    public function esDobleJornada(): bool
    {
        return $this->horarios()
            ->whereNotNull('hora_salida_maniana')
            ->whereNotNull('hora_entrada_tarde')
            ->exists();
    }

    /**
     * Registro de horario activo del personal para el día ISO indicado (1=lunes .. 7=domingo).
     */
    public function getHorarioParaDia(int $diaSemana): ?HorarioPersonals
    {
        return $this->horarios()
            ->activos()
            ->where('dia_semana', $diaSemana)
            ->first();
    }

    /**
     * Array de horario compatible con el controlador biométrico, para el día indicado
     * (o para hoy si no se pasa fecha).
     */
    public function getHorarioArray(?string $fecha = null): ?array
    {
        $carbonFecha = $fecha ? Carbon::parse($fecha) : now();

        $horario = $this->getHorarioParaDia($carbonFecha->dayOfWeekIso);

        return $horario?->toHorarioArray();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopePorTipo($query, string $tipo)
    {
        return $query->where('tipo_personal', $tipo);
    }
}
