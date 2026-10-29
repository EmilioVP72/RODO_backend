<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicTicketResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'folio' => $this->folio,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'restaurant' => [
                'name' => $this->restaurant?->name,
                'code' => $this->restaurant?->code,
            ],
            'customer' => [
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
                'street' => $this->street,
                'neighborhood' => $this->neighborhood,
                'address_reference' => $this->address_reference,
                'map_url' => $this->map_url,
            ],
            'pricing' => [
                'distance_km' => (float) $this->distance_km,
                'delivery_fee' => (float) $this->delivery_fee,
                'payment_method' => $this->payment_method,
                'amount_to_collect' => $this->amount_to_collect !== null ? (float) $this->amount_to_collect : null,
                'needs_change' => (bool) $this->needs_change,
                'change_from' => $this->change_from,
            ],
            'details' => [
                'description' => $this->description,
                'comments' => $this->comments,
                'is_ready' => (bool) $this->is_ready,
                'care_instructions' => $this->careInstructions?->pluck('instruction')->values()->all() ?? [],
            ],
        ];
    }
}
