<?php

namespace App\Jobs;

use App\Services\Ai\Insights\AiInsightService;
use App\Services\AiTasks\AiTaskQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

/** Writes one on-click AI insight (operator pressed its button); can wait for Claude (the job then runs again). */
final class WriteAiInsightJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public string $kind, public int $subjectId) {}

    public function handle(AiInsightService $insights, AiTaskQueue $tasks): void
    {
        $definition = $insights->definition($this->kind);
        $subject = $definition->subjectClass()::query()->find($this->subjectId);
        $meta = $subject instanceof Model ? $definition->meta($subject) : [];
        $tasks->begin(new self($this->kind, $this->subjectId), isset($meta['brand_id']) ? (int) $meta['brand_id'] : null, (string) ($meta['title'] ?? $definition->label()));
        try {
            $insights->write($this->kind, $this->subjectId);
        } finally {
            $tasks->settle();
        }
    }
}
