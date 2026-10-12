<?php

namespace App\Livewire\Operator\Strategy;

use App\Models\Brand;
use App\Models\Strategy;
use App\Services\Strategy\StrategyAdsEditorExport;
use App\Services\Strategy\StrategyBook;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One strategy (`/stratejiler/{id}`), all on one screen: the facts strip (brand, channel, service, budget, markets,
 * languages), the text with a sticky table of contents, and beside it the data it was based on, every version (read
 * an earlier one, bring it back, or save a new one) and the brand's other strategies. The scope is edited in place;
 * status buttons move it between Taslak, Onaylandı, Uygulanıyor and Arşiv. Nothing here writes to an ad account.
 */
#[Layout('operator.layouts.app')]
final class StrategyPage extends Component
{
    public int $strategyId;

    #[Url(as: 'surum')]
    public ?int $version = null;

    public bool $editing = false;

    public bool $revising = false;

    /** @var array<string, string> */
    public array $scope = [];

    public string $draftBody = '';

    public string $changeNote = '';

    public string $flash = '';

    public function mount(int $strategyId): void
    {
        $this->strategyId = Strategy::query()->findOrFail($strategyId)->id;
    }

    public function setStatus(string $status, StrategyBook $book): void
    {
        $book->setStatus($this->strategy(), $status);
        $this->flash = 'Durum: '.(Strategy::STATUSES[$status] ?? $status).'.';
    }

    public function startEdit(): void
    {
        $s = $this->strategy();
        $this->scope = [
            'title' => $s->title, 'brand_id' => (string) ($s->brand_id ?? ''), 'channel' => $s->channel, 'service' => (string) $s->service,
            'markets' => implode(', ', $s->markets ?? []), 'languages' => implode(', ', $s->languages ?? []),
            'budget_amount' => $s->budget_amount === null ? '' : rtrim(rtrim((string) $s->budget_amount, '0'), '.'),
            'budget_currency' => $s->budget_currency, 'budget_period' => (string) ($s->budget_period ?? ''), 'summary' => (string) $s->summary,
        ];
        $this->editing = true;
    }

    public function saveScope(StrategyBook $book): void
    {
        $this->resetErrorBag();
        $data = [
            'title' => trim($this->scope['title'] ?? ''),
            'brand_id' => ($this->scope['brand_id'] ?? '') !== '' ? (int) $this->scope['brand_id'] : null,
            'channel' => $this->scope['channel'] ?? 'multi',
            'service' => trim($this->scope['service'] ?? '') ?: null,
            'markets' => StrategiesPage::list($this->scope['markets'] ?? ''),
            'languages' => StrategiesPage::list($this->scope['languages'] ?? ''),
            'budget_amount' => ($this->scope['budget_amount'] ?? '') !== '' ? str_replace(',', '.', $this->scope['budget_amount']) : null,
            'budget_currency' => trim($this->scope['budget_currency'] ?? '') ?: 'TRY',
            'budget_period' => ($this->scope['budget_period'] ?? '') ?: null,
            'summary' => trim($this->scope['summary'] ?? '') ?: null,
        ];
        $validator = Validator::make($data, StrategyBook::rules(false));
        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                $this->addError('scope.'.$field, $messages[0]);
            }

            return;
        }
        $book->save($validator->validated(), (string) (auth()->user()?->name ?? 'Operatör'), $this->strategy());
        $this->editing = false;
        $this->flash = 'Kapsam kaydedildi.';
    }

    public function startRevision(): void
    {
        $s = $this->strategy();
        $this->draftBody = (string) $s->versions()->where('version', $s->current_version)->value('body');
        $this->changeNote = '';
        $this->revising = true;
        $this->version = null;
    }

    public function saveRevision(StrategyBook $book): void
    {
        $this->resetErrorBag();
        $validator = Validator::make(['body' => $this->draftBody, 'change_note' => trim($this->changeNote) ?: null],
            ['body' => StrategyBook::rules(true)['body'], 'change_note' => StrategyBook::rules(true)['change_note']], [], ['body' => 'strateji metni']);
        if ($validator->fails()) {
            $this->addError('draftBody', $validator->errors()->first('body') ?: $validator->errors()->first());

            return;
        }
        $before = $this->strategy()->current_version;
        $strategy = $book->save($validator->validated(), (string) (auth()->user()?->name ?? 'Operatör'), $this->strategy());
        $this->revising = false;
        $this->flash = $strategy->current_version === $before ? 'Metin değişmedi; yeni sürüm açılmadı.' : 'v'.$strategy->current_version.' kaydedildi.';
    }

    public function restore(int $version, StrategyBook $book): void
    {
        $book->restore($this->strategy(), $version, (string) (auth()->user()?->name ?? 'Operatör'));
        $this->version = null;
        $this->flash = 'v'.$version.' yeniden geçerli sürüm yapıldı (v'.$this->strategy()->current_version.').';
    }

    public function download(): StreamedResponse
    {
        $s = $this->strategy();
        $number = $this->version ?? $s->current_version;
        $body = (string) $s->versions()->where('version', $number)->value('body');

        return response()->streamDownload(fn () => print ($body), Str::slug($s->title).'-v'.$number.'.md', ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    public function render(StrategyBook $book): View
    {
        $strategy = $this->strategy()->load('brand:id,name');
        $versions = $strategy->versions()->get();
        $shown = $versions->firstWhere('version', $this->version ?? $strategy->current_version) ?? $versions->first();
        $rendered = $shown !== null ? $book->render($shown->body) : ['html' => '', 'toc' => [], 'words' => 0];
        $others = $strategy->brand_id !== null
            ? Strategy::query()->where('brand_id', $strategy->brand_id)->whereKeyNot($strategy->id)->latest('updated_at')->limit(8)->get(['id', 'title', 'status', 'channel', 'updated_at'])
            : collect();

        return view('livewire.operator.strategy.show', [
            'strategy' => $strategy,
            'versions' => $versions,
            'shown' => $shown,
            'html' => $rendered['html'],
            'toc' => $rendered['toc'],
            'readMinutes' => max(1, (int) ceil($rendered['words'] / 200)),
            'planCounts' => ! empty($strategy->plan['campaigns'] ?? []) ? StrategyAdsEditorExport::counts($strategy) : null,
            'others' => $others,
            'allBrands' => $this->editing ? Brand::query()->orderBy('name')->pluck('name', 'id')->all() : [],
            'channels' => Strategy::CHANNELS,
            'statuses' => Strategy::STATUSES,
            'periods' => Strategy::BUDGET_PERIODS,
        ])->title($strategy->title.' · Stratejiler');
    }

    private function strategy(): Strategy
    {
        return Strategy::query()->findOrFail($this->strategyId);
    }
}
