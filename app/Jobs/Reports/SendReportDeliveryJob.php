<?php

namespace App\Jobs\Reports;

use App\Services\ReportDelivery\SendReportDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queue job payload: Delivery ID only — no Snapshot/PDF/secrets.
 */
class SendReportDeliveryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(SendReportDeliveryService $sender): void
    {
        $sender->send($this->deliveryId);
    }
}
