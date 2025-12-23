<?php

namespace App\Http\Controllers;

use App\Models\PrintJob;
use App\Services\PrintJobProcessor;
use App\Support\Tenant;
use Illuminate\Http\Request;

class PrintJobController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');
        $limit = (int) $request->query('limit', 50);

        $jobs = PrintJob::query()
            ->where('account_id', Tenant::accountId())
            ->where('location_id', Tenant::locationId())
            ->where('status', $status)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return response()->json($jobs);
    }

    /**
     * Claim de 1 job pendente (pull-based para PDV).
     * Body:
     * - claimed_by (string) obrigatório
     * - type (opcional)
     */
    public function claim(Request $request, PrintJobProcessor $processor)
    {
        $data = $request->validate([
            'claimed_by' => ['required', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:50'],
        ]);

        $job = $processor->claimNext($data['type'] ?? null, $data['claimed_by']);

        return response()->json([
            'job' => $job,
        ]);
    }

    /**
     * Ack do PDV/worker ao finalizar tentativa de impressão.
     * Body:
     * - status: sent|failed
     * - error: string (obrigatório se failed)
     */
    public function ack(Request $request, PrintJob $printJob, PrintJobProcessor $processor)
    {
        $data = $request->validate([
            'status' => ['required', 'in:sent,failed'],
            'error' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($data['status'] === 'sent') {
            $job = $processor->ackSent($printJob);
        } else {
            $err = $data['error'] ?? 'Erro não informado.';
            $job = $processor->ackFailed($printJob, $err);
        }

        return response()->json([
            'job' => $job,
        ]);
    }
}
