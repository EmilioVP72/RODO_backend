<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppSettingController extends Controller
{
    /**
     * Endpoint público para consultar el icono/logo y el banner de la aplicación.
     */
    public function showBranding(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Configuración visual obtenida correctamente.',
            'data' => AppSetting::getBranding(),
        ]);
    }

    /**
     * Endpoint exclusivo del Administrador para actualizar el icono/logo y el banner.
     */
    public function updateBranding(Request $request): JsonResponse
    {
        AppSetting::ensureSchemaExists();

        $validated = $request->validate([
            'logo_image_url' => ['nullable', 'string'],
            'banner_image_url' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($validated) {
            if (array_key_exists('logo_image_url', $validated)) {
                $val = !empty($validated['logo_image_url']) ? trim($validated['logo_image_url']) : null;
                AppSetting::updateOrCreate(
                    ['key' => 'logo_image_url'],
                    ['value' => $val]
                );
            }

            if (array_key_exists('banner_image_url', $validated)) {
                $val = !empty($validated['banner_image_url']) ? trim($validated['banner_image_url']) : null;
                AppSetting::updateOrCreate(
                    ['key' => 'banner_image_url'],
                    ['value' => $val]
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Apariencia de la aplicación actualizada correctamente.',
            'data' => AppSetting::getBranding(),
        ]);
    }
}

