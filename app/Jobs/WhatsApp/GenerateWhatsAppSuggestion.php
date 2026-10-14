<?php

namespace App\Jobs\WhatsApp;

use App\Models\WhatsAppConversation;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\WhatsApp\WhatsAppSuggestions;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateWhatsAppSuggestion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public int $conversationId)
    {
        $this->onConnection('redis')->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'wa-suggestion:'.$this->conversationId;
    }

    public function handle(WhatsAppSuggestions $suggestions): void
    {
        $tasks = app(AiTaskQueue::class);
        $tasks->begin(new self($this->conversationId), null, 'WhatsApp yanıt önerisi');
        try {
            $suggestions->generate($this->conversationId);
        } finally {
            $tasks->settle();
        }
    }

    public function failed(?Throwable $exception): void
    {
        WhatsAppConversation::query()->whereKey($this->conversationId)->where('suggestion_status', 'running')
            ->update(['suggestion_status' => 'failed', 'error_code' => 'worker_interrupted', 'updated_at' => now()]);
    }
}
