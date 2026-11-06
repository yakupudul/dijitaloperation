<?php

namespace App\Services\Site\Backlinks;

use App\Jobs\Site\ProposeBacklinkSourcesJob;
use App\Jobs\Site\VerifyBacklinkSourceJob;
use App\Jobs\Site\WriteLinkPack;
use App\Models\Backlink;
use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Models\BrandServiceArea;
use App\Models\LinkSource;
use App\Models\LinkTarget;
use App\Services\Compliance\SectorPack;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\SiteDomains;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Bağlantı masası (Backlink hizmeti mimarisi, katman 2 ve 4; yakup 2026-11-06): ONE work list of link opportunities for
 * every brand. Each month a brand gets as many rows as its package can turn into links (budget × IN_FLIGHT open rows);
 * rows come first from links that disappeared (geri kazanım), then from the shared pool (sector × city, the sources
 * that worked best first). A citation row points to the home page with the brand name; any other row points to a
 * target page of the Hedef motoru with the next text of its link text plan. Every row gets its material written by
 * Claude, then waits for the operator's click: nothing leaves MoxDOP by itself.
 *
 * Red lines: no paid links (a paid source is marked and its link must be sponsored), never our own brands linking each
 * other, no brandless sites, no more verified links a month than the package (a sudden jump looks unnatural).
 */
final class LinkDesk
{
    /** Verified links a month by package. */
    public const array PACKAGES = ['kapali' => 0, 'temel' => 2, 'buyume' => 4, 'otorite' => 6];

    public const array PACKAGE_LABELS = ['kapali' => 'Kapalı', 'temel' => 'Temel · ayda 2', 'buyume' => 'Büyüme · ayda 4', 'otorite' => 'Otorite · ayda 6'];

    /** Open rows a brand may have per monthly link (not every application brings a link). */
    public const int IN_FLIGHT = 3;

    /** A link Bing stopped listing within this many days gets a "geri iste" row. */
    public const int LOST_DAYS = 60;

    /** Open desk statuses: prepared or sent, no link yet. */
    public const array OPEN = [BacklinkSource::NONE, BacklinkSource::APPLIED];

    public function __construct(private readonly LinkTargets $targets) {}

    public static function budget(Brand $brand): int
    {
        return self::PACKAGES[(string) ($brand->link_package ?? 'temel')] ?? self::PACKAGES['temel'];
    }

    /**
     * This month of one brand: the package's links, links given or verified this month, rows open now.
     *
     * @return array{budget: int, won: int, open: int, room: int}
     */
    public function month(Brand $brand): array
    {
        $budget = self::budget($brand);
        $start = now('Europe/Istanbul')->startOfMonth()->utc();
        $won = BacklinkSource::query()->where('brand_id', $brand->id)->where(fn (Builder $q) => $q
            ->where(fn (Builder $v) => $v->where('status', BacklinkSource::VERIFIED)->where('verified_at', '>=', $start))
            ->orWhere(fn (Builder $g) => $g->where('status', BacklinkSource::GIVEN)->where('given_at', '>=', $start)))->count();
        $open = self::openRows($brand)->count();
        $room = $won >= $budget ? 0 : max(0, $budget * self::IN_FLIGHT - $open);

        return ['budget' => $budget, 'won' => $won, 'open' => $open, 'room' => $room];
    }

