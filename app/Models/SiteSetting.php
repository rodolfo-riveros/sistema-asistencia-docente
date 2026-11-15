<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SiteSetting extends Model
{
    protected $fillable = [
        'app_name',
        'logo_path',
    ];

    private const CACHE_KEY = 'site-settings.current';

    /**
     * Fila única de configuración global del sitio (se crea de forma perezosa).
     */
    public static function current(): self
    {
        $attributes = Cache::rememberForever(
            self::CACHE_KEY,
            fn () => self::query()->firstOrCreate(['id' => 1])->getAttributes()
        );

        return (new self)->newFromBuilder($attributes);
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::forgetCache());
        static::deleted(fn () => self::forgetCache());
    }

    public function getLogoUrlAttribute(): ?string
    {
        // asset() usa el host de la petición actual, así el logo carga sin importar APP_URL.
        return $this->logo_path ? asset('storage/'.$this->logo_path) : null;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->app_name ?: config('app.name', 'Laravel');
    }
}
