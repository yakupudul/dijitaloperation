<?php

namespace App\Jobs\Sales;

use App\Models\SalesLead;
use App\Services\Sales\SalesBrain;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Satış beyni: Claude reads a closed deal and proposes lessons and questions (Satış › Ayarlar). */
class LearnFromDeal implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public int $timeout = 150;

    public int $tries = 1;

    public function __construct(public int $leadId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'sales-lesson:'.$this->leadId;
    }

    public function handle(SalesBrain $brain): void
    {
        $brain->learn($this->leadId, 120, $this);
    }

    public function failed(?Throwable $exception): void
    {
        SalesLead::query()->whereKey($this->leadId)->whereIn('lesson_status', ['queued', 'running'])
            ->update(['lesson_status' => 'failed', 'lesson_note' => 'Ders çıkarma yarıda kaldı.']);
    }
}
