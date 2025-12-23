<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function pay(Request $request, Order $order)
    {
        // Tenant guard
        if ($order->account_id !== Tenant::accountId() || $order->location_id !== Tenant::locationId()) {
            abort(404);
        }

        // Bloqueia pagamento em pedido já finalizado
        if (in_array($order->status, ['paid', 'canceled'], true)) {
            abort(409, 'Pedido já finalizado.');
        }

        // Venda fora do caixa: bloquear
        if (!$order->shift_id) {
            abort(409, 'Pedido não está vinculado a um turno de caixa (shift). Abra o caixa e reabra o pedido.');
        }

        $request->validate([
            'client_uid' => 'nullable|string|size:26', // ULID
            'method' => 'required|in:cash,pix,card,voucher',
            'amount' => 'required|numeric|min:0.01',
        ]);

        return DB::transaction(function () use ($request, $order) {
            $accountId = Tenant::accountId();
            $locationId = Tenant::locationId();

            // Lock no pedido (sempre operar no lockedOrder)
            $lockedOrder = Order::where('id', $order->id)
                ->where('account_id', $accountId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedOrder->status, ['paid', 'canceled'], true)) {
                abort(409, 'Pedido já finalizado.');
            }

            if (!$lockedOrder->shift_id) {
                abort(409, 'Pedido não está vinculado a um turno de caixa (shift).');
            }

            // Regra crítica: não pagar pedido de shift fechado
            $shift = Shift::where('id', $lockedOrder->shift_id)
                ->where('account_id', $accountId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            if (!$shift || $shift->status !== 'open') {
                abort(409, 'Turno de caixa está fechado. Abra um novo caixa para registrar pagamentos.');
            }

            // Idempotência por client_uid (offline-first)
            $clientUid = $request->input('client_uid');
            if ($clientUid) {
                $existingPayment = Payment::where('account_id', $accountId)
                    ->where('location_id', $locationId)
                    ->where('client_uid', $clientUid)
                    ->where('order_id', $lockedOrder->id)
                    ->first();

                if ($existingPayment) {
                    // Se o pagamento já existe, apenas reavalia o status do pedido e retorna
                    $paid = $lockedOrder->payments()
                        ->where('status', 'confirmed')
                        ->sum('amount');

                    if ($paid >= $lockedOrder->total && $lockedOrder->status !== 'paid') {
                        $lockedOrder->update([
                            'status' => 'paid',
                            'closed_at' => now(),
                            'closed_by' => Auth::id(),
                        ]);

                        if ($lockedOrder->table_id) {
                            \App\Models\DiningTable::where('id', $lockedOrder->table_id)
                                ->where('account_id', $accountId)
                                ->where('location_id', $locationId)
                                ->update(['status' => 'free']);
                        }
                    }

                    return $lockedOrder->load('payments');
                }
            }

            // Cria pagamento (append-only)
            Payment::create([
                'account_id' => $accountId,
                'location_id' => $locationId,
                'client_uid' => $clientUid, // ULID p/ idempotência
                'order_id' => $lockedOrder->id,
                'method' => $request->method,
                'amount' => $request->amount,
                'status' => 'confirmed',
                'paid_at' => now(),
                'created_by' => Auth::id(),
            ]);

            // Recalcular total pago
            $paid = $lockedOrder->payments()
                ->where('status', 'confirmed')
                ->sum('amount');

            // Fecha pedido quando quitado
            if ($paid >= $lockedOrder->total) {
                $lockedOrder->update([
                    'status' => 'paid',
                    'closed_at' => now(),
                    'closed_by' => Auth::id(),
                ]);

                // liberar mesa (se houver)
                if ($lockedOrder->table_id) {
                    \App\Models\DiningTable::where('id', $lockedOrder->table_id)
                        ->where('account_id', $accountId)
                        ->where('location_id', $locationId)
                        ->update(['status' => 'free']);
                }
            }

            return $lockedOrder->load('payments');
        });
    }
}