    /**
     * Refreshes the brand's targets and adds new rows up to its room this month; each new row's material is written.
     *
     * @return array{targets: array<string, int>, added: int, recovery: int, discovery: bool}
     */
    public function fill(Brand $brand): array
    {
        $out = ['targets' => ['picked' => 0, 'kept' => 0, 'done' => 0, 'released' => 0], 'added' => 0, 'recovery' => 0, 'discovery' => false];
        if (! $brand->isOperational() || self::budget($brand) === 0 || ! self::hasServicedSite($brand)) {
            return $out;
        }
        $out['targets'] = $this->targets->refresh($brand);
        LinkMagnets::ensureTarget($brand);
        LinkCitations::ensure();
        $room = $this->month($brand)['room'];
        if ($room === 0) {
            return $out;
        }
        $own = SiteDomains::ownDomains($brand);
        $known = BacklinkSource::query()->where('brand_id', $brand->id)->pluck('domain')->map(fn ($d): string => SiteDomains::registrable((string) $d))->flip();
        $linking = Backlink::query()->where('brand_id', $brand->id)->where('status', 'aktif')->distinct()->pluck('source_domain')
            ->map(fn ($d): string => SiteDomains::registrable((string) $d))->flip();
        $new = [];

        // 1. Geri kazanım: a site that linked and stopped (Bing no longer lists it) is asked to put it back.
        $lost = Backlink::query()->where('brand_id', $brand->id)->where('status', 'kaldirildi')->where('lost_at', '>=', now()->subDays(self::LOST_DAYS)->toDateString())
            ->orderByDesc('lost_at')->get()->unique(fn (Backlink $b): string => SiteDomains::registrable((string) $b->source_domain));
        foreach ($lost as $link) {
            $domain = SiteDomains::registrable((string) $link->source_domain);
            if ($room === 0 || $known->has($domain) || $linking->has($domain) || SiteDomains::isOwn($domain, $own) || self::otherBrandDomain($brand, $domain)) {
                continue;
            }
            $new[] = BacklinkSource::query()->create([
                'brand_id' => $brand->id, 'name' => $domain, 'url' => (string) $link->source_url, 'domain' => (string) $link->source_domain, 'kind' => 'diger',
                'fee' => 'teyit', 'origin' => 'ai', 'status' => BacklinkSource::NONE, 'engine' => 'geri_kazanim', 'lane' => 'masa',
                'reason' => mb_substr('Bu site '.($link->lost_at?->format('d.m.Y') ?? '').' tarihine kadar '.SeoText::urlPath((string) $link->target_url).' sayfasına link veriyordu.', 0, 240),
                'anchor' => $link->anchor ?: $brand->name, 'material_status' => 'queued',
                'link_target_id' => $this->targetFor($brand, (string) $link->target_url)?->id,
            ]);
            $known[$domain] = true;
            $room--;
            $out['recovery']++;
        }

        $targets = LinkTarget::query()->where('brand_id', $brand->id)->where('status', LinkTarget::ACTIVE)->orderBy('rank')->get();

        // 2. The brand's own candidates (the client's form and the operator first, then AI proposals and sites shown for
        // its searches once their pool source passed the quality gate), oldest first.
        foreach (BacklinkSource::query()->with('pool')->where('brand_id', $brand->id)->where('status', BacklinkSource::NONE)->whereNull('material_status')
            ->orderByRaw("CASE WHEN origin IN ('musteri', 'manual') THEN 0 ELSE 1 END")->orderBy('id')->get() as $candidate) {
            if ($room === 0) {
                break;
            }
            if ($candidate->pool !== null && $candidate->pool->quality !== 'uygun') {
                continue; // waits for the quality gate (a refused one is dropped by the gate)
            }
            if ($linking->has(SiteDomains::registrable((string) $candidate->domain)) || self::otherBrandDomain($brand, SiteDomains::registrable((string) $candidate->domain))) {
                continue;
            }
            $kind = $candidate->pool?->kind ?? $candidate->kind;
            $citation = in_array($kind, LinkSource::CITATION_KINDS, true);
            $target = $citation ? null : $this->nextTarget($targets, $kind);
            $candidate->forceFill(['material_status' => 'queued', 'link_target_id' => $target?->id,
                'anchor' => $target !== null ? $this->anchorFor($target) : $brand->name])->save();
            $new[] = $candidate;
            $room--;
        }

        // 3. The shared pool: the sector's and the brand's cities' sources; the ones that link to the brand's competitors
        // and not to it first (rakip boşluğu), then the ones that brought links.
        if ($room > 0) {
            $rivals = LinkRivals::rivals($brand);
            $pool = $this->poolFor($brand)->take(200)->map(fn (LinkSource $s): array => [$s, LinkRivals::gap($s, $rivals, $own)])
                ->sortByDesc(fn (array $p): int => count($p[1]))->values();
            foreach ($pool as [$source, $gap]) {
                if ($room === 0) {
                    break;
                }
                $domain = SiteDomains::registrable($source->domain);
                if ($known->has($domain) || $linking->has($domain) || SiteDomains::isOwn($domain, $own) || self::otherBrandDomain($brand, $domain)) {
                    continue;
                }
                $citation = in_array($source->kind, LinkSource::CITATION_KINDS, true);
                $target = $citation ? null : $this->nextTarget($targets, $source->kind);
                $new[] = BacklinkSource::query()->create([
                    'brand_id' => $brand->id, 'name' => $source->name, 'url' => $source->apply_url ?: $source->url, 'domain' => $source->domain,
                    'kind' => self::legacyKind($source->kind), 'fee' => $source->fee, 'fee_evidence_url' => $source->fee_evidence_url, 'origin' => 'ai',
                    'status' => BacklinkSource::NONE, 'link_source_id' => $source->id, 'engine' => $gap !== [] ? 'rakip' : ($citation ? 'kunye' : 'iliski'),
                    'lane' => in_array($source->kind, LinkSource::PERSON_KINDS, true) || $source->apply_via === 'telefon' ? 'sen' : 'masa',
                    'paid' => $source->fee === 'ucretli', 'reason' => mb_substr(($gap !== [] ? 'Rakiplerinize link veriyor: '.implode(', ', array_slice($gap, 0, 3)).' · ' : '').self::poolReason($source), 0, 240),
                    'link_target_id' => $target?->id, 'anchor' => $target !== null ? $this->anchorFor($target) : $brand->name, 'material_status' => 'queued',
                ]);
                $known[$domain] = true;
                $room--;
            }
        }
        foreach ($new as $row) {
            WriteLinkPack::dispatch((int) $row->id);
        }
        $out['added'] = count($new);

        // Künye keşfi: the pool has nothing left for this brand, so Claude looks for its city's and sector's sources
        // (they pass the quality gate before the next fill). At most once a month per brand.
        if ($room > 0 && ! $this->hasFreshPool($brand, $known, $linking) && Cache::add('links:discover:'.$brand->id, true, now()->addDays(30))) {
            BacklinkSourceProposer::markRunning((int) $brand->id);
            ProposeBacklinkSourcesJob::dispatch((int) $brand->id);
            $out['discovery'] = true;
        }

        return $out;
    }

