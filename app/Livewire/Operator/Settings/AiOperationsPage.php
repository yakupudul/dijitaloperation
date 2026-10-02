<?php

namespace App\Livewire\Operator\Settings;

use App\Livewire\Operator\AiLiveIndicator;
use App\Models\AgencySetting;
use App\Models\AiLiveOperation;
use App\Models\PromptVersion;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiLiveOperations;
use App\Services\Ai\AiRouteResolver;
use App\Services\Ai\AiSchedule;
use App\Services\AiJobs\AiJobTracker;
use App\Services\Prompts\PromptRegistry;
use App\Services\Prompts\PromptRunStats;
use App\Services\Prompts\PromptTrial;
use App\Support\Ai\AiProviderCatalog;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ayarlar › AI işlemleri ve promptlar (Faz 8, admin only): list of every AI operation with model, version and the last
 * 30 days of runs; detail with the template, variables, context sources, output shape, model, version history
 * ("Bu sürüme dön") and recent runs. Saving publishes a new version; "Örnekte dene" runs the unsaved draft once on a real
 * past input or a pasted sample (output, duration, cost; nothing saved to suggestions).
 */
#[Layout('operator.layouts.app')]
#[Title('AI işlemleri ve promptlar')]
final class AiOperationsPage extends Component
{
    #[Url(as: 'islem')]
    public string $operation = '';

    public string $template = '';

    public string $model = '';

    /** "Örnekte dene": the input the draft runs on (a past run's input or a pasted sample). */
    public string $trialInput = '';

    /** Monthly AI budget in USD (paid models stop when the month's spend reaches it; free models keep running). */
    public string $budget = '';

    /** Daily ceiling of automatic AI work in USD (rolling 24 hours; operator clicks keep running). */
    public string $dailyAutoBudget = '';

    /** Cost breakdown window: 24 | 168 hours. */
    public int $costHours = 24;

    public function mount(PromptRegistry $registry, AiBudget $aiBudget): void
    {
        $this->authorizeAdmin();
        $this->budget = (string) round($aiBudget->monthlyBudget(), 2);
        $this->dailyAutoBudget = (string) round($aiBudget->dailyBudget(), 2);
        if ($this->operation !== '') {
            $this->open($this->operation, $registry);
        }
    }

    public function open(string $operation, PromptRegistry $registry): void
    {
        $this->authorizeAdmin();
        if (! $registry->has($operation)) {
            $this->operation = '';

            return;
        }
        $current = $registry->current($operation);
        $this->operation = $operation;
        $this->template = (string) $current->template;
        $this->model = (string) ($current->model ?? '');
        $this->trialInput = '';
        $this->resetErrorBag();
    }

    public function saveBudget(): void
    {
        $this->authorizeAdmin();
        $this->validate(['budget' => ['required', 'numeric', 'min:0', 'max:100000'], 'dailyAutoBudget' => ['required', 'numeric', 'min:0', 'max:10000']], [],
            ['budget' => 'Aylık bütçe', 'dailyAutoBudget' => 'Günlük AI tavanı']);
        $setting = AgencySetting::query()->orderBy('id')->first() ?? new AgencySetting;
        $setting->forceFill(['ai_monthly_budget_usd' => round((float) $this->budget, 2), 'ai_daily_auto_budget_usd' => round((float) $this->dailyAutoBudget, 2)])->save();
        session()->flash('status', 'AI bütçeleri kaydedildi.');
    }

    public function close(): void
    {
        $this->operation = '';
    }

    public function save(PromptRegistry $registry): void
    {
        $this->authorizeAdmin();
        try {
            $version = $registry->publish($this->operation, ['template' => $this->template, 'model' => $this->model], auth()->user());
        } catch (InvalidArgumentException $exception) {
            $this->addError('template', $exception->getMessage());

            return;
        }
        $this->template = (string) $version->template;
        session()->flash('status', 'Sürüm '.$version->version.' yayınlandı.');
    }

