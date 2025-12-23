<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Location;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\User;
use App\Services\ShiftReportService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ShiftReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ShiftReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ShiftReportService::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
public function it_reports_only_opening_cash_when_no_orders_and_no_movements(): void

    {
        [$user, $terminal] = $this->makeTenantWithTerminal();

        $shift = Shift::create([
            'account_id'   => $user->account_id,
            'location_id'  => $user->location_id,
            'terminal_id'  => $terminal->id,
            'opened_by'    => $user->id,
            'opened_at'    => now(),
            'opening_cash' => 100,
            'status'       => 'open',
        ]);

        $report = $this->service->generate($shift, false);

        $this->assertSame(100.0, (float) $report['shift']['opening_cash']);
        $this->assertSame(100.0, (float) $report['shift']['expected_cash']);
        $this->assertNull($report['shift']['difference']);

        $this->assertSame(0.0, (float) $report['sales']['total']);
        $this->assertSame(0.0, (float) $report['sales']['by_method']['cash']);
        $this->assertSame(0.0, (float) $report['sales']['by_method']['pix']);

        $this->assertSame(0.0, (float) $report['cash_movements']['by_type']['cash_in']);
        $this->assertSame(0.0, (float) $report['cash_movements']['by_type']['withdrawal']);

        // include_orders=0 -> orders deve ser null (conforme sua implementação atual)
        $this->assertNull($report['orders']);

        // auditoria
        $this->assertSame(100.0, (float) $report['audit']['expected_cash_computed']);
        $this->assertSame('computed', $report['audit']['expected_cash_source']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
public function it_reports_sales_by_method_and_total_sales(): void

    {
        [$user, $terminal] = $this->makeTenantWithTerminal();

        $shift = Shift::create([
            'account_id'   => $user->account_id,
            'location_id'  => $user->location_id,
            'terminal_id'  => $terminal->id,
            'opened_by'    => $user->id,
            'opened_at'    => now(),
            'opening_cash' => 0,
            'status'       => 'open',
        ]);

        // cria order no shift
        $orderId = DB::table('orders')->insertGetId([
            'account_id'  => $user->account_id,
            'location_id' => $user->location_id,
            'terminal_id' => $terminal->id,
            'shift_id'    => $shift->id,
            'opened_by'   => $user->id,
            'type'        => 'counter',
            'status'      => 'paid',
            'subtotal'    => 50,
            'discount'    => 0,
            'service_fee' => 0,
            'total'       => 50,
            'opened_at'   => now(),
            'closed_at'   => now(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // payments confirmados: cash 30 + pix 20
        DB::table('payments')->insert([
            [
                'account_id'  => $user->account_id,
                'location_id' => $user->location_id,
                'order_id'    => $orderId,
                'method'      => 'cash',
                'amount'      => 30,
                'status'      => 'confirmed',
                'paid_at'     => now(),
                'created_by'  => $user->id,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'account_id'  => $user->account_id,
                'location_id' => $user->location_id,
                'order_id'    => $orderId,
                'method'      => 'pix',
                'amount'      => 20,
                'status'      => 'confirmed',
                'paid_at'     => now(),
                'created_by'  => $user->id,
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);

        $report = $this->service->generate($shift, true);

        $this->assertSame(50.0, (float) $report['sales']['total']);
        $this->assertSame(30.0, (float) $report['sales']['by_method']['cash']);
        $this->assertSame(20.0, (float) $report['sales']['by_method']['pix']);

        // expected_cash computado = opening(0) + cashSales(30)
        $this->assertSame(30.0, (float) $report['shift']['expected_cash']);
        $this->assertSame('computed', $report['audit']['expected_cash_source']);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $report['orders']);
$this->assertCount(1, $report['orders']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
public function it_reports_expected_cash_with_movements(): void
    {
        [$user, $terminal] = $this->makeTenantWithTerminal();

        $shift = Shift::create([
            'account_id'   => $user->account_id,
            'location_id'  => $user->location_id,
            'terminal_id'  => $terminal->id,
            'opened_by'    => $user->id,
            'opened_at'    => now(),
            'opening_cash' => 100,
            'status'       => 'open',
        ]);

        // cash_movements: cash_in 50, cash_out 10, withdrawal 20, expense 5
        DB::table('cash_movements')->insert([
            [
                'account_id'  => $user->account_id,
                'location_id' => $user->location_id,
                'shift_id'    => $shift->id,
                'created_by'  => $user->id,
                'type'        => 'cash_in',
                'amount'      => 50,
                'reason'      => 'reforço',
                'occurred_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'account_id'  => $user->account_id,
                'location_id' => $user->location_id,
                'shift_id'    => $shift->id,
                'created_by'  => $user->id,
                'type'        => 'cash_out',
                'amount'      => 10,
                'reason'      => 'retirada',
                'occurred_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'account_id'  => $user->account_id,
                'location_id' => $user->location_id,
                'shift_id'    => $shift->id,
                'created_by'  => $user->id,
                'type'        => 'withdrawal',
                'amount'      => 20,
                'reason'      => 'sangria',
                'occurred_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'account_id'  => $user->account_id,
                'location_id' => $user->location_id,
                'shift_id'    => $shift->id,
                'created_by'  => $user->id,
                'type'        => 'expense',
                'amount'      => 5,
                'reason'      => 'despesa',
                'occurred_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);

        // expected = 100 + 0 + 50 - 10 - 20 - 5 = 115
        $report = $this->service->generate($shift, false);

        $this->assertSame(115.0, (float) $report['shift']['expected_cash']);
        $this->assertSame(115.0, (float) $report['audit']['expected_cash_computed']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
public function it_uses_snapshot_when_shift_is_closed(): void
    {
        [$user, $terminal] = $this->makeTenantWithTerminal();

        $shift = Shift::create([
            'account_id'   => $user->account_id,
            'location_id'  => $user->location_id,
            'terminal_id'  => $terminal->id,
            'opened_by'    => $user->id,
            'opened_at'    => now(),
            'opening_cash' => 100,
            'status'       => 'closed',
            'closing_cash' => 80,
            'expected_cash'=> 999,   // snapshot propositalmente diferente do computado
            'difference'   => -1,
            'closed_at'    => now(),
            'closed_by'    => $user->id,
        ]);

        $report = $this->service->generate($shift, false);

        $this->assertSame('snapshot', $report['audit']['expected_cash_source']);
        $this->assertSame(999.0, (float) $report['shift']['expected_cash']);
        $this->assertSame(-1.0, (float) $report['shift']['difference']);

        // computado ainda deve bater com opening (100), sem movimentos/vendas
        $this->assertSame(100.0, (float) $report['audit']['expected_cash_computed']);
    }

    /**
     * Cria Account/Location/Terminal/User e seta o Tenant context para os testes.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\Terminal}
     */
    private function makeTenantWithTerminal(): array
    {
        // Ajuste se seus models tiverem campos diferentes/obrigatórios.
        $account = Account::create([
    'name' => 'Account Test',
    'slug' => 'acc-test-' . uniqid(),
]);

        $location = Location::create([
            'account_id' => $account->id,
            'name' => 'Location Test',
        ]);

       $terminal = Terminal::create([
    'account_id' => $account->id,
    'location_id' => $location->id,
    'name' => 'Terminal Test',
    'code' => 'TST-01',
]);

        $user = User::create([
            'account_id' => $account->id,
            'location_id' => $location->id,
            'name' => 'Admin Test',
            'email' => 'admin+'.uniqid().'@zaiongc.test',
            'password' => Hash::make('password'),
        ]);

        $this->setTenantContext($account->id, $location->id);

        return [$user, $terminal];
    }

    /**
     * Define Tenant::accountId() / Tenant::locationId() para o teste,
     * tentando os métodos mais comuns e fallback via reflection.
     */
    private function setTenantContext(int $accountId, int $locationId): void
    {
        // Caso seu Tenant tenha métodos setter (ideal)
        if (method_exists(Tenant::class, 'set')) {
            Tenant::set($accountId, $locationId);
            return;
        }

        if (method_exists(Tenant::class, 'setContext')) {
            Tenant::setContext($accountId, $locationId);
            return;
        }

        // Fallback: tenta setar propriedades estáticas comuns via Reflection
        $ref = new \ReflectionClass(Tenant::class);

        foreach (['accountId', 'account_id'] as $prop) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue(null, $accountId);
            }
        }

        foreach (['locationId', 'location_id'] as $prop) {
            if ($ref->hasProperty($prop)) {
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue(null, $locationId);
            }
        }
    }
}