    /**
     * Pool sources a brand may use: passed the quality gate, of its sector or any sector, of its cities or the whole
     * country (health platforms only for health brands); the ones that brought links first, then by authority.
     *
     * @return Collection<int, LinkSource>
     */
    public function poolFor(Brand $brand): Collection
    {
        $cities = self::cities($brand);

        $health = self::isHealth($brand);

        return LinkSource::query()->where('quality', 'uygun')->when(! $health, fn (Builder $q) => $q->where('kind', '!=', 'saglik'))
            ->where(fn (Builder $q) => $q->whereNull('sector_id')->when($brand->sector_id !== null, fn (Builder $s) => $s->orWhere('sector_id', $brand->sector_id)))
            ->where(fn (Builder $q) => $q->whereNull('city')->when($cities !== [], fn (Builder $c) => $c->orWhereIn('city', $cities)))
            ->get()
            ->sortByDesc(fn (LinkSource $s): array => [$s->won_count > 0 ? 1 : 0, $s->winRate() ?? 50, $s->quality === 'uygun' ? 1 : 0, $s->authority ?? 0])
            ->values();
    }

    /**
     * A source found for one brand (AI proposal, a pasted competitor list, the operator) joins the shared pool once.
     *
     * @param  array{name: string, url: string, domain: string, kind?: string, fee?: string, fee_evidence_url?: ?string, city?: ?string, apply_via?: ?string, contact_email?: ?string}  $source
     */
    public static function absorb(array $source, ?Brand $brand, string $origin = 'ai'): LinkSource
    {
        $existing = LinkSource::query()->where('domain', $source['domain'])->first();
        if ($existing !== null) {
            $fill = array_filter([
                'city' => $existing->city ?? (isset($source['city']) ? self::city((string) $source['city']) : null),
                'apply_via' => $existing->apply_via === 'bilinmiyor' && isset(LinkSource::APPLY_VIA[$source['apply_via'] ?? '']) ? $source['apply_via'] : null,
                'contact_email' => $existing->contact_email ?? self::email($source['contact_email'] ?? null),
            ], fn ($v): bool => $v !== null && $v !== '');
            if ($fill !== []) {
                $existing->forceFill($fill)->save();
            }

            return $existing;
        }

        return LinkSource::query()->create([
            'domain' => $source['domain'], 'name' => mb_substr($source['name'], 0, 200), 'url' => $source['url'],
            'kind' => isset(LinkSource::KINDS[$source['kind'] ?? '']) ? $source['kind'] : 'diger', 'sector_id' => $brand?->sector_id,
            'city' => isset($source['city']) && trim((string) $source['city']) !== '' ? self::city((string) $source['city']) : null,
            'apply_via' => isset(LinkSource::APPLY_VIA[$source['apply_via'] ?? '']) ? $source['apply_via'] : 'bilinmiyor',
            'contact_email' => self::email($source['contact_email'] ?? null), 'fee' => $source['fee'] ?? 'teyit',
            'fee_evidence_url' => $source['fee_evidence_url'] ?? null, 'origin' => in_array($origin, ['ai', 'manual', 'serp'], true) ? $origin : 'ai',
        ]);
    }

