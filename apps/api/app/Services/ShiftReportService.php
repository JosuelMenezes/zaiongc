<?php

namespace App\Services;

use App\Models\Shift;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

class ShiftReportService
{
    /**
     * Gera relatório completo do turno (shift), com agregações e (opcionalmente) lista de pedidos.
     *
     * @return array<string, mixed>
     */
    public function generate(Shift $shift, bool $includeOrders = true): array
    {
        $this->assertTenant($shift);

        $accountId  = $shift->account_id;
        $locationId = $shift->location_id;

        // 1) Vendas por método (JOIN payments -> orders, pois payments não tem shift_id)
        $salesByMethod = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.account_id', $accountId)
            ->where('orders.location_id', $locationId)
            ->where('orders.shift_id', $shift->id)
            ->where('payments.status', 'confirmed')
            ->selectRaw('payments.method, SUM(payments.amount) as total')
            ->groupBy('payments.method')
            ->pluck('total', 'payments.method');

        // Normalização (garantir que sempre existam as chaves)
        $salesByMethod = $this->normalizeMoneyMap($salesByMethod, ['cash', 'pix', 'card', 'voucher']);

        $totalSales = $this->sumMoneyMap($salesByMethod);

        // 2) Movimentações por tipo
        $movements = DB::table('cash_movements')
            ->where('account_id', $accountId)
            ->where('location_id', $locationId)
            ->where('shift_id', $shift->id)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $movements = $this->normalizeMoneyMap($movements, ['cash_in', 'cash_out', 'withdrawal', 'expense']);

        // 3) Regras do caixa esperado (mesma regra do close/summary)
        $openingCash = (float) $shift->opening_cash;

        $cashSales   = (float) $salesByMethod['cash'];
        $cashIn      = (float) $movements['cash_in'];
        $cashOut     = (float) $movements['cash_out'];
        $withdrawal  = (float) $movements['withdrawal'];
        $expense     = (float) $movements['expense'];

        $expectedCashComputed = round($openingCash + $cashSales + $cashIn - $cashOut - $withdrawal - $expense, 2);

        // 4) Snapshot persistido no shift (se fechado, deve existir)
        // Preferimos o snapshot quando existir; caso contrário usamos o computado (turno aberto ou legado).
        $expectedCash = $shift->expected_cash !== null ? (float) $shift->expected_cash : $expectedCashComputed;
        $difference   = $shift->difference !== null ? (float) $shift->difference : (
            $shift->closing_cash !== null ? round(((float) $shift->closing_cash) - $expectedCash, 2) : null
        );

        // 5) Pedidos do turno (opcional)
        $orders = null;
        if ($includeOrders) {
            $orders = DB::table('orders')
                ->where('account_id', $accountId)
                ->where('location_id', $locationId)
                ->where('shift_id', $shift->id)
                ->select('id', 'type', 'status', 'subtotal', 'discount', 'service_fee', 'total', 'opened_at', 'closed_at', 'table_id', 'terminal_id')
                ->orderBy('id')
                ->get();
        }

        return [
            'shift' => [
                'id' => $shift->id,
                'terminal_id' => $shift->terminal_id,
                'status' => $shift->status,
                'opened_by' => $shift->opened_by,
                'opened_at' => optional($shift->opened_at)->toISOString(),
                'opening_cash' => round((float) $shift->opening_cash, 2),

                'closed_by' => $shift->closed_by,
                'closed_at' => optional($shift->closed_at)->toISOString(),
                'closing_cash' => $shift->closing_cash !== null ? round((float) $shift->closing_cash, 2) : null,

                // snapshot (se existir) + fallback computado
                'expected_cash' => round((float) $expectedCash, 2),
                'difference' => $difference !== null ? round((float) $difference, 2) : null,
            ],

            'sales' => [
                'by_method' => [
                    'cash' => round((float) $salesByMethod['cash'], 2),
                    'pix' => round((float) $salesByMethod['pix'], 2),
                    'card' => round((float) $salesByMethod['card'], 2),
                    'voucher' => round((float) $salesByMethod['voucher'], 2),
                ],
                'total' => round((float) $totalSales, 2),
            ],

            'cash_movements' => [
                'by_type' => [
                    'cash_in' => round((float) $movements['cash_in'], 2),
                    'cash_out' => round((float) $movements['cash_out'], 2),
                    'withdrawal' => round((float) $movements['withdrawal'], 2),
                    'expense' => round((float) $movements['expense'], 2),
                ],
            ],

            // este campo é útil para auditoria: mostra o "recomputado" vs o snapshot
            'audit' => [
                'expected_cash_computed' => $expectedCashComputed,
                'expected_cash_source' => $shift->expected_cash !== null ? 'snapshot' : 'computed',
            ],

            'orders' => $orders,
        ];
    }

    private function assertTenant(Shift $shift): void
    {
        if ($shift->account_id !== Tenant::accountId() || $shift->location_id !== Tenant::locationId()) {
            abort(404);
        }
    }

    /**
     * @param \Illuminate\Support\Collection|\Illuminate\Support\Enumerable|array $map
     * @param array<int,string> $keys
     * @return array<string,float>
     */
    private function normalizeMoneyMap($map, array $keys): array
    {
        $arr = is_array($map) ? $map : $map->toArray();

        $out = [];
        foreach ($keys as $k) {
            $out[$k] = isset($arr[$k]) ? (float) $arr[$k] : 0.0;
        }
        return $out;
    }

    /**
     * @param array<string,float> $map
     */
    private function sumMoneyMap(array $map): float
    {
        $sum = 0.0;
        foreach ($map as $v) {
            $sum += (float) $v;
        }
        return $sum;
    }
}
