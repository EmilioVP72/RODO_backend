<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OrderService
{
    public function calculateDeliveryFee(float $distanceKm): float
    {
        if ($distanceKm < 0 || $distanceKm > 5) {
            throw new \InvalidArgumentException(
                'La distancia debe estar entre 0 y 5 km.'
            );
        }

        return round(min(50, 40 + (2 * $distanceKm)), 2);
    }

    public function calculateFeeForRestaurant(Restaurant $restaurant, float $distanceKm): float
    {
        if ($restaurant->fixed_delivery_fee !== null && (float) $restaurant->fixed_delivery_fee > 0) {
            return round((float) $restaurant->fixed_delivery_fee, 2);
        }

        return $this->calculateDeliveryFee($distanceKm);
    }

    public function createOrder(
        Restaurant $restaurant,
        array $data
    ): Order {
        return DB::transaction(function () use ($restaurant, $data) {
            $hasFixedFee = $restaurant->fixed_delivery_fee !== null && (float) $restaurant->fixed_delivery_fee > 0;

            $distanceKm = isset($data['distance_km']) && $data['distance_km'] !== ''
                ? (float) $data['distance_km']
                : 0.0;

            if ($hasFixedFee) {
                $deliveryFee = round((float) $restaurant->fixed_delivery_fee, 2);
            } else {
                $deliveryFee = $this->calculateDeliveryFee($distanceKm);
            }

            $orderAttributes = [
                'restaurant_id' => $restaurant->id,

                // Folio temporal único basado en UUID.
                'folio' => 'RODO-' . strtoupper(
                    Str::random(12)
                ),

                'public_token' => (string) Str::uuid(),

                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'],
                'street' => $data['street'],
                'neighborhood' => $data['neighborhood'],
                'address_reference' => $data['address_reference'] ?? null,
                'map_url' => $data['map_url'] ?? null,

                'distance_km' => $distanceKm,
                'delivery_fee' => $deliveryFee,

                'payment_method' => $data['payment_method'] ?? null,
                'amount_to_collect' => $data['amount_to_collect'] ?? null,
                'needs_change' => $data['needs_change'] ?? false,
                'change_from' => $data['change_from'] ?? null,

                'description' => $data['description'] ?? null,
                'comments' => $data['comments'] ?? null,
                'is_ready' => $data['is_ready'] ?? false,

                'status' => 'pending',
            ];

            if (Schema::hasColumn('orders', 'origin_lat')) {
                $orderAttributes['origin_lat'] = isset($data['origin_lat']) && is_numeric($data['origin_lat'])
                    ? (float) $data['origin_lat']
                    : null;
                $orderAttributes['origin_lng'] = isset($data['origin_lng']) && is_numeric($data['origin_lng'])
                    ? (float) $data['origin_lng']
                    : null;
                $orderAttributes['destination_lat'] = isset($data['destination_lat']) && is_numeric($data['destination_lat'])
                    ? (float) $data['destination_lat']
                    : null;
                $orderAttributes['destination_lng'] = isset($data['destination_lng']) && is_numeric($data['destination_lng'])
                    ? (float) $data['destination_lng']
                    : null;
                $orderAttributes['origin_address'] = $data['origin_address'] ?? null;
                $orderAttributes['destination_address'] = $data['destination_address']
                    ?? trim(($data['street'] ?? '') . ', ' . ($data['neighborhood'] ?? ''), ', ');
                $orderAttributes['duration_minutes'] = isset($data['duration_minutes']) && is_numeric($data['duration_minutes'])
                    ? round((float) $data['duration_minutes'], 2)
                    : null;
            }

            $order = Order::create($orderAttributes);

            foreach ($data['care_instructions'] ?? [] as $instruction) {
                $order->careInstructions()->create([
                    'instruction' => $instruction,
                ]);
            }

            return $order->load([
                'restaurant:id,name,code,fixed_delivery_fee,location_url',
                'careInstructions',
            ]);
        });
    }
}
