<?php

namespace App\Jobs\Prompts;

use App\Services\Prompts\PromptTrial;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** "Örnekte dene": one run of a draft prompt on a sample input (Ayarlar › AI işlemleri). */
final class RunPromptTrialJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $operation, public string $template, public string $model, public string $input, public int $userId) {}

    public function handle(PromptTrial $trial): void
    {
        $trial->run($this->operation, $this->template, $this->model, $this->input, $this->userId);
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(PromptTrial::stateKey($this->userId, $this->operation), ['status' => 'failed', 'message' => 'Deneme tamamlanamadı; tekrar deneyin.'], now()->addDay());
    }
}
