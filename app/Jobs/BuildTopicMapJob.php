<?php

namespace App\Jobs;

use App\Services\ContentStudio\TopicMapBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Faz 3: rebuilds one website's topic map from the brand query hub (stored data only). */
final class BuildTopicMapJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $buildId) {}

    public function handle(TopicMapBuilder $builder): void
    {
        $builder->run($this->buildId);
    }
}
