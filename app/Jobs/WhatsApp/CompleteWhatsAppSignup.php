<?php

namespace App\Jobs\WhatsApp;

use App\Services\WhatsApp\WhatsAppSignup;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CompleteWhatsAppSignup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 180;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public string $attemptId)
    {
        $this->onConnection('redis')->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'wa-signup:'.$this->attemptId;
    }

    public function handle(WhatsAppSignup $signup): void
    {
        $signup->complete($this->attemptId);
    }
}
