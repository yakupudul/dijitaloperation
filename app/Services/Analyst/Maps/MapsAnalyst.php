<?php

namespace App\Services\Analyst\Maps;

use App\Models\AnalystDecision;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\GbpReview;
use App\Models\User;
use App\Services\Analyst\AbstractChannelAnalyst;
use App\Services\Analyst\AnalystPack;
use App\Services\Gbp\GbpPostDrafter;
use App\Services\Gbp\ReviewReplyDrafter;
use App\Services\SeoTasks\SeoText;
use App\Support\Options\IndustryOptions;
use Illuminate\Validation\ValidationException;

/**
 * Harita: every Business Profile task done right so the brand shows up (and higher) in local map searches. Inputs
 * (rule code, stored data only): per location the collected profile (categories, services, attributes, description,
 * hours, phone / website / address), 28-day performance vs the 28 before, search keywords matched to the brand's
 * services, reviews (count, rating, velocity, unanswered, reply time), posts and photos, the gbp_* standards and the
 * GBP advisor rules (candidate facts), map grid scans with the top competitors and the website NAP check.
 * Writes stay ADR-073 (review replies and posts are drafted here, sent only after Admin approval on the asset page);
 * profile fields are edited by hand on Google ("Elle düzenle").
 */
final class MapsAnalyst extends AbstractChannelAnalyst
{
    public const array ACTIONS = [
        'reply_reviews' => ['label' => 'Yanıt taslakları hazırla', 'kind' => 'run', 'targets' => ['loc', 'rev']],
        'prepare_post' => ['label' => 'Gönderi taslağı hazırla', 'kind' => 'run', 'targets' => ['loc', 'svc', 'kw']],
        'fix_profile_field' => ['label' => 'Elle düzenle', 'kind' => 'link', 'targets' => ['std', 'loc', 'adv']],
        'add_service' => ['label' => 'Hizmet ekle', 'kind' => 'link', 'targets' => ['svc', 'kw', 'std']],
        'upload_photos' => ['label' => 'Fotoğraf yükle', 'kind' => 'link', 'targets' => ['loc', 'std']],
        'open_grid' => ['label' => 'Harita sıralamasını aç', 'kind' => 'link', 'targets' => ['grid', 'comp']],
        'fix_nap' => ['label' => 'Site ile farkı aç', 'kind' => 'link', 'targets' => ['nap', 'std']],
    ];

    /** Unanswered reviews drafted by one "Yanıt taslakları hazırla" click. */
    public const int REPLY_BATCH = 5;

    public function __construct(private readonly MapsFacts $facts) {}

    public function channel(): string
    {
        return 'maps';
    }

    public function allowedActions(): array
    {
        return self::ACTIONS;
    }

    public function instructions(): string
    {
        return <<<'TXT'
Channel: Google Business Profile / Google Maps (local pack). Goal: every Business Profile task done right so the brand
shows up, and higher, in map searches and views. Think like a senior local-SEO / Business Profile consultant. Google's
local ranking is relevance (primary + additional categories, services, description, attributes that match the
search), distance (not controllable — never suggest fake addresses or extra listings) and prominence (review count,
rating, velocity and replies, photos, posts, website authority, consistent NAP).
- Fix what blocks relevance first: failing standards ("std:"), missing services that people search ("kw:" with
  status "profilde yok", "svc:" not in profile) → fix_profile_field / add_service; a wrong primary category or
  missing hours / special hours → fix_profile_field.
- Prominence: unanswered reviews → reply_reviews (target the location "loc:" or a single review "rev:"); no post for
  more than 7 days → prepare_post (target the location, a service or a search keyword as the topic); old or few
  photos → upload_photos; site vs profile NAP differences → fix_nap.
- Map grid ("grid:", "comp:"): low top-3 share or competitors taking the top 3 → open_grid, and name the concrete
  profile work that closes the gap.
- Never suggest keywords, districts or services in the business name (suspension risk). Questions & answers are
  discontinued by Google — never suggest them. Health brands follow the Turkish health promotion regulation: no
  discounts, campaigns, "en iyi" / superlatives or patient testimonials in posts or descriptions.
Prioritize by impact (maps views, calls, directions); quote only numbers from the pack.
TXT;
    }

