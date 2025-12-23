<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $fillable = [
        'account_id',
        'location_id',
        'terminal_id',
        'opened_by',
        'opened_at',
        'opening_cash',
        'status',
        'closing_cash',
        'expected_cash',
        'difference',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_cash' => 'decimal:2',
        'closing_cash' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'difference' => 'decimal:2',
    ];
}
