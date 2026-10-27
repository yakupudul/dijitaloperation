<?php

namespace App\Services\Site\Showcase;

use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\GbpReview;
use App\Models\SiteQuestion;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\Desk\ReviewDesk;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Services\Site\BrandlessSites;
use App\Support\ServiceScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sorular ve yorumlar (yakup, 2026-10-27): what MoxDOP sends to the brand's WordPress sites (connector ≥ 1.28.0) while
 * the brand switch "Sitede yorum ve soru göster" is on: the Google reviews of every served Business Profile of the
 * brand (4–5 stars with text; no price, phone, e-mail or link; "Sitede gizle" left out; the reviewer's name shortened)
 * with Google's rating, and the approved questions of every page the site admin assigned. The site decides where they
 * show (widget / shortcode). Switching the brand off sends `enabled = false`, which removes them from the site.
 */
final class SiteShowcase
{
    public const string MIN_VERSION = '1.28.0';

    /** Reviews sent to a site, newest first. */
    public const int REVIEW_LIMIT = 200;

    private const array STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    public function __construct(private readonly WordPressConnectorClient $client, private readonly GbpDesk $gbp) {}

    /**
     * The brand's paired WordPress sites (enabled connection).
     *
     * @return Collection<int, CoreConnection>
     */
    public function connections(Brand $brand): Collection
    {
        return CoreConnection::query()->with(['credential', 'digitalAsset'])->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('enabled', true)->whereIn('digital_asset_id', DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->select('id'))
            ->orderBy('id')->get();
    }

    public static function supports(CoreConnection $connection): bool
    {
        return version_compare((string) data_get($connection->config, 'plugin_version', '0.0.0'), self::MIN_VERSION, '>=');
    }

    public function connection(int $siteId): ?CoreConnection
    {
        return CoreConnection::query()->with(['credential', 'digitalAsset.brand'])->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('enabled', true)->where('digital_asset_id', $siteId)->first();
    }

    /**
     * The assigned pages of a site (plugin's Settings › Sorular ve yorumlar).
     *
     * @return list<array{key: string, kind: string, id: int, type: string, title: string, url: string, qa_url: string, published: int, language: string}>
     */
    public function targets(CoreConnection $connection): array
    {
        $state = $this->client->showcaseState($connection);

        return array_values(array_filter((array) ($state['targets'] ?? []), fn (mixed $t): bool => is_array($t) && preg_match('/^(post|term):\d+$/', (string) ($t['key'] ?? '')) === 1));
    }

    /**
     * Sends the brand switch, the reviews and the published questions to one site. Returns the site's answer plus the
     * assigned pages it reported (null when the site was not asked).
     *
     * @return array{sent: bool, reason?: string, result?: array<string, mixed>, targets?: list<array<string, mixed>>}
     */
    public function sync(int $siteId): array
    {
        $connection = $this->connection($siteId);
        $site = $connection?->digitalAsset;
        if ($connection === null || $site === null) {
            return ['sent' => false, 'reason' => 'Sitenin MoxDOP eklentisi bağlı değil.'];
        }
        if (! self::supports($connection)) {
            return ['sent' => false, 'reason' => 'Eklenti '.data_get($connection->config, 'plugin_version', '?').'; en az '.self::MIN_VERSION.' gerekli.'];
        }
        $brand = $site->brand;
        $on = $brand !== null && $brand->showcase_enabled && BrandlessSites::serviced($site);
        if (! $on) {
            return ['sent' => true, 'result' => $this->client->showcaseApply($connection, ['enabled' => false]), 'targets' => []];
        }
        $targets = $this->targets($connection);
        $qa = [];
        foreach ($targets as $target) {
            $qa[$target['key']] = $this->items($siteId, $target['key']);
        }
        // 1.29.0: visitor questions taken off in MoxDOP stay off the site even when they were answered there by hand.
        $hidden = SiteQuestion::query()->where('website_asset_id', $siteId)->where('status', SiteQuestion::HIDDEN)->whereNotNull('local_id')
            ->latest('id')->limit(500)->pluck('local_id')->map(fn ($id): int => (int) $id)->all();
        $result = $this->client->showcaseApply($connection, ['enabled' => true, 'reviews' => $this->reviews($brand), 'qa' => (object) $qa, 'qa_hidden' => $hidden]);

        return ['sent' => true, 'result' => $result, 'targets' => $targets];
    }

    /**
     * Published questions of one page, in MoxDOP's order (asked first by the order they were approved).
     *
     * @return list<array<string, mixed>>
     */
    public function items(int $siteId, string $target): array
    {
        return SiteQuestion::query()->where('website_asset_id', $siteId)->where('target', $target)->where('status', SiteQuestion::PUBLISHED)
            ->orderBy('position')->orderBy('published_at')->orderBy('id')->get()
            ->map(fn (SiteQuestion $q): array => [
                'id' => 'm'.$q->id, 'q' => (string) $q->question, 'a' => (string) $q->answer, 'date' => $q->asked_at?->toDateString() ?? $q->created_at?->toDateString(),
                'source' => $q->source, 'link_url' => (string) $q->link_url, 'link_title' => (string) $q->link_title, 'local_id' => (int) $q->local_id,
            ])->values()->all();
    }

    /**
     * The brand's served Business Profiles: Google's rating and count (latest snapshot) and the reviews a site may show.
     *
     * @return array{rating: float, count: int, url: string, places: list<array<string, mixed>>, items: list<array<string, mixed>>}
     */
    public function reviews(Brand $brand): array
    {
        $scope = app(ServiceScope::class);
        $assets = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'google_business_profile')->orderBy('id')->get(['id', 'name'])
            ->filter(fn (DigitalAsset $a): bool => $scope->isAssetOperational($a->id))->values();
        $snapshots = $this->gbp->snapshots($assets->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $places = [];
        $sum = 0.0;
        $count = 0;
        foreach ($assets as $asset) {
            $snap = $snapshots[$asset->id] ?? null;
            if ($snap === null || ($snap['reviews'] ?? 0) < 1) {
                continue;
            }
            $places[$snap['resource_id']] = ['name' => GbpDesk::shortName((string) $asset->name), 'rating' => (float) $snap['rating'], 'count' => (int) $snap['reviews'], 'url' => (string) $snap['maps_uri']];
            $sum += (float) $snap['rating'] * (int) $snap['reviews'];
            $count += (int) $snap['reviews'];
        }
        $items = $places === [] ? [] : GbpReview::query()->whereIn('external_resource_id', array_keys($places))->where('site_hidden', false)
            ->whereIn(DB::raw('upper(star_rating)'), ['FOUR', 'FIVE'])->whereNotNull('comment')->orderByDesc('create_time')->limit(self::REVIEW_LIMIT * 2)->get()
            ->map(function (GbpReview $review) use ($places): ?array {
                $text = trim(ReviewDesk::original((string) $review->comment));
                if (! self::showable($text)) {
                    return null;
                }

                return ['id' => 'g'.$review->id, 'name' => self::shortName((string) data_get($review->reviewer, 'displayName', '')), 'stars' => self::STARS[strtoupper((string) $review->star_rating)] ?? 5,
                    'text' => mb_substr($text, 0, 2000), 'date' => $review->create_time?->toDateString() ?? '',
                    'reply' => mb_substr(trim((string) data_get($review->review_reply, 'comment', '')), 0, 1500), 'place' => (string) ($places[$review->external_resource_id]['name'] ?? '')];
            })->filter()->take(self::REVIEW_LIMIT)->values()->all();
        $first = array_values($places)[0] ?? null;

        return ['rating' => $count > 0 ? round($sum / $count, 1) : 0.0, 'count' => $count, 'url' => (string) ($first['url'] ?? ''), 'places' => array_values($places), 'items' => $items];
    }

    /** A review a site may show: it has text, and no price, phone number, e-mail or link. */
    public static function showable(string $text): bool
    {
        $text = trim($text);
        if (mb_strlen($text) < 2) {
            return false;
        }
        $patterns = [
            '/\d[\d.,]*\s*(tl|₺|try|lira|euro|eur|€|\$|usd|dolar|pound|£|sterlin)\b/iu', '/(₺|€|\$|£)\s*\d/u',
            '/(\+?90|0)?\s*\(?5\d{2}\)?[\s.-]*\d{3}[\s.-]*\d{2}[\s.-]*\d{2}/u', '/\b0?\(?[2-4]\d{2}\)?[\s.-]*\d{3}[\s.-]*\d{2}[\s.-]*\d{2}\b/u',
            '/[^\s@]+@[^\s@]+\.[a-z]{2,}/iu', '~https?://|www\.~iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return false;
            }
        }

        return true;
    }

    /** "Ayşe Yılmaz" → "Ayşe Y."; an anonymous Google user → "Google kullanıcısı". */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $parts = array_values(array_filter($parts, fn (string $p): bool => $p !== ''));
        if ($parts === [] || str_contains(mb_strtolower($name), 'google')) {
            return 'Google kullanıcısı';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }

        return $parts[0].' '.mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)).'.';
    }

    /** The brand's sites that can take it now (paired, served, connector ≥ 1.28.0). @return list<int> */
    public function siteIds(Brand $brand): array
    {
        return $this->connections($brand)->filter(fn (CoreConnection $c): bool => self::supports($c) && $c->digitalAsset !== null && BrandlessSites::serviced($c->digitalAsset))
            ->pluck('digital_asset_id')->map(fn ($id): int => (int) $id)->values()->all();
    }

    /** Throws when the site cannot take it (used by the operator's screens). */
    public function assertSupported(int $siteId): CoreConnection
    {
        $connection = $this->connection($siteId);
        if ($connection === null || ! self::supports($connection)) {
            throw new RuntimeException('Bu sitenin eklentisi '.self::MIN_VERSION.' ya da üstü değil; Web siteleri ekranından güncelle.');
        }

        return $connection;
    }
}
