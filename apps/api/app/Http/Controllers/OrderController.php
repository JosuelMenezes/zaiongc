<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OrderController extends Controller
{
    public function open(Request $request)
    {
        $request->validate([
            'client_uid' => 'nullable|string|size:26', // ULID
            'table_id' => 'nullable|exists:dining_tables,id',
            'type' => 'required|in:table,counter,delivery',
            'terminal_id' => 'nullable|exists:terminals,id',
            // shift_id removido: será resolvido automaticamente via terminal
        ]);

        return DB::transaction(function () use ($request) {
            $accountId = Tenant::accountId();
            $locationId = Tenant::locationId();

            $type = $request->type;
            $tableId = $request->table_id;
            $clientUid = $request->input('client_uid');

            // Idempotência por client_uid (offline-first)
            if ($clientUid) {
                $existingByUid = Order::where('account_id', $accountId)
                    ->where('location_id', $locationId)
                    ->where('client_uid', $clientUid)
                    ->first();

                if ($existingByUid) {
                    return $existingByUid->load('items');
                }
            }

            // Regra: para PDV (mesa/balcão), terminal é obrigatório.
            // Para delivery, você pode optar por exigir também; aqui deixei opcional.
            if (in_array($type, ['table', 'counter'], true) && !$request->terminal_id) {
                abort(422, 'terminal_id é obrigatório para pedidos do tipo table/counter.');
            }

            // Se foi informado terminal, garantir que pertence ao tenant.
            if ($request->terminal_id) {
                $terminal = \App\Models\Terminal::where('id', $request->terminal_id)
                    ->where('account_id', $accountId)
                    ->where('location_id', $locationId)
                    ->first();

                if (!$terminal) {
                    abort(404, 'Terminal não encontrado para este estabelecimento.');
                }
            }

            // Se for pedido em mesa: table_id é obrigatório
            if ($type === 'table' && !$tableId) {
                abort(422, 'table_id é obrigatório para pedidos do tipo table.');
            }

            // Se já existir pedido aberto para a mesa, retorna (evita duplicidade)
            if ($type === 'table') {
                $existing = Order::where('account_id', $accountId)
                    ->where('location_id', $locationId)
                    ->where('table_id', $tableId)
                    ->whereIn('status', ['open', 'sent'])
                    ->first();

                if ($existing) {
                    return $existing->load('items');
                }
            }

            // Resolver shift automaticamente via terminal (quando houver terminal_id)
            $shiftId = null;

            if ($request->terminal_id) {
                $shift = \App\Models\Shift::where('account_id', $accountId)
                    ->where('location_id', $locationId)
                    ->where('terminal_id', $request->terminal_id)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();

                if (!$shift) {
                    abort(409, 'Não existe caixa aberto para este terminal. Abra o caixa antes de lançar pedidos.');
                }

                $shiftId = $shift->id;
            }

            // Para pedido em mesa: validar status e travar a mesa
            if ($type === 'table') {
                $table = \App\Models\DiningTable::where('id', $tableId)
                    ->where('account_id', $accountId)
                    ->where('location_id', $locationId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($table->status !== 'free') {
                    abort(409, 'Mesa não está livre.');
                }

                $table->update(['status' => 'occupied']);
            }

            return Order::create([
                'account_id' => $accountId,
                'location_id' => $locationId,
                'client_uid' => $clientUid, // ULID p/ idempotência
                'table_id' => $tableId,
                'type' => $type,
                'terminal_id' => $request->terminal_id,
                'shift_id' => $shiftId,
                'opened_by' => Auth::id(),
                'status' => 'open',
                'opened_at' => now(),
            ]);
        });
    }

    public function addItem(Request $request, Order $order)
    {
        $this->assertTenant($order);

        if (in_array($order->status, ['paid', 'canceled'], true)) {
            abort(409, 'Pedido já finalizado.');
        }

        $request->validate([
            'client_uid' => 'nullable|string|size:26', // ULID
            'name' => 'required|string|max:120',
            'quantity' => 'required|numeric|min:0.001',
            'unit_price' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($request, $order) {
            $clientUid = $request->input('client_uid');

            // Idempotência do item (offline-first)
            if ($clientUid) {
                $existingItem = OrderItem::where('account_id', Tenant::accountId())
                    ->where('location_id', Tenant::locationId())
                    ->where('client_uid', $clientUid)
                    ->where('order_id', $order->id)
                    ->first();

                if ($existingItem) {
                    $this->recalc($order);
                    return $existingItem;
                }
            }

            $itemTotal = round($request->quantity * $request->unit_price, 2);

            $item = OrderItem::create([
                'account_id' => Tenant::accountId(),
                'location_id' => Tenant::locationId(),
                'client_uid' => $clientUid, // ULID p/ idempotência
                'order_id' => $order->id,
                'name' => $request->name,
                'quantity' => $request->quantity,
                'unit_price' => $request->unit_price,
                'total' => $itemTotal,
                'status' => 'pending',
                'notes' => $request->notes,
                'created_by' => Auth::id(),
            ]);

            $this->recalc($order);

            return $item;
        });
    }

    public function show(Order $order)
    {
        $this->assertTenant($order);

        return $order->load(['items', 'payments']);
    }

    public function cancelItem(Request $request, Order $order, OrderItem $item)
    {
        $this->assertTenant($order);

        if ($item->order_id !== $order->id) {
            abort(404);
        }

        // Permissão (dev): se você ainda não configurou Gate, comente e use hasRole('admin')
        Gate::authorize('order.cancel_item');

        $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        if ($item->status === 'done') {
            abort(409, 'Item já finalizado, não pode ser cancelado.');
        }

        return DB::transaction(function () use ($request, $order, $item) {
            $item->update([
                'status' => 'canceled',
                'canceled_by' => Auth::id(),
                'cancel_reason' => $request->reason,
            ]);

            $this->recalc($order);

            return $order->load('items');
        });
    }

    private function recalc(Order $order): void
    {
        $subtotal = $order->items()
            ->where('status', '!=', 'canceled')
            ->sum('total');

        $order->update([
            'subtotal' => $subtotal,
            'total' => $subtotal - $order->discount + $order->service_fee,
        ]);
    }

    private function assertTenant(Order $order): void
    {
        if ($order->account_id !== Tenant::accountId() || $order->location_id !== Tenant::locationId()) {
            abort(404);
        }
    }
}
