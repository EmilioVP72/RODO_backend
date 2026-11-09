<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AppSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Garantiza que la tabla app_settings y la columna show_daily_kpis existan
     * incluso si el entorno de despliegue aún no ejecutó php artisan migrate.
     */
    public static function ensureSchemaExists(): void
    {
        try {
            if (!Schema::hasTable('app_settings')) {
                Schema::create('app_settings', function (Blueprint $table) {
                    $table->id();
                    $table->string('key', 100)->unique();
                    $table->longText('value')->nullable();
                    $table->timestamps();
                });
            }

            if (Schema::hasTable('restaurants') && !Schema::hasColumn('restaurants', 'show_daily_kpis')) {
                Schema::table('restaurants', function (Blueprint $table) {
                    $table->boolean('show_daily_kpis')->default(false);
                });
            }
        } catch (\Throwable $e) {
            // Ignorar de forma segura si el usuario de BD no tiene permisos DDL o en concurrencia
        }
    }

    /**
     * Obtener configuración de branding (logo e imagen de banner).
     */
    public static function getBranding(): array
    {
        self::ensureSchemaExists();

        try {
            $settings = self::whereIn('key', ['logo_image_url', 'banner_image_url'])
                ->pluck('value', 'key');

            return [
                'logo_image_url' => $settings['logo_image_url'] ?? null,
                'banner_image_url' => $settings['banner_image_url'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'logo_image_url' => null,
                'banner_image_url' => null,
            ];
        }
    }
}
