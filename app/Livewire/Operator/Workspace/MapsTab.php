<?php

namespace App\Livewire\Operator\Workspace;

use App\Livewire\Operator\Workspace\Concerns\HandlesAnalystDecisions;
use App\Models\Brand;
use App\Services\Analyst\AnalystWorkspace;
use App\Services\Analyst\Maps\MapsFacts;
use App\Support\ServiceScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/** Brand workspace › Harita: Durum (5–6 numbers) · Yapılacaklar (AI cards) · Kanıt (locations, searches, standards, grid). */
class MapsTab extends Component
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

    public function render(MapsFacts $facts, AnalystWorkspace $workspace): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);
        $operational = app(ServiceScope::class)->isBrandOperational($brand->id);
        $missing = $operational ? $facts->missing($brand) : ServiceScope::NOT_SERVED;
        $stats = [];
        $locations = [];
        $keywords = [];
        $standards = [];
        $grid = [];
        if ($operational && $missing === null) {
            try {
                $stats = $facts->stats($brand);
                $snap = $facts->snapshot($brand);
                foreach ($snap['locations'] as $loc) {
                    $locations[] = ['name' => $loc['name'], 'rating' => $loc['rating'], 'reviews' => $loc['reviews'], 'unanswered' => $loc['unanswered'],
                        'maps_views' => $loc['maps_views'], 'last_post_days' => $loc['last_post_days']];
                    foreach ($loc['keywords'] as $kw) {
                        $keywords[] = ['text' => $kw['text'], 'impressions' => $kw['impressions'], 'service' => $kw['service'], 'status' => $kw['status']];
                    }
                    foreach ($loc['standards'] as $result) {
                        if (in_array($result['state'], ['fail', 'review'], true)) {
                            $standards[] = ['rule' => $result['title'], 'note' => $result['finding']];
                        }
                    }
                }
                usort($keywords, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
                $keywords = array_slice($keywords, 0, 25);
                $grid = array_map(fn (array $g): array => ['text' => $g['text'], 'top3_pct' => $g['top3_pct'], 'position' => $g['position'],
                    'leader' => $g['competitors'][0]['title'] ?? null], $snap['grid']);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return view('livewire.operator.workspace.maps-tab', [
            'operational' => $operational,
            'missing' => $missing,
            'stats' => $stats,
            'locations' => $locations,
            'keywords' => $keywords,
            'standards' => $standards,
            'grid' => $grid,
            'decisions' => $operational ? $workspace->forChannel($brand, 'maps') : [],
            'lastRun' => $workspace->lastRun($brand, 'maps'),
        ]);
    }
}
