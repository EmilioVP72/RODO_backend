<?php

namespace App\Services;

use App\Contracts\RoutingProviderInterface;
use App\Exceptions\RoutingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class RoutingService implements RoutingProviderInterface
{
    /**
     * Calcula la ruta real por carretera entre origen y destino.
     * Nunca utiliza distancias en línea recta como sustituto.
     *
     * @param array{lat: float|numeric, lng: float|numeric} $origin
     * @param array{lat: float|numeric, lng: float|numeric} $destination
     * @return array{
     *   distance_km: float,
     *   duration_minutes: float,
     *   distance_meters: float,
     *   duration_seconds: float,
     *   geometry: array{type: string, coordinates: array<int, array{0: float, 1: float}>},
     *   provider: string
     * }
     * @throws RoutingException
     */
    public function calculateRoute(array $origin, array $destination): array
    {
        $originCoords = $this->validateAndNormalizeCoordinates($origin, 'origen');
        $destCoords = $this->validateAndNormalizeCoordinates($destination, 'destino');

        if ($this->areCoordinatesIdentical($originCoords, $destCoords)) {
            throw new RoutingException(
                'El punto de origen y el punto de destino no pueden ser iguales.',
                422,
                'IDENTICAL_COORDINATES'
            );
        }

        $provider = strtolower((string) config('services.routing.provider', 'openrouteservice'));

        if ($provider === 'osrm') {
            return $this->calculateWithOsrm($originCoords, $destCoords);
        }

        $apiKey = trim((string) config('services.openrouteservice.key', ''));

        // Si no se configuró API key de OpenRouteService y está habilitado el fallback vial OSRM
        if ($apiKey === '' && (bool) config('services.routing.allow_osrm_when_unconfigured', false)) {
            return $this->calculateWithOsrm($originCoords, $destCoords);
        }

        return $this->calculateWithOpenRouteService($originCoords, $destCoords, $apiKey);
    }

    /**
     * Consulta la API vigente de OpenRouteService v2 Directions (GeoJSON).
     * Endpoint oficial: POST https://api.openrouteservice.org/v2/directions/{profile}/geojson
     *
     * @param array{lat: float, lng: float} $origin
     * @param array{lat: float, lng: float} $destination
     * @throws RoutingException
     */
    protected function calculateWithOpenRouteService(
        array $origin,
        array $destination,
        string $apiKey
    ): array {
        if ($apiKey === '') {
            throw new RoutingException(
                'La credencial OPENROUTESERVICE_API_KEY no está configurada en el servidor.',
                503,
                'MISSING_API_KEY'
            );
        }

        $baseUrl = rtrim((string) config('services.openrouteservice.base_url', 'https://api.openrouteservice.org'), '/');
        $profile = trim((string) config('services.openrouteservice.profile', 'driving-car'));
        $timeout = max(2, (int) config('services.openrouteservice.timeout', 6));

        $endpoint = "{$baseUrl}/v2/directions/{$profile}/geojson";

        try {
            $response = Http::withHeaders([
                'Authorization' => $apiKey,
                'Accept' => 'application/geo+json, application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => 'RodoMotoMandados/1.0',
            ])
                ->timeout($timeout)
                ->post($endpoint, [
                    'coordinates' => [
                        [$origin['lng'], $origin['lat']],
                        [$destination['lng'], $destination['lat']],
                    ],
                    'instructions' => false,
                ]);
        } catch (ConnectionException $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'operation timed out')) {
                throw new RoutingException(
                    'El servicio de cálculo de rutas tardó demasiado en responder (timeout). Intenta nuevamente.',
                    504,
                    'PROVIDER_TIMEOUT',
                    $e
                );
            }

            throw new RoutingException(
                'No fue posible conectar con el proveedor de rutas en este momento.',
                502,
                'CONNECTION_ERROR',
                $e
            );
        } catch (\Throwable $e) {
            throw new RoutingException(
                'Error inesperado al comunicarse con el proveedor de rutas.',
                502,
                'CONNECTION_ERROR',
                $e
            );
        }

        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new RoutingException(
                'Credenciales de OpenRouteService inválidas o no autorizadas.',
                502,
                'INVALID_API_KEY'
            );
        }

        if ($status === 429) {
            throw new RoutingException(
                'Se alcanzó el límite de solicitudes del proveedor de rutas. Intenta en unos segundos.',
                429,
                'RATE_LIMIT_EXCEEDED'
            );
        }

        if ($status === 404 || $status === 400 || $status === 422) {
            $errorBody = $response->json();
            $orsMessage = $errorBody['error']['message'] ?? $errorBody['message'] ?? null;

            throw new RoutingException(
                $orsMessage
                    ? "No se encontró una ruta por carretera válida: {$orsMessage}"
                    : 'No se encontró una ruta por carretera disponible entre los puntos seleccionados.',
                422,
                'ROUTE_NOT_FOUND'
            );
        }

        if (!$response->successful()) {
            throw new RoutingException(
                'El proveedor de rutas devolvió un error al procesar la solicitud.',
                502,
                'PROVIDER_ERROR'
            );
        }

        $payload = $response->json();

        // Formato 1: Respuesta GeoJSON FeatureCollection (/v2/directions/{profile}/geojson)
        if (isset($payload['features']) && is_array($payload['features'])) {
            if (count($payload['features']) === 0) {
                throw new RoutingException(
                    'No se encontró una ruta por carretera disponible entre el origen y el destino.',
                    422,
                    'ROUTE_NOT_FOUND'
                );
            }

            $feature = $payload['features'][0];
            $summary = $feature['properties']['summary'] ?? null;
            $geometry = $feature['geometry'] ?? null;

            if (
                !is_array($summary) ||
                !isset($summary['distance']) ||
                !is_array($geometry) ||
                empty($geometry['coordinates'])
            ) {
                throw new RoutingException(
                    'No hay una ruta transitable por carretera entre las ubicaciones indicadas.',
                    422,
                    'ROUTE_NOT_FOUND'
                );
            }

            $distanceMeters = (float) $summary['distance'];
            $durationSeconds = (float) ($summary['duration'] ?? 0.0);

            return [
                'distance_km' => round($distanceMeters / 1000, 2),
                'duration_minutes' => round($durationSeconds / 60, 1),
                'distance_meters' => round($distanceMeters, 1),
                'duration_seconds' => round($durationSeconds, 1),
                'geometry' => [
                    'type' => (string) ($geometry['type'] ?? 'LineString'),
                    'coordinates' => $geometry['coordinates'],
                ],
                'provider' => 'openrouteservice',
            ];
        }

        // Formato 2: Respuesta JSON estándar de ORS (/v2/directions/{profile}) si se llegara a usar sin /geojson
        if (isset($payload['routes']) && is_array($payload['routes']) && count($payload['routes']) > 0) {
            $route = $payload['routes'][0];
            $summary = $route['summary'] ?? null;
            if (!is_array($summary) || !isset($summary['distance'])) {
                throw new RoutingException(
                    'No se encontró una ruta por carretera disponible entre el origen y el destino.',
                    422,
                    'ROUTE_NOT_FOUND'
                );
            }

            $distanceMeters = (float) $summary['distance'];
            $durationSeconds = (float) ($summary['duration'] ?? 0.0);
            $geometry = $route['geometry'] ?? null;

            $geoJsonGeometry = is_array($geometry) && isset($geometry['coordinates'])
                ? [
                    'type' => (string) ($geometry['type'] ?? 'LineString'),
                    'coordinates' => $geometry['coordinates'],
                ]
                : [
                    'type' => 'LineString',
                    'coordinates' => [
                        [$origin['lng'], $origin['lat']],
                        [$destination['lng'], $destination['lat']],
                    ],
                ];

            return [
                'distance_km' => round($distanceMeters / 1000, 2),
                'duration_minutes' => round($durationSeconds / 60, 1),
                'distance_meters' => round($distanceMeters, 1),
                'duration_seconds' => round($durationSeconds, 1),
                'geometry' => $geoJsonGeometry,
                'provider' => 'openrouteservice',
            ];
        }

        throw new RoutingException(
            'No se encontró una ruta por carretera disponible entre las ubicaciones seleccionadas.',
            422,
            'ROUTE_NOT_FOUND'
        );
    }

    /**
     * Proveedor alternativo OSRM por carretera (para entornos autoalojados o migración futura).
     * Obtiene ruta real por calles con geometría GeoJSON LineString.
     *
     * @param array{lat: float, lng: float} $origin
     * @param array{lat: float, lng: float} $destination
     * @throws RoutingException
     */
    protected function calculateWithOsrm(array $origin, array $destination): array
    {
        $baseUrl = rtrim((string) config('services.osrm.base_url', 'https://router.project-osrm.org'), '/');
        $profile = trim((string) config('services.osrm.profile', 'driving'));
        $timeout = max(2, (int) config('services.osrm.timeout', 5));

        $url = sprintf(
            '%s/route/v1/%s/%.6f,%.6f;%.6f,%.6f',
            $baseUrl,
            $profile,
            $origin['lng'],
            $origin['lat'],
            $destination['lng'],
            $destination['lat']
        );

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'RodoMotoMandados/1.0',
                'Accept' => 'application/json',
            ])
                ->timeout($timeout)
                ->get($url, [
                    'overview' => 'full',
                    'geometries' => 'geojson',
                    'steps' => 'false',
                ]);
        } catch (ConnectionException $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
                throw new RoutingException(
                    'El servidor de rutas OSRM tardó demasiado en responder.',
                    504,
                    'PROVIDER_TIMEOUT',
                    $e
                );
            }

            throw new RoutingException(
                'No fue posible conectar con el servidor de rutas OSRM.',
                502,
                'CONNECTION_ERROR',
                $e
            );
        }

        if ($response->status() === 429) {
            throw new RoutingException(
                'Se alcanzó el límite de solicitudes del servidor de rutas.',
                429,
                'RATE_LIMIT_EXCEEDED'
            );
        }

        if (!$response->successful()) {
            throw new RoutingException(
                'No se encontró una ruta por carretera disponible entre los puntos seleccionados.',
                422,
                'ROUTE_NOT_FOUND'
            );
        }

        $routes = $response->json('routes');
        if (!is_array($routes) || count($routes) === 0 || !isset($routes[0]['distance'])) {
            throw new RoutingException(
                'No se encontró una ruta por carretera disponible entre el origen y el destino.',
                422,
                'ROUTE_NOT_FOUND'
            );
        }

        $route = $routes[0];
        $distanceMeters = (float) $route['distance'];
        $durationSeconds = (float) ($route['duration'] ?? 0.0);
        $geometry = $route['geometry'] ?? null;

        if (!is_array($geometry) || empty($geometry['coordinates'])) {
            throw new RoutingException(
                'La ruta devuelta no contiene geometría vial válida.',
                422,
                'ROUTE_NOT_FOUND'
            );
        }

        return [
            'distance_km' => round($distanceMeters / 1000, 2),
            'duration_minutes' => round($durationSeconds / 60, 1),
            'distance_meters' => round($distanceMeters, 1),
            'duration_seconds' => round($durationSeconds, 1),
            'geometry' => [
                'type' => (string) ($geometry['type'] ?? 'LineString'),
                'coordinates' => $geometry['coordinates'],
            ],
            'provider' => 'osrm',
        ];
    }

    /**
     * Valida que las coordenadas sean numéricas y estén dentro del rango geográfico mundial.
     *
     * @param array<string, mixed> $point
     * @return array{lat: float, lng: float}
     * @throws RoutingException
     */
    protected function validateAndNormalizeCoordinates(array $point, string $label): array
    {
        if (!isset($point['lat'], $point['lng']) || !is_numeric($point['lat']) || !is_numeric($point['lng'])) {
            throw new RoutingException(
                "Las coordenadas de {$label} son inválidas o están incompletas.",
                422,
                'INVALID_COORDINATES'
            );
        }

        $lat = (float) $point['lat'];
        $lng = (float) $point['lng'];

        if (!is_finite($lat) || !is_finite($lng) || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            throw new RoutingException(
                "Las coordenadas de {$label} están fuera del rango geográfico permitido.",
                422,
                'OUT_OF_RANGE_COORDINATES'
            );
        }

        if ($lat === 0.0 && $lng === 0.0) {
            throw new RoutingException(
                "Las coordenadas de {$label} (0, 0) no corresponden a una ubicación válida.",
                422,
                'INVALID_COORDINATES'
            );
        }

        return [
            'lat' => round($lat, 6),
            'lng' => round($lng, 6),
        ];
    }

    /**
     * Determina si origen y destino son prácticamente el mismo punto (< ~5 metros).
     *
     * @param array{lat: float, lng: float} $a
     * @param array{lat: float, lng: float} $b
     */
    protected function areCoordinatesIdentical(array $a, array $b): bool
    {
        return abs($a['lat'] - $b['lat']) < 0.00005 && abs($a['lng'] - $b['lng']) < 0.00005;
    }
}