    public function buildPack(Brand $brand): AnalystPack
    {
        $context = [
            'brand' => $brand->name,
            'sector' => implode(', ', array_filter(array_map(fn (string $c): string => (string) IndustryOptions::label($c), $brand->sectorCodes()))),
            'window_days' => MapsFacts::WINDOW_DAYS,
        ];
        $missing = $this->facts->missing($brand);
        if ($missing !== null) {
            return AnalystPack::missing('maps', (int) $brand->id, $missing, [], $context);
        }
        $snap = $this->facts->snapshot($brand);
        $stats = $this->facts->stats($brand);
        $context += [
            'locations' => count($snap['locations']), 'keyword_months' => $snap['keyword_months'],
            'service_areas' => array_slice($snap['service_areas'], 0, 20),
        ];
        $multi = count($snap['locations']) > 1;

        $sections = [];
        foreach ($snap['locations'] as $loc) {
            $sections['locations']['loc:'.$loc['asset_id']] = [
                'name' => $loc['name'], 'category' => $loc['category'], 'extra_categories' => $loc['extra_categories'], 'area' => $loc['area'],
                'open_status' => $loc['open_status'], 'phone' => $loc['phone'], 'website' => $loc['website'], 'description_chars' => $loc['description_chars'],
                'hours_days' => $loc['hours_days'], 'special_hours' => $loc['special_hours'], 'services_listed' => $loc['services_listed'], 'attributes_set' => $loc['attributes_set'],
                'photos' => $loc['photos'], 'last_photo_days' => $loc['last_photo_days'], 'last_post_days' => $loc['last_post_days'], 'next_post' => $loc['next_post'],
                'rating' => $loc['rating'], 'reviews' => $loc['reviews'], 'reviews_30d' => $loc['reviews_30d'], 'reviews_90d' => $loc['reviews_90d'],
                'reviews_prev_90d' => $loc['reviews_prev_90d'], 'unanswered' => $loc['unanswered'], 'median_reply_hours' => $loc['median_reply_hours'],
                'impressions' => $loc['maps_views'], 'maps_views_prev' => $loc['has_previous'] ? $loc['maps_views_prev'] : null, 'search_views' => $loc['search_views'],
                'calls' => $loc['calls'], 'directions' => $loc['directions'], 'website_clicks' => $loc['website_clicks'],
                'display' => ($loc['rating'] !== null ? str_replace('.', ',', (string) $loc['rating']).' ★ · ' : '').$loc['reviews'].' yorum',
            ];
        }
        foreach ($snap['locations'] as $loc) {
            foreach ($loc['standards'] as $id => $result) {
                if (! in_array($result['state'], ['fail', 'review'], true)) {
                    continue;
                }
                $evidence = (array) ($result['evidence'] ?? []);
                $suggest = match (true) {
                    ($evidence['missing'] ?? []) !== [] => 'Ekle: '.implode(', ', array_slice((array) $evidence['missing'], 0, 6)),
                    ($evidence['unset'] ?? []) !== [] => 'İşaretle: '.implode(', ', array_slice((array) $evidence['unset'], 0, 6)),
                    default => mb_substr((string) ($result['solution'] ?? ''), 0, 160),
                };
                $sections['standards']['std:'.$loc['asset_id'].':'.$id] = [
                    'rule' => $result['title'], 'status' => $result['state'], 'severity' => $result['severity'], 'note' => mb_substr((string) $result['finding'], 0, 200),
                    'display' => $suggest ?: null, 'location' => $multi ? $loc['name'] : null,
                ];
            }
        }
        $impressionsByService = [];
        foreach ($snap['locations'] as $loc) {
            foreach ($loc['keywords'] as $kw) {
                if ($kw['service_id'] !== null) {
                    $impressionsByService[$kw['service_id']] = ($impressionsByService[$kw['service_id']] ?? 0) + $kw['impressions'];
                }
            }
        }
        foreach (array_slice($snap['offerings'], 0, 40) as $offering) {
            $missingAt = [];
            foreach ($snap['locations'] as $loc) {
                foreach ($loc['offerings'] as $o) {
                    if ((int) $o['id'] === (int) $offering['id'] && ! $o['in_profile']) {
                        $missingAt[] = $loc['name'];
                    }
                }
            }
            $sections['services']['svc:'.$offering['id']] = [
                'name' => (string) $offering['name'], 'status' => $missingAt === [] ? 'profilde var' : 'profilde yok', 'priority' => (bool) ($offering['is_priority'] ?? false),
                'impressions' => $impressionsByService[(int) $offering['id']] ?? 0, 'note' => $multi && $missingAt !== [] ? implode(', ', $missingAt) : null,
            ];
        }
        foreach ($snap['locations'] as $loc) {
            foreach ($loc['keywords'] as $kw) {
                $sections['keywords'][self::keywordRef($loc['asset_id'], $kw['text'])] = [
                    'text' => $kw['text'], 'impressions' => $kw['impressions'], 'service' => $kw['service'], 'status' => $kw['status'], 'location' => $multi ? $loc['name'] : null,
                ];
            }
        }
        foreach ($snap['locations'] as $loc) {
            foreach ($loc['unanswered_rows'] as $review) {
                $sections['reviews']['rev:'.$review['id']] = [
                    'status' => 'yanıtsız', 'rating' => $review['rating'], 'age_days' => $review['age_days'], 'note' => $review['note'], 'location' => $multi ? $loc['name'] : null,
                ];
            }
        }
        foreach ($snap['grid'] as $grid) {
            $sections['grid']['grid:'.$grid['run_id']] = [
                'text' => $grid['text'], 'top3_pct' => $grid['top3_pct'], 'position' => $grid['position'], 'atrp' => $grid['atrp'], 'points' => $grid['points'], 'date' => $grid['date'],
            ];
            foreach ($grid['competitors'] as $i => $competitor) {
                $sections['competitors']['comp:'.$grid['run_id'].':'.($i + 1)] = [
                    'name' => $competitor['title'], 'text' => $grid['text'], 'top3_cells' => $competitor['top3'], 'top20_cells' => $competitor['top20'],
                    'position' => $competitor['avg_rank'], 'rating' => $competitor['rating'], 'votes' => $competitor['votes'],
                ];
            }
        }
        if ($snap['nap'] !== null) {
            $sections['nap']['nap:'.$snap['nap']['site_id']] = ['status' => $snap['nap']['status'], 'note' => $snap['nap']['note'], 'path' => $snap['nap']['site']];
        }
        foreach ($snap['locations'] as $loc) {
            foreach ($loc['advisor'] as $item) {
                $sections['advisor']['adv:'.$loc['asset_id'].':'.$item['rule_id']] = [
                    'title' => mb_substr((string) $item['title'], 0, 140), 'status' => $item['severity'], 'note' => mb_substr((string) ($item['impact_label'] ?? ''), 0, 140),
                    'display' => mb_substr((string) (($item['checklist'] ?? [])[0] ?? ''), 0, 160) ?: null, 'location' => $multi ? $loc['name'] : null,
                ];
            }
        }

        return (new AnalystPack('maps', (int) $brand->id, $context, $stats, $sections))->trimTo();
    }

