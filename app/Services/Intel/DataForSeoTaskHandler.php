<?php

namespace App\Services\Intel;

/** Receives the result of a queued DataForSEO task (one row of `dataforseo_tasks`). */
interface DataForSeoTaskHandler
{
    /** @param array<string, mixed> $result the task's first result object */
    public function handleResult(object $task, array $result): void;

    public function handleFailure(object $task, string $error): void;
}
