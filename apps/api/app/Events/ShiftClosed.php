<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShiftClosed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $shiftId,
        public int $accountId,
        public int $locationId,
        public int $closedByUserId
    ) {}
}
