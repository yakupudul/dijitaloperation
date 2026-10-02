<?php

namespace App\Livewire\Operator\Website\V2;

use App\Models\DigitalAsset;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Site\Analysis\SiteAnalysisReader;
use App\Services\Site\Analysis\SiteRange;
use App\Support\Reality\OperatorCanonicalAsset;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Web sitesi ekranı — one level of tabs (Search Console style): Kümeler (service → main cluster → sub clusters, ideas and
 * queries) · Analiz (Search Console × GA4 × pages) · Sayfalar (by type) · Sorgular · Teknik SEO · Yapılacaklar · Ayarlar,
 * and the rarely used views under "Diğer". One date picker in the header (presets, custom range, comparison) drives
 * every tab that reads Search Console / GA4. Each view is its own component that reads stored results only; old tab ids
 * keep working through LEGACY_TABS / LEGACY_SUBS.
 */
#[Layout('operator.layouts.app')]
#[Title('Web Sitesi')]
final class WebsiteScreen extends Component
{
    public const array TABS = ['kumeler' => 'Kümeler', 'analiz' => 'Analiz', 'sayfalar' => 'Sayfalar', 'sorgular' => 'Sorgular',
        'teknik' => 'Teknik SEO', 'yapilacaklar' => 'Yapılacaklar', 'ayarlar' => 'Ayarlar'];

    /** Under "Diğer": views kept for detail work, out of the main row. */
    public const array MORE = ['fikirler' => 'İçerik fikirleri (ayrıntılı liste)', 'icerik' => 'İçerik planı ve taslaklar', 'hedef' => 'Hedef sorgular',
        'donusumler' => 'Dönüşümler', 'eslestirme' => 'Sayfa ↔ hizmet ↔ küme', 'rakipler' => 'Rakipler', 'backlinkler' => 'Backlinkler'];

    /** Tabs whose components read Search Console / GA4: the date picker shows there and the range goes along. */
    public const array RANGED = ['analiz', 'sayfalar', 'sorgular', 'kumeler', 'hedef', 'donusumler'];

    /** tab → components (rendered in order) and their extra mount parameters. */
    public const array COMPONENTS = [
        'kumeler' => [[ClustersBoardTab::class, []]],
        'analiz' => [[AnalyticsTab::class, []]],
        'sayfalar' => [[PagesTab::class, []]],
        'sorgular' => [[AnalysisTab::class, ['fixed' => 'queries']]],
        'teknik' => [[TechnicalSeoTab::class, []], [HealthTab::class, []]],
        'yapilacaklar' => [[SuggestionsTab::class, []]],
        'ayarlar' => [[SettingsTab::class, []], [LinkedAssetsTab::class, []]],
        'fikirler' => [[ContentIdeasTab::class, []]],
        'icerik' => [[ContentTab::class, []]],
        'hedef' => [[AnalysisTab::class, ['fixed' => 'targets']]],
        'donusumler' => [[AnalysisTab::class, ['fixed' => 'conversions']]],
        'eslestirme' => [[ClustersPagesTab::class, []]],
        'rakipler' => [[CompetitorsTab::class, []]],
        'backlinkler' => [[BacklinksTab::class, []]],
    ];

    /** Old tab ids (previous website screens) → the tab that owns that content now. */
    public const array LEGACY_TABS = [
        'ozet' => 'analiz', 'genel' => 'analiz', 'overview' => 'analiz', 'operations' => 'analiz', 'insights' => 'analiz', 'activity' => 'analiz',
        'ga4_analysis' => 'analiz', 'analytics' => 'analiz', 'ga4' => 'analiz', 'performance' => 'analiz',
        'saglik' => 'teknik', 'health' => 'teknik', 'technical' => 'teknik', 'fixes' => 'teknik', 'infrastructure' => 'teknik', 'domain' => 'teknik', 'hosting' => 'teknik',
        'pages' => 'sayfalar',
        'analiz_kumeler' => 'kumeler', 'search_console' => 'sorgular', 'gsc' => 'sorgular', 'search' => 'sorgular',
        'conversions' => 'donusumler', 'oneriler' => 'yapilacaklar', 'scorecard' => 'yapilacaklar', 'standards' => 'yapilacaklar',
        'content' => 'icerik', 'studio' => 'icerik',
        'setup' => 'ayarlar', 'settings' => 'ayarlar', 'connections' => 'ayarlar', 'lifecycle' => 'ayarlar', 'varliklar' => 'ayarlar',
    ];