    public static function keywordRef(int $assetId, string $keyword): string
    {
        return 'kw:'.$assetId.':'.substr(hash('sha256', mb_strtolower(trim($keyword))), 0, 8);
    }

    protected function extraCheck(array $decision, AnalystPack $pack): ?string
    {
        $text = SeoText::fold($decision['title_tr'].' '.$decision['why_tr']);
        if (preg_match('/\bsoru (ve )?cevap|\bq a\b/', $text) === 1) {
            return 'Soru-cevap Google’da kapatıldı';
        }
        if (preg_match('/\b(isletme|profil|firma) (ad|adi|adina|adini|ismi|ismine)\b/', $text) === 1 && preg_match('/\b(kelime|ilce|semt|ekle)/', $text) === 1) {
            return 'işletme adına kelime önerilmez';
        }
        if (preg_match('/\b(indirim|kampanya|en iyi|garantili|hasta yorum)/', SeoText::fold($decision['title_tr'])) === 1) {
            return 'tanıtım kuralına aykırı başlık';
        }

        return null;
    }

    public function presentAction(AnalystDecision $decision): ?array
    {
        $spec = self::ACTIONS[$decision->action_type] ?? null;
        if ($spec === null) {
            return null;
        }
        if ($spec['kind'] === 'run') {
            return ['label' => $spec['label'], 'kind' => 'run', 'url' => null];
        }
        $target = (string) ($decision->action_params['target'] ?? '');
        [$prefix, $id] = array_pad(explode(':', $target, 3), 2, '');
        $brandId = (int) $decision->brand_id;
        $asset = $this->assetFor($brandId, $target);
        $profile = $asset !== null ? route('operator.gbp', ['assetId' => $asset->id, 'tab' => 'profile']) : null;
        $url = match ($decision->action_type) {
            'open_grid' => route('operator.market.map-rankings', ['brand' => $brandId, 'run' => (int) $id]),
            'fix_nap' => $this->napUrl($brandId, $prefix, (int) $id) ?? $profile,
            default => $profile,
        };

        return ['label' => $spec['label'], 'kind' => 'link', 'url' => $url];
    }

    public function perform(AnalystDecision $decision, User $user): string
    {
        $target = (string) ($decision->action_params['target'] ?? '');

        return match ($decision->action_type) {
            'reply_reviews' => $this->replyReviews((int) $decision->brand_id, $target),
            'prepare_post' => $this->preparePost($decision, $target),
            default => throw ValidationException::withMessages(['analyst' => 'Bu kart için çalıştırılacak bir işlem yok.']),
        };
    }

