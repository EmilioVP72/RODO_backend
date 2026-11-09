<?php

namespace App\Services;

use App\Models\Restaurant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RouteDistanceService
{
    /**
     * Calcula automáticamente la distancia por calles (en km) entre la ubicación
     * registrada del restaurante y el destino del pedido.
     *
     * @return array{
     *   success: bool,
     *   distance_km: float|null,
     *   origin_coords: array{lat: float, lng: float}|null,
     *   destination_coords: array{lat: float, lng: float}|null,
     *   source: string|null,
     *   message: string
     * }
     */
    public function calculateBetweenRestaurantAndDestination(
        Restaurant $restaurant,
        array $destinationData
    ): array {
        if (empty($restaurant->location_url)) {
            return [
                'success' => false,
                'distance_km' => null,
                'origin_coords' => null,
                'destination_coords' => null,
                'source' => null,
                'message' => 'El restaurante aún no tiene una ubicación registrada por el administrador.',
            ];
        }

        // 1. Resolver coordenadas del restaurante (con caché para respuesta instantánea)
        $originCoords = $this->resolveCoordinatesFromUrlOrText(
            $restaurant->location_url,
            $restaurant->name
        );

        if (!$originCoords) {
            return [
                'success' => false,
                'distance_km' => null,
                'origin_coords' => null,
                'destination_coords' => null,
                'source' => null,
                'message' => 'No se pudieron extraer las coordenadas de la ubicación del restaurante.',
            ];
        }

        // 2. Resolver coordenadas del destino (primero por map_url si existe, luego por calle + colonia)
        $destinationCoords = null;
        $resolutionSource = null;

        $mapUrl = trim((string) ($destinationData['map_url'] ?? ''));
        if ($mapUrl !== '') {
            $destinationCoords = $this->resolveCoordinatesFromUrlOrText($mapUrl, null, $originCoords);
            if ($destinationCoords) {
                $resolutionSource = 'map_url';
            }
        }

        if (!$destinationCoords) {
            $street = trim((string) ($destinationData['street'] ?? ''));
            $neighborhood = trim((string) ($destinationData['neighborhood'] ?? ''));

            if ($street !== '' || $neighborhood !== '') {
                $destinationCoords = $this->geocodeAddressNearOrigin(
                    $street,
                    $neighborhood,
                    $originCoords
                );
                if ($destinationCoords) {
                    $resolutionSource = 'address_geocoding';
                }
            }
        }

        if (!$destinationCoords) {
            return [
                'success' => false,
                'distance_km' => null,
                'origin_coords' => $originCoords,
                'destination_coords' => null,
                'source' => null,
                'message' => 'No se pudo localizar automáticamente la dirección de destino. Puedes pegar el enlace de Google Maps del cliente o ajustar los km.',
            ];
        }

        // 3. Calcular distancia real por calles (OSRM Driving API con fallback Haversine urbano)
        $routeResult = $this->calculateDrivingDistanceKm($originCoords, $destinationCoords);

        return [
            'success' => true,
            'distance_km' => $routeResult['distance_km'],
            'origin_coords' => $originCoords,
            'destination_coords' => $destinationCoords,
            'source' => $resolutionSource . ':' . $routeResult['method'],
            'message' => sprintf(
                'Distancia calculada automáticamente: %.1f km desde %s.',
                $routeResult['distance_km'],
                $restaurant->name
            ),
        ];
    }

    /**
     * Extrae coordenadas {lat, lng} de cualquier URL de Google Maps (completa o acortada)
     * o texto con coordenadas/dirección.
     *
     * @param array{lat: float, lng: float}|null $biasCoords
     * @return array{lat: float, lng: float}|null
     */
    public function resolveCoordinatesFromUrlOrText(
        string $input,
        ?string $fallbackPlaceName = null,
        ?array $biasCoords = null
    ): ?array {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $cacheKey = 'rodo_coords_' . md5($input . '|' . ($fallbackPlaceName ?? ''));
        return Cache::remember($cacheKey, now()->addHours(12), function () use ($input, $fallbackPlaceName, $biasCoords) {
            // Paso 1: Intentar extraer coordenadas directamente de la cadena
            $direct = $this->extractCoordsFromString($input);
            if ($direct) {
                return $direct;
            }

            // Paso 2: Si es un enlace acortado (maps.app.goo.gl, goo.gl, bit.ly), expandir redirecciones
            if (preg_match('#^https?://#i', $input)) {
                $expanded = $this->expandUrlAndExtractCoords($input);
                if ($expanded) {
                    return $expanded;
                }

                // Paso 3: Si la URL tiene nombre de lugar (/maps/place/... o ?q=...), geocodificar ese texto
                $placeQuery = $this->extractPlaceQueryFromMapUrl($input);
                if ($placeQuery) {
                    $geocoded = $this->geocodeFreeformQuery($placeQuery, $biasCoords);
                    if ($geocoded) {
                        return $geocoded;
                    }
                }
            } else {
                // Es texto libre (ej. dirección escrita en location_url)
                $geocoded = $this->geocodeFreeformQuery($input, $biasCoords);
                if ($geocoded) {
                    return $geocoded;
                }
            }

            if ($fallbackPlaceName) {
                return $this->geocodeFreeformQuery($fallbackPlaceName . ', México', $biasCoords);
            }

            return null;
        });
    }

    /**
     * Busca patrones de latitud/longitud en una URL de Google Maps o cadena de texto.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function extractCoordsFromString(string $text): ?array
    {
        $decoded = urldecode($text);

        // 1. Patrón exacto del pin en Google Maps: !3d{lat}!4d{lng} (el último par suele ser el destino exacto)
        if (preg_match_all('/!3d(-?\d{1,2}\.\d{3,12})!4d(-?\d{1,3}\.\d{3,12})/', $decoded, $matches, PREG_SET_ORDER)) {
            $last = end($matches);
            $coords = $this->validateLatLng((float) $last[1], (float) $last[2]);
            if ($coords) {
                return $coords;
            }
        }

        // 2. Parámetros query: ?q=lat,lng | query=lat,lng | ll=lat,lng | destination=lat,lng | center=lat,lng
        if (preg_match('/(?:[?&](?:q|query|ll|sll|center|destination|daddr|origin)=)(-?\d{1,2}\.\d{3,12})\s*,\s*(-?\d{1,3}\.\d{3,12})/i', $decoded, $m)) {
            $coords = $this->validateLatLng((float) $m[1], (float) $m[2]);
            if ($coords) {
                return $coords;
            }
        }

        // 3. Patrón de viewport en URL de Google Maps: /@lat,lng
        if (preg_match('/@(-?\d{1,2}\.\d{3,12})\s*,\s*(-?\d{1,3}\.\d{3,12})/', $decoded, $m)) {
            $coords = $this->validateLatLng((float) $m[1], (float) $m[2]);
            if ($coords) {
                return $coords;
            }
        }

        // 4. Patrón en meta/HTML de Google Maps: [null,null,lat,lng]
        if (preg_match('/\[null,null,(-?\d{1,2}\.\d{4,12}),(-?\d{1,3}\.\d{4,12})\]/', $decoded, $m)) {
            $coords = $this->validateLatLng((float) $m[1], (float) $m[2]);
            if ($coords) {
                return $coords;
            }
        }

        // 5. Cadena directa "lat, lng"
        if (preg_match('/^\s*(-?\d{1,2}\.\d{3,12})\s*,\s*(-?\d{1,3}\.\d{3,12})\s*$/', $decoded, $m)) {
            $coords = $this->validateLatLng((float) $m[1], (float) $m[2]);
            if ($coords) {
                return $coords;
            }
        }

        return null;
    }

    /**
     * Sigue redirecciones de enlaces cortos (maps.app.goo.gl) para obtener la URL completa o coordenadas.
     *
     * @return array{lat: float, lng: float}|null
     */
    protected function expandUrlAndExtractCoords(string $url): ?array
    {
        try {
            $currentUrl = $url;

            // Seguir hasta 5 redirecciones manualmente para capturar cada cabecera Location
            for ($i = 0; $i < 5; $i++) {
                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36',
                    'Accept-Language' => 'es-MX,es;q=0.9',
                ])
                    ->timeout(5)
                    ->withoutRedirecting()
                    ->get($currentUrl);

                $location = $response->header('Location');
                if ($location) {
                    $coords = $this->extractCoordsFromString($location);
                    if ($coords) {
                        return $coords;
                    }

                    // Si el Location ya es una URL con ?q= o /place/, guardar para geocodificar si no hay más redirects
                    if ( str_starts_with($location, '/') ) {
                        $parsed = parse_url($currentUrl);
                        $currentUrl = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'www.google.com') . $location;
                    } else {
                        $currentUrl = $location;
                    }
                    continue;
                }

                // Si ya no redirige, buscar coordenadas en el cuerpo HTML o en la URL final
                $coords = $this->extractCoordsFromString($currentUrl);
                if ($coords) {
                    return $coords;
                }

                $body = $response->body();
                if ($body) {
                    $coordsFromBody = $this->extractCoordsFromString($body);
                    if ($coordsFromBody) {
                        return $coordsFromBody;
                    }
                }

                // Si la URL expandida tiene un nombre de lugar, intentar geocodificarlo
                $placeQuery = $this->extractPlaceQueryFromMapUrl($currentUrl);
                if ($placeQuery) {
                    return $this->geocodeFreeformQuery($placeQuery);
                }

                break;
            }
        } catch (\Throwable $e) {
            // Ignorar errores de red y continuar con fallbacks
        }

        return null;
    }

    /**
     * Extrae el nombre o dirección de una URL de Google Maps (/maps/place/... o /maps/search/...).
     */
    protected function extractPlaceQueryFromMapUrl(string $url): ?string
    {
        $decoded = urldecode($url);

        if (preg_match('#/maps/(?:place|search)/([^/?&@]+)#i', $decoded, $m)) {
            $place = str_replace('+', ' ', $m[1]);
            $place = trim($place);
            if ($place !== '' && !preg_match('/^-?\d+\.\d+$/', $place)) {
                return $place;
            }
        }

        $parsed = parse_url($url);
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $params);
            foreach (['q', 'query', 'daddr', 'destination'] as $key) {
                if (!empty($params[$key]) && is_string($params[$key])) {
                    return trim($params[$key]);
                }
            }
        }

        return null;
    }

    /**
     * Geocodifica calle + colonia priorizando la ciudad y radio cercano a las coordenadas del restaurante.
     *
     * @param array{lat: float, lng: float} $originCoords
     * @return array{lat: float, lng: float}|null
     */
    protected function geocodeAddressNearOrigin(
        string $street,
        string $neighborhood,
        array $originCoords
    ): ?array {
        // Limpiar abreviaturas comunes para mejorar el match en OpenStreetMap
        $cleanStreet = preg_replace('/\b(?:entre|esq\.?|esquina|casi esq|frente a)\b.*$/i', '', $street);
        $cleanStreet = preg_replace('/#(\d+)/', '$1', (string) $cleanStreet);
        $cleanStreet = trim((string) $cleanStreet);

        $cleanNeighborhood = preg_replace('/^(?:col(?:onia)?\.?|fracc(?:ionamiento)?\.?|barrio|unidad|privada)\s+/i', '', $neighborhood);
        $cleanNeighborhood = trim((string) $cleanNeighborhood);

        // Obtener contexto de ciudad/municipio desde las coordenadas del restaurante
        $localityContext = $this->resolveLocalityContext($originCoords);
        $citySuffix = $localityContext ? ", {$localityContext}" : ', México';

        $queriesToTry = [];

        if ($cleanStreet !== '' && $cleanNeighborhood !== '') {
            $queriesToTry[] = "{$cleanStreet}, {$cleanNeighborhood}{$citySuffix}";
        }
        if ($cleanStreet !== '') {
            $queriesToTry[] = "{$cleanStreet}{$citySuffix}";
        }
        if ($cleanNeighborhood !== '') {
            $queriesToTry[] = "{$cleanNeighborhood}{$citySuffix}";
            if ($neighborhood !== $cleanNeighborhood) {
                $queriesToTry[] = "{$neighborhood}{$citySuffix}";
            }
        }

        foreach ($queriesToTry as $query) {
            $coords = $this->geocodeFreeformQuery($query, $originCoords, true);
            if ($coords) {
                return $coords;
            }
        }

        return null;
    }

    /**
     * Obtiene el nombre de la ciudad y estado a partir de las coordenadas del restaurante (con caché).
     *
     * @param array{lat: float, lng: float} $coords
     */
    protected function resolveLocalityContext(array $coords): ?string
    {
        $cacheKey = sprintf('rodo_locality_%.3f_%.3f', $coords['lat'], $coords['lng']);

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($coords) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'RodoMotoMandados/1.0 (https://rodo-frontend-phi.vercel.app)',
                    'Accept-Language' => 'es',
                ])
                    ->timeout(4)
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'format' => 'jsonv2',
                        'lat' => $coords['lat'],
                        'lon' => $coords['lng'],
                        'zoom' => 12,
                    ]);

                if ($response->successful()) {
                    $addr = $response->json('address') ?? [];
                    $city = $addr['city'] ?? $addr['town'] ?? $addr['municipality'] ?? $addr['county'] ?? null;
                    $state = $addr['state'] ?? null;
                    $country = $addr['country'] ?? 'México';

                    $parts = array_filter([$city, $state, $country]);
                    if (!empty($parts)) {
                        return implode(', ', $parts);
                    }
                }
            } catch (\Throwable $e) {
                // Fallback silencioso
            }

            return 'México';
        });
    }

    /**
     * Consulta Nominatim y Photon para convertir texto en coordenadas {lat, lng}.
     *
     * @param array{lat: float, lng: float}|null $biasCoords
     * @return array{lat: float, lng: float}|null
     */
    protected function geocodeFreeformQuery(
        string $query,
        ?array $biasCoords = null,
        bool $strictLocalRadius = false
    ): ?array {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        try {
            $params = [
                'format' => 'jsonv2',
                'q' => $query,
                'limit' => 3,
                'countrycodes' => 'mx',
            ];

            if ($biasCoords) {
                // Viewbox de ~15km alrededor del restaurante
                $delta = 0.14;
                $left = $biasCoords['lng'] - $delta;
                $top = $biasCoords['lat'] + $delta;
                $right = $biasCoords['lng'] + $delta;
                $bottom = $biasCoords['lat'] - $delta;
                $params['viewbox'] = "{$left},{$top},{$right},{$bottom}";
                if ($strictLocalRadius) {
                    $params['bounded'] = 1;
                }
            }

            $response = Http::withHeaders([
                'User-Agent' => 'RodoMotoMandados/1.0 (https://rodo-frontend-phi.vercel.app)',
                'Accept-Language' => 'es',
            ])
                ->timeout(4)
                ->get('https://nominatim.openstreetmap.org/search', $params);

            if ($response->successful()) {
                $results = $response->json();
                if (is_array($results) && count($results) > 0) {
                    $first = $results[0];
                    if (isset($first['lat'], $first['lon'])) {
                        $coords = $this->validateLatLng((float) $first['lat'], (float) $first['lon']);
                        if ($coords) {
                            return $coords;
                        }
                    }
                }
            }

            // Fallback con Photon API (Komoot) con sesgo de coordenadas
            $photonParams = [
                'q' => $query,
                'limit' => 3,
            ];
            if ($biasCoords) {
                $photonParams['lat'] = $biasCoords['lat'];
                $photonParams['lon'] = $biasCoords['lng'];
            }

            $photonRes = Http::withHeaders([
                'User-Agent' => 'RodoMotoMandados/1.0',
            ])
                ->timeout(4)
                ->get('https://photon.komoot.io/api/', $photonParams);

            if ($photonRes->successful()) {
                $features = $photonRes->json('features') ?? [];
                foreach ($features as $feature) {
                    $geomCoords = $feature['geometry']['coordinates'] ?? null;
                    if (is_array($geomCoords) && count($geomCoords) >= 2) {
                        $candidate = $this->validateLatLng((float) $geomCoords[1], (float) $geomCoords[0]);
                        if ($candidate) {
                            if ($strictLocalRadius && $biasCoords) {
                                $dist = $this->haversineKm($biasCoords, $candidate);
                                if ($dist > 25) {
                                    continue;
                                }
                            }
                            return $candidate;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignorar errores de red
        }

        return null;
    }

    /**
     * Calcula la distancia de manejo por calles usando OSRM, o Haversine * 1.30 como respaldo.
     *
     * @param array{lat: float, lng: float} $origin
     * @param array{lat: float, lng: float} $destination
     * @return array{distance_km: float, method: string}
     */
    public function calculateDrivingDistanceKm(array $origin, array $destination): array
    {
        try {
            $url = sprintf(
                'https://router.project-osrm.org/route/v1/driving/%.6f,%.6f;%.6f,%.6f',
                $origin['lng'],
                $origin['lat'],
                $destination['lng'],
                $destination['lat']
            );

            $response = Http::withHeaders([
                'User-Agent' => 'RodoMotoMandados/1.0',
            ])
                ->timeout(4)
                ->get($url, [
                    'overview' => 'false',
                ]);

            if ($response->successful()) {
                $routes = $response->json('routes');
                if (is_array($routes) && isset($routes[0]['distance'])) {
                    $meters = (float) $routes[0]['distance'];
                    return [
                        'distance_km' => round($meters / 1000, 1),
                        'method' => 'osrm_driving',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Fallback a cálculo geométrico con factor de ruta urbana
        }

        $straightKm = $this->haversineKm($origin, $destination);
        $urbanRoadKm = round($straightKm * 1.30, 1);

        return [
            'distance_km' => $urbanRoadKm,
            'method' => 'haversine_urban',
        ];
    }

    /**
     * Distancia en línea recta mediante fórmula de Haversine (en km).
     *
     * @param array{lat: float, lng: float} $a
     * @param array{lat: float, lng: float} $b
     */
    public function haversineKm(array $a, array $b): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);

        $lat1 = deg2rad($a['lat']);
        $lat2 = deg2rad($b['lat']);

        $sinDLat = sin($dLat / 2);
        $sinDLng = sin($dLng / 2);

        $h = ($sinDLat * $sinDLat) + cos($lat1) * cos($lat2) * ($sinDLng * $sinDLng);
        $c = 2 * atan2(sqrt($h), sqrt(max(0.0, 1 - $h)));

        return $earthRadiusKm * $c;
    }

    /**
     * Valida que la latitud y longitud estén en rangos geográficos reales.
     *
     * @return array{lat: float, lng: float}|null
     */
    protected function validateLatLng(float $lat, float $lng): ?array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }
        if ($lat === 0.0 && $lng === 0.0) {
            return null;
        }

        return [
            'lat' => round($lat, 6),
            'lng' => round($lng, 6),
        ];
    }
}

