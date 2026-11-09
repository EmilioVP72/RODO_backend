<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class Restaurant extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = [
        'name',
        'code',
        'photo_url',
        'location_url',
        'latitude',
        'longitude',
        'description',
        'fixed_delivery_fee',
        'show_daily_kpis',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'show_daily_kpis' => 'boolean',
        'fixed_delivery_fee' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function riderRequests(): HasMany
    {
        return $this->hasMany(RiderRequest::class);
    }
}