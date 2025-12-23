<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrintJob extends Model
{
    protected $fillable = [
        'account_id',
        'location_id',
        'type',
        'payload',
        'status',
        'attempts',
        'last_error',
        'available_at',
        'claimed_at',
        'claimed_by',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'available_at' => 'datetime',
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