    public function baseline(AnalystDecision $decision): array
    {
        $brand = Brand::query()->find($decision->brand_id);
        if ($brand === null || $this->facts->missing($brand) !== null) {
            return [];
        }
        $stats = collect($this->facts->stats($brand))->keyBy('id');

        return ['maps_views_28d' => $stats['maps_views']['value'] ?? null, 'actions_28d' => $stats['actions']['value'] ?? null,
            'unanswered_reviews' => $stats['unanswered_reviews']['value'] ?? null];
    }

    /** ADR-073: AI reply drafts only; the Admin sends each reply from İşletme Profili › Yorumlar. */
    private function replyReviews(int $brandId, string $target): string
    {
        [$prefix, $id] = array_pad(explode(':', $target, 3), 2, '');
        $query = GbpReview::query()->whereNull('review_reply');
        if ($prefix === 'rev') {
            $query->whereKey((int) $id);
        } else {
            $asset = $this->assetFor($brandId, $target);
            $resourceId = $asset !== null ? CoreAssetBinding::query()->where('digital_asset_id', $asset->id)->where('capability', 'google_business_profile')
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)->orderByDesc('id')->value('external_resource_id') : null;
            $query->where('external_resource_id', (int) $resourceId)->orderByDesc('create_time')->limit(self::REPLY_BATCH);
        }
        $reviews = $query->get()->filter(fn (GbpReview $review): bool => $this->reviewAsset($brandId, $review) !== null);
        if ($reviews->isEmpty()) {
            throw ValidationException::withMessages(['analyst' => 'Yanıt bekleyen yorum yok; yeniden analiz edin.']);
        }
        $drafter = app(ReviewReplyDrafter::class);
        foreach ($reviews as $review) {
            $drafter->queue($review);
        }

        return $reviews->count().' yorum için yanıt taslağı hazırlanıyor; İşletme Profili › Yorumlar’da incele ve onayla.';
    }

    /** ADR-073: an AI post draft; the operator schedules it in the content calendar and the Admin approves it. */
    private function preparePost(AnalystDecision $decision, string $target): string
    {
        $brandId = (int) $decision->brand_id;
        $asset = $this->assetFor($brandId, $target);
        if ($asset === null) {
            throw ValidationException::withMessages(['analyst' => 'Markanın İşletme Profili bulunamadı.']);
        }
        [$prefix, $id] = array_pad(explode(':', $target, 3), 2, '');
        $topic = match ($prefix) {
            'svc' => (string) (BrandOffering::query()->where('brand_id', $brandId)->find((int) $id)?->displayName() ?? ''),
            'kw' => (string) ($decision->action_params['_target']['text'] ?? ''),
            default => '',
        };
        app(GbpPostDrafter::class)->queue($asset, $topic);

        return 'Gönderi taslağı hazırlanıyor'.($topic !== '' ? ' ('.$topic.')' : '').'; İşletme Profili › Gönderiler’de takvime ekle.';
    }

    /** The brand's location a target points at (loc / std / adv / kw carry the asset id; others → the first location). */
    private function assetFor(int $brandId, string $target): ?DigitalAsset
    {
        [$prefix, $id] = array_pad(explode(':', $target, 3), 2, '');
        $base = DigitalAsset::query()->where('brand_id', $brandId)->whereIn('type', ['google_business_profile', 'gbp'])->where('status', 'active');
        if (in_array($prefix, ['loc', 'std', 'adv', 'kw'], true) && ctype_digit($id)) {
            $asset = (clone $base)->find((int) $id);
            if ($asset !== null) {
                return $asset;
            }
        }
        if ($prefix === 'rev' && ctype_digit($id) && ($review = GbpReview::query()->find((int) $id)) !== null) {
            return $this->reviewAsset($brandId, $review);
        }

        return $base->orderBy('id')->first();
    }

    private function reviewAsset(int $brandId, GbpReview $review): ?DigitalAsset
    {
        $assetIds = CoreAssetBinding::query()->where('external_resource_id', $review->external_resource_id)->where('capability', 'google_business_profile')
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('digital_asset_id');

        return DigitalAsset::query()->where('brand_id', $brandId)->whereIn('id', $assetIds)->first();
    }

    private function napUrl(int $brandId, string $prefix, int $id): ?string
    {
        $site = DigitalAsset::query()->where('brand_id', $brandId)->where('type', 'website')
            ->when($prefix === 'nap', fn ($q) => $q->whereKey($id))->orderBy('id')->first();

        return $site !== null ? route('operator.website', ['assetId' => $site->id, 'tab' => 'scorecard']) : null;
    }
}
