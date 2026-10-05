<?php

namespace App\Jobs\WhatsApp;

use App\Services\Ai\AiLiveOperations;
use App\Services\WhatsApp\WhatsAppBrain;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Throwable;

/** Reads the past chats once and writes the WhatsApp brain (OpenAI, the model chosen on the WhatsApp screen). */
class LearnWhatsAppBrain implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public ?int $userId = null)
    {
        $this->onConnection('redis')->onQueue('heavy');
    }

    public function uniqueId(): string
    {
        return 'wa-brain';
    }

    public function handle(WhatsAppBrain $brain): void
    {
        if ($this->userId !== null) {
            Context::addHidden(AiLiveOperations::USER_CONTEXT, $this->userId);
        }
        $brain->learn();
    }

    public function failed(?Throwable $exception): void
    {
        app(WhatsAppBrain::class)->markFailed('worker_interrupted');
    }
}
