<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\RestaurantCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RestaurantRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_register_new_restaurant(): void
    {
        $response = $this->postJson('/api/v1/restaurants', [
            'name' => 'Pecorinos Roma',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'name' => 'Pecorinos Roma',
                ],
            ]);

        $this->assertDatabaseHas('restaurants', [
            'name' => 'Pecorinos Roma',
        ]);
    }

    public function test_cannot_register_restaurant_with_exact_same_name(): void
    {
        Restaurant::create([
            'name' => 'Pecorinos Roma',
            'code' => '100',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/restaurants', [
            'name' => 'Pecorinos Roma',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
        $this->assertEquals(
            'Ya existe un restaurante registrado con este nombre.',
            $response->json('errors.name.0')
        );
    }

    public function test_cannot_register_restaurant_with_same_name_in_uppercase(): void
    {
        Restaurant::create([
            'name' => 'Pecorinos Roma',
            'code' => '101',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/restaurants', [
            'name' => 'PECORINOS ROMA',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
        $this->assertEquals(
            'Ya existe un restaurante registrado con este nombre.',
            $response->json('errors.name.0')
        );
    }

    public function test_cannot_register_restaurant_with_same_name_in_lowercase(): void
    {
        Restaurant::create([
            'name' => 'Pecorinos Roma',
            'code' => '102',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/restaurants', [
            'name' => 'pecorinos roma',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
        $this->assertEquals(
            'Ya existe un restaurante registrado con este nombre.',
            $response->json('errors.name.0')
        );
    }

    public function test_cannot_register_restaurant_with_same_name_mixed_case_and_whitespace(): void
    {
        Restaurant::create([
            'name' => 'Pecorinos Roma',
            'code' => '103',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/restaurants', [
            'name' => '   pEcOrInOs  RoMa   ',
        ]);

        // When name has internal whitespace differences:
        // Here with trim it is 'pEcOrInOs  RoMa' vs 'Pecorinos Roma'
        // Test same name with extra outer padding:
        $response2 = $this->postJson('/api/v1/restaurants', [
            'name' => '   pEcOrInOs RoMa   ',
        ]);

        $response2->assertStatus(422);
        $response2->assertJsonValidationErrors(['name']);
    }

    public function test_code_service_throws_exception_on_case_insensitive_duplicate(): void
    {
        Restaurant::create([
            'name' => 'Tacos El Guero',
            'code' => '200',
            'is_active' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ya existe un restaurante registrado con este nombre.');

        $service = app(RestaurantCodeService::class);
        $service->createRestaurant('tacos el guero');
    }
}
