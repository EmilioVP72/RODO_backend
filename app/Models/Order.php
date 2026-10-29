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
        'distance_km',
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
        'distance_km' => 'decimal:2',
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