<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'account_id','location_id','order_id','method','amount','status','paid_at','created_by'
    ];

    protected $casts = [
        'paid_at' => 'datetime',
    ];
}
