<?php

namespace App\Livewire\Operator\Website\V2;

use App\Livewire\Operator\Website\V2\Concerns\WebsiteTab;
use App\Services\Site\Analysis\SitePagesReader;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sayfalar: one row per URL — inventory × Search Console × GA4 × Google Ads landing × health × service / cluster.
 * Filters (Ana hizmet sayfaları default · Tüm · Trafik almayan · Sorunlu · Düşüşte), search, sort, pagination; a row
 * opens the page detail drawer (queries, 90-day line, channels, issues, links, suggestions). Read only.
 */
final class PagesTab extends Component
{
    use WebsiteTab;
    use WithPagination;

    private const int PER_PAGE = 50;

    #[Url(as: 'donem', history: true)]
    public int $period = 28;

    #[Url(as: 'filtre', history: true)]
    public string $filter = 'ana';

    #[Url(as: 'ara')]
    public string $search = '';

    #[Url(as: 'sirala')]
    public string $sort = 'clicks';

    #[Url(as: 'yon')]
    public string $dir = 'desc';

    #[Url(as: 'sayfa', history: true)]
    public string $detail = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['period', 'filter', 'search'], true)) {
            $this->resetPage('p');
        }
    }

    public function setFilter(string $filter): void
    {
        $this->filter = array_key_exists($filter, SitePagesReader::FILTERS) ? $filter : 'ana';
        $this->resetPage('p');
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, SitePagesReader::SORTS, true)) {
            return;
        }
        if ($this->sort === $column) {
            $this->dir = $this->dir === 'desc' ? 'asc' : 'desc';
        } else {
            $this->dir = in_array($column, ['path', 'position'], true) ? 'asc' : 'desc';
        }
        $this->sort = $column;
        $this->resetPage('p');
    }

    public function open(string $path): void
    {
        $this->detail = $path;
    }

    public function close(): void
    {
        $this->detail = '';
    }

    public function render(SitePagesReader $reader): View
    {
        $site = $this->site();
        $period = SitePagesReader::period($this->period);
        $filter = array_key_exists($this->filter, SitePagesReader::FILTERS) ? $this->filter : 'ana';

        return view('livewire.operator.website.v2.pages-tab', [
            'site' => $site,
            'periodDays' => $period,
            'activeFilter' => $filter,
            'counts' => $reader->counts($site, $period),
            'rows' => $reader->list($site, $period, $filter, $this->search, $this->sort, $this->dir !== 'asc', max(1, (int) $this->getPage('p')), self::PER_PAGE),
            'page' => $this->detail !== '' ? $reader->detail($site, $this->detail, $period) : null,
        ]);
    }
}
