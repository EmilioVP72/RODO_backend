<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RouteCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected Restaurant $restaurant;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.routing.provider', 'openrouteservice');
        Config::set('services.routing.allow_osrm_when_unconfigured', false);
        Config::set('services.openrouteservice.key', 'test-ors-api-key-12345');
        Config::set('services.openrouteservice.base_url', 'https://api.openrouteservice.org');

        $this->restaurant = Restaurant::create([
            'name' => 'Pronto Pizza San Antonio',
            'code' => '609',
            'location_url' => 'https://www.google.com/maps?q=20.9144,-100.7452',
            'latitude' => 20.9144,
            'longitude' => -100.7452,
            'is_active' => true,
        ]);

        $this->token = $this->restaurant->createToken('test-token')->plainTextToken;
    }

    protected function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->token,
            'X-Restaurant-Code' => $this->restaurant->code,
            'Accept' => 'application/json',
        ];
    }

    public function test_calculates_valid_route_and_delivery_fee_with_openrouteservice(): void
    {
        Http::fake([
            'https://api.openrouteservice.org/v2/directions/*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'properties' => [
                            'summary' => [
                                'distance' => 2500.0, // 2.5 km
                                'duration' => 420.0,  // 7.0 min
                            ],
                        ],
                        'geometry' => [
                            'type' => 'LineString',
                            'coordinates' => [
                                [-100.7452, 20.9144],
                                [-100.7400, 20.9180],
                                [-100.7350, 20.9220],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/routes/calculate', [
                'origin' => ['lat' => 20.9144, 'lng' => -100.7452],
                'destination' => ['lat' => 20.9220, 'lng' => -100.7350],
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'distance_km' => 2.5,
                    'duration_minutes' => 7.0,
                    'delivery_fee' => 45.0, // $40 base + (2.5 * $2)
                    'is_fixed_fee' => false,
                    'out_of_coverage' => false,
                    'provider' => 'openrouteservice',
                    'geometry' => [
                        'type' => 'LineString',
                    ],
                ],
            ]);
    }

    public function test_rejects_out_of_range_coordinates(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/routes/calculate', [
                'origin' => ['lat' => 125.0, 'lng' => -100.7452],
                'destination' => ['lat' => 20.9220, 'lng' => -295.0],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['origin.lat', 'destination.lng']);
    }

    public function test_rejects_identical_origin_and_destination(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/routes/calculate', [
                'origin' => ['lat' => 20.9144, 'lng' => -100.7452],
                'destination' => ['lat' => 20.9144, 'lng' => -100.7452],
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'IDENTICAL_COORDINATES',
            ]);
    }

    public function test_recalculates_when_marker_or_destination_changes(): void
    {
        Http::fakeSequence('https://api.openrouteservice.org/v2/directions/*')
            ->push([
                'type' => 'FeatureCollection',
                'features' => [[
                    'properties' => ['summary' => ['distance' => 1500.0, 'duration' => 240.0]],
                    'geometry' => ['type' => 'LineString', 'coordinates' => [[-100.7452, 20.9144], [-100.7410, 20.9160]]],
                ]],
            ], 200)
            ->push([
                'type' => 'FeatureCollection',
                'features' => [[
                    'properties' => ['summary' => ['distance' => 4000.0, 'duration' => 600.0]],
                    'geometry' => ['type' => 'LineString', 'coordinates' => [[-100.7452, 20.9144], [-100.7200, 20.9350]]],
                ]],
            ], 200);

        $first = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/routes/calculate', [
                'origin' => ['lat' => 20.9144, 'lng' => -100.7452],
                'destination' => ['lat' => 20.9160, 'lng' => -100.7410],
            ]);

        $first->assertStatus(200)
            ->assertJsonPath('data.distance_km', 1.5)
            ->assertJsonPath('data.delivery_fee', 43);

        $second = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/routes/calculate', [
                'origin' => ['lat' => 20.9144, 'lng' => -100.7452],
                'destination' => ['lat' => 20.9350, 'lng' => -100.7200],
            ]);

        $second->assertStatus(200)
            ->assertJsonPath('data.distance_km', 4)
            ->assertJsonPath('data.delivery_fee', 48);
    }

    public function test_handles_non_existent_route(): void
    {
        Http::fake([
            'https://api.openrouteservice.org/v2/directions/*' => Http::response([
                'error' => [
                    'code' => 2009,
                    'message' => 'Route could not be found - Unable to find a route between points.',
                ],
            ], 404),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/routes/calculate', [
                'origin' => ['lat' => 20.9144, 'lng' => -100.7452],
                'destination' => ['lat' => 20.9800, 'lng' => -100.8100],
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error_code' => 'ROUTE_NOT_FOUND',
            ]);
    }

    public function test_handles_invalid_api_key(): void
    {
        Http::fake([
            'https://api.openrouteservice.org/v2/directions/*' => Http::response([
                'error' => 'Access to this API has been disallowed; invalid key.',
            ], 403),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/routes/calculate', [
                'origin' => ['lat' => 20.9144, 'lng' => -100.7452],
                'destination' => ['lat' => 20.9220, 'lng' => -100.7350],
            ]);

        $response->assertStatus(502)
            ->assertJson([
                'success' => false,
                'error_code' => 'INVALID_API_KEY',
            ]);
    }

    public function test_handles_provider_timeout(): void
    {
        Http::fake([
            'https://api.openrouteservice.org/v2/directions/*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out after 6000 milliseconds');
            },
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/routes/calculate', [
                'origin' => ['lat' => 20.9144, 'lng' => -100.7452],
                'destination' => ['lat' => 20.9220, 'lng' => -100.7350],
            ]);

        $response->assertStatus(504)
            ->assertJson([
                'success' => false,
                'error_code' => 'PROVIDER_TIMEOUT',
            ]);
    }

    public function test_handles_rate_limit_exceeded(): void
    {
        Http::fake([
            'https://api.openrouteservice.org/v2/directions/*' => Http::response([
                'message' => 'Quota exceeded',
            ], 429),
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/routes/calculate', [
                'origin' => ['lat' => 20.9144, 'lng' => -100.7452],
                'destination' => ['lat' => 20.9220, 'lng' => -100.7350],
            ]);

        $response->assertStatus(429)
            ->assertJson([
                'success' => false,
                'error_code' => 'RATE_LIMIT_EXCEEDED',
            ]);
    }

    public function test_handles_connection_error(): void
    {
        Http::fake([
            'https://api.openrouteservice.org/v2/directions/*' => function () {
                throw new ConnectionException('cURL error 6: Could not resolve host: api.openrouteservice.org');
            },
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/routes/calculate', [
                'origin' => ['lat' => 20.9144, 'lng' => -100.7452],
                'destination' => ['lat' => 20.9220, 'lng' => -100.7350],
            ]);

        $response->assertStatus(502)
            ->assertJson([
                'success' => false,
                'error_code' => 'CONNECTION_ERROR',
            ]);
    }

    public function test_persists_order_with_route_coordinates_and_duration(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/orders', [
                'customer_name' => 'Carlos Mendoza',
                'customer_phone' => '4151234567',
                'street' => 'Calle Hidalgo 45',
                'neighborhood' => 'Centro',
                'address_reference' => 'Portón de madera',
                'origin_address' => 'Pronto Pizza San Antonio, San Miguel de Allende',
                'destination_address' => 'Calle Hidalgo 45, Centro, San Miguel de Allende',
                'origin_lat' => 20.9144,
                'origin_lng' => -100.7452,
                'destination_lat' => 20.9220,
                'destination_lng' => -100.7350,
                'distance_km' => 2.5,
                'duration_minutes' => 7.0,
                'payment_method' => 'Efectivo',
                'amount_to_collect' => 280.00,
                'needs_change' => false,
                'is_ready' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.distance_km', '2.50')
            ->assertJsonPath('data.duration_minutes', 7)
            ->assertJsonPath('data.delivery_fee', '45.00');

        $this->assertDatabaseHas('orders', [
            'restaurant_id' => $this->restaurant->id,
            'customer_name' => 'Carlos Mendoza',
            'origin_address' => 'Pronto Pizza San Antonio, San Miguel de Allende',
            'destination_address' => 'Calle Hidalgo 45, Centro, San Miguel de Allende',
            'distance_km' => 2.5,
            'duration_minutes' => 7.0,
            'delivery_fee' => 45.0,
        ]);
    }
}
