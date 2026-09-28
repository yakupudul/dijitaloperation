<?php

namespace App\Services\CommandCenter;

use App\Models\AdvisorItem;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Services\Gbp\GbpDailyWorkspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * İşletme Profili in the command center, one item per profile and problem: reviews waiting for a reply longer than
 * 48 hours, no post for two weeks (and none planned), reviews Google does not give, and profile gaps when the
 * advisor has no open "profile-gaps" item for it. Each links to the matching tab of the profile page.
 */
final class GbpSource implements CommandCenterSource
{
    public function items(): Collection
    {
        $daily = app(GbpDailyWorkspace::class);
        $out = collect();
        $assets = DigitalAsset::query()->operational()->with('brand')->where('type', 'google_business_profile')->orderBy('id')->limit(500)->get();
        $bindings = CoreAssetBinding::query()->whereIn('digital_asset_id', $assets->pluck('id'))->where('capability', 'google_business_profile')
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('external_resource_id', 'digital_asset_id');
        $withAdvisorGaps = AdvisorItem::query()->open()->where('rule_id', 'profile-gaps')
            ->whereIn('digital_asset_id', $assets->pluck('id'))->pluck('digital_asset_id')->flip();

        foreach ($assets as $asset) {
            $resourceId = $bindings[$asset->id] ?? null;
            if ($resourceId === null) {
                continue;
            }
            $base = ['brand_id' => $asset->brand_id, 'brand' => $asset->brand?->name, 'asset' => $asset->name, 'asset_id' => $asset->id,
                'asset_type' => $asset->type, 'channel' => 'İşletme Profili', 'actions' => ['snooze']];
            $url = fn (string $tab): string => route('operator.gbp', ['assetId' => $asset->id, 'tab' => $tab]);

            $late = DB::table('gbp_reviews')->where('external_resource_id', $resourceId)->whereNull('review_reply')
                ->where('create_time', '<', now()->subHours(GbpDailyWorkspace::REPLY_SLA_HOURS))->where('create_time', '>=', now()->subDays(90));
            $count = (clone $late)->count();
            if ($count > 0) {
                $out->push(CommandCenter::item('gbp', 'reviews-'.$asset->id, 'medium', sprintf('%d yorum 48 saatten uzun süredir yanıt bekliyor', $count), $base + [
                    'detail' => 'En eskisi '.substr((string) (clone $late)->min('create_time'), 0, 10).' tarihli. Yorumlar sekmesinde AI taslağıyla yanıtlayıp Google’a gönderin.',
                    'rule' => 'reviews_unanswered', 'url' => $url('reviews'), 'age' => (clone $late)->min('create_time'),
                    // The low-rating alert says the same thing more urgently; while it is open this item waits.
                    'dedupe' => 'advisor-rule:'.$asset->id.':gbp-reviews-unanswered',
                ]));
            }

            $hasData = DB::table('gbp_location_snapshots')->where('external_resource_id', $resourceId)->exists();
            if ($hasData) {
                $cadence = $daily->cadence($asset, (int) $resourceId);
                if ($cadence['next'] === null && ($cadence['days_since'] === null || $cadence['days_since'] >= GbpDailyWorkspace::POST_REMINDER_DAYS)) {
                    $out->push(CommandCenter::item('gbp', 'posts-'.$asset->id, 'low', $cadence['days_since'] === null ? 'Profilde hiç gönderi yok' : sprintf('%d gündür gönderi yok', $cadence['days_since']), $base + [
                        'detail' => 'Haftada 1 gönderi önerilir. Gönderiler sekmesinden AI taslağıyla yeni gönderi planlayın.',
                        'rule' => 'no_recent_post', 'url' => $url('posts'),
                    ]));
                }
                if (! $withAdvisorGaps->has($asset->id)) {
                    $gaps = collect($daily->health((int) $resourceId, $asset)['items'])->filter(fn (array $item): bool => in_array($item['state'], ['fail', 'review'], true));
                    if ($gaps->isNotEmpty()) {
                        $out->push(CommandCenter::item('gbp', 'profile-'.$asset->id, 'low', sprintf('Profil eksikleri (%d)', $gaps->count()), $base + [
                            'detail' => $gaps->take(4)->pluck('label')->implode(', ').($gaps->count() > 4 ? '…' : '').' — Google İşletme Profili’nde düzenleyin.',
                            'rule' => 'profile_gaps', 'url' => $url('profile'),
                        ]));
                    }
                }
            }

            $access = $daily->reviewAccess($asset);
            if ($access['state'] === 'unavailable') {
                $out->push(CommandCenter::item('gbp', 'reviews-access-'.$asset->id, 'medium', 'Yorumlar toplanamıyor', $base + [
                    'detail' => $access['reason'],
                    'rule' => 'reviews_access', 'url' => $url('reviews'),
                ]));
            }
        }

        return $out;
    }
}