    public function revertTo(int $version, PromptRegistry $registry): void
    {
        $this->authorizeAdmin();
        try {
            $new = $registry->revert($this->operation, $version, auth()->user());
        } catch (InvalidArgumentException $exception) {
            $this->addError('template', $exception->getMessage());

            return;
        }
        $this->template = (string) $new->template;
        $this->model = (string) ($new->model ?? '');
        session()->flash('status', 'Sürüm '.$version.' geri yüklendi (yeni sürüm '.$new->version.').');
    }

    /** Loads a past run's input of this operation into the trial box. */
    public function useSample(int $id, PromptTrial $trial): void
    {
        $this->authorizeAdmin();
        $this->trialInput = (string) ($trial->sample($this->operation, $id) ?? '');
    }

    /** Runs the editor's draft (not published) once on the trial input, in the background. */
    public function runTrial(PromptTrial $trial): void
    {
        $this->authorizeAdmin();
        try {
            $trial->queue($this->operation, $this->template, $this->model, $this->trialInput, auth()->user());
        } catch (InvalidArgumentException $exception) {
            $this->addError('trial', $exception->getMessage());
        }
    }

    /** "Varsayılana dön": the code default becomes the new current version. */
    public function resetDefault(PromptRegistry $registry): void
    {
        $this->authorizeAdmin();
        if (! $registry->has($this->operation)) {
            return;
        }
        $new = $registry->resetToDefault($this->operation, auth()->user());
        $this->template = (string) $new->template;
        $this->model = (string) ($new->model ?? '');
        session()->flash('status', 'Varsayılan prompt geri yüklendi (yeni sürüm '.$new->version.').');
    }

    public function render(PromptRegistry $registry, PromptRunStats $stats, AiRouteResolver $routes, PromptTrial $trial, AiBudget $aiBudget, AiLiveOperations $live): View
    {
        $summary = $stats->summary();
        $current = PromptVersion::query()->where('is_current', true)->get(['operation', 'version', 'model'])->keyBy('operation');
        $rows = [];
        foreach ($registry->definitions() as $key => $definition) {
            $version = $current->get($key);
            $rows[] = [
                'operation' => $key,
                'purpose' => $definition['purpose'],
                'model' => (string) ($version->model ?? $definition['model'] ?? ''),
                'version' => $version?->version,
                'runs' => $summary[$key]['runs'] ?? 0,
                'avg_ms' => $summary[$key]['avg_ms'] ?? null,
                'cost' => $summary[$key]['cost'] ?? 0.0,
            ];
        }

        $detail = null;
        if ($this->operation !== '' && $registry->has($this->operation)) {
            $definition = $registry->definition($this->operation);
            $detail = [
                'definition' => $definition,
                'current' => $registry->current($this->operation),
                'versions' => PromptVersion::query()->with('creator:id,name')->where('operation', $this->operation)->orderByDesc('version')->limit(50)->get(),
                'runs' => $stats->recent($this->operation),
                'models' => $this->modelOptions($routes),
                'samples' => $trial->samples($this->operation),
                'trial' => $trial->state((int) auth()->id(), $this->operation),
            ];
        }

        $liveRows = $detail === null ? $this->live($live) : null;

        return view('livewire.operator.settings.ai-operations', ['rows' => $rows, 'detail' => $detail, 'live' => $liveRows, 'monthSpend' => $aiBudget->monthSpend(),
            'monthlyBudget' => $aiBudget->monthlyBudget(),
            'costs' => $detail === null ? $aiBudget->breakdown(in_array($this->costHours, [24, 168], true) ? $this->costHours : 24) : [],
            'autoSpend' => $aiBudget->dailySpend(), 'autoBudget' => $aiBudget->dailyBudget(), 'remaining' => max(0.0, $aiBudget->monthlyBudget() - $aiBudget->monthSpend()),
            'schedule' => $detail === null ? app(AiSchedule::class)->upcoming() : []]);
    }

