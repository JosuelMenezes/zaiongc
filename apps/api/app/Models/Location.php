<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Location extends Model
{
    protected $fillable = [
        'account_id','name','code','phone','address_line','city','state','zip','is_active'
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
