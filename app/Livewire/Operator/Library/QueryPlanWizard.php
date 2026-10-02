<?php

namespace App\Livewire\Operator\Library;

use App\Jobs\Queries\ProcessQueriesJob;
use App\Jobs\Queries\RescanQueriesJob;
use App\Livewire\Concerns\PreviewsKeywordImpact;
use App\Models\FilterTerm;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\ServiceMatchingKeyword;
use App\Models\User;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Queries\QueryNormalizer;
use App\Services\Queries\QueryPipeline;
use App\Services\Queries\QueryPlanner;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Sorgular › "AI ile planla": 1. Hesaplar ve sektörler · 2. Hizmetler ve eşleme kelimeleri · 3. Sektör filtre sepeti →
 * the first import (later: a rescan to approve). Every AI step is one queued call; its proposal is only pre-selected
 * and nothing is saved before "Onayla".
 */
#[Layout('operator.layouts.app')]
#[Title('AI ile planla')]
final class QueryPlanWizard extends Component
{
    use PreviewsKeywordImpact;

    public const array STEP_KEYS = [1 => 'sectors', 2 => 'services', 3 => 'filters'];

    #[Url(history: true)]
    public int $step = 1;

    /** @var array<int|string, string> brand id => '' | sector id | "new:Ad" */
    public array $brandSectors = [];

    /** @var array<int|string, string> asset id => '' (brand's) | sector id | "new:Ad" */
    public array $assetSectors = [];

    /** @var array<int|string, bool> checklist of the step's AI proposal (index => ticked) */
    public array $pick = [];

    /** Step whose proposal was merged into the form (pre-selected once). */
    public string $merged = '';

    /** @var array<int|string, string> */
    public array $newService = [];

    /** @var array<int|string, string> */
    public array $newKeyword = [];

    /** @var array<int|string, string> sector id => "Toplu hizmet ekle" lines ("Hizmet" or "Hizmet: kelime1, kelime2") */
    public array $bulkServices = [];

    /** @var array<int|string, string> */
    public array $newTerm = [];

    public string $message = '';

    /** Filtre sepeti: the operator's own instruction for "AI ile oluştur" (sent with the stored prompt). */
    public string $filterInstruction = '';

    public function mount(QueryPlanner $planner): void
    {
        $this->step = in_array($this->step, [1, 2, 3], true) ? $this->step : 1;
        foreach ($planner->brands() as $brand) {
            $this->brandSectors[$brand->id] = (string) ($brand->sector_id ?? '');
            foreach ($brand->digitalAssets as $asset) {
                $this->assetSectors[$asset->id] = (string) ($asset->sector_id ?? '');
            }
        }
        $this->syncProposal();
    }

    public function goTo(int $step): void
    {
        if (in_array($step, [1, 2, 3], true)) {
            $this->step = $step;
            $this->pick = [];
            $this->merged = '';
            $this->syncProposal();
        }
    }

    /**
     * "AI ile sektör ata" / "AI ile hizmet keşfet" / "AI ile oluştur": queued AI calls for this step (steps 2 and 3: one
     * job per sector in parallel, progress shown while they run).
     */
    public function runAi(QueryPlanner $planner): void
    {
        $actor = $this->actor();
        $key = self::STEP_KEYS[$this->step];
        $this->merged = '';
        $this->pick = [];
        QueryPlanner::start((int) $actor->id, $key, $this->step === 1 ? [] : $planner->usedSectorIds(), $key === 'filters' ? $this->filterInstruction : '');
        $this->syncProposal();
    }

    /** "Durdur": forgets the running step (late answers are ignored) so it can be started again. */
    public function stopAi(): void
    {
        $actor = $this->actor();
        QueryPlanner::reset((int) $actor->id, self::STEP_KEYS[$this->step]);
        $this->merged = '';
        $this->pick = [];
        $this->message = 'AI adımı durduruldu; yeniden başlatabilirsiniz.';
    }

