<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Strategy;
use App\Services\Strategy\StrategyBook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Stratejiler" view of the brand page (Aşama 11: the /stratejiler list left the panel): this brand's strategies,
 * newest first, with channel, service, scope, status and version; a status filter and "Yeni strateji" stores a pasted
 * one for the brand. Claude saves strategies through the MCP `save-strategy` tool; each opens on its own page.
 */
class BrandStrategiesTab extends Component
{
    #[Locked]
    public int $brandId;

    public string $status = '';

    public bool $creating = false;

    /** @var array<string, string> */
    public array $form = ['title' => '', 'channel' => 'google_ads', 'service' => '', 'markets' => '', 'languages' => '',
        'budget_amount' => '', 'budget_period' => 'daily', 'summary' => '', 'body' => ''];

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
    }

    public function create(StrategyBook $book): void
    {
        $this->resetErrorBag();
        $data = [
            'title' => trim($this->form['title']),
            'brand_id' => $this->brandId,
            'channel' => $this->form['channel'] ?: 'multi',
            'service' => trim($this->form['service']) ?: null,
            'markets' => StrategyBook::splitList($this->form['markets']),
            'languages' => StrategyBook::splitList($this->form['languages']),
            'budget_amount' => $this->form['budget_amount'] !== '' ? str_replace(',', '.', $this->form['budget_amount']) : null,
            'budget_period' => $this->form['budget_amount'] !== '' ? ($this->form['budget_period'] ?: null) : null,
            'summary' => trim($this->form['summary']) ?: null,
            'body' => $this->form['body'],
        ];
        $validator = Validator::make($data, StrategyBook::rules(true), [], ['title' => 'başlık', 'body' => 'strateji metni']);
        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                $this->addError('form.'.$field, $messages[0]);
            }

            return;
        }
        $strategy = $book->save($validator->validated(), (string) (auth()->user()?->name ?? 'Operatör'));
        $this->redirectRoute('operator.strategy', ['strategyId' => $strategy->id], navigate: true);
    }

    public function render(): View
    {
        $base = Strategy::query()->where('brand_id', $this->brandId);
        $counts = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();
        $strategies = (clone $base)->withCount('versions')
            ->when(isset(Strategy::STATUSES[$this->status]), fn ($q) => $q->where('status', $this->status), fn ($q) => $q->where('status', '!=', 'archived'))
            ->latest('updated_at')->limit(200)->get();

        return view('livewire.operator.portfolio.brand-strategies-tab', [
            'strategies' => $strategies,
            'counts' => $counts,
            'channels' => Strategy::CHANNELS,
            'statuses' => Strategy::STATUSES,
            'periods' => Strategy::BUDGET_PERIODS,
        ]);
    }
}
