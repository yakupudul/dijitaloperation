<?php

namespace App\Jobs\Brand;

use App\Models\Brand;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Epicrisis\Epicrisis;
use App\Services\Epicrisis\EpicrisisFacts;
use App\Services\Epicrisis\EpicrisisSynthesis;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Marka epikrizi of one brand, on the background queue (nightly after the brand file, or the Epikriz tab's button):
 * the rule pass, then — when due — the AI reading of the key pages and the synthesis. Both AI steps can wait for
 * Claude (MCP): the job then runs again once Claude answered and goes on from there.
 */
final class RunBrandEpicrisisJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $brandId, public bool $force = false)
    {
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->brandId;
    }

    public function handle(Epicrisis $epicrisis, EpicrisisFacts $facts, EpicrisisSynthesis $synthesis, AiTaskQueue $tasks): void
    {
        $brand = Brand::query()->find($this->brandId);
        if ($brand === null || ! $brand->isOperational()) {
            return;
        }
        $tasks->begin(new self($this->brandId, $this->force), $this->brandId, 'Marka epikrizi · '.$brand->name);
        try {
            $epicrisis->build($brand);
            if ($facts->readSite($brand, $this->force)['status'] === 'queued') {
                return;
            }
            $epicrisis->build($brand);
            if ($synthesis->run($brand, $this->force)['status'] === 'queued') {
                return;
            }
            $epicrisis->summarize($brand);
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            $tasks->settle();
        }
    }
}
