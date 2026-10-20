<?php

namespace App\Services\Work;

use App\Models\ContentSiteState;
use App\Models\ContentTopic;
use App\Models\DigitalAsset;
use App\Services\Site\ContentPlanner;
use App\Services\Site\ContentTopics;

/**
 * Yazım sıklığı (yakup, 2026-10-16: "3 içerik 1 güncelleme ana dil üzerinde; çeviri bu kotadan olmayacak"): how many new
 * articles and page updates a site gets each week, by the stage of its map, not a fixed number:
 *
 *  - empty (fewer than 40% of its clusters answered by a sufficient page): 3 new + 1 update,
 *  - half (40–80%): 2 + 2,
 *  - full (over 80%): 1 + 3,
 *  - narrow local business (at most two services and six clusters, one language, no abroad): at most 4 new a month
 *    (1 a week) + 1 update.
 *
 * The brand's own weekly number is only a ceiling. Never more new articles than evidenced empty places (open topics
 * of its maps, clusters without a page); what the site cannot fill goes to updates. Translations of the written
 * articles are extra (not in the count).
 */
final class ContentRhythm
{
    public const array STAGES = [
        'empty' => ['label' => 'Haritanın çoğu boş', 'new' => 3, 'update' => 1],
        'half' => ['label' => 'Harita yarı dolu', 'new' => 2, 'update' => 2],
        'full' => ['label' => 'Harita büyük ölçüde dolu', 'new' => 1, 'update' => 3],
        'local' => ['label' => 'Dar yerel iş', 'new' => 1, 'update' => 1],
    ];

    /** @return array{stage: string, label: string, new: int, update: int, filled: int, gaps: int} */
    public function for(DigitalAsset $site): array
    {
        $rows = ContentTopics::mainRows($site);
        $total = $rows->count();
        $sufficient = $rows->where('state', 'sufficient')->count();
        $services = $rows->map(fn ($r): int => (int) ($r->cluster?->service_id ?? 0))->filter()->unique()->count();
        $filled = $total > 0 ? (int) round(100 * $sufficient / $total) : 0;
        $local = $services <= 2 && $total <= 6 && ! ContentTopics::servesAbroad($site) && count(ContentPlanner::siteLanguages($site)) === 1;
        $stage = match (true) {
            $total === 0 => 'empty',
            $local => 'local',
            $filled < 40 => 'empty',
            $filled <= 80 => 'half',
            default => 'full',
        };
        $openTopics = ContentTopic::query()->where('website_asset_id', $site->id)->where('status', 'open')->where('coverage', 'missing')->count();
        $gaps = max($openTopics, $rows->whereIn('state', ContentCoverage::GAP_STATES)->count());
        $plan = self::STAGES[$stage];
        // The brand's weekly number (Web sitesi › Ayarlar) is only a ceiling.
        $ceiling = max(1, (int) ($site->brand?->weekly_content_capacity ?? $plan['new']));
        $new = min($plan['new'], $gaps, $ceiling);
        $update = $plan['update'] + ($stage === 'local' ? 0 : $plan['new'] - $new);

        return ['stage' => $stage, 'label' => $plan['label'], 'new' => $new, 'update' => $update, 'filled' => $filled, 'gaps' => $gaps];
    }

    /** Computes and keeps the site's rhythm on its content state (shown on the content table). */
    public function store(DigitalAsset $site): array
    {
        $rhythm = $this->for($site);
        ContentSiteState::for($site)->forceFill(['stage' => $rhythm['stage'], 'new_per_week' => $rhythm['new'], 'updates_per_week' => $rhythm['update']])->save();

        return $rhythm;
    }
}
