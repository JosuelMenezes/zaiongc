<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KitchenController extends Controller
{
    public function send(Order $order)
    {
        if ($order->account_id !== Tenant::accountId() || $order->location_id !== Tenant::locationId()) {
            abort(404);
        }

        return DB::transaction(function () use ($order) {
            $order->items()
                ->where('status', 'pending')
                ->update([
                    'status' => 'sent',
                    'sent_to_kitchen_at' => now(),
                ]);

            $order->update(['status' => 'sent']);

            return $order->load('items');
        });
    }
}
