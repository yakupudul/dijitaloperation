<?php

namespace App\Livewire\Operator\Strategy;

use App\Models\Brand;
use App\Models\Strategy;
use App\Services\Strategy\StrategyBook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Stratejiler (`/stratejiler`): every strategy Claude wrote for the operator, newest first, with brand, channel,
 * service, markets, budget, status and version; filters by brand, channel and status; "Yeni strateji" stores a pasted
 * one. Claude saves its strategies here through the MCP `save-strategy` tool.
 */
#[Layout('operator.layouts.app')]
#[Title('Stratejiler')]
final class StrategiesPage extends Component
{
    #[Url(as: 'marka')]
    public string $brand = '';

    #[Url(as: 'kanal')]
    public string $channel = '';

    #[Url(as: 'durum')]
    public string $status = '';

    #[Url(as: 'ara')]
    public string $search = '';

    public bool $creating = false;

    /** @var array<string, string> */
    public array $form = ['title' => '', 'brand_id' => '', 'channel' => 'google_ads', 'service' => '', 'markets' => '', 'languages' => '',
        'budget_amount' => '', 'budget_period' => 'daily', 'summary' => '', 'body' => ''];

    public function create(StrategyBook $book): void
    {
        $this->resetErrorBag();
        $data = [
            'title' => trim($this->form['title']),
            'brand_id' => $this->form['brand_id'] !== '' ? (int) $this->form['brand_id'] : null,
            'channel' => $this->form['channel'] ?: 'multi',
            'service' => trim($this->form['service']) ?: null,
            'markets' => self::list($this->form['markets']),
            'languages' => self::list($this->form['languages']),
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

    /** @return list<string> "Birleşik Krallık, Almanya" → two markets */
    public static function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $value) ?: [])));
    }

    public function render(): View
    {
        $base = Strategy::query()
            ->when($this->brand !== '', fn ($q) => $q->where('brand_id', (int) $this->brand))
            ->when(isset(Strategy::CHANNELS[$this->channel]), fn ($q) => $q->where('channel', $this->channel))
            ->when(trim($this->search) !== '', function ($q): void {
                $term = '%'.mb_strtolower(trim($this->search)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(title) like ?', [$term])->orWhereRaw('lower(coalesce(service, \'\')) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(summary, \'\')) like ?', [$term]));
            });
        $counts = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n): int => (int) $n)->all();
        $strategies = (clone $base)->with('brand:id,name,logo_url')->withCount('versions')
            ->when(isset(Strategy::STATUSES[$this->status]), fn ($q) => $q->where('status', $this->status), fn ($q) => $q->where('status', '!=', 'archived'))
            ->latest('updated_at')->limit(200)->get();
        $brandIds = Strategy::query()->whereNotNull('brand_id')->distinct()->pluck('brand_id');

        return view('livewire.operator.strategy.index', [
            'strategies' => $strategies,
            'counts' => $counts,
            'filterBrands' => Brand::query()->whereIn('id', $brandIds)->orderBy('name')->pluck('name', 'id')->all(),
            'allBrands' => $this->creating ? Brand::query()->orderBy('name')->pluck('name', 'id')->all() : [],
            'channels' => Strategy::CHANNELS,
            'statuses' => Strategy::STATUSES,
            'periods' => Strategy::BUDGET_PERIODS,
        ]);
    }
}
