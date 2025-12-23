<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Location;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShiftCloseEndpointTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_requires_authentication(): void
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

        $this->postJson("/api/shifts/{$shift->id}/close", ['closing_cash' => 80])
            ->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_404_for_shift_outside_tenant(): void
    {
        // Tenant A cria o shift
        [$userA, $terminalA] = $this->makeTenantWithTerminal();

        $shiftA = Shift::create([
            'account_id'   => $userA->account_id,
            'location_id'  => $userA->location_id,
            'terminal_id'  => $terminalA->id,
            'opened_by'    => $userA->id,
            'opened_at'    => now(),
            'opening_cash' => 100,
            'status'       => 'open',
        ]);

        // Tenant B tenta fechar
        [$userB] = $this->makeAnotherTenantUserOnly();

        Sanctum::actingAs($userB);

        $this->postJson("/api/shifts/{$shiftA->id}/close", ['closing_cash' => 80])
            ->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_close_when_there_are_open_orders_in_shift(): void
    {
        [$user, $terminal] = $this->makeTenantWithTerminal();
        Sanctum::actingAs($user);

        $shift = Shift::create([
            'account_id'   => $user->account_id,
            'location_id'  => $user->location_id,
            'terminal_id'  => $terminal->id,
            'opened_by'    => $user->id,
            'opened_at'    => now(),
            'opening_cash' => 100,
            'status'       => 'open',
        ]);

        // Pedido em aberto no shift => deve bloquear
        DB::table('orders')->insert([
            'account_id'  => $user->account_id,
            'location_id' => $user->location_id,
            'terminal_id' => $terminal->id,
            'shift_id'    => $shift->id,
            'opened_by'   => $user->id,
            'type'        => 'counter',
            'status'      => 'open',
            'subtotal'    => 20,
            'discount'    => 0,
            'service_fee' => 0,
            'total'       => 20,
            'opened_at'   => now(),
            'closed_at'   => null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $res = $this->postJson("/api/shifts/{$shift->id}/close", ['closing_cash' => 80]);

        $res->assertStatus(422);
        $this->assertSame('open', DB::table('shifts')->where('id', $shift->id)->value('status'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_closes_shift_and_persists_snapshot_correctly(): void
    {
        [$user, $terminal] = $this->makeTenantWithTerminal();
        Sanctum::actingAs($user);

        $shift = Shift::create([
            'account_id'   => $user->account_id,
            'location_id'  => $user->location_id,
            'terminal_id'  => $terminal->id,
            'opened_by'    => $user->id,
            'opened_at'    => now(),
            'opening_cash' => 100,
            'status'       => 'open',
        ]);

        // Cria um pedido pago no shift
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

        // Payments confirmados:
        // - cash 30 (entra no expected cash)
        // - pix 20 (não entra no expected cash do caixa físico)
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

        // Movimentações:
        // cash_in 50 (entra)
        // withdrawal 20 (sai)
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
                'type'        => 'withdrawal',
                'amount'      => 20,
                'reason'      => 'sangria',
                'occurred_at' => now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ]);

        // expected_cash (regra do close atual):
        // opening(100) + cashSales(30) + cash_in(50) - withdrawals(20) = 160
        // closing_cash = 150 => difference = -10
        $res = $this->postJson("/api/shifts/{$shift->id}/close", ['closing_cash' => 150]);

        $res->assertOk();

        $res->assertJsonStructure([
            'message',
            'data' => [
                'shift_id',
                'terminal_id',
                'status',
                'opening_cash',
                'expected_cash',
                'closing_cash',
                'difference',
                'closed_at',
            ],
        ]);

        $res->assertJsonPath('data.status', 'closed');
        $res->assertJsonPath('data.expected_cash', 160);
        $res->assertJsonPath('data.closing_cash', 150);
        $res->assertJsonPath('data.difference', -10);

        // Persistência
        $row = DB::table('shifts')->where('id', $shift->id)->first();

        $this->assertSame('closed', $row->status);
        $this->assertSame(150.0, (float) $row->closing_cash);
        $this->assertSame(160.0, (float) $row->expected_cash);
        $this->assertSame(-10.0, (float) $row->difference);
        $this->assertNotNull($row->closed_at);
        $this->assertSame($user->id, (int) $row->closed_by);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_422_when_shift_is_already_closed(): void
    {
        [$user, $terminal] = $this->makeTenantWithTerminal();
        Sanctum::actingAs($user);

        $shift = Shift::create([
            'account_id'   => $user->account_id,
            'location_id'  => $user->location_id,
            'terminal_id'  => $terminal->id,
            'opened_by'    => $user->id,
            'opened_at'    => now(),
            'opening_cash' => 100,
            'status'       => 'closed',
            'closing_cash' => 80,
            'expected_cash'=> 100,
            'difference'   => -20,
            'closed_at'    => now(),
            'closed_by'    => $user->id,
        ]);

        $this->postJson("/api/shifts/{$shift->id}/close", ['closing_cash' => 90])
            ->assertStatus(422);
    }

    /**
     * Seed mínimo do tenant + terminal.
     *
     * @return array{0: \App\Models\User, 1: \App\Models\Terminal}
     */
    private function makeTenantWithTerminal(): array
    {
        $account = Account::create([
            'name' => 'Account Test',
            'slug' => 'acc-test-'.uniqid(),
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
     * Cria outro tenant para teste cross-tenant.
     *
     * @return array{0: \App\Models\User}
     */
    private function makeAnotherTenantUserOnly(): array
    {
        $account = Account::create([
            'name' => 'Account B',
            'slug' => 'acc-b-'.uniqid(),
        ]);

        $location = Location::create([
            'account_id' => $account->id,
            'name' => 'Location B',
        ]);

        $user = User::create([
            'account_id' => $account->id,
            'location_id' => $location->id,
            'name' => 'User B',
            'email' => 'userb+'.uniqid().'@zaiongc.test',
            'password' => Hash::make('password'),
        ]);

        $this->setTenantContext($account->id, $location->id);

        return [$user];
    }

    /**
     * Define Tenant context para o ShiftReportService/ShiftController tenant guard.
     */
    private function setTenantContext(int $accountId, int $locationId): void
    {
        if (method_exists(Tenant::class, 'set')) {
            Tenant::set($accountId, $locationId);
            return;
        }

        if (method_exists(Tenant::class, 'setContext')) {
            Tenant::setContext($accountId, $locationId);
            return;
        }

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
