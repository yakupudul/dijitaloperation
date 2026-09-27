<?php

namespace App\Services\Advisor;

use App\Models\AdvisorItem;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Archive\ProductionArchive;
use App\Support\ServiceScope;
use Illuminate\Validation\ValidationException;

/**
 * Advisor item actions shared by the Danışman panel and the Komuta merkezi inbox: the operator-approved AI copy
 * draft and the rules-only re-check after "Yapıldı".
 */
final class AdvisorItemActions
{
    public function __construct(private readonly AdvisorChannels $channels) {}

    /**
     * Queues an AI copy draft (1 AI call) or restores a fresh one from the production archive. Null when the rule
     * offers no draft or one is already being prepared.
     */
    public function requestDraft(AdvisorItem $item): ?string
    {
        $channel = $this->channels->get($item->channel);
        if (! in_array($item->rule_id, $channel->draftRules(), true) || $item->draft_status === 'queued') {
            return null;
        }
        if (! app(ServiceScope::class)->isAssetOperational($item->digital_asset_id)) {
            return ServiceScope::NOT_SERVED;
        }
        // Üretim Arşivi: a fresh draft for this item (e.g. lost to a failed retry) is shown before a new AI call.
        $hasDraft = is_array($item->draft) && ! isset($item->draft['error']) && $item->draft_status === 'ready';
        $archive = app(ProductionArchive::class);
        $fresh = $hasDraft ? null : $archive->fresh($archive->advisorKind($item), $item);
        if ($fresh !== null) {
            $item->forceFill(['draft_status' => 'ready', 'draft' => $fresh->content])->save();

            return 'Son 14 günde hazırlanmış taslak (sürüm '.$fresh->version.') arşivden geri yüklendi; AI çağrılmadı. Yeni taslak için "Yeniden hazırla".';
        }
        $item->forceFill(['draft_status' => 'queued', 'draft' => null])->save();
        $channel->dispatchDraft($item->id);

        return 'Metin taslağı hazırlanıyor (1 AI çağrısı). Hazır olunca burada görünür.';
    }

    /** Faz 7: re-runs the same channel's rules for the item's asset right away (rules only, no AI) to verify it. */
    public function verify(AdvisorItem $item, ?User $user): void
    {
        if (! (bool) config('moxdop-advisor.brain.verify_on_done', true)) {
            return;
        }
        $asset = DigitalAsset::query()->find($item->digital_asset_id);
        if ($asset === null || $this->channels->forAssetType((string) $asset->type)?->channel() !== $item->channel) {
            return;
        }
        try {
            app(AdvisorPlanRunner::class)->queue($asset, $user, 'verify');
        } catch (ValidationException) {
            // Advisor disabled or asset not eligible: the weekly plan verifies instead.
        }
    }
}