    /** "Durdur" (Admin): removes a queued job, or asks a running one to stop between its AI calls. */
    public function stop(int $id, AiJobTracker $jobs): void
    {
        $this->authorizeAdmin();
        $row = AiLiveOperation::query()->whereNull('parent_id')->find($id);
        session()->flash('status', ($row !== null ? $jobs->cancel($row, auth()->user()) : null) ?? 'Bu iş artık durdurulamaz (bitmiş ya da bir sayfa isteğinin parçası).');
    }

    /**
     * "Şu an": running top-level work with the step it is on (its running / last AI call), the calls done so far and
     * the cost so far; the queue; the last 24 hours; today's totals.
     *
     * @return array<string, mixed>
     */
    private function live(AiLiveOperations $live): array
    {
        $running = $live->running();
        $queued = $live->queued(30);
        $finished = AiLiveOperation::query()->whereNull('parent_id')->whereNotIn('status', [AiLiveOperation::RUNNING, AiLiveOperation::QUEUED])
            ->where('finished_at', '>=', now()->subDay())->orderByDesc('finished_at')->orderByDesc('id')->limit(40)->get();
        $steps = [];
        if ($running->isNotEmpty()) {
            AiLiveOperation::query()->whereIn('parent_id', $running->pluck('id'))->orderBy('started_at')->orderBy('id')
                ->get(['id', 'parent_id', 'label', 'status', 'model', 'provider', 'cost_usd', 'started_at'])
                ->groupBy('parent_id')->each(function ($calls, $parentId) use (&$steps): void {
                    $current = $calls->firstWhere('status', AiLiveOperation::RUNNING) ?? $calls->last();
                    $steps[(int) $parentId] = ['label' => (string) $current->label, 'model' => (string) ($current->model ?? ''), 'running' => $current->status === AiLiveOperation::RUNNING,
                        'done' => $calls->where('status', AiLiveOperation::DONE)->count(), 'calls' => $calls->count(), 'cost' => (float) $calls->sum('cost_usd')];
                });
        }
        $today = now('Europe/Istanbul')->startOfDay()->utc();
        $todayRows = AiLiveOperation::query()->whereNull('parent_id')->where('started_at', '>=', $today);

        return [
            'running' => $running, 'queued' => $queued, 'finished' => $finished, 'steps' => $steps,
            'users' => AiLiveIndicator::userNames($running->concat($queued)->concat($finished)),
            'today' => [
                'done' => (clone $todayRows)->where('status', AiLiveOperation::DONE)->count(),
                'failed' => (clone $todayRows)->where('status', AiLiveOperation::FAILED)->count(),
                'cost' => (float) AiLiveOperation::query()->where('kind', AiLiveOperation::KIND_CALL)->where('started_at', '>=', $today)->sum('cost_usd'),
            ],
        ];
    }

    /** @return array<string, string> value ("provider:model", '' = route model) => label */
    private function modelOptions(AiRouteResolver $routes): array
    {
        $options = ['' => 'Rota modeli (varsayılan)'];
        foreach (AiProviderCatalog::supported() as $provider) {
            if (! $routes->providerReady($provider)) {
                continue;
            }
            $models = array_keys((array) config('moxdop-ai-pricing.models.'.$provider, []));
            $models[] = AiProviderCatalog::defaultModel($provider);
            foreach (array_unique($models) as $model) {
                if ($model === '*' || str_starts_with((string) $model, 'text-embedding')) {
                    continue;
                }
                $options[$provider.':'.$model] = AiProviderCatalog::label($provider).' · '.$model;
            }
        }
        if ($this->model !== '' && ! isset($options[$this->model])) {
            $options[$this->model] = $this->model;
        }

        return $options;
    }

    private function authorizeAdmin(): void
    {
        abort_unless(PromptRegistry::canEdit(auth()->user()), 403);
    }
}
