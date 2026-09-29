<?php

namespace App\Livewire\Operator\Workspace;

use App\Livewire\Operator\Workspace\Concerns\HandlesAnalystDecisions;
use App\Models\Brand;
use App\Services\Analyst\AnalystWorkspace;
use App\Services\Analyst\Meta\MetaAnalyst;
use App\Services\Analyst\Meta\MetaFacts;
use App\Support\ServiceScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/** Brand workspace › Meta: Durum (6 numbers) · Yapılacaklar (AI cards) · Kanıt (accounts, campaigns, regions, creatives, measurement). */
class MetaTab extends Component
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

    public function render(MetaFacts $facts, MetaAnalyst $analyst, AnalystWorkspace $workspace): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);
        $operational = app(ServiceScope::class)->isBrandOperational($brand->id);
        $stats = [];
        $evidence = ['accounts' => [], 'campaigns' => [], 'regions' => [], 'ads' => [], 'tracking' => []];
        $missing = $operational ? null : ServiceScope::NOT_SERVED;
        if ($operational) {
            try {
                $missing = $facts->missing($brand);
                $stats = $analyst->stats($brand);
                $evidence = $this->evidence($facts->analyze($brand));
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return view('livewire.operator.workspace.meta-tab', [
            'brand' => $brand,
            'operational' => $operational,
            'missing' => $missing,
            'stats' => $stats,
            'evidence' => $evidence,
            'decisions' => $operational ? $workspace->forChannel($brand, 'meta') : [],
            'lastRun' => $workspace->lastRun($brand, 'meta'),
        ]);
    }

    /**
     * @param  array{accounts: array<int, array<string, mixed>>}  $analysis
     * @return array<string, list<array<string, mixed>>>
     */
    private function evidence(array $analysis): array
    {
        $out = ['accounts' => [], 'campaigns' => [], 'regions' => [], 'ads' => [], 'tracking' => []];
        foreach ($analysis['accounts'] as $account) {
            $c = $account['currency'];
            $cur = $account['totals']['cur'];
            $cpr = $cur['results'] > 0 ? $cur['conv_spend'] / $cur['results'] : null;
            $out['accounts'][] = ['name' => $account['name'], 'spend' => MetaFacts::money($cur['spend'], $c), 'results' => (int) round($cur['results']),
                'cpr' => MetaFacts::money($cpr, $c), 'frequency' => $account['frequency_7d']];
            foreach (array_slice($account['campaigns'], 0, 10, true) as $campaign) {
                if ($campaign['cur']['spend'] <= 0) {
                    continue;
                }
                $w = $campaign['cur'];
                $out['campaigns'][] = ['name' => $campaign['name'], 'fit' => $campaign['objective_fit'], 'budget' => $campaign['budget'], 'spend' => MetaFacts::money($w['spend'], $c),
                    'results' => (int) round($w['results']), 'cpr' => MetaFacts::money($w['results'] > 0 ? $w['spend'] / $w['results'] : null, $c)];
            }
            foreach ($account['regions'] as $region) {
                if ($region['in_area'] === false) {
                    $out['regions'][] = ['name' => $region['region'], 'spend' => MetaFacts::money($region['spend'], $c), 'share' => $region['share'], 'results' => $region['results']];
                }
            }
            foreach ($account['ads'] as $ad) {
                if ($ad['fatigue'] || $ad['compliance'] !== []) {
                    $out['ads'][] = ['name' => $ad['name'], 'status' => $ad['compliance'] !== [] ? 'kurala aykırı' : 'yoruldu', 'frequency' => $ad['frequency_7d'],
                        'ctr' => $ad['ctr_7d'], 'ctr_prev' => $ad['ctr_prev_7d'], 'spend' => MetaFacts::money($ad['spend'], $c)];
                }
            }
            foreach ($account['tracking'] as $issue) {
                $out['tracking'][] = ['name' => $issue['subject'], 'issue' => $issue['issue'], 'fix' => $issue['fix']];
            }
        }

        return $out;
    }
}
