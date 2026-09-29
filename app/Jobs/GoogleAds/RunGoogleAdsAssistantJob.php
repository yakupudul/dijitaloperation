<?php

namespace App\Jobs\GoogleAds;

use App\Services\GoogleAds\GoogleAdsAssistant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** One Google Ads AI operation (search terms, structure, ad texts) on an operator click. */
final class RunGoogleAdsAssistantJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 600;

    /** @param  array{terms?: list<string>, ad_group?: string}  $params */
    public function __construct(public int $assetId, public string $operation, public array $params = []) {}

    public function uniqueId(): string
    {
        return $this->assetId.':'.$this->operation;
    }

    public function handle(GoogleAdsAssistant $assistant): void
    {
        $assistant->run($this->assetId, $this->operation, $this->params);
    }

    public function failed(?Throwable $exception): void
    {
        Cache::put(GoogleAdsAssistant::stateKey($this->assetId, $this->operation), ['status' => 'failed', 'message' => 'İşlem tamamlanamadı; tekrar deneyin.'], now()->addDay());
    }
}