    /** The operator applied / sent: the row waits for an answer; FOLLOW_UP_DAYS later it asks for a follow-up. */
    public function sent(BacklinkSource $row): void
    {
        if ($row->status !== BacklinkSource::NONE) {
            return;
        }
        $row->forceFill(['status' => BacklinkSource::APPLIED, 'sent_at' => now(), 'next_step_at' => now()->addDays(BacklinkSource::FOLLOW_UP_DAYS)])->save();
        $row->pool?->increment('applied_count');
    }

    /** The source says the link is up: the page is checked at once. */
    public function given(BacklinkSource $row, string $linkUrl): void
    {
        $row->forceFill(['status' => BacklinkSource::GIVEN, 'link_url' => $linkUrl, 'given_at' => now(), 'next_step_at' => null])->save();
        VerifyBacklinkSourceJob::dispatch((int) $row->id);
    }

    public function drop(BacklinkSource $row, string $why = ''): void
    {
        $row->forceFill(['status' => BacklinkSource::DROPPED, 'next_step_at' => null, 'note' => mb_substr($why, 0, 240) ?: $row->note])->save();
    }

    /** A dropped row back on the desk (material kept). */
    public function reopen(BacklinkSource $row): void
    {
        if ($row->status === BacklinkSource::DROPPED) {
            $row->forceFill(['status' => BacklinkSource::NONE, 'material_status' => $row->material !== null ? 'ready' : 'queued'])->save();
            if ($row->material === null) {
                WriteLinkPack::dispatch((int) $row->id);
            }
        }
    }

    /** Called when the verifier finds the link the first time: the pool learns what this source brings and how fast. */
    public static function learn(BacklinkSource $row): void
    {
        $pool = $row->pool;
        if ($pool === null) {
            return;
        }
        $from = $row->sent_at ?? $row->created_at;
        $days = $from !== null ? (int) max(0, round($from->diffInDays(now()))) : 0;
        $pool->forceFill(['won_count' => $pool->won_count + 1, 'days_total' => $pool->days_total + $days,
            'quality' => $pool->quality === 'bekliyor' ? 'uygun' : $pool->quality])->save();
    }

    /** Health, dental and medical aesthetics brands (the health sector pack applies to them). */
    public static function isHealth(Brand $brand): bool
    {
        return collect(app(SectorPackRegistry::class)->forBrand($brand))->contains(fn (SectorPack $pack): bool => $pack->id() === 'health');
    }

    /**
     * @param  Collection<string, mixed>  $known
     * @param  Collection<string, mixed>  $linking
     */
    private function hasFreshPool(Brand $brand, Collection $known, Collection $linking): bool
    {
        return $this->poolFor($brand)->contains(fn (LinkSource $s): bool => ! $known->has(SiteDomains::registrable($s->domain)) && ! $linking->has(SiteDomains::registrable($s->domain)))
            || LinkSource::query()->where('quality', 'bekliyor')->where('sector_id', $brand->sector_id)->exists();
    }

