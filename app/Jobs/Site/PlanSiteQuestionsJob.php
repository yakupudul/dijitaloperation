<?php

namespace App\Jobs\Site;

use App\Models\DigitalAsset;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Site\Showcase\SiteQuestions;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes the decision questions (with answers) of one assigned page from its search demand (Sorular ve yorumlar).
 * Can be delegated to Claude: the job runs again once Claude answered.
 */
final class PlanSiteQuestionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    /** @param array{key: string, title: string, url: string} $target */
    public function __construct(public int $siteId, public array $target)
    {
        $this->onQueue((string) config('queue.content_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return $this->siteId.':'.$this->target['key'];
    }

    public function handle(SiteQuestions $questions, AiTaskQueue $tasks): void
    {
        $site = DigitalAsset::query()->with('brand')->find($this->siteId);
        if ($site === null) {
            return;
        }
        $tasks->begin(new self($this->siteId, $this->target), $site->brand_id !== null ? (int) $site->brand_id : null,
            'Sayfa soruları · '.($this->target['title'] !== '' ? $this->target['title'] : $site->name));
        try {
            $questions->write($this->siteId, $this->target);
        } finally {
            $tasks->settle();
        }
    }
}
