<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Location;
use App\Models\PrintJob;
use App\Models\Terminal;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrintJobsProcessFlowTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_claims_a_pending_job_and_allows_ack_sent(): void
    {
        [$user] = $this->makeTenantUserOnly();
        Sanctum::actingAs($user);

        $job = PrintJob::create([
            'account_id' => $user->account_id,
            'location_id' => $user->location_id,
            'type' => 'shift_report',
            'payload' => ['shift_id' => 1, 'copies' => 1],
            'status' => 'pending',
            'available_at' => now(),
        ]);

        // claim
        $res = $this->postJson('/api/print-jobs/claim', [
            'claimed_by' => 'pdv-01',
            'type' => 'shift_report',
        ]);

        $res->assertOk();
        $claimedId = $res->json('job.id');
        $this->assertSame($job->id, $claimedId);

        $res->assertJsonPath('job.status', 'processing');
        $res->assertJsonPath('job.claimed_by', 'pdv-01');

        // ack sent
        $ack = $this->postJson("/api/print-jobs/{$job->id}/ack", [
            'status' => 'sent',
        ]);

        $ack->assertOk();
        $ack->assertJsonPath('job.status', 'sent');
        $this->assertNotNull($ack->json('job.sent_at'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_ack_failed_increments_attempts_and_sets_last_error(): void
    {
        [$user] = $this->makeTenantUserOnly();
        Sanctum::actingAs($user);

        $job = PrintJob::create([
            'account_id' => $user->account_id,
            'location_id' => $user->location_id,
            'type' => 'shift_report',
            'payload' => ['shift_id' => 1],
            'status' => 'pending',
            'available_at' => now(),
            'attempts' => 0,
        ]);

        // claim
        $this->postJson('/api/print-jobs/claim', [
            'claimed_by' => 'pdv-02',
        ])->assertOk();

        // ack failed
        $ack = $this->postJson("/api/print-jobs/{$job->id}/ack", [
            'status' => 'failed',
            'error' => 'Impressora offline',
        ]);

        $ack->assertOk();
        $ack->assertJsonPath('job.status', 'failed');
        $ack->assertJsonPath('job.attempts', 1);
        $ack->assertJsonPath('job.last_error', 'Impressora offline');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_allow_cross_tenant_ack(): void
    {
        [$userA] = $this->makeTenantUserOnly();
        $job = PrintJob::create([
            'account_id' => $userA->account_id,
            'location_id' => $userA->location_id,
            'type' => 'shift_report',
            'payload' => ['shift_id' => 1],
            'status' => 'processing',
            'available_at' => now(),
        ]);

        // outro tenant
        [$userB] = $this->makeAnotherTenantUserOnly();
        Sanctum::actingAs($userB);

        $this->postJson("/api/print-jobs/{$job->id}/ack", [
            'status' => 'sent',
        ])->assertStatus(404);
    }

    /**
     * @return array{0:\App\Models\User}
     */
    private function makeTenantUserOnly(): array
    {
        $account = Account::create([
            'name' => 'Account Test',
            'slug' => 'acc-test-'.uniqid(),
        ]);

        $location = Location::create([
            'account_id' => $account->id,
            'name' => 'Location Test',
        ]);

        // terminal não é necessário aqui, mas manter seed mínimo ok
        Terminal::create([
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

        return [$user];
    }

    /**
     * @return array{0:\App\Models\User}
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
