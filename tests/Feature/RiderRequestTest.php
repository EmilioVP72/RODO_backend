<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RiderRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RiderRequestTest extends TestCase
{
    use RefreshDatabase;

    private function createSampleRestaurant(): Restaurant
    {
        return Restaurant::create([
            'name' => 'Pecorinos Express',
            'code' => '456',
            'is_active' => true,
        ]);
    }

    public function test_unauthenticated_restaurant_cannot_create_rider_request(): void
    {
        $response = $this->postJson('/api/v1/rider-requests', [
            'riders_count' => 2,
            'note' => 'Dos pedidos listos',
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_restaurant_can_create_rider_request(): void
    {
        $restaurant = $this->createSampleRestaurant();

        $response = $this->actingAs($restaurant, 'restaurant')
            ->postJson('/api/v1/rider-requests', [
                'riders_count' => 3,
                'note' => 'Pedidos listos en mostrador principal',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'message',
            'data' => [
                'folio',
                'riders_count',
                'note',
                'status',
                'public_token',
                'request_url',
                'created_at',
                'restaurant' => [
                    'name',
                    'code',
                ],
            ],
        ]);

        $response->assertJson([
            'data' => [
                'riders_count' => 3,
                'note' => 'Pedidos listos en mostrador principal',
                'status' => 'pending',
                'restaurant' => [
                    'name' => 'Pecorinos Express',
                    'code' => '456',
                ],
            ],
        ]);

        $this->assertDatabaseHas('rider_requests', [
            'restaurant_id' => $restaurant->id,
            'riders_count' => 3,
            'status' => 'pending',
        ]);
    }

    public function test_valid_token_allows_viewing_public_rider_request(): void
    {
        $restaurant = $this->createSampleRestaurant();
        $token = (string) Str::uuid();

        $riderRequest = $restaurant->riderRequests()->create([
            'folio' => 'RODO-REP-TEST1234',
            'public_token' => $token,
            'riders_count' => 2,
            'note' => 'Llevar cambio de $200',
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/v1/rider-requests/{$token}");

        $response->assertStatus(200);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $response->assertJson([
            'data' => [
                'folio' => 'RODO-REP-TEST1234',
                'riders_count' => 2,
                'note' => 'Llevar cambio de $200',
                'status' => 'pending',
                'restaurant' => [
                    'name' => 'Pecorinos Express',
                    'code' => '456',
                ],
            ],
        ]);
    }

    public function test_non_existent_token_returns_404(): void
    {
        $response = $this->getJson('/api/v1/rider-requests/' . Str::uuid());

        $response->assertStatus(404);
        $response->assertJson([
            'message' => 'Solicitud de repartidores no encontrada.',
        ]);
    }

    public function test_public_rider_request_does_not_leak_internal_ids(): void
    {
        $restaurant = $this->createSampleRestaurant();
        $token = (string) Str::uuid();

        $restaurant->riderRequests()->create([
            'folio' => 'RODO-REP-SECRET01',
            'public_token' => $token,
            'riders_count' => 1,
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/v1/rider-requests/{$token}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertArrayNotHasKey('id', $data);
        $this->assertArrayNotHasKey('restaurant_id', $data);
        $this->assertArrayNotHasKey('public_token', $data);
        $this->assertArrayNotHasKey('id', $data['restaurant']);
    }

    public function test_validation_requires_valid_riders_count(): void
    {
        $restaurant = $this->createSampleRestaurant();

        $response = $this->actingAs($restaurant, 'restaurant')
            ->postJson('/api/v1/rider-requests', [
                'riders_count' => 0, // Invalido: menor a 1
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['riders_count']);
    }
}
