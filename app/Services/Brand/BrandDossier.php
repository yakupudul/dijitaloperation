<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandMemory;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\ResourceAutomation;
use App\Models\Suggestion;
use App\Services\Site\Analysis\SitePagesReader;
use App\Services\Site\SiteScope;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Marka dosyası (operator decision 2026-11-17): one short markdown file per brand that every AI agent reads first, so
 * no agent reads the thousands of rows behind it again. Compiled from the stages without AI: identity → bound assets →
 * services → demand (queries, clusters) → website state → decisions and their measured outcomes → open work → the
 * operator's notes (goals / constraints, edited on the same tab; the only hand-written part).
 *
 * Stored in `brand_memory` (kind `dossier`): the markdown, every section's hash (what changed since an agent last
 * looked: `changedSince()`), the whole file's hash and when it was built. Rebuilt nightly for operational brands, after
 * "Otomatik kur" and on the tab's "Yenile"; unchanged sections keep their hash, so a rebuild with nothing new is a no-op
 * for the agents.
 */
final class BrandDossier
{
    public const string KIND = 'dossier';

    public const array SECTIONS = [
        'identity' => 'Kimlik',
        'assets' => 'Bağlı varlıklar',
        'services' => 'Hizmetler',
        'demand' => 'Talep',
        'site' => 'Web sitesi durumu',
        'decisions' => 'Kararlar ve sonuçları',
        'open' => 'Açık işler',
        'notes' => 'Operatörün notları',
    ];

    private const int TOP_CLUSTERS = 5;

    private const int DECISIONS = 10;

    private const int OPEN_WORK = 5;

    /** @return array{markdown: string, sections: array<string, array{title: string, markdown: string, hash: string}>, hash: string, built_at: ?string}|null */
    public static function stored(Brand $brand): ?array
    {
        $row = self::row($brand);
        if ($row === null || ! is_array($row->data)) {
            return null;
        }

        return ['markdown' => (string) $row->summary, 'sections' => (array) ($row->data['sections'] ?? []), 'hash' => (string) ($row->data['hash'] ?? ''),
            'built_at' => $row->data['built_at'] ?? null];
    }

    /** The file for a prompt: the stored one (built now if missing). */
    public function forPrompt(Brand $brand): string
    {
        return (self::stored($brand) ?? $this->build($brand))['markdown'];
    }

    /**
     * Section keys whose content changed since the given section hashes (an agent's last read).
     *
     * @param  array<string, string>  $seen  section => hash
     * @return list<string>
     */
    public static function changedSince(Brand $brand, array $seen): array
    {
        $stored = self::stored($brand);

        return $stored === null ? array_keys(self::SECTIONS) : array_values(array_keys(array_filter($stored['sections'],
            fn (array $section, string $key): bool => ($seen[$key] ?? null) !== $section['hash'], ARRAY_FILTER_USE_BOTH)));
    }

    /** @return array{markdown: string, sections: array<string, array{title: string, markdown: string, hash: string}>, hash: string, built_at: string} */
    public function build(Brand $brand): array
    {
        $sections = [];
        foreach (self::SECTIONS as $key => $title) {
            try {
                $body = trim($this->section($key, $brand));
            } catch (Throwable $exception) {
                report($exception);
                $body = '_(okunamadı)_';
            }
            $sections[$key] = ['title' => $title, 'markdown' => $body !== '' ? $body : '_—_', 'hash' => md5($body)];
        }
        $markdown = '# '.$brand->name."\n\n".collect($sections)->map(fn (array $s): string => '## '.$s['title']."\n".$s['markdown'])->implode("\n\n")."\n";
        $result = ['markdown' => $markdown, 'sections' => $sections, 'hash' => md5($markdown), 'built_at' => now()->toIso8601String()];

        $row = self::row($brand) ?? new BrandMemory(['brand_id' => $brand->id, 'kind' => self::KIND, 'ref_type' => 'brand', 'ref_id' => $brand->id]);
        $row->forceFill(['summary' => $markdown, 'data' => ['sections' => $sections, 'hash' => $result['hash'], 'built_at' => $result['built_at']]])->save();

        return $result;
    }

