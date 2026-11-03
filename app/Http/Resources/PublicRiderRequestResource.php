<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicRiderRequestResource extends JsonResource
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
            'riders_count' => (int) $this->riders_count,
            'note' => $this->note,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'restaurant' => [
                'name' => $this->restaurant?->name,
                'code' => $this->restaurant?->code,
                'photo_url' => $this->restaurant?->photo_url,
                'location_url' => $this->restaurant?->location_url,
                'description' => $this->restaurant?->description,
            ],
        ];
    }
}
