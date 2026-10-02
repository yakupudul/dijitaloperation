<?php

namespace App\Livewire\Operator\Website\V2;

use App\Livewire\Operator\Website\V2\Concerns\UsesSiteRange;
use App\Livewire\Operator\Website\V2\Concerns\WebsiteTab;
use App\Services\Site\Analysis\SitePagesReader;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sayfalar: one row per URL — inventory × Search Console × GA4 × Google Ads landing × health × service / cluster.
 * Type cards on top (Hizmet · Hizmet kategorisi · Soru-cevap / blog · Kurumsal · Lokasyon · Anahtar kelime · Diğer);
 * "Tümü" shows each type's top pages in its own section, a type shows its full table with the filters (Ana hizmet
 * sayfaları · Tüm · Trafik almayan · Sorunlu · Düşüşte), search, sort and pagination; a row opens the page detail drawer
 * (queries, 90-day line, channels, issues, links, suggestions). The website screen's date range drives the period.
 */
final class PagesTab extends Component
{
    use UsesSiteRange;
    use WebsiteTab;
    use WithPagination;

    private const int PER_PAGE = 50;

    #[Url(as: 'donem', history: true)]
    public int $period = 28;

    #[Url(as: 'filtre', history: true)]
    public string $filter = 'tum';

    /** Page type ('' = Tümü: every type as its own section). */
    #[Url(as: 'tip', history: true)]
    public string $type = '';

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
        if (in_array($property, ['period', 'filter', 'search', 'type'], true)) {
            $this->resetPage('p');
        }
    }

    public function setFilter(string $filter): void
    {
        $this->filter = array_key_exists($filter, SitePagesReader::FILTERS) ? $filter : 'tum';
        $this->resetPage('p');
    }

    public function setType(string $type): void
    {
        $this->type = array_key_exists($type, SitePagesReader::TYPES) ? $type : '';
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
        $screenRange = $this->hasScreenRange();
        $period = $screenRange ? $this->siteRange()->days : SitePagesReader::period($this->period);
        $filter = array_key_exists($this->filter, SitePagesReader::FILTERS) ? $this->filter : 'tum';
        $type = array_key_exists($this->type, SitePagesReader::TYPES) ? $this->type : '';
        $grouped = $type === '' && $this->search === '';

        return view('livewire.operator.website.v2.pages-tab', [
            'site' => $site,
            'periodDays' => $period,
            'screenRange' => $screenRange,
            'activeFilter' => $filter,
            'activeType' => $type,
            'grouped' => $grouped,
            'types' => $reader->types($site, $period),
            'counts' => $reader->counts($site, $period),
            'rows' => $grouped ? null : $reader->list($site, $period, $filter, $this->search, $this->sort, $this->dir !== 'asc', max(1, (int) $this->getPage('p')), self::PER_PAGE, $type),
            'page' => $this->detail !== '' ? $reader->detail($site, $this->detail, $period) : null,
        ]);
    }
}
