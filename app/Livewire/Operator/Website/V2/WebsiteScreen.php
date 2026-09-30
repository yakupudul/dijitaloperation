<?php

namespace App\Livewire\Operator\Website\V2;

use App\Models\DigitalAsset;
use App\Support\Reality\OperatorCanonicalAsset;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Web sitesi ekranı: Özet (trafik, ana hizmet sayfaları, açık işler · Öneriler · İçerik · Rakipler · Backlinkler) |
 * Sayfalar (URL başına envanter × Search Console × GA4 × Google Ads × sağlık) | Sorgular (Kümeler · Hedef sorgular ·
 * Sorgular · Dönüşümler · Kümeler & Sayfalar) | Sağlık | Ayarlar (+ bağlı varlıklar, veri toplama durumu). Each view
 * is its own component that reads stored results only. Old tab ids keep working through LEGACY_TABS.
 */
#[Layout('operator.layouts.app')]
#[Title('Web Sitesi')]
final class WebsiteScreen extends Component
{
    public const array TABS = ['ozet' => 'Özet', 'sayfalar' => 'Sayfalar', 'sorgular' => 'Sorgular', 'saglik' => 'Sağlık', 'ayarlar' => 'Ayarlar'];

    /** Second row of a tab (first entry = default). */
    public const array SUBS = [
        'ozet' => ['ozet' => 'Özet', 'oneriler' => 'Öneriler', 'icerik' => 'İçerik', 'rakipler' => 'Rakipler', 'backlinkler' => 'Backlinkler'],
        'sorgular' => ['kumeler' => 'Kümeler', 'hedef' => 'Hedef sorgular', 'sorgular' => 'Sorgular', 'donusumler' => 'Dönüşümler', 'eslestirme' => 'Kümeler & Sayfalar'],
    ];

    /** tab or tab.sub → components (rendered in order) and their extra mount parameters. */
    public const array COMPONENTS = [
        'ozet.ozet' => [[OverviewTab::class, []]],
        'ozet.oneriler' => [[SuggestionsTab::class, []]],
        'ozet.icerik' => [[ContentTab::class, []]],
        'ozet.rakipler' => [[CompetitorsTab::class, []]],
        'ozet.backlinkler' => [[BacklinksTab::class, []]],
        'sayfalar' => [[PagesTab::class, []]],
        'sorgular.kumeler' => [[AnalysisTab::class, ['fixed' => 'clusters']]],
        'sorgular.hedef' => [[AnalysisTab::class, ['fixed' => 'targets']]],
        'sorgular.sorgular' => [[AnalysisTab::class, ['fixed' => 'queries']]],
        'sorgular.donusumler' => [[AnalysisTab::class, ['fixed' => 'conversions']]],
        'sorgular.eslestirme' => [[ClustersPagesTab::class, []]],
        'saglik' => [[HealthTab::class, []]],
        'ayarlar' => [[SettingsTab::class, []], [LinkedAssetsTab::class, []]],
    ];

    /** Old tab ids (previous website screens) → the tab (or tab.sub) that owns that content now. */
    public const array LEGACY_TABS = [
        'genel' => 'ozet', 'overview' => 'ozet', 'operations' => 'ozet', 'insights' => 'ozet', 'activity' => 'ozet',
        'ga4_analysis' => 'ozet', 'analytics' => 'ozet', 'ga4' => 'ozet',
        'health' => 'saglik', 'technical' => 'saglik', 'fixes' => 'saglik', 'infrastructure' => 'saglik', 'domain' => 'saglik', 'hosting' => 'saglik',
        'pages' => 'sayfalar',
        'analiz' => 'sorgular.kumeler', 'search_console' => 'sorgular.sorgular', 'gsc' => 'sorgular.sorgular', 'search' => 'sorgular.sorgular',
        'performance' => 'sorgular.kumeler', 'conversions' => 'sorgular.donusumler',
        'content' => 'ozet.icerik', 'studio' => 'ozet.icerik', 'scorecard' => 'ozet.oneriler', 'standards' => 'ozet.oneriler',
        'setup' => 'ayarlar', 'settings' => 'ayarlar', 'connections' => 'ayarlar', 'lifecycle' => 'ayarlar', 'varliklar' => 'ayarlar',
    ];

    #[Locked]
    public int $assetId = 0;

    #[Url]
    public string $tab = 'ozet';

    #[Url]
    public string $sub = '';

    public function mount(?string $assetId = null): void
    {
        $this->assetId = (int) OperatorCanonicalAsset::require($assetId, ['website'])->id;
        [$this->tab, $this->sub] = self::resolve($this->tab, $this->sub);
    }

    /**
     * Current or old (tab, sub) → a valid (tab, sub).
     *
     * @return array{0: string, 1: string}
     */
    public static function resolve(string $tab, string $sub): array
    {
        if ($tab === 'seo') {
            // Old "SEO Yapılacaklar": Kümeler & Sayfalar moved under Sorgular, the rest under Özet.
            return in_array($sub, ['', 'kumeler'], true) ? ['sorgular', 'eslestirme'] : self::resolve('ozet', $sub);
        }
        $target = self::LEGACY_TABS[$tab] ?? $tab;
        if (str_contains($target, '.')) {
            [$target, $sub] = explode('.', $target, 2);
        }
        if (! array_key_exists($target, self::TABS)) {
            $target = 'ozet';
        }
        $subs = self::SUBS[$target] ?? [];
        if ($subs === []) {
            return [$target, ''];
        }

        return [$target, array_key_exists($sub, $subs) ? $sub : (string) array_key_first($subs)];
    }

    public function setTab(string $tab): void
    {
        [$this->tab, $this->sub] = self::resolve(array_key_exists($tab, self::TABS) ? $tab : 'ozet', '');
    }

    /** A sub view by id, wherever it lives (e.g. the Özet "Aç" buttons call setSub('oneriler')). */
    public function setSub(string $sub): void
    {
        foreach (self::SUBS as $tab => $subs) {
            if (array_key_exists($sub, $subs)) {
                [$this->tab, $this->sub] = [$tab, $sub];

                return;
            }
        }
        [$this->tab, $this->sub] = self::resolve($sub, '');
    }

    /** @return list<array{0: class-string, 1: array<string, mixed>}> components of the open view */
    public function components(): array
    {
        return self::COMPONENTS[$this->sub !== '' ? $this->tab.'.'.$this->sub : $this->tab] ?? [];
    }

    public function render(): View
    {
        $site = DigitalAsset::query()->with('brand')->findOrFail($this->assetId);

        return view('livewire.operator.website.v2.website-screen', ['site' => $site, 'views' => $this->components()]);
    }
}
