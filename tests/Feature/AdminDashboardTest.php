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
}
