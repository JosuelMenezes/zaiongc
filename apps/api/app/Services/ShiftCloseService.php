<?php

namespace App\Services;

use App\Events\ShiftClosed;
use App\Models\Shift;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

class ShiftCloseService
{
    /**
     * Fecha um turno de caixa (shift) com snapshot auditável.
     *
     * Regras:
     * - Tenant guard (404 cross-tenant) via Tenant Context
     * - Só fecha se status=open
     * - Não fecha se houver pedidos em aberto
     * - expected_cash = opening_cash + cashSales + cash_in - withdrawals
     * - difference = closing_cash - expected_cash
     *
     * @return array<string,mixed>
     */
    public function close(Shift $shift, User $user, float $closingCash): array
    {
        $closingCash = round((float) $closingCash, 2);

        return DB::transaction(function () use ($shift, $user, $closingCash) {

            // Lock no shift para evitar fechamento simultâneo
            $shift = Shift::query()
                ->whereKey($shift->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Tenant guard (source of truth = Tenant Context)
            $accountId  = Tenant::accountId();
            $locationId = Tenant::locationId();

            if (!$accountId || !$locationId) {
                // Se isso acontecer, normalmente indica middleware Tenant não aplicado
                abort(500, 'Tenant context não definido.');
            }

            if ($shift->account_id !== $accountId || $shift->location_id !== $locationId) {
                abort(404);
            }

            if ($shift->status !== 'open') {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'Shift já está fechado ou em estado inválido.',
                    'data' => [
                        'shift_id' => $shift->id,
                        'status' => $shift->status,
                    ],
                ];
            }

            // 1) Bloqueia fechamento com pedidos em aberto no turno
            $hasOpenOrders = DB::table('orders')
                ->where('account_id', $shift->account_id)
                ->where('location_id', $shift->location_id)
                ->where('shift_id', $shift->id)
                ->where('status', 'open')
                ->exists();

            if ($hasOpenOrders) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'Não é possível fechar o caixa com pedidos em aberto neste turno.',
                    'data' => [
                        'shift_id' => $shift->id,
                    ],
                ];
            }

            // 2) Vendas em dinheiro (payments -> orders)
            $cashSales = (float) DB::table('payments')
                ->join('orders', 'orders.id', '=', 'payments.order_id')
                ->where('orders.shift_id', $shift->id)
                ->where('orders.account_id', $shift->account_id)
                ->where('orders.location_id', $shift->location_id)
                ->where('payments.method', 'cash')
                ->where('payments.status', 'confirmed')
                ->sum('payments.amount');

            // 3) Movimentações do caixa no turno
            $cashIn = (float) DB::table('cash_movements')
                ->where('account_id', $shift->account_id)
                ->where('location_id', $shift->location_id)
                ->where('shift_id', $shift->id)
                ->where('type', 'cash_in')
                ->sum('amount');

            $withdrawals = (float) DB::table('cash_movements')
                ->where('account_id', $shift->account_id)
                ->where('location_id', $shift->location_id)
                ->where('shift_id', $shift->id)
                ->where('type', 'withdrawal')
                ->sum('amount');

            $openingCash = (float) $shift->opening_cash;

            $expectedCash = round($openingCash + $cashSales + $cashIn - $withdrawals, 2);
            $difference   = round($closingCash - $expectedCash, 2);

            // 4) Fecha o shift e grava snapshot
            $shift->update([
                'status' => 'closed',
                'closing_cash' => $closingCash,
                'expected_cash' => $expectedCash,
                'difference' => $difference,
                'closed_at' => now(),
                'closed_by' => $user->id,
            ]);

            // 5) Evento (Opção B): agenda ações pós-fechamento (ex.: PrintJob)
            event(new ShiftClosed(
                shiftId: $shift->id,
                accountId: $shift->account_id,
                locationId: $shift->location_id,
                closedByUserId: $user->id
            ));

            return [
                'ok' => true,
                'status' => 200,
                'message' => 'Caixa fechado com sucesso.',
                'data' => [
                    'shift_id' => $shift->id,
                    'terminal_id' => $shift->terminal_id,
                    'status' => $shift->status,
                    'opening_cash' => (float) $shift->opening_cash,
                    'expected_cash' => (float) $shift->expected_cash,
                    'closing_cash' => (float) $shift->closing_cash,
                    'difference' => (float) $shift->difference,
                    'closed_at' => optional($shift->closed_at)->toISOString(),
                ],
            ];
        });
    }
}
