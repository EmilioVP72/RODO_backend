<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderCareInstruction extends Model
{
    protected $fillable = [
        'order_id',
        'instruction',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}