<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Terminal extends Model
{
protected $fillable = [
    'account_id',
    'location_id',
    'name',
    'code',
    'device_code',
    'is_active',
];
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
}
