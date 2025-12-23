<?php

namespace App\Http\Controllers;

use App\Http\Requests\CloseShiftRequest;
use App\Models\CashMovement;
use App\Models\Shift;
use App\Models\Terminal;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\ShiftReportService;
use App\Services\ShiftCloseService;

class ShiftController extends Controller
{
    public function open(Request $request)
    {
        $request->validate([
            'terminal_id'   => 'required|exists:terminals,id',
            'opening_cash'  => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request) {
            $accountId  = Tenant::accountId();
            $locationId = Tenant::locationId();

            $terminal = Terminal::where('id', $request->terminal_id)
                ->where('account_id', $accountId)
                ->where('location_id', $locationId)
                ->firstOrFail();

            $alreadyOpen = Shift::where('account_id', $accountId)
                ->where('location_id', $locationId)
                ->where('terminal_id', $terminal->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($alreadyOpen) {
                return $alreadyOpen;
            }

            return Shift::create([
                'account_id'    => $accountId,
                'location_id'   => $locationId,
                'terminal_id'   => $terminal->id,
                'opened_by'     => Auth::id(),
                'status'        => 'open',
                'opened_at'     => now(),
                'opening_cash'  => $request->opening_cash ?? 0,
            ]);
        });
    }

    /**
     * Retorna o shift aberto atual.
     * Importante para PDV multi-terminal: filtrar por terminal_id quando informado.
     * GET /api/shifts/current?terminal_id=1
     */
    public function current(Request $request)
    {
        $query = Shift::where('account_id', Tenant::accountId())
            ->where('location_id', Tenant::locationId())
            ->where('status', 'open');

        if ($request->filled('terminal_id')) {
            $terminalId = (int) $request->query('terminal_id');
            $query->where('terminal_id', $terminalId);
        }

        $shift = $query->orderByDesc('id')->first();

        return response()->json($shift);
    }

    public function movement(Request $request, Shift $shift)
    {
        $this->assertTenantShift($shift);

        if ($shift->status !== 'open') {
            abort(409, 'Turno já está fechado.');
        }

        $request->validate([
            'type'   => 'required|in:cash_in,cash_out,withdrawal,expense',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
        ]);

        $mov = CashMovement::create([
            'account_id'  => Tenant::accountId(),
            'location_id' => Tenant::locationId(),
            'shift_id'    => $shift->id,
            'created_by'  => Auth::id(),
            'type'        => $request->type,
            'amount'      => $request->amount,
            'reason'      => $request->reason,
            'occurred_at' => now(),
        ]);

        return response()->json($mov, 201);
    }

    public function close(CloseShiftRequest $request, Shift $shift): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $closingCash = (float) $request->input('closing_cash');

        /** @var ShiftCloseService $service */
        $service = app(ShiftCloseService::class);

        $result = $service->close($shift, $user, $closingCash);

        return response()->json(
            [
                'message' => $result['message'],
                'data' => $result['data'] ?? null,
            ],
            $result['status'] ?? 200
        );
    }

    public function report(Request $request, Shift $shift, ShiftReportService $service)
    {
        // include_orders=0 para relatórios leves
        $includeOrders = $request->boolean('include_orders', true);

        $data = $service->generate($shift, $includeOrders);

        return response()->json($data);
    }

    public function summary(Shift $shift)
    {
        $this->assertTenantShift($shift);

        $accountId  = Tenant::accountId();
        $locationId = Tenant::locationId();

        // Payments por método NO turno (join via orders)
        $salesByMethod = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.account_id', $accountId)
            ->where('orders.location_id', $locationId)
            ->where('orders.shift_id', $shift->id)
            ->where('payments.status', 'confirmed')
            ->selectRaw('payments.method, SUM(payments.amount) as total')
            ->groupBy('payments.method')
            ->pluck('total', 'payments.method');

        $movements = CashMovement::where('account_id', $accountId)
            ->where('location_id', $locationId)
            ->where('shift_id', $shift->id)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $opening = (float) $shift->opening_cash;

        $cashSales   = (float) ($salesByMethod['cash'] ?? 0);
        $cashIn      = (float) ($movements['cash_in'] ?? 0);
        $cashOut     = (float) ($movements['cash_out'] ?? 0);
        $withdrawal  = (float) ($movements['withdrawal'] ?? 0);
        $expense     = (float) ($movements['expense'] ?? 0);

        $expectedCash = $opening + $cashSales + $cashIn - $cashOut - $withdrawal - $expense;

        return response()->json([
            'shift' => $shift,
            'sales_by_method' => $salesByMethod,
            'movements' => $movements,
            'expected_cash' => round($expectedCash, 2),
        ]);
    }

    private function assertTenantShift(Shift $shift): void
    {
        if ($shift->account_id !== Tenant::accountId() || $shift->location_id !== Tenant::locationId()) {
            abort(404);
        }
    }
}
