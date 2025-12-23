<?php

namespace App\Listeners;

use App\Events\ShiftClosed;
use App\Models\PrintJob;

class CreateShiftClosePrintJob
{
    /**
     * Cria um PrintJob do relatório do turno.
     * Obs.: não imprime aqui. Só agenda o job para ser processado por worker/PDV.
     */
    public function handle(ShiftClosed $event): void
    {
        PrintJob::create([
            'account_id' => $event->accountId,
            'location_id' => $event->locationId,
            'type' => 'shift_report',
            'payload' => [
                'shift_id' => $event->shiftId,
                'copies' => 1,
                'requested_by' => $event->closedByUserId,
            ],
            'status' => 'pending',
            'available_at' => now(),
        ]);
    }
}
