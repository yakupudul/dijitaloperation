<?php

namespace App\Livewire\Operator\Library;

use App\Services\SearchDemand\QueryExclusionService;
use App\Support\Options\LocationOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class QueryExclusions extends Component
{
    use WithPagination;

    public bool $open = false;
    public string $section = 'rules';
    public string $bulkWords = '';
    public string $ruleSearch = '';
    public string $ruleLabel = '';
    public string $notice = '';
    public string $importLogId = '';

    #[Locked]
    public ?int $editingRuleId = null;

    #[Locked]
    public ?int $runId = null;

    #[Locked]
    public ?int $notifiedRunId = null;

    public function mount(): void
    {
        $id = DB::table('query_exclusion_runs')->where('created_by', auth()->id())->latest('id')->value('id');
        $this->runId = $id !== null ? (int) $id : null;
    }

    public function boot(QueryExclusionService $service): void
    {
        $service->authorize(auth()->user());
    }

    public function updatedRuleSearch(): void
    {
        $this->resetPage('exclusionRules');
    }

    public function updatedImportLogId(): void
    {
        $this->resetPage('exclusionLogs');
    }

    public function changeSection(string $section): void
    {
        abort_unless(in_array($section, ['rules', 'preview', 'exceptions', 'imports'], true), 422);
        $this->section = $section;
    }

    private function normalizedRule(string $label): string
    {
        $key = LocationOptions::fold($label);
        if ($key === '' || mb_strlen($label) > 255 || mb_strlen($key) > 255) {
            throw ValidationException::withMessages(['bulkWords' => __('query-exclusions.invalid_rule')]);
        }

        return $key;
    }

    public function addRules(): void
    {
        $this->validate(['bulkWords' => ['required', 'string', 'max:200000']]);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $this->bulkWords) ?: []), fn ($line): bool => $line !== ''));
        if ($lines === [] || count($lines) > 2000) {
            throw ValidationException::withMessages(['bulkWords' => __('query-exclusions.line_limit')]);
        }
        $rows = [];
        foreach ($lines as $label) {
            $rows[$this->normalizedRule($label)] = $label;
        }
        $count = DB::transaction(function () use ($rows): int {
            $count = 0;
            foreach ($rows as $key => $label) {
                $count += DB::table('query_exclusion_rules')->insertOrIgnore([
                    'label' => $label, 'normalized' => $key, 'active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $count;
        });
        $this->bulkWords = '';
        $this->notice = __('query-exclusions.added', ['count' => $count]);
        $this->resetPage('exclusionRules');
    }

    public function editRule(int $id): void
    {
        $rule = DB::table('query_exclusion_rules')->find($id);
        abort_unless($rule, 404);
        $this->editingRuleId = $id;
        $this->ruleLabel = $rule->label;
        $this->resetValidation();
    }

    public function cancelRuleEdit(): void
    {
        $this->editingRuleId = null;
        $this->ruleLabel = '';
        $this->resetValidation();
    }

    public function saveRule(): void
    {
        $this->validate(['ruleLabel' => ['required', 'string', 'max:255']]);
        abort_if($this->editingRuleId === null, 422);
        $key = $this->normalizedRule(trim($this->ruleLabel));
        try {
            DB::table('query_exclusion_rules')->where('id', $this->editingRuleId)->update([
                'label' => trim($this->ruleLabel), 'normalized' => $key, 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['ruleLabel' => __('query-exclusions.duplicate_rule')]);
        }
        $this->cancelRuleEdit();
        $this->notice = __('query-exclusions.saved');
    }

    public function toggleRule(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $rule = DB::table('query_exclusion_rules')->where('id', $id)->lockForUpdate()->first();
            abort_unless($rule, 404);
            DB::table('query_exclusion_rules')->where('id', $id)->update(['active' => ! $rule->active, 'updated_at' => now()]);
        });
    }

    public function deleteRule(int $id): void
    {
        DB::table('query_exclusion_rules')->where('id', $id)->delete();
        $this->cancelRuleEdit();
        $this->resetPage('exclusionRules');
        $this->notice = __('query-exclusions.rule_removed');
    }

    public function startPreview(QueryExclusionService $service): void
    {
        $this->runId = $service->start(auth()->user());
        $this->section = 'preview';
        $this->open = true;
        $this->notice = __('query-exclusions.scanning_help');
        $this->resetPage('exclusionMatches');
    }

    public function openRun(int $id): void
    {
        abort_unless(DB::table('query_exclusion_runs')->where('created_by', auth()->id())->where('id', $id)->exists(), 404);
        $this->runId = $id;
        $this->section = 'preview';
        $this->resetPage('exclusionMatches');
    }

    public function toggleMatch(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $run = DB::table('query_exclusion_runs')->where('id', $this->runId)->where('created_by', auth()->id())->lockForUpdate()->first();
            abort_unless($run && $run->status === 'ready', 409);
            $match = DB::table('query_exclusion_matches')->where('run_id', $run->id)->where('id', $id)->first();
            abort_unless($match, 404);
            DB::table('query_exclusion_matches')->where('id', $id)->update(['selected' => ! $match->selected, 'updated_at' => now()]);
        });
    }

    public function selectMatches(bool $selected): void
    {
        DB::transaction(function () use ($selected): void {
            $run = DB::table('query_exclusion_runs')->where('id', $this->runId)->where('created_by', auth()->id())->lockForUpdate()->first();
            abort_unless($run && $run->status === 'ready', 409);
            DB::table('query_exclusion_matches')->where('run_id', $run->id)->update(['selected' => $selected, 'updated_at' => now()]);
        });
    }

    public function confirmRemoval(QueryExclusionService $service): void
    {
        abort_if($this->runId === null, 422);
        $service->approve($this->runId, auth()->user());
        $this->notice = __('query-exclusions.applying_help');
    }

    public function removeException(int $id): void
    {
        DB::table('query_exclusion_exceptions')->where('id', $id)->delete();
        $this->resetPage('exclusionExceptions');
    }

    public function refreshProgress(): void
    {
        $run = $this->runId ? DB::table('query_exclusion_runs')->where('created_by', auth()->id())->find($this->runId) : null;
        if ($run && in_array($run->status, ['completed', 'failed'], true) && $this->notifiedRunId !== $run->id) {
            $this->notifiedRunId = $run->id;
            $this->dispatch('query-exclusions-applied');
        }
    }

    public function render(): View
    {
        $run = $this->runId ? DB::table('query_exclusion_runs')->where('created_by', auth()->id())->find($this->runId) : null;
        $logs = DB::table('query_exclusion_import_rows')->when(ctype_digit($this->importLogId), fn ($q) => $q->where('import_id', (int) $this->importLogId));
        $rulesChanged = $run && $run->rules_hash !== app(QueryExclusionService::class)->fingerprint(app(QueryExclusionService::class)->rules());

        return view('livewire.operator.library.query-exclusions', [
            'run' => $run,
            'rulesChanged' => $rulesChanged,
            'activeCount' => DB::table('query_exclusion_rules')->where('active', true)->count(),
            'rules' => DB::table('query_exclusion_rules')->when(trim($this->ruleSearch) !== '', fn ($q) => $q->where('normalized', 'like', '%'.LocationOptions::fold($this->ruleSearch).'%'))
                ->orderByDesc('id')->paginate(20, ['*'], 'exclusionRules'),
            'matches' => DB::table('query_exclusion_matches')->where('run_id', $run?->id ?? 0)->orderBy('id')->paginate(50, ['*'], 'exclusionMatches'),
            'selectedCount' => DB::table('query_exclusion_matches')->where('run_id', $run?->id ?? 0)->where('selected', true)->count(),
            'runs' => DB::table('query_exclusion_runs')->where('created_by', auth()->id())->latest('id')->limit(20)->get(),
            'exceptions' => DB::table('query_exclusion_exceptions')->latest('id')->paginate(20, ['*'], 'exclusionExceptions'),
            'logs' => $logs->latest('id')->paginate(50, ['*'], 'exclusionLogs'),
            'imports' => DB::table('search_query_library_imports')->where('excluded_rows', '>', 0)->latest('id')->limit(100)->get(['id', 'source_type', 'created_at', 'excluded_rows']),
        ]);
    }
}
