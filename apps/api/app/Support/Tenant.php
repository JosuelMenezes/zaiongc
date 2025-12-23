<?php

namespace App\Support;

use Illuminate\Support\Facades\App;

class Tenant
{
    public const BINDING = 'tenant.context';

    public static function set(int $accountId, int $locationId): void
    {
        App::instance(self::BINDING, [
            'account_id' => $accountId,
            'location_id' => $locationId,
        ]);
    }

    public static function accountId(): int
    {
        $ctx = App::bound(self::BINDING) ? App::make(self::BINDING) : null;

        if (!$ctx || empty($ctx['account_id'])) {
            abort(500, 'Tenant context (account_id) não definido.');
        }

        return (int) $ctx['account_id'];
    }

    public static function locationId(): int
    {
        $ctx = App::bound(self::BINDING) ? App::make(self::BINDING) : null;

        if (!$ctx || empty($ctx['location_id'])) {
            abort(500, 'Tenant context (location_id) não definido.');
        }

        return (int) $ctx['location_id'];
    }
}