    /** Hedefler / kısıtlar: the operator's own lines (same row as Marka › Ayarlar notes). */
    public static function saveNotes(Brand $brand, string $goals, string $constraints): void
    {
        $row = BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'profile')->where('ref_type', 'manual_notes')->first()
            ?? new BrandMemory(['brand_id' => $brand->id, 'kind' => 'profile', 'ref_type' => 'manual_notes']);
        $row->fill(['data' => ['goals' => trim($goals), 'constraints' => trim($constraints)], 'summary' => null])->save();
    }

    /** @return array{goals: string, constraints: string} */
    public static function notes(Brand $brand): array
    {
        $data = (array) BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'profile')->where('ref_type', 'manual_notes')->value('data');

        return ['goals' => (string) ($data['goals'] ?? ''), 'constraints' => (string) ($data['constraints'] ?? '')];
    }

    private static function row(Brand $brand): ?BrandMemory
    {
        return BrandMemory::query()->where('brand_id', $brand->id)->where('kind', self::KIND)->first();
    }

    private function section(string $key, Brand $brand): string
    {
        return match ($key) {
            'identity' => $this->identity($brand),
            'assets' => $this->assets($brand),
            'services' => $this->services($brand),
            'demand' => $this->demand($brand),
            'site' => $this->site($brand),
            'decisions' => $this->decisions($brand),
            'open' => $this->openWork($brand),
            'notes' => $this->notesSection($brand),
            default => '',
        };
    }

    private function identity(Brand $brand): string
    {
        $brand->loadMissing(['customer', 'sectorCategory']);
        $areas = SiteScope::areas($brand)->map(fn ($a): string => $a->label().($a->physical_branch ? ' (şube)' : ''))->filter()->all();

        return implode("\n", array_filter([
            '- Müşteri: '.($brand->customer?->name ?? '—').($brand->isOperational() ? ' · aktif' : ' · pasif'),
            '- Sektör: '.($brand->sectorCategory?->name ?? 'atanmamış'),
            '- Hizmet bölgeleri: '.($areas !== [] ? implode(', ', $areas) : 'tanımlı değil'),
        ]));
    }

    private function assets(Brand $brand): string
    {
        $lines = [];
        foreach (DigitalAsset::query()->where('brand_id', $brand->id)->orderBy('type')->orderBy('id')->get(['id', 'type', 'name', 'primary_url', 'domain']) as $asset) {
            $bindings = CoreAssetBinding::query()->with('externalResource:id,display_name,resource_type')->where('digital_asset_id', $asset->id)
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)->get();
            $accounts = $bindings->map(function (CoreAssetBinding $b): string {
                $through = ResourceAutomation::query()->where('external_resource_id', $b->external_resource_id)->value('data_through');

                return (string) ($b->externalResource?->resource_type ?? $b->capability).($through !== null ? ' (veri '.substr((string) $through, 0, 10).')' : ' (veri yok)');
            })->all();
            $lines[] = '- '.$asset->type.': '.($asset->primary_url ?: $asset->domain ?: $asset->name).($accounts !== [] ? ' — '.implode(', ', $accounts) : '');
        }

        return implode("\n", $lines) ?: 'Bağlı varlık yok.';
    }

    private function services(Brand $brand): string
    {
        return SiteScope::offerings($brand)->map(function (BrandOffering $o): string {
            $page = DB::table('offering_pages as op')->join('pages as p', 'p.id', '=', 'op.page_id')->where('op.brand_offering_id', $o->id)->value('p.path');

            return '- '.($o->is_priority || $o->priority === 'main' ? '★ ' : '').$o->displayName().($page !== null ? ' → '.$page : ' → sayfası eşleşmedi');
        })->implode("\n") ?: 'Etkin hizmet yok.';
    }

    private function demand(Brand $brand): string
    {
        $serviceIds = BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')->whereNotNull('service_catalog_item_id')->pluck('service_catalog_item_id');
        if ($serviceIds->isEmpty()) {
            return 'Hizmet kataloğa bağlı değil; talep okunamıyor.';
        }
        $sector = $brand->sector_id;
        $queries = DB::table('queries')->whereIn('service_id', $serviceIds)->when($sector !== null, fn ($q) => $q->where('sector_id', $sector))
            ->where('hidden', false)->where('is_suggested', false)->selectRaw('count(*) as n, coalesce(sum(impressions), 0) as imp')->first();
        $clusters = Cluster::query()->whereIn('service_id', $serviceIds)->when($sector !== null, fn ($q) => $q->where('sector_id', $sector));
        $top = (clone $clusters)->where('approved', true)
            ->select('clusters.id', 'clusters.name')->selectSub(DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')
            ->whereColumn('cq.cluster_id', 'clusters.id')->selectRaw('coalesce(sum(q.impressions), 0)'), 'demand')
            ->orderByDesc('demand')->orderBy('clusters.id')->limit(self::TOP_CLUSTERS)->get();

        return implode("\n", array_filter([
            sprintf('- Sorgu kütüphanesinde bu hizmetlere ait %s sorgu, toplam %s gösterim', number_format((int) $queries->n, 0, ',', '.'), number_format((int) $queries->imp, 0, ',', '.')),
            sprintf('- Kümeler: %d (onaylı %d)', (clone $clusters)->count(), (clone $clusters)->where('approved', true)->count()),
            $top->isNotEmpty() ? '- En çok aranan onaylı kümeler: '.$top->map(fn ($c): string => $c->name.' ('.number_format((int) $c->demand, 0, ',', '.').')')->implode(', ') : null,
        ]));
    }

    private function site(Brand $brand): string
    {
        $lines = [];
        foreach (DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->get() as $site) {
            $pages = Page::query()->where('website_asset_id', $site->id);
            $states = BrandClusterPage::query()->where('website_asset_id', $site->id)->whereNotNull('state')->groupBy('state')
                ->selectRaw('state, count(*) as n')->pluck('n', 'state')->all();
            $trend = null;
            try {
                $trend = app(SitePagesReader::class)->trend($site, 28);
            } catch (Throwable) {
                // no Search Console / GA4 bound
            }
            $lines[] = '- '.($site->primary_url ?: $site->domain).': '.(clone $pages)->count().' sayfa ('.(clone $pages)->where('category', 'hizmet')->count().' hizmet sayfası)';
            if ($trend !== null && ($trend['has_gsc'] || $trend['has_ga4'])) {
                $lines[] = sprintf('  - Son 28 gün: %s tıklama (önceki %s), %s oturum', number_format($trend['current']['clicks'], 0, ',', '.'),
                    number_format($trend['previous']['clicks'], 0, ',', '.'), number_format($trend['current']['sessions'], 0, ',', '.'));
            }
            if ($states !== []) {
                $lines[] = '  - Küme sayfaları: '.collect($states)->map(fn ($n, $state): string => (BrandClusterPage::STATE_LABELS[$state] ?? $state).' '.$n)->implode(', ');
            }
        }

        return implode("\n", $lines) ?: 'Web sitesi bağlı değil.';
    }

    private function decisions(Brand $brand): string
    {
        return BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'decision')->orderByDesc('updated_at')->orderByDesc('id')->limit(self::DECISIONS)->get()
            ->map(function (BrandMemory $m): string {
                $d = (array) $m->data;
                $outcome = collect((array) ($d['outcome'] ?? []))->map(fn ($o, $point): string => $point.': '.($o['verdict'] ?? '?'))->implode(', ');

                return '- '.($d['decision'] ?? '?').': '.($d['title'] ?? $m->summary).(! empty($d['reason']) ? ' — neden: '.$d['reason'] : '').($outcome !== '' ? ' — sonuç: '.$outcome : '');
            })->implode("\n") ?: 'Henüz karar yok.';
    }

    private function openWork(Brand $brand): string
    {
        $open = Suggestion::query()->where('brand_id', $brand->id)->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::RECHECK]);
        $count = (clone $open)->count();
        $top = (clone $open)->orderBy('priority')->orderByDesc('id')->limit(self::OPEN_WORK)->get(['title', 'channel', 'status']);

        return $count === 0 ? 'Açık iş yok.' : '- Toplam '.$count." açık iş\n".$top->map(fn (Suggestion $s): string => '- ['.$s->channel.'] '.$s->title)->implode("\n");
    }

    private function notesSection(Brand $brand): string
    {
        $notes = self::notes($brand);

        return implode("\n", array_filter([
            $notes['goals'] !== '' ? "Hedefler:\n".$notes['goals'] : null,
            $notes['constraints'] !== '' ? "Kısıtlar (asla önerme / yazma):\n".$notes['constraints'] : null,
        ])) ?: 'Not yok.';
    }
}
