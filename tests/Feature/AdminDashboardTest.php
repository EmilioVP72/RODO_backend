<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_with_code_000(): void
    {
        $response = $this->postJson('/api/v1/restaurant/login', [
            'identifier' => '000',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_admin' => true,
                'data' => [
                    'code' => '000',
                    'role' => 'admin',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_admin_can_fetch_summary_and_manage_restaurants_crud(): void
    {
        $loginResponse = $this->postJson('/api/v1/restaurant/login', [
            'identifier' => '000',
        ]);

        $token = $loginResponse->json('data.token');

        // 1. Register a restaurant normally (only name, nullable fields remain null)
        $regResponse = $this->postJson('/api/v1/restaurants', [
            'name' => 'Taquería El Pastor',
        ]);
        $regResponse->assertStatus(201);
        $restaurantId = $regResponse->json('data.id');

        $restaurant = Restaurant::find($restaurantId);
        $this->assertNull($restaurant->photo_url);
        $this->assertNull($restaurant->location_url);
        $this->assertNull($restaurant->description);

        // 2. Admin updates restaurant adding photo, location_url, and description
        $updateResponse = $this->withHeader('X-Admin-Token', $token)
            ->putJson("/api/v1/admin/restaurants/{$restaurantId}", [
                'name' => 'Taquería El Pastor Centro',
                'photo_url' => 'https://example.com/foto-pastor.jpg',
                'location_url' => 'https://maps.google.com/?q=20.9144,-100.7452',
                'description' => 'Especialidad en tacos al pastor y volcanes. Horario 1pm a 11pm.',
                'is_active' => true,
            ]);

        $updateResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $restaurantId,
                    'name' => 'Taquería El Pastor Centro',
                    'photo_url' => 'https://example.com/foto-pastor.jpg',
                    'location_url' => 'https://maps.google.com/?q=20.9144,-100.7452',
                    'description' => 'Especialidad en tacos al pastor y volcanes. Horario 1pm a 11pm.',
                    'has_complete_profile' => true,
                ],
            ]);

        // 3. Admin fetches summary KPIs and charts
        $summaryResponse = $this->withHeader('X-Admin-Token', $token)
            ->getJson('/api/v1/admin/summary?period=30d');

        $summaryResponse->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'kpis' => [
                        'total_orders',
                        'total_delivery_fees',
                        'total_restaurants',
                        'restaurants_with_complete_profile',
                    ],
                    'charts' => [
                        'daily_trend',
                        'hourly_distribution',
                        'top_restaurants',
                        'payment_methods',
                        'top_neighborhoods',
                    ],
                ],
            ]);

        // 4. Admin deletes restaurant
        $deleteResponse = $this->withHeader('X-Admin-Token', $token)
            ->deleteJson("/api/v1/admin/restaurants/{$restaurantId}");

        $deleteResponse->assertStatus(200);
        $this->assertDatabaseMissing('restaurants', ['id' => $restaurantId]);
    }

    public function test_fixed_delivery_fee_and_automatic_distance_calculation(): void
    {
        $loginResponse = $this->postJson('/api/v1/restaurant/login', [
            'identifier' => '000',
        ]);
        $adminToken = $loginResponse->json('data.token');

        // 1. Crear restaurante con ubicación registrada pero SIN tarifa fija (cálculo por distancia)
        $createRes = $this->withHeader('X-Admin-Token', $adminToken)
            ->postJson('/api/v1/admin/restaurants', [
                'name' => 'Pronto Pizza San Antonio',
                'location_url' => 'https://www.google.com/maps/place/Pronto/@20.9144,-100.7452,17z',
                'fixed_delivery_fee' => null,
            ]);

        $createRes->assertStatus(201);
        $restCode = $createRes->json('data.code');
        $restId = $createRes->json('data.id');

        // Calcular distancia automática entre location_url del restaurante y map_url del destino
        $calcRes = $this->withHeader('X-Restaurant-Code', $restCode)
            ->postJson('/api/v1/orders/calculate-distance', [
                'street' => 'Calle Principal 10',
                'neighborhood' => 'La Lomita',
                'map_url' => 'https://www.google.com/maps/place/Destino/@20.9220,-100.7400,17z',
            ]);

        $calcRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'has_fixed_fee' => false,
                'out_of_coverage' => false,
            ]);
        $this->assertGreaterThan(0, $calcRes->json('distance_km'));
        $this->assertGreaterThanOrEqual(40, $calcRes->json('delivery_fee'));

        // 2. Ahora el Admin asigna una Tarifa Fija de $45.00 MXN a este restaurante
        $updateRes = $this->withHeader('X-Admin-Token', $adminToken)
            ->putJson("/api/v1/admin/restaurants/{$restId}", [
                'fixed_delivery_fee' => 45.00,
            ]);

        $updateRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'fixed_delivery_fee' => 45.0,
                ],
            ]);

        // 3. Crear pedido con este restaurante: debe colocar SIEMPRE la tarifa fija ($45.00)
        $orderRes = $this->withHeader('X-Restaurant-Code', $restCode)
            ->postJson('/api/v1/orders', [
                'customer_name' => 'Juan Pérez',
                'customer_phone' => '4151234567',
                'street' => 'Av. Independencia 120',
                'neighborhood' => 'Centro',
                'payment_method' => 'Efectivo',
                'amount_to_collect' => 250,
            ]);

        $orderRes->assertStatus(201);
        $this->assertEquals(45.00, (float) $orderRes->json('data.delivery_fee'));
        $this->assertEquals('pending', $orderRes->json('data.status'));

        // 4. Marcar el pedido como completado desde el ticket público
        $publicToken = $orderRes->json('data.public_token');
        $completeRes = $this->postJson("/api/v1/tickets/{$publicToken}/complete");

        $completeRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'completed',
                ],
            ]);
    }
}