    /** Old "tab.sub" views → their tab now. */
    public const array LEGACY_SUBS = [
        'ozet.ozet' => 'analiz', 'ozet.oneriler' => 'yapilacaklar', 'ozet.icerik' => 'icerik', 'ozet.rakipler' => 'rakipler', 'ozet.backlinkler' => 'backlinkler',
        'sorgular.fikirler' => 'kumeler', 'sorgular.kumeler' => 'kumeler', 'sorgular.hedef' => 'hedef', 'sorgular.sorgular' => 'sorgular',
        'sorgular.donusumler' => 'donusumler', 'sorgular.eslestirme' => 'eslestirme', 'seo.kumeler' => 'kumeler', 'seo' => 'kumeler',
    ];

    #[Locked]
    public int $assetId = 0;

    #[Url]
    public string $tab = 'kumeler';

    /** Kept for old links ("?tab=sorgular&sub=hedef"); cleared once resolved. */
    #[Url]
    public string $sub = '';

    /** Date picker: preset days, or a custom start / end, and the comparison. */
    #[Url(as: 'donem')]
    public int $days = 28;

    #[Url(as: 'bas')]
    public string $start = '';

    #[Url(as: 'bit')]
    public string $end = '';

    #[Url(as: 'kars')]
    public string $compare = SiteRange::COMPARE_PREVIOUS;

    public function mount(?string $assetId = null): void
    {
        $this->assetId = (int) OperatorCanonicalAsset::require($assetId, ['website'])->id;
        $this->tab = self::resolve($this->tab, $this->sub);
        $this->sub = '';
    }

    /** Current or old tab (and sub) → a valid tab. */
    public static function resolve(string $tab, string $sub = ''): string
    {
        if (isset(self::LEGACY_SUBS[$tab.'.'.$sub])) {
            return self::LEGACY_SUBS[$tab.'.'.$sub];
        }
        if (isset(self::LEGACY_SUBS[$tab]) && $sub === '') {
            return self::LEGACY_SUBS[$tab];
        }
        // An old "tab + sub" link whose sub is a view of its own now ("?tab=seo&sub=rakipler").
        if ($sub !== '' && ! array_key_exists($tab, self::COMPONENTS) && (array_key_exists($sub, self::COMPONENTS) || isset(self::LEGACY_TABS[$sub]))) {
            return self::resolve($sub);
        }
        if (array_key_exists($tab, self::COMPONENTS)) {
            return $tab;
        }
        $target = self::LEGACY_TABS[$tab] ?? null;

        return $target !== null && array_key_exists($target, self::COMPONENTS) ? $target : 'kumeler';
    }

    public function setTab(string $tab): void
    {
        $this->tab = self::resolve($tab);
    }

    /** Old buttons inside tabs (e.g. "Öneriler" → setSub('oneriler')). */
    public function setSub(string $sub): void
    {
        $this->tab = self::resolve($sub);
    }

    /** "Uygula" in the date picker: a preset (days) or a custom start–end, and the comparison. */
    public function setRange(int $days, string $start = '', string $end = '', string $compare = SiteRange::COMPARE_PREVIOUS): void
    {
        $range = SiteRange::from($days, $start !== '' ? $start : null, $end !== '' ? $end : null, $compare);
        $this->days = $range->days;
        $this->start = (string) $range->start;
        $this->end = (string) $range->end;
        $this->compare = $range->compare;
    }

    public function range(): SiteRange
    {
        return SiteRange::from($this->days, $this->start !== '' ? $this->start : null, $this->end !== '' ? $this->end : null, $this->compare);
    }

    /** @return list<array{0: class-string, 1: array<string, mixed>}> components of the open view */
    public function components(): array
    {
        $range = $this->range();
        $params = ['days' => $range->days, 'start' => $range->start, 'end' => $range->end, 'compare' => $range->compare];

        return array_map(fn (array $view): array => [$view[0], in_array($this->tab, self::RANGED, true) && $view[0] !== HealthTab::class ? $view[1] + ['range' => $params] : $view[1]],
            self::COMPONENTS[$this->tab] ?? []);
    }

    public function render(SiteAnalysisReader $reader): View
    {
        $site = DigitalAsset::query()->with('brand')->findOrFail($this->assetId);
        $lastDay = $reader->lastDay($site);
        $range = $this->range()->bind();
        $window = $range->window($lastDay);

        return view('livewire.operator.website.v2.website-screen', [
            'site' => $site, 'views' => $this->components(), 'range' => $range, 'window' => $window, 'lastDay' => $lastDay->toDateString(),
            'ranged' => in_array($this->tab, self::RANGED, true),
            'banners' => collect(app(DataStatusReader::class)->forAsset($site))->filter(fn ($status): bool => in_array($status->state, ['not_bound', 'first_load'], true))->groupBy('state'),
            'rangeKey' => md5(json_encode([$range->days, $range->start, $range->end, $range->compare])),
        ]);
    }
}
