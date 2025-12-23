<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Location;
use App\Models\PrintJob;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrintJobOnShiftCloseTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_creates_print_job_when_shift_is_closed(): void
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

        $this->postJson("/api/shifts/{$shift->id}/close", ['closing_cash' => 100])
            ->assertOk();

        $job = PrintJob::query()
            ->where('account_id', $user->account_id)
            ->where('location_id', $user->location_id)
            ->where('type', 'shift_report')
            ->latest('id')
            ->first();

        $this->assertNotNull($job);
        $this->assertSame('pending', $job->status);
        $this->assertSame($shift->id, (int)($job->payload['shift_id'] ?? 0));
        $this->assertSame(1, (int)($job->payload['copies'] ?? 0));
        $this->assertSame($user->id, (int)($job->payload['requested_by'] ?? 0));
    }

    /**
     * @return array{0:\App\Models\User, 1:\App\Models\Terminal}
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
