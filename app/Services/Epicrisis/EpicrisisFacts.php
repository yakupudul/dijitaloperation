<?php

namespace App\Services\Epicrisis;

use App\Ai\Agents\Site\EpicrisisFactsAgent;
use App\Models\Brand;
use App\Models\BrandEpicrisis;
use App\Models\BrandEpicrisisItem;
use App\Models\BrandExpert;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandMemory;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\Brand\BrandFacts;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\SiteAi;
use App\Services\Site\SiteScope;
use App\Support\WebsiteTelephoneExtractor;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Epikriz katman 1 — doğrulanmış gerçekler. By rules from MoxDOP's own records (services, branches, experts, the
 * operator's card and business context), the Business Profile (phone, address, hours, website per branch) and the
 * site's own pages (phones, e-mails); once a month, or when the key pages change, the AI reads the key pages and the
 * profile descriptions for what only the business can say (warranty, materials, licence, experience, its process,
 * prices, payment, abroad care) — every such fact must be quoted word for word from its source, its numbers included.
 */
final class EpicrisisFacts
{
    /** Fields the rules fill; the AI reads the rest. */
    public const array RULE_FIELDS = ['service', 'branch', 'phone', 'email', 'address', 'hours', 'website'];

    /** Pages of these paths are read first. */
    private const string KEY_PATHS = '/(hakkimizda|hakkinda|kurumsal|about|iletisim|contact|fiyat|ucret|price|doktor|hekim|ekibimiz|kadromuz|team|garanti|warranty|neden|why|sss|faq|tedavi|hizmet)/';

    private const array DAYS = ['MONDAY' => 'Pzt', 'TUESDAY' => 'Sal', 'WEDNESDAY' => 'Çar', 'THURSDAY' => 'Per', 'FRIDAY' => 'Cum', 'SATURDAY' => 'Cmt', 'SUNDAY' => 'Paz'];

    public function __construct(private readonly EpicrisisStore $store, private readonly SiteAi $ai) {}

