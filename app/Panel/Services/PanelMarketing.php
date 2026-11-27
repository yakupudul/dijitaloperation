<?php

namespace App\Panel\Services;

use App\Models\BrandSession;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Models\SocialPost;
use App\Services\Operator\BrandOverviewReader;
use App\Services\Repair\RepairDesk;
use App\Services\Work\FlowFeed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pazarlama: what the agency did for a brand and what came of it, in the client's words. These records carry no brand
 * lock of their own, so every read here names the brand. Nothing internal (findings, plans, failed writes) is shown.
 */
final class PanelMarketing
{
    /** What a finished write is called on the panel; an action that is not here is not shown. */
    public const array WORK = [
        ExternalWriteAction::ACTION_SITE_FIX => 'Sitede arama motoru düzeltmesi',
        ExternalWriteAction::ACTION_CONTENT_APPLY => 'Sayfa metni güncellemesi',
        ExternalWriteAction::ACTION_LOCAL_POST => 'İşletme Profili gönderisi',
        ExternalWriteAction::ACTION_REVIEW_REPLY => 'Yorum yanıtı',
        ExternalWriteAction::ACTION_MEDIA_UPLOAD => 'İşletme Profiline fotoğraf',
        ExternalWriteAction::ACTION_PROFILE_FIELDS => 'İşletme Profili bilgi güncellemesi',
        ExternalWriteAction::ACTION_PROFILE_UPDATE => 'İşletme Profili bilgi güncellemesi',
        ExternalWriteAction::ACTION_ARTICLE_DRAFTS => 'Blog yazısı',
        ExternalWriteAction::ACTION_DRAFT_CREATE => 'Blog yazısı',
        ExternalWriteAction::ACTION_ADS_CHANGE => 'Google Ads iyileştirmesi',
        ExternalWriteAction::ACTION_NEGATIVE_LIST_ADD => 'Google Ads\'te boşa giden aramaların kapatılması',
        ExternalWriteAction::ACTION_UPDATE_APPLY => 'Site güncellemesi',
        ExternalWriteAction::ACTION_SITE_BUILD => 'Site kurulumu',
    ];

    /** @return Collection<int, BrandSession> the reports the operator published, newest month first */
    public function reports(int $brandId): Collection
    {
        return BrandSession::query()->where('brand_id', $brandId)->whereNotNull('panel_published_at')->orderByDesc('month')->limit(36)->get();
    }

    /** The brand opened a report: the first time is kept, so the operator sees it was read. */
    public function markRead(BrandSession $report): void
    {
        if ($report->panel_read_at === null) {
            $report->forceFill(['panel_read_at' => now()])->saveQuietly();
        }
    }

    /**
     * What was done, month by month: a count per kind of work and the latest single jobs.
     *
     * @return array{months: list<array{label: string, total: int, kinds: list<array{label: string, count: int}>}>, recent: list<array{at: string, label: string, subject: string}>}
     */
    public function work(int $brandId, int $months = 6): array
    {
        $from = CarbonImmutable::now('Europe/Istanbul')->startOfMonth()->subMonths($months - 1);
        $rows = ExternalWriteAction::query()->where('brand_id', $brandId)->whereIn('status', ['succeeded', 'partial'])->whereIn('action', array_keys(self::WORK))
            ->where('updated_at', '>=', $from->utc())->orderByDesc('updated_at')->limit(4000)->get(['id', 'action', 'updated_at', 'request_payload']);
        $out = [];
        foreach ($rows->groupBy(fn (ExternalWriteAction $w): string => $w->updated_at->timezone('Europe/Istanbul')->format('Y-m')) as $month => $set) {
            $out[] = [
                'label' => Str::ucfirst(CarbonImmutable::parse($month.'-01')->locale('tr')->isoFormat('MMMM YYYY')),
                'total' => $set->count(),
                'kinds' => $set->groupBy(fn (ExternalWriteAction $w): string => self::WORK[$w->action])->map(fn (Collection $kind, string $label): array => ['label' => $label, 'count' => $kind->count()])
                    ->sortByDesc('count')->values()->all(),
            ];
        }

        return [
            'months' => $out,
            'recent' => $rows->take(25)->map(fn (ExternalWriteAction $w): array => [
                'at' => $w->updated_at->timezone('Europe/Istanbul')->locale('tr')->isoFormat('D MMMM'),
                'label' => self::WORK[$w->action],
                'subject' => ($subject = FlowFeed::writeSubject($w)) === (RepairDesk::WRITE_LABELS[$w->action] ?? $w->action) ? '' : $subject,
            ])->all(),
        ];
    }

    /**
     * What is approved and waits for its day: profile posts and social posts.
     *
     * @return Collection<int, array{key: string, kind: string, on: CarbonImmutable, text: string, image: ?string}>
     */
    public function upcoming(int $brandId): Collection
    {
        $today = CarbonImmutable::now('Europe/Istanbul')->startOfDay();
        $items = collect();
        foreach (GbpQueuedPost::query()->where('brand_id', $brandId)->where('status', GbpQueuedPost::APPROVED)->where('publish_on', '>=', $today->toDateString())->orderBy('publish_on')->limit(40)->get() as $post) {
            $items->push(['key' => 'gbp-'.$post->id, 'kind' => 'İşletme Profili gönderisi', 'on' => CarbonImmutable::parse((string) $post->publish_on, 'Europe/Istanbul'), 'text' => (string) $post->summary, 'image' => filled($post->image_url) ? (string) $post->image_url : null]);
        }
        foreach (SocialPost::query()->where('brand_id', $brandId)->where('status', SocialPost::APPROVED)->where('publish_at', '>=', $today->utc())->orderBy('publish_at')->limit(40)->get() as $post) {
            $items->push(['key' => 'social-'.$post->id, 'kind' => 'Sosyal medya gönderisi', 'on' => CarbonImmutable::parse($post->publish_at)->timezone('Europe/Istanbul'), 'text' => (string) ($post->caption ?: $post->title), 'image' => null]);
        }

        return $items->sortBy(fn (array $item): int => $item['on']->getTimestamp())->values();
    }

    /** @return list<array{label: string, name: string, state: string}> the brand's accounts the agency works on */
    public function assets(int $brandId): array
    {
        $order = ['website' => 0, 'google_business_profile' => 1, 'gbp' => 1, 'google_ads' => 2, 'meta_ads' => 3];

        return DigitalAsset::query()->where('brand_id', $brandId)->get()
            ->sortBy(fn (DigitalAsset $a): int => $order[(string) $a->type] ?? 9)
            ->map(fn (DigitalAsset $asset): array => [
                'label' => BrandOverviewReader::TYPE_LABELS[(string) $asset->type] ?? 'Hesap',
                'name' => (string) $asset->name,
                'state' => $asset->intent === DigitalAsset::INTENT_RETIRED ? 'Kullanılmıyor' : ($asset->intent === DigitalAsset::INTENT_PAUSED ? 'Durduruldu' : 'Takipte'),
            ])->values()->all();
    }
}
