<?php

namespace App\Livewire\Operator\Website\V2;

use App\Livewire\Operator\Website\V2\Concerns\WebsiteTab;
use App\Services\Site\Analysis\SiteAnalysisReader;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Analiz: Search Console + GA4 of the website for a period (28 gün default) against the previous period. Sub-tabs:
 * Kümeler (per cluster, split by brand target area) · Hedef sorgular (brand target queries) · Sayfalar · Sorgular (raw)
 * · Dönüşümler (GA4 key events by landing page × source / medium). Read only; paginated.
 */
final class AnalysisTab extends Component
{
    use WebsiteTab;
    use WithPagination;

    public const array SUBTABS = ['clusters' => 'Kümeler', 'targets' => 'Hedef sorgular', 'pages' => 'Sayfalar', 'queries' => 'Sorgular', 'conversions' => 'Dönüşümler'];

    private const int PER_PAGE = 50;

    #[Url(as: 'donem', history: true)]
    public int $period = 28;

    #[Url(as: 'analiz', history: true)]
    public string $sub = 'clusters';

    public function setSub(string $sub): void
    {
        $this->sub = array_key_exists($sub, self::SUBTABS) ? $sub : 'clusters';
        $this->resetPage('p');
    }

    public function updatedPeriod(): void
    {
        $this->resetPage('p');
    }

    public function render(SiteAnalysisReader $reader): View
    {
        $site = $this->site();
        $period = array_key_exists($this->period, SiteAnalysisReader::PERIODS) ? $this->period : 28;
        $sub = array_key_exists($this->sub, self::SUBTABS) ? $this->sub : 'clusters';
        $rows = match ($sub) {
            'targets' => $reader->targetQueries($site, $period),
            'pages' => $reader->pages($site, $period),
            'queries' => $reader->queries($site, $period),
            'conversions' => $reader->conversions($site, $period),
            default => $reader->clusters($site, $period),
        };
        $page = max(1, (int) $this->getPage('p'));

        return view('livewire.operator.website.v2.analysis-tab', [
            'window' => $reader->window($site, $period),
            'totals' => $reader->totals($site, $period),
            'rows' => new LengthAwarePaginator(array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($rows), self::PER_PAGE, $page, ['pageName' => 'p']),
            'activeSub' => $sub,
        ]);
    }
}
