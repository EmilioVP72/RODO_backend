<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'restaurant_id',
        'folio',
        'public_token',
        'customer_name',
        'customer_phone',
        'street',
        'neighborhood',
        'address_reference',
        'map_url',
        'origin_address',
        'destination_address',
        'origin_lat',
        'origin_lng',
        'destination_lat',
        'destination_lng',
        'distance_km',
        'duration_minutes',
        'delivery_fee',
        'payment_method',
        'amount_to_collect',
        'needs_change',
        'change_from',
        'description',
        'comments',
        'is_ready',
        'status',
        'assigned_at',
        'picked_up_at',
        'delivered_at',
        'cancelled_at',
    ];

    protected $hidden = [
        'public_token',
    ];

    protected $casts = [
        'origin_lat' => 'float',
        'origin_lng' => 'float',
        'destination_lat' => 'float',
        'destination_lng' => 'float',
        'distance_km' => 'decimal:2',
        'duration_minutes' => 'float',
        'delivery_fee' => 'decimal:2',
        'amount_to_collect' => 'decimal:2',
        'needs_change' => 'boolean',
        'is_ready' => 'boolean',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function careInstructions()
    {
        return $this->hasMany(OrderCareInstruction::class);
    }

    public function attachments()
    {
        return $this->hasMany(OrderAttachment::class);
    }
}