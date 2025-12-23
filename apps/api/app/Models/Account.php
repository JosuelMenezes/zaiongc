<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'name','slug','document','email','phone','plan','status','trial_ends_at'
    ];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class);
    }
}
