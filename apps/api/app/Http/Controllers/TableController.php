<?php

namespace App\Http\Controllers;

use App\Models\DiningTable;
use App\Support\Tenant;
use Illuminate\Http\Request;
use App\Models\Order;


class TableController extends Controller
{
    public function index()
    {
        return DiningTable::where('account_id', Tenant::accountId())
            ->where('location_id', Tenant::locationId())
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:60',
            'seats' => 'nullable|integer|min:1|max:20',
        ]);

        $table = DiningTable::create([
            'account_id' => Tenant::accountId(),
            'location_id' => Tenant::locationId(),
            'name' => $request->name,
            'seats' => $request->seats ?? 4,
            'status' => 'free',
            'is_active' => true,
        ]);

        return response()->json($table, 201);
    }

     public function currentOrder(Request $request, DiningTable $table)
    {
        // Tenant guard
        if ($table->account_id !== Tenant::accountId() || $table->location_id !== Tenant::locationId()) {
            abort(404);
        }

        $order = Order::query()
            ->where('account_id', Tenant::accountId())
            ->where('location_id', Tenant::locationId())
            ->where('table_id', $table->id)
            ->whereIn('status', ['open', 'sent'])
            ->orderByDesc('id')
            ->first();

        if (!$order) {
            return response()->json(['order' => null]);
        }

        // Retorna leve (sem itens) para decidir navegação no PDV.
        return response()->json([
            'order' => [
                'id' => $order->id,
                'table_id' => $order->table_id,
                'type' => $order->type,
                'status' => $order->status,
                'terminal_id' => $order->terminal_id,
                'shift_id' => $order->shift_id,
                'opened_at' => $order->opened_at,
            ],
        ]);
    }
}
