<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'account_id','location_id','order_id',
        'name','quantity','unit_price','total',
        'status','notes','sent_to_kitchen_at','done_at',
        'created_by','canceled_by','cancel_reason'
    ];

    protected $casts = [
        'sent_to_kitchen_at' => 'datetime',
        'done_at' => 'datetime',
    ];
}