    /** Polled while the AI step runs: a ready proposal is pre-selected once. */
    public function syncProposal(): void
    {
        $user = auth()->user();
        $key = self::STEP_KEYS[$this->step];
        $proposal = $user instanceof User ? QueryPlanner::current((int) $user->id, $key) : null;
        if (($proposal['status'] ?? null) !== 'ready' || $this->merged === $key) {
            return;
        }
        $this->merged = $key;
        if ($key === 'sectors') {
            foreach ((array) $proposal['brands'] as $id => $row) {
                if (($this->brandSectors[$id] ?? '') === '') {
                    $this->brandSectors[$id] = (string) $row['value'];
                }
            }
            foreach ((array) $proposal['assets'] as $id => $row) {
                if (($this->assetSectors[$id] ?? '') === '') {
                    $this->assetSectors[$id] = (string) $row['value'];
                }
            }

            return;
        }
        $this->pick = array_fill_keys(array_keys((array) $proposal['items']), true);
    }

    // ── Adım 1 ───────────────────────────────────────────────────────────────

    public function approveSectors(QueryPlanner $planner): void
    {
        $actor = $this->actor();
        $saved = $planner->applySectors($this->brandSectors, $this->assetSectors);
        QueryPlanner::discard((int) $actor->id, 'sectors');
        $this->message = sprintf('%d marka · %d varlık sektörü kaydedildi.', $saved['brands'], $saved['assets']);
        $this->mount($planner);
        $this->goTo(2);
    }

    // ── Adım 2 ───────────────────────────────────────────────────────────────

    /** "Tümünü seç" / "Hiçbirini": every line of the current AI proposal. */
    public function pickAll(bool $ticked): void
    {
        $user = auth()->user();
        $proposal = $user instanceof User ? QueryPlanner::current((int) $user->id, self::STEP_KEYS[$this->step]) : null;
        if (($proposal['status'] ?? null) === 'ready') {
            $this->pick = array_fill_keys(array_keys((array) $proposal['items']), $ticked);
        }
    }

    /**
     * "Seçilenleri ekle": every ticked proposal line at once (new services with all their keywords, keyword adds /
     * removes / moves); the applied lines leave the proposal, the step stays open for the rest.
     */
    public function applyPicked(QueryPlanner $planner): void
    {
        $actor = $this->actor();
        $proposal = QueryPlanner::current((int) $actor->id, 'services');
        $picked = $this->picked();
        if (($proposal['status'] ?? null) !== 'ready' || $picked === []) {
            $this->message = 'Önce öneri seçin.';

            return;
        }
        $types = array_count_values(array_map(fn (int $i): string => (string) ($proposal['items'][$i]['type'] ?? ''), $picked));
        $applied = $planner->applyServices($proposal, $picked, $actor);
        foreach ($picked as $index) {
            unset($proposal['items'][$index], $this->pick[$index]);
        }
        Cache::put(QueryPlanner::cacheKey((int) $actor->id, 'services'), $proposal, now()->addDay());
        if ($applied > 0 && QueryPipeline::importedAt() !== null) {
            RescanQueriesJob::dispatch((int) $actor->id);
        }
        $this->message = sprintf('%d öneri eklendi (%d yeni hizmet · %d kelime değişikliği).', $applied, $types['new_service'] ?? 0, count($picked) - ($types['new_service'] ?? 0));
    }

