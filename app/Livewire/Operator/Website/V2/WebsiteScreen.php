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
 * Web sitesi varlık ekranı (v2): Genel Bakış | SEO Yapılacaklar (Kümeler & Sayfalar · Öneriler · İçerik · Rakipler ·
 * Backlinkler) | Site Sağlığı | Analiz | Bağlı Varlıklar | Ayarlar. Each tab is its own component that reads stored
 * results only; tabs built in a parallel phase render only when their class exists.
 */
#[Layout('operator.layouts.app')]
#[Title('Web Sitesi')]
final class WebsiteScreen extends Component
{
    public const array TABS = ['genel' => 'Genel Bakış', 'seo' => 'SEO Yapılacaklar', 'saglik' => 'Site Sağlığı', 'analiz' => 'Analiz', 'varliklar' => 'Bağlı Varlıklar', 'ayarlar' => 'Ayarlar'];

    public const array SEO_TABS = ['kumeler' => 'Kümeler & Sayfalar', 'oneriler' => 'Öneriler', 'icerik' => 'İçerik', 'rakipler' => 'Rakipler', 'backlinkler' => 'Backlinkler'];

    /** Tab → component (Faz 4b components are optional until they exist). */
    public const array COMPONENTS = [
        'genel' => OverviewTab::class,
        'saglik' => 'App\\Livewire\\Operator\\Website\\V2\\HealthTab',
        'analiz' => 'App\\Livewire\\Operator\\Website\\V2\\AnalysisTab',
        'varliklar' => 'App\\Livewire\\Operator\\Website\\V2\\LinkedAssetsTab',
        'ayarlar' => SettingsTab::class,
        'kumeler' => ClustersPagesTab::class,
        'oneriler' => SuggestionsTab::class,
        'icerik' => ContentTab::class,
        'rakipler' => 'App\\Livewire\\Operator\\Website\\V2\\CompetitorsTab',
        'backlinkler' => 'App\\Livewire\\Operator\\Website\\V2\\BacklinksTab',
    ];

    /** Old tab ids of the previous website page → the tab that owns that content now. */
    private const array LEGACY_TABS = [
        'overview' => 'genel', 'operations' => 'genel', 'insights' => 'genel', 'activity' => 'genel',
        'health' => 'saglik', 'technical' => 'saglik', 'fixes' => 'saglik', 'infrastructure' => 'saglik', 'domain' => 'saglik', 'hosting' => 'saglik',
        'search_console' => 'analiz', 'ga4_analysis' => 'analiz', 'analytics' => 'analiz', 'ga4' => 'analiz', 'gsc' => 'analiz', 'search' => 'analiz', 'performance' => 'analiz', 'pages' => 'analiz', 'conversions' => 'analiz',
        'content' => 'seo', 'scorecard' => 'seo', 'studio' => 'seo', 'standards' => 'seo',
        'setup' => 'ayarlar', 'settings' => 'ayarlar', 'connections' => 'varliklar', 'lifecycle' => 'ayarlar',
    ];

    #[Locked]
    public int $assetId = 0;

    #[Url]
    public string $tab = 'genel';

    #[Url]
    public string $sub = 'kumeler';

    public function mount(?string $assetId = null): void
    {
        $this->assetId = (int) OperatorCanonicalAsset::require($assetId, ['website'])->id;
        $this->tab = self::LEGACY_TABS[$this->tab] ?? $this->tab;
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'genel';
        }
        if (! array_key_exists($this->sub, self::SEO_TABS)) {
            $this->sub = 'kumeler';
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'genel';
    }

    public function setSub(string $sub): void
    {
        $this->tab = 'seo';
        $this->sub = array_key_exists($sub, self::SEO_TABS) ? $sub : 'kumeler';
    }

    /** The component of the open tab, or null when it is not built yet. */
    public function component(): ?string
    {
        $class = self::COMPONENTS[$this->tab === 'seo' ? $this->sub : $this->tab] ?? null;

        return $class !== null && class_exists($class) ? $class : null;
    }

    public function render(): View
    {
        $site = DigitalAsset::query()->with('brand')->findOrFail($this->assetId);

        return view('livewire.operator.website.v2.website-screen', ['site' => $site, 'component' => $this->component()]);
    }
}