    /**
     * Rule facts of the brand, stored (the AI's site facts and the operator's answers are kept as they are).
     *
     * @return int facts stored or seen
     */
    public function collect(Brand $brand): int
    {
        $rows = [];
        foreach ([fn (): array => $this->records($brand), fn (): array => $this->profiles($brand), fn (): array => $this->sitePages($brand)] as $source) {
            try {
                array_push($rows, ...$source());
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $kept = $this->store->sync($brand, BrandEpicrisisItem::FACT, $rows, 'rules');

        return count($kept);
    }

    /** @return list<array<string, mixed>> */
    private function records(Brand $brand): array
    {
        $rows = [];
        foreach (SiteScope::offerings($brand) as $offering) {
            /** @var BrandOffering $offering */
            $name = trim($offering->displayName());
            if ($name !== '') {
                $rows[] = ['field' => 'service', 'value' => $name, 'source_kind' => 'moxdop', 'source_ref' => 'Marka › Hizmetler', 'confidence' => $offering->locked ? BrandEpicrisisItem::VERIFIED : BrandEpicrisisItem::DATA,
                    'detail' => ['rule' => true, 'main' => $offering->isMain()]];
            }
        }
        foreach (SiteScope::areas($brand) as $area) {
            if ($area->physical_branch && trim($area->label()) !== '') {
                $rows[] = ['field' => 'branch', 'value' => trim($area->label()), 'source_kind' => 'moxdop', 'source_ref' => 'Marka › Hizmet verdiği yerler', 'detail' => ['rule' => true]];
            }
        }
        foreach (BrandExpert::query()->where('brand_id', $brand->id)->orderByDesc('is_default')->orderBy('name')->get() as $expert) {
            $rows[] = ['field' => 'expert', 'value' => trim($expert->name.($expert->title ? ' · '.$expert->title : '')), 'source_kind' => 'moxdop', 'source_ref' => 'Marka › Ayarlar › Uzmanlar',
                'confidence' => BrandEpicrisisItem::VERIFIED, 'detail' => ['rule' => true]];
        }
        // What the operator wrote on the Marka bilgi kartı counts as said by the operator.
        $card = (array) data_get(BrandMemory::query()->where('brand_id', $brand->id)->where('kind', BrandFacts::KIND)->value('data'), 'manual', []);
        foreach (['services' => 'service', 'regions' => 'branch', 'experts' => 'expert'] as $key => $field) {
            foreach (preg_split('/\R/u', (string) data_get($card, $key.'.text', '')) ?: [] as $line) {
                $line = trim((string) preg_replace('/^[-*★\s]+/u', '', $line));
                if ($line !== '') {
                    $rows[] = ['field' => $field, 'value' => mb_substr($line, 0, 160), 'source_kind' => 'operator', 'source_ref' => 'Marka bilgi kartı', 'confidence' => BrandEpicrisisItem::VERIFIED, 'detail' => ['rule' => true]];
                }
            }
        }
        $context = BrandIntelligenceContext::query()->where('brand_id', $brand->id)->first();
        if ($context !== null) {
            $byOperator = in_array((string) $context->source, [BrandIntelligenceContext::SOURCE_OPERATOR, BrandIntelligenceContext::SOURCE_PUBLIC_DISCOVERY_EDITED], true);
            foreach ((array) $context->differentiators as $row) {
                $text = trim(is_array($row) ? (string) ($row['name'] ?? $row['label'] ?? '') : (string) $row);
                if ($text !== '') {
                    $rows[] = ['field' => 'differentiator', 'value' => mb_substr($text, 0, 200), 'source_kind' => $byOperator ? 'operator' : 'moxdop', 'source_ref' => 'Marka › İş bağlamı',
                        'confidence' => $byOperator ? BrandEpicrisisItem::VERIFIED : BrandEpicrisisItem::DATA, 'detail' => ['rule' => true]];
                }
            }
        }

        return $rows;
    }

    /** Per Business Profile: phone, address, hours and website, scoped to the branch. @return list<array<string, mixed>> */
    private function profiles(Brand $brand): array
    {
        $rows = [];
        $desk = app(GbpDesk::class);
        $locations = $desk->locations((int) $brand->id);
        foreach ($desk->snapshots($locations->pluck('id')->map(fn ($id): int => (int) $id)->all()) as $snapshot) {
            $scope = GbpDesk::shortName($snapshot['title']) ?: null;
            $ref = $snapshot['maps_uri'] !== '' ? $snapshot['maps_uri'] : 'İşletme Profili · '.$snapshot['title'];
            $base = ['scope' => $scope, 'source_kind' => 'gbp', 'source_ref' => mb_substr($ref, 0, 400), 'detail' => ['rule' => true]];
            if ($snapshot['phone'] !== '') {
                $rows[] = $base + ['field' => 'phone', 'value' => $snapshot['phone']];
            }
            if ($snapshot['address_text'] !== '') {
                $rows[] = $base + ['field' => 'address', 'value' => mb_substr($snapshot['address_text'], 0, 300)];
            }
            if (($hours = self::hours($snapshot['regular_hours'])) !== '') {
                $rows[] = $base + ['field' => 'hours', 'value' => $hours];
            }
            if ($snapshot['website'] !== '') {
                $rows[] = $base + ['field' => 'website', 'value' => mb_substr($snapshot['website'], 0, 300)];
            }
        }

        return $rows;
    }

    /** Phones and e-mails written on the site's own contact / about / home pages. @return list<array<string, mixed>> */
    private function sitePages(Brand $brand): array
    {
        $rows = [];
        $phones = new WebsiteTelephoneExtractor;
        foreach ($this->keyPages($brand)->take(15) as $page) {
            $text = (string) $page->content_text;
            $base = ['source_kind' => 'site', 'source_ref' => (string) $page->url, 'detail' => ['rule' => true]];
            if (preg_match_all('/(?:\+?90[\s.\-]?)?\(?0?\s?[2-5]\d{2}\)?[\s.\-]?\d{3}[\s.\-]?\d{2}[\s.\-]?\d{2}/u', $text, $m)) {
                foreach (array_unique($m[0]) as $raw) {
                    if ($phones->normalize($raw) !== null && strlen((string) preg_replace('/\D/', '', $raw)) >= 10) {
                        $rows[] = $base + ['field' => 'phone', 'value' => trim($raw), 'source_text' => trim($raw)];
                    }
                }
            }
            if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', $text, $m)) {
                foreach (array_unique($m[0]) as $mail) {
                    $rows[] = $base + ['field' => 'email', 'value' => mb_strtolower($mail), 'source_text' => $mail];
                }
            }
        }

        return $rows;
    }

    /**
     * The brand's key pages, in reading order: home, about / contact / team / prices, service pages.
     *
     * @return Collection<int, Page>
     */
    public function keyPages(Brand $brand): Collection
    {
        $sites = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->pluck('id');

        return Page::query()->whereIn('website_asset_id', $sites->all() ?: [0])->where('is_indexable', true)->whereNotNull('content_text')
            ->orderBy('id')->limit(3000)->get(['id', 'website_asset_id', 'url', 'path', 'title', 'h1', 'category', 'content_text', 'content_hash', 'content_summary', 'language'])
            ->map(function (Page $page): Page {
                $path = SeoText::fold(SeoText::urlPath((string) $page->url));
                $page->setAttribute('epicrisis_rank', match (true) {
                    trim($path, '/') === '' || preg_match('#^/[a-z]{2}/?$#', $path) === 1 => 0,
                    in_array((string) $page->category, ['kurumsal', 'iletisim'], true) || preg_match('/(hakkimizda|hakkinda|about|iletisim|contact|ekibimiz|kadromuz|doktor|hekim|team)/', $path) === 1 => 1,
                    preg_match(self::KEY_PATHS, $path) === 1 => 2,
                    (string) $page->category === 'hizmet' => 3,
                    default => 9,
                });

                return $page;
            })
            ->filter(fn (Page $p): bool => $p->getAttribute('epicrisis_rank') < 9)
            ->sortBy(fn (Page $p): array => [$p->getAttribute('epicrisis_rank'), mb_strlen((string) $p->path), $p->id])
            ->take((int) config('moxdop-epicrisis.key_pages', 40))->values();
    }

    /**
     * Reads the key pages and the profile descriptions with AI (`brand.epicrisis_facts`) when they changed or the last
     * reading is older than facts_days. Runs inside a resumable job: "queued" while Claude has not answered.
     *
     * @return array{status: string, facts?: int}
     */
    public function readSite(Brand $brand, bool $force = false): array
    {
        $head = BrandEpicrisis::for($brand);
        $pages = $this->keyPages($brand);
        $desk = app(GbpDesk::class);
        $profiles = collect($desk->snapshots($desk->locations((int) $brand->id)->pluck('id')->map(fn ($id): int => (int) $id)->all()))
            ->filter(fn (array $s): bool => $s['description'] !== '')->values();
        if ($pages->isEmpty() && $profiles->isEmpty()) {
            return ['status' => 'no_sources'];
        }
        $basis = hash('sha256', $pages->map(fn (Page $p): string => $p->id.':'.$p->content_hash)->implode('|').'#'.$profiles->map(fn (array $s): string => md5($s['description']))->implode('|'));
        $fresh = $head->facts_read_at !== null && $head->facts_read_at->gt(now()->subDays((int) config('moxdop-epicrisis.facts_days', 30)));
        if (! $force && $head->facts_basis === $basis && $fresh) {
            return ['status' => 'unchanged'];
        }
        $memory = BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'page')->where('ref_type', 'page')->whereIn('ref_id', $pages->pluck('id')->all() ?: [0])->get()->keyBy('ref_id');
        $sources = [];
        $texts = [];
        foreach ($pages as $page) {
            $facts = (array) data_get($memory->get($page->id)?->data, 'facts', []);
            $sources[] = ['ref' => (string) $page->url, 'title' => (string) ($page->title ?: $page->h1),
                'text' => mb_substr(trim(($page->content_summary ? $page->content_summary."\n" : '').($facts !== [] ? implode("\n", $facts)."\n" : '').$page->aiText(1800)), 0, 2600)];
            $texts[(string) $page->url] = (string) $page->title."\n".$page->content_text."\n".implode("\n", $facts)."\n".$page->content_summary;
        }
        foreach ($profiles as $profile) {
            $ref = 'gbp:'.GbpDesk::shortName($profile['title']);
            $sources[] = ['ref' => $ref, 'title' => $profile['title'], 'text' => mb_substr($profile['description'], 0, 1500)];
            $texts[$ref] = $profile['description'];
        }
        $fields = array_diff_key((array) config('moxdop-epicrisis.fields'), array_flip(self::RULE_FIELDS));
        $result = $this->ai->run(new EpicrisisFactsAgent, [
            'brand' => (string) $brand->name,
            'sector' => $brand->sectorLabel(),
            'services' => SiteScope::offerings($brand)->map(fn (BrandOffering $o): string => $o->displayName())->filter()->values()->all(),
            'branches' => SiteScope::areas($brand)->filter(fn ($a): bool => (bool) $a->physical_branch)->map(fn ($a): string => $a->label())->values()->all(),
            'fields' => array_map(fn (string $key, array $f): array => ['field' => $key, 'label' => $f['label']], array_keys($fields), $fields),
            'sources' => $sources,
        ], 600, 'epicrisis-facts-'.$brand->id.'-'.substr($basis, 0, 12));
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status']];
        }
        $rows = [];
        foreach ((array) ($result['data']['facts'] ?? []) as $fact) {
            $row = $this->groundedFact($fact, array_keys($fields), $texts);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        $this->store->sync($brand, BrandEpicrisisItem::FACT, $rows, 'site_ai');
        $head->forceFill(['facts_basis' => $basis, 'facts_read_at' => now()])->save();

        return ['status' => 'ready', 'facts' => count($rows)];
    }