    /**
     * "Toplu hizmet ekle": one service per line, optional matching keywords after ":" ("Diş Beyazlatma: beyazlatma,
     * bleaching"). An existing service of the sector only gets the keywords; a name owned by another sector is skipped.
     */
    public function addServicesBulk(int $sectorId, ServiceCatalogService $catalog, ServiceKeywordService $keywords): void
    {
        $actor = $this->actor();
        $sector = ServiceCategory::query()->findOrFail($sectorId);
        $counts = ['created' => 0, 'existing' => 0, 'keywords' => 0];
        $skipped = [];
        foreach (self::serviceLines((string) ($this->bulkServices[$sectorId] ?? '')) as [$name, $words]) {
            try {
                $result = $catalog->resolveOrCreate($name, (string) $sector->code, actor: $actor);
            } catch (ValidationException) {
                $skipped[] = $name;

                continue;
            }
            if (! $result['created'] && $result['service']->sector !== $sector->code) {
                $skipped[] = $name;

                continue;
            }
            $counts[$result['created'] ? 'created' : 'existing']++;
            foreach ($words as $word) {
                try {
                    $keywords->add($result['service'], $word);
                    $counts['keywords']++;
                } catch (ValidationException) {
                    $skipped[] = $name.': '.$word;
                }
            }
        }
        if ($counts === ['created' => 0, 'existing' => 0, 'keywords' => 0] && $skipped === []) {
            throw ValidationException::withMessages(['bulkServices.'.$sectorId => 'Her satıra bir hizmet yazın.']);
        }
        $this->bulkServices[$sectorId] = '';
        if ($counts['keywords'] > 0 && QueryPipeline::importedAt() !== null) {
            RescanQueriesJob::dispatch((int) $actor->id);
        }
        $this->message = sprintf('%s: %d hizmet eklendi · %d mevcut · %d eşleme kelimesi', $sector->name, $counts['created'], $counts['existing'], $counts['keywords'])
            .($skipped !== [] ? ' · atlanan: '.implode(', ', array_slice($skipped, 0, 10)).(count($skipped) > 10 ? '…' : '') : '').'.';
    }

