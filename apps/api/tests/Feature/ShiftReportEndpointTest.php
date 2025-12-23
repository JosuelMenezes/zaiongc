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

class ShiftReportEndpointTest extends TestCase
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

        $this->getJson("/api/shifts/{$shift->id}/report")
            ->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_report_for_authenticated_user_same_tenant(): void
    {
        [$user, $terminal] = $this->makeTenantWithTerminal();

        Sanctum::actingAs($user);

        $shift = Shift::create([
            'account_id'   => $user->account_id,
            'location_id'  => $user->location_id,
            'terminal_id'  => $terminal->id,
            'opened_by'    => $user->id,
            'opened_at'    => now(),
            'opening_cash' => 0,
            'status'       => 'open',
        ]);

        // Cria order no shift
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

        // Payments confirmados: cash 30 + pix 20
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

        $res = $this->getJson("/api/shifts/{$shift->id}/report");

        $res->assertOk();

        // Estrutura mínima (contrato)
        $res->assertJsonStructure([
            'shift' => [
                'id',
                'terminal_id',
                'status',
                'opened_by',
                'opened_at',
                'opening_cash',
                'closed_by',
                'closed_at',
                'closing_cash',
                'expected_cash',
                'difference',
            ],
            'sales' => [
                'by_method' => ['cash', 'pix', 'card', 'voucher'],
                'total',
            ],
            'cash_movements' => [
                'by_type' => ['cash_in', 'cash_out', 'withdrawal', 'expense'],
            ],
            'audit' => [
                'expected_cash_computed',
                'expected_cash_source',
            ],
            'orders',
        ]);

        // Valores importantes (regra do PDV)
        $res->assertJsonPath('sales.by_method.cash', 30);
        $res->assertJsonPath('sales.by_method.pix', 20);
        $res->assertJsonPath('sales.total', 50);

        // expected_cash computado = opening(0) + cashSales(30)
        $res->assertJsonPath('shift.expected_cash', 30);
        $res->assertJsonPath('audit.expected_cash_source', 'computed');

        // include_orders default = true → deve vir array
        $this->assertIsArray($res->json('orders'));
        $this->assertCount(1, $res->json('orders'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_can_return_light_report_without_orders(): void
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

        $res = $this->getJson("/api/shifts/{$shift->id}/report?include_orders=0");

        $res->assertOk();
        $res->assertJsonPath('shift.expected_cash', 100);
        $res->assertJsonPath('orders', null);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_404_for_shift_outside_tenant(): void
    {
        // Tenant A
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

        // Tenant B
        [$userB] = $this->makeAnotherTenantUserOnly();

        Sanctum::actingAs($userB);

        $this->getJson("/api/shifts/{$shiftA->id}/report")
            ->assertStatus(404);
    }

    /**
     * Cria Account/Location/Terminal/User e ajusta Tenant context para bater com SetTenantContext.
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
     * Cria um tenant diferente apenas com User (para testar 404 cross-tenant).
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
     * Define Tenant::accountId() / Tenant::locationId() para o teste,
     * tentando métodos setter e fallback por reflection (compatível com seu Tenant atual).
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
