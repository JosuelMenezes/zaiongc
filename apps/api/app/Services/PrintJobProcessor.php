<?php

namespace App\Services;

use App\Models\PrintJob;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

class PrintJobProcessor
{
    /**
     * Claim concorrente seguro de um PrintJob pendente.
     *
     * Estratégia:
     * - filtra por tenant
     * - status=pending
     * - available_at <= now OR null
     * - lockForUpdate e update status=processing + claimed_at/by
     */
    public function claimNext(?string $type, string $claimedBy): ?PrintJob
    {
        $accountId  = Tenant::accountId();
        $locationId = Tenant::locationId();

        if (!$accountId || !$locationId) {
            abort(500, 'Tenant context não definido.');
        }

        return DB::transaction(function () use ($accountId, $locationId, $type, $claimedBy) {
            $q = PrintJob::query()
                ->where('account_id', $accountId)
                ->where('location_id', $locationId)
                ->where('status', 'pending')
                ->where(function ($w) {
                    $w->whereNull('available_at')
                      ->orWhere('available_at', '<=', now());
                })
                ->orderBy('id')
                ->lockForUpdate();

            if ($type) {
                $q->where('type', $type);
            }

            /** @var PrintJob|null $job */
            $job = $q->first();

            if (!$job) {
                return null;
            }

            $job->update([
                'status' => 'processing',
                'claimed_at' => now(),
                'claimed_by' => $claimedBy,
            ]);

            return $job->fresh();
        });
    }

    public function ackSent(PrintJob $job): PrintJob
    {
        $this->assertTenant($job);

        if (!in_array($job->status, ['processing', 'pending'], true)) {
            abort(409, 'PrintJob não está em estado processável.');
        }

        $job->update([
            'status' => 'sent',
            'sent_at' => now(),
            'last_error' => null,
        ]);

        return $job->fresh();
    }

    public function ackFailed(PrintJob $job, string $error): PrintJob
    {
        $this->assertTenant($job);

        if (!in_array($job->status, ['processing', 'pending'], true)) {
            abort(409, 'PrintJob não está em estado processável.');
        }

        $job->update([
            'status' => 'failed',
            'attempts' => ((int) $job->attempts) + 1,
            'last_error' => mb_substr($error, 0, 5000),
        ]);

        return $job->fresh();
    }

    private function assertTenant(PrintJob $job): void
    {
        if ($job->account_id !== Tenant::accountId() || $job->location_id !== Tenant::locationId()) {
            abort(404);
        }
    }
}