    /**
     * @return list<array{0: string, 1: list<string>}> [service name, keywords] per non-empty line (at most 200)
     */
    public static function serviceLines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            [$name, $words] = array_pad(explode(':', $line, 2), 2, '');
            $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 0, 120);
            if ($name === '') {
                continue;
            }
            $words = array_values(array_unique(array_filter(array_map(fn (string $w): string => trim(preg_replace('/\s+/u', ' ', $w) ?? ''), preg_split('/[,;]/u', $words) ?: []))));
            $lines[] = [$name, array_slice($words, 0, 40)];
        }

        return array_slice($lines, 0, 200);
    }

    public function addService(int $sectorId, ServiceCatalogService $catalog): void
    {
        $actor = $this->actor();
        $sector = ServiceCategory::query()->findOrFail($sectorId);
        $name = trim((string) ($this->newService[$sectorId] ?? ''));
        try {
            $result = $catalog->resolveOrCreate($name, (string) $sector->code, actor: $actor);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['newService.'.$sectorId => collect($exception->errors())->flatten()->first()]);
        }
        if (! $result['created']) {
            throw ValidationException::withMessages(['newService.'.$sectorId => 'Bu hizmet zaten var.']);
        }
        $this->newService[$sectorId] = '';
    }

    public function removeService(int $serviceId, ServiceCatalogService $catalog): void
    {
        $catalog->delete(ServiceCatalogItem::query()->findOrFail($serviceId), $this->actor());
    }

    public function addKeyword(int $serviceId, ServiceKeywordService $keywords): void
    {
        $this->actor();
        try {
            $keywords->add(ServiceCatalogItem::query()->findOrFail($serviceId), (string) ($this->newKeyword[$serviceId] ?? ''));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['newKeyword.'.$serviceId => collect($exception->errors())->flatten()->first()]);
        }
        $this->newKeyword[$serviceId] = '';
        $this->impact = [];
    }

    public function removeKeyword(int $id): void
    {
        $this->actor();
        ServiceMatchingKeyword::query()->whereKey($id)->delete();
    }

    public function approveServices(QueryPlanner $planner): void
    {
        $actor = $this->actor();
        $proposal = QueryPlanner::current((int) $actor->id, 'services');
        $applied = ($proposal['status'] ?? null) === 'ready' ? $planner->applyServices($proposal, $this->picked(), $actor) : 0;
        QueryPlanner::discard((int) $actor->id, 'services');
        if (QueryPipeline::importedAt() !== null) {
            RescanQueriesJob::dispatch((int) $actor->id);
        }
        $this->message = $applied.' öneri uygulandı.';
        $this->goTo(3);
    }

    // ── Adım 3 ───────────────────────────────────────────────────────────────

    public function addTerm(int $sectorId): void
    {
        $actor = $this->actor();
        ServiceCategory::query()->findOrFail($sectorId);
        $term = trim(preg_replace('/\s+/u', ' ', QueryNormalizer::lower((string) ($this->newTerm[$sectorId] ?? ''))) ?? '');
        if (mb_strlen($term) < 2 || mb_strlen($term) > 200) {
            throw ValidationException::withMessages(['newTerm.'.$sectorId => 'Terim 2–200 karakter olmalı.']);
        }
        if (QueryNormalizer::isQuestionTerm($term)) {
            throw ValidationException::withMessages(['newTerm.'.$sectorId => 'Soru / bilgi kelimesi (nedir, nasıl…) içeren sorgular içerik kümeleri için tutulur; filtreye eklenmez.']);
        }
        FilterTerm::query()->firstOrCreate(['sector_id' => $sectorId, 'term' => $term], ['source' => 'manual', 'created_by' => $actor->id]);
        $this->newTerm[$sectorId] = '';
    }

    public function deleteTerm(int $id): void
    {
        $this->actor();
        FilterTerm::query()->whereKey($id)->delete();
    }

    /** "Onayla ve içe aktar": ticked terms saved; the first import (later: a rescan to approve) starts. */
    public function approveFilters(QueryPlanner $planner): mixed
    {
        $actor = $this->actor();
        $proposal = QueryPlanner::current((int) $actor->id, 'filters');
        $saved = ($proposal['status'] ?? null) === 'ready' ? $planner->applyFilters($proposal, $this->picked(), $actor) : 0;
        QueryPlanner::discard((int) $actor->id, 'filters');
        if (QueryPipeline::importedAt() === null) {
            ProcessQueriesJob::dispatch(true, (int) $actor->id);
            $message = $saved.' filtre terimi kaydedildi · ilk içe aktarma başladı, bitince bildirim gelir.';
        } else {
            RescanQueriesJob::dispatch((int) $actor->id);
            $message = $saved.' filtre terimi kaydedildi · tarama başladı, bitince bildirim gelir.';
        }
        session()->flash('queries-message', $message);

        return $this->redirectRoute('operator.library.queries', navigate: true);
    }

    public function render(QueryPlanner $planner): View
    {
        $user = auth()->user();
        $key = self::STEP_KEYS[$this->step];
        $proposal = $user instanceof User ? QueryPlanner::current((int) $user->id, $key) : null;
        $sectors = ServiceCategory::query()->orderBy('name')->get(['id', 'code', 'name']);
        $used = $this->step === 1 ? collect() : $sectors->whereIn('id', $planner->usedSectorIds())->values();
        $newOptions = collect([...$this->brandSectors, ...$this->assetSectors])->filter(fn ($v): bool => str_starts_with((string) $v, 'new:'))->unique()->values()->all();

        return view('livewire.operator.library.query-plan-wizard', [
            'proposal' => $proposal,
            'sectors' => $sectors->pluck('name', 'id')->all(),
            'newOptions' => $newOptions,
            'brands' => $this->step === 1 ? $planner->brands() : collect(),
            'unbound' => $this->step === 1 ? $planner->unboundAccounts() : [],
            'used' => $used,
            'services' => $this->step === 2 ? $used->mapWithKeys(fn (ServiceCategory $s): array => [$s->id => QueryPlanner::sectorServices($s)])->all() : [],
            'terms' => $this->step === 3 ? FilterTerm::query()->whereIn('sector_id', $used->pluck('id'))->orderBy('term')->get()->groupBy('sector_id')->all() : [],
            'running' => ($proposal['status'] ?? null) === 'running',
        ]);
    }

    /** @return list<int> */
    private function picked(): array
    {
        return array_map('intval', array_keys(array_filter($this->pick)));
    }

    private function actor(): User
    {
        $this->resetErrorBag();
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return $actor;
    }
}
