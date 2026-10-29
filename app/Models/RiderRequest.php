<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiderRequest extends Model
{
    protected $fillable = [
        'restaurant_id',
        'folio',
        'public_token',
        'riders_count',
        'note',
        'status',
    ];

    protected $hidden = [
        'public_token',
    ];

    protected $casts = [
        'riders_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}
