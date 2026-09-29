<?php

namespace App\Livewire\Operator\Workspace;

use App\Livewire\Operator\Workspace\Concerns\HandlesAnalystDecisions;
use App\Models\Brand;
use App\Services\Analyst\AnalystWorkspace;
use App\Services\Analyst\Search\SearchAnalyst;
use App\Services\Analyst\Search\SearchFacts;
use App\Support\ServiceScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/** Brand workspace › Arama: Durum (5 numbers) · Yapılacaklar (AI cards) · Kanıt (matrix, queries, blockers). */
class SearchTab extends Component
{
    use HandlesAnalystDecisions;

    #[Locked]
    public int $brandId;

    public string $notice = '';

    public string $noticeTone = 'success';

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
    }

    protected function analystBrandId(): int
    {
        return $this->brandId;
    }

    protected function analystNotice(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    public function render(SearchFacts $facts, SearchAnalyst $analyst, AnalystWorkspace $workspace): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);
        $operational = app(ServiceScope::class)->isBrandOperational($brand->id);
        $site = $facts->website($brand);
        $stats = [];
        $matrix = null;
        $queries = [];
        $standards = [];
        $missing = $operational ? $facts->missing($brand, $site) : ServiceScope::NOT_SERVED;
        if ($operational && $site !== null) {
            try {
                $stats = $analyst->stats($brand, $site);
                $matrix = $facts->matrix($brand, $site);
                $queries = $facts->coreQueries($brand)->orderByDesc('gsc_impressions')->limit(25)->get()
                    ->map(fn ($q): array => ['text' => (string) $q->query, 'impressions' => (int) $q->gsc_impressions, 'clicks' => (int) $q->gsc_clicks,
                        'position' => $q->gsc_position !== null ? (float) $q->gsc_position : null])->all();
                $standards = collect($facts->failingStandards($site))->map(fn (array $s): array => ['rule' => $s['rule'], 'urls' => $s['urls'], 'path' => $s['example']])->values()->all();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return view('livewire.operator.workspace.search-tab', [
            'brand' => $brand,
            'operational' => $operational,
            'missing' => $missing,
            'stats' => $stats,
            'matrix' => $matrix,
            'queries' => $queries,
            'standards' => $standards,
            'decisions' => $operational ? $workspace->forChannel($brand, 'search') : [],
            'lastRun' => $workspace->lastRun($brand, 'search'),
        ]);
    }
}
