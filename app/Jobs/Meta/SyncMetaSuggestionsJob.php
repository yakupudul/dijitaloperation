<?php

namespace App\Jobs\Meta;

use App\Ai\Agents\MetaCampaignServicesAgent;
use App\Models\DigitalAsset;
use App\Services\Ai\AiBudget;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Meta\MetaCampaignServices;
use App\Services\Meta\MetaChecks;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Meta system checks → suggestions (no AI) and the campaign → service rules. Daily and on "Yeniden kontrol et".
 * Campaigns no rule matched go to AI in one batch when the operation runs on Claude or automatic AI is on.
 */
final class SyncMetaSuggestionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public int $assetId) {}

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public function handle(MetaChecks $checks, MetaCampaignServices $services, AiTaskQueue $tasks): void
    {
        $asset = DigitalAsset::query()->find($this->assetId);
        if ($asset === null || $asset->type !== 'meta_ads') {
            return;
        }
        $matched = $services->sync($asset);
        if ($matched['unmatched'] !== [] && ($tasks->delegated(MetaCampaignServicesAgent::OPERATION) || AiBudget::automaticAllowed(MetaCampaignServicesAgent::OPERATION))) {
            MatchMetaCampaignServicesJob::dispatch((int) $asset->id);
        }
        $checks->sync($asset);
    }
}