    /**
     * An AI fact kept only when its quote stands in its source word for word and every number of its value is in the
     * quote.
     *
     * @param  list<string>  $fields
     * @param  array<string, string>  $texts
     * @return array<string, mixed>|null
     */
    private function groundedFact(mixed $fact, array $fields, array $texts): ?array
    {
        if (! is_array($fact)) {
            return null;
        }
        $field = (string) ($fact['field'] ?? '');
        $value = trim((string) ($fact['value'] ?? ''));
        $quote = trim((string) ($fact['quote'] ?? ''));
        $ref = (string) ($fact['source'] ?? '');
        if (! in_array($field, $fields, true) || mb_strlen($value) < 2 || mb_strlen($quote) < 4 || ! isset($texts[$ref])) {
            return null;
        }
        $source = SeoText::fold(preg_replace('/\s+/u', ' ', $texts[$ref]) ?? '');
        if (! str_contains($source, SeoText::fold(preg_replace('/\s+/u', ' ', $quote) ?? ''))) {
            return null;
        }
        preg_match_all('/\d+(?:[.,]\d+)?/u', $value, $numbers);
        foreach ($numbers[0] as $number) {
            if (! str_contains($quote, $number)) {
                return null;
            }
        }
        $scope = trim((string) ($fact['scope'] ?? ''));

        return ['field' => $field, 'scope' => $scope !== '' ? mb_substr($scope, 0, 160) : null, 'value' => mb_substr($value, 0, 240), 'source_kind' => str_starts_with($ref, 'gbp:') ? 'gbp' : 'site',
            'source_ref' => mb_substr($ref, 0, 400), 'source_text' => mb_substr($quote, 0, 400), 'detail' => ['extracted' => true]];
    }

