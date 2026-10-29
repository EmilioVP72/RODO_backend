<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicTicketTest extends TestCase
{
    use RefreshDatabase;

    private function createSampleOrder(?Restaurant $restaurant = null): Order
    {
        $restaurant = $restaurant ?? Restaurant::create([
            'name' => 'Restaurante Test',
            'code' => '789',
            'is_active' => true,
        ]);

        $order = Order::create([
            'restaurant_id' => $restaurant->id,
            'folio' => 'RODO-' . strtoupper(Str::random(12)),
            'public_token' => (string) Str::uuid(),
            'customer_name' => 'Juan Pérez',
            'customer_phone' => '9991234567',
            'street' => 'Calle 60 #100',
            'neighborhood' => 'Centro',
            'address_reference' => 'Frente al parque',
            'map_url' => 'https://maps.google.com/?q=20.967,-89.623',
            'distance_km' => 2.5,
            'delivery_fee' => 45.00,
            'payment_method' => 'Efectivo',
            'amount_to_collect' => 250.00,
            'needs_change' => true,
            'change_from' => '500',
            'description' => '2 Pizzas y 1 Refresco',
            'comments' => 'Tocar timbre blanco',
            'is_ready' => true,
            'status' => 'pending',
        ]);

        $order->careInstructions()->createMany([
            ['instruction' => 'Mantener horizontal'],
            ['instruction' => 'Manejar con cuidado'],
        ]);

        return $order->fresh(['restaurant', 'careInstructions']);
    }

    public function test_valid_token_allows_viewing_public_ticket(): void
    {
        $order = $this->createSampleOrder();

        $response = $this->getJson("/api/v1/tickets/{$order->public_token}");

        $response->assertStatus(200);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $response->assertJson([
            'success' => true,
            'data' => [
                'folio' => $order->folio,
                    'status' => 'pending',
                    'restaurant' => [
                        'name' => 'Restaurante Test',
                        'code' => '789',
                    ],
                    'customer' => [
                        'name' => 'Juan Pérez',
                        'phone' => '9991234567',
                        'street' => 'Calle 60 #100',
                        'neighborhood' => 'Centro',
                        'address_reference' => 'Frente al parque',
                        'map_url' => 'https://maps.google.com/?q=20.967,-89.623',
                    ],
                    'pricing' => [
                        'distance_km' => 2.5,
                        'delivery_fee' => 45.0,
                        'payment_method' => 'Efectivo',
                        'amount_to_collect' => 250.0,
                        'needs_change' => true,
                        'change_from' => '500',
                    ],
                    'details' => [
                        'description' => '2 Pizzas y 1 Refresco',
                        'comments' => 'Tocar timbre blanco',
                        'is_ready' => true,
                        'care_instructions' => [
                            'Mantener horizontal',
                            'Manejar con cuidado',
                        ],
                    ],
                ],
            ]);
    }

    public function test_non_existent_token_returns_404(): void
    {
        $fakeToken = (string) Str::uuid();

        $response = $this->getJson("/api/v1/tickets/{$fakeToken}");

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Ticket no encontrado.',
            ]);
    }

    public function test_public_ticket_does_not_leak_internal_ids_or_credentials(): void
    {
        $order = $this->createSampleOrder();

        $response = $this->getJson("/api/v1/tickets/{$order->public_token}");

        $response->assertStatus(200);

        $json = $response->json('data');

        $this->assertArrayNotHasKey('id', $json);
        $this->assertArrayNotHasKey('restaurant_id', $json);
        $this->assertArrayNotHasKey('id', $json['restaurant']);
        $this->assertArrayNotHasKey('created_at', $json['restaurant']);
        $this->assertArrayNotHasKey('updated_at', $json['restaurant']);
    }

    public function test_public_ticket_endpoint_is_strictly_read_only(): void
    {
        $order = $this->createSampleOrder();

        $this->postJson("/api/v1/tickets/{$order->public_token}", ['status' => 'cancelled'])
            ->assertStatus(405);

        $this->putJson("/api/v1/tickets/{$order->public_token}", ['status' => 'cancelled'])
            ->assertStatus(405);

        $this->deleteJson("/api/v1/tickets/{$order->public_token}")
            ->assertStatus(405);

        // Verify order status unchanged in database
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
        ]);
    }

    public function test_authenticated_order_creation_returns_ticket_url_and_public_token(): void
    {
        $restaurant = Restaurant::create([
            'name' => 'Pecorinos',
            'code' => '123',
            'is_active' => true,
        ]);

        $payload = [
            'customer_phone' => '9997654321',
            'street' => 'Av. Hidalgo 45',
            'neighborhood' => 'García',
            'distance_km' => 3.0,
            'payment_method' => 'Efectivo',
        ];

        $response = $this->actingAs($restaurant, 'restaurant')
            ->postJson('/api/v1/orders', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'folio',
                    'delivery_fee',
                    'public_token',
                    'ticket_url',
                ],
            ]);

        $data = $response->json('data');
        $this->assertNotEmpty($data['public_token']);
        $this->assertStringContainsString('/ticket/' . $data['public_token'], $data['ticket_url']);
    }

    public function test_order_index_does_not_leak_public_token_and_only_lists_own_orders(): void
    {
        $restaurantA = Restaurant::create([
            'name' => 'Restaurante A',
            'code' => '111',
            'is_active' => true,
        ]);

        $restaurantB = Restaurant::create([
            'name' => 'Restaurante B',
            'code' => '222',
            'is_active' => true,
        ]);

        $orderA = $this->createSampleOrder($restaurantA);
        $orderB = $this->createSampleOrder($restaurantB);

        $response = $this->actingAs($restaurantA, 'restaurant')
            ->getJson('/api/v1/orders');

        $response->assertStatus(200);

        $orders = $response->json('data.data');

        $this->assertCount(1, $orders);
        $this->assertEquals($orderA->folio, $orders[0]['folio']);

        // Verify public_token is not leaked in order list
        $this->assertArrayNotHasKey('public_token', $orders[0]);
    }

    public function test_restaurant_registration_and_session_recovery(): void
    {
        $response = $this->postJson('/api/v1/restaurants', [
            'name' => 'El Buen Taco',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'El Buen Taco',
                ],
            ]);

        $restaurant = Restaurant::where('name', 'El Buen Taco')->first();
        $this->assertNotNull($restaurant);

        // Verify session /me endpoint returns authenticated restaurant data
        $this->actingAs($restaurant, 'restaurant')
            ->getJson('/api/v1/restaurant/me')
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $restaurant->id,
                    'name' => 'El Buen Taco',
                    'code' => $restaurant->code,
                ],
            ]);
    }
}