    /**
     * Rows on the desk now: sent ones and prepared ones. A candidate (found by an AI proposal, the brand's searches or
     * the operator, no material yet) is not on the desk until fill() takes it in.
     *
     * @return Builder<BacklinkSource>
     */
    public static function openRows(Brand $brand): Builder
    {
        return BacklinkSource::query()->where('brand_id', $brand->id)->where(fn (Builder $q) => $q->where('status', BacklinkSource::APPLIED)
            ->orWhere(fn (Builder $n) => $n->where('status', BacklinkSource::NONE)->whereNotNull('material_status')));
    }

    /** @param  Collection<int, LinkTarget>  $targets */
    private function nextTarget(Collection $targets, ?string $kind = null): ?LinkTarget
    {
        if ($targets->isEmpty()) {
            return null;
        }
        // News, associations, chambers and universities point to the link magnet (a guide they can quote) when there is one.
        $magnet = $targets->firstWhere('kind', LinkMagnets::TARGET_KIND);
        if ($magnet !== null && in_array($kind, LinkMagnets::MAGNET_SOURCES, true)) {
            return $magnet;
        }
        $targets = $targets->where('kind', '!=', LinkMagnets::TARGET_KIND)->whenEmpty(fn () => $targets);
        $counts = BacklinkSource::query()->whereIn('link_target_id', $targets->pluck('id'))->where('status', '!=', BacklinkSource::DROPPED)
            ->selectRaw('link_target_id, count(*) as n')->groupBy('link_target_id')->pluck('n', 'link_target_id');

        return $targets->sortBy(fn (LinkTarget $t): array => [(int) ($counts[$t->id] ?? 0), $t->rank])->first();
    }

    private function anchorFor(LinkTarget $target): string
    {
        $given = BacklinkSource::query()->where('link_target_id', $target->id)->where('status', '!=', BacklinkSource::DROPPED)
            ->whereNotNull('anchor')->pluck('anchor')->map(fn ($a): string => (string) $a)->all();

        return LinkTargets::nextAnchor($target, $given) ?: (string) $target->brand?->name;
    }

    private function targetFor(Brand $brand, string $url): ?LinkTarget
    {
        return LinkTarget::query()->where('brand_id', $brand->id)->where('url_key', hash('sha256', SeoText::urlKey($url)))->first();
    }

    /** @return list<string> folded city names of the brand's active places */
    public static function cities(Brand $brand): array
    {
        return BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->pluck('city_name')
            ->map(fn ($c): string => self::city((string) $c))->filter()->unique()->values()->all();
    }

    public static function city(string $name): string
    {
        return mb_substr(trim(SeoText::fold($name)), 0, 80);
    }

    private static function email(mixed $value): ?string
    {
        $value = trim((string) $value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($value, 0, 255) : null;
    }

    /** Red line: our brands never link to each other, so another serviced brand's domain is never a source. */
    private static function otherBrandDomain(Brand $brand, string $domain): bool
    {
        static $map = null;
        if ($map === null || app()->runningUnitTests()) {
            $map = [];
            foreach (Brand::query()->with('customer')->get() as $other) {
                foreach (SiteDomains::ownDomains($other) as $own) {
                    $map[SiteDomains::registrable($own)][] = (int) $other->id;
                }
            }
        }

        return collect($map[$domain] ?? [])->contains(fn (int $id): bool => $id !== (int) $brand->id);
    }

    private static function hasServicedSite(Brand $brand): bool
    {
        return $brand->digitalAssets()->where('type', 'website')->where('status', 'active')->exists();
    }

    private static function legacyKind(string $kind): string
    {
        return in_array($kind, ['dizin', 'dernek', 'yerel_haber', 'oda'], true) ? $kind : ($kind === 'rehber' || $kind === 'saglik' || $kind === 'harita' ? 'dizin' : 'diger');
    }

    private static function poolReason(LinkSource $source): string
    {
        $rate = $source->winRate();

        return $source->kindLabel().($source->city !== null ? ' · '.mb_convert_case($source->city, MB_CASE_TITLE) : ' · ulusal')
            .($source->won_count > 0 ? ' · '.$source->won_count.' markaya link verdi (%'.$rate.')' : '')
            .($source->averageDays() !== null ? ', ort. '.$source->averageDays().' gün' : '');
    }
}