    /** "Pzt–Cum 09:00–19:00, Cmt 10:00–14:00" from the profile's regular hours. @param list<array<string, mixed>> $periods */
    public static function hours(array $periods): string
    {
        $byDay = [];
        foreach ($periods as $period) {
            $day = (string) ($period['openDay'] ?? '');
            if (! isset(self::DAYS[$day])) {
                continue;
            }
            $time = fn (mixed $t): string => sprintf('%02d:%02d', (int) data_get($t, 'hours', 0), (int) data_get($t, 'minutes', 0));
            $byDay[$day][] = $time($period['openTime'] ?? []).'–'.$time($period['closeTime'] ?? []);
        }
        $out = [];
        $run = null;
        foreach (array_keys(self::DAYS) as $day) {
            $slot = isset($byDay[$day]) ? implode(', ', $byDay[$day]) : null;
            if ($run !== null && $run['slot'] === $slot) {
                $run['to'] = $day;

                continue;
            }
            if ($run !== null && $run['slot'] !== null) {
                $out[] = self::DAYS[$run['from']].($run['to'] !== $run['from'] ? '–'.self::DAYS[$run['to']] : '').' '.$run['slot'];
            }
            $run = ['from' => $day, 'to' => $day, 'slot' => $slot];
        }
        if ($run !== null && $run['slot'] !== null) {
            $out[] = self::DAYS[$run['from']].($run['to'] !== $run['from'] ? '–'.self::DAYS[$run['to']] : '').' '.$run['slot'];
        }

        return implode(', ', $out);
    }
}
