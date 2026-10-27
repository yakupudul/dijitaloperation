<?php

namespace App\Jobs\Site;

use App\Models\SiteQuestion;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Site\Showcase\SiteQuestions;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sorts and answers one question a visitor asked on a site (Sorular ve yorumlar). Can be delegated to Claude: the job
 * runs again once Claude answered, and the answer then waits on the Onarım masası.
 */
final class AnswerSiteQuestionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $questionId)
    {
        $this->onQueue((string) config('queue.content_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->questionId;
    }

    public function handle(SiteQuestions $questions, AiTaskQueue $tasks): void
    {
        $question = SiteQuestion::query()->with('brand')->find($this->questionId);
        if ($question === null || $question->status !== SiteQuestion::NEW) {
            return;
        }
        $tasks->begin(new self($this->questionId), (int) $question->brand_id, 'Sitedeki soru · '.($question->brand?->name ?? ''));
        try {
            $questions->answer($this->questionId);
        } finally {
            $tasks->settle();
        }
    }
}
