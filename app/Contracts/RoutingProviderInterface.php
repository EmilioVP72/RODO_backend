<?php

namespace App\Contracts;

use App\Exceptions\RoutingException;

interface RoutingProviderInterface
{
    /**
     * Calcula la ruta real por carretera entre un punto de origen y un punto de destino.
     *
     * @param array{lat: float, lng: float} $origin
     * @param array{lat: float, lng: float} $destination
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
    public function calculateRoute(array $origin, array $destination): array;
}
