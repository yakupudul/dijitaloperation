<?php

namespace App\Livewire\Operator\Seo;

use App\Enums\SeoTaskStatus;
use App\Enums\SeoTaskType;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SeoPlan;
use App\Models\SeoTask;
use App\Models\ServicePageAssignment;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\SeoTasks\SeoPlanRunner;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * One list, two homes: the global /seo-tasks page (websiteId = null, all brands) and the
 * website asset "SEO" tab (websiteId set). Same rows, same actions, site filter pre-applied.
 */
final class SeoTasksPanel extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $websiteId = null;

    #[Url(as: 'seo_customer')]
    public string $customerFilter = '';

    #[Url(as: 'seo_site')]
    public string $siteFilter = '';

    #[Url(as: 'seo_type')]
    public string $typeFilter = '';

    #[Url(as: 'seo_status')]
    public string $statusFilter = 'open';

    public ?int $expandedId = null;

    /** @var array<int|string, string> offering id => chosen URL or "none" (mapping card selects) */
    public array $mapping = [];

    public string $message = '';

    public string $messageTone = 'success';

    public function mount(?int $websiteId = null): void
    {
        $this->authorizeAccess();
        $this->websiteId = $websiteId;
        if ($websiteId !== null) {
            $this->siteFilter = (string) $websiteId;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['customerFilter', 'siteFilter', 'typeFilter', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    public function toggle(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function markDone(int $id): void
    {
        $this->resolve($id, SeoTaskStatus::Done, 'Görev "Yapıldı" olarak işaretlendi.');
    }

    public function skip(int $id): void
    {
        $this->resolve($id, SeoTaskStatus::Skipped, 'Görev atlandı; bir sonraki planda tekrar üretilmez.');
    }

    public function reopen(int $id): void
    {
        $task = $this->task($id);
        $task->forceFill(['status' => SeoTaskStatus::Open->value, 'resolved_at' => null, 'resolved_by' => null])->save();
        $this->flash('Görev yeniden açıldı.');
    }

    /** Answer one service in the grouped mapping card; the card closes when every service is answered. */
    public function answerMapping(int $taskId, int $offeringId, ?string $choice = null): void
    {
        $task = $this->task($taskId);
        $choice ??= (string) ($this->mapping[$offeringId] ?? '');
        $services = collect($task->evidence['services'] ?? []);
        $item = $services->firstWhere('offering_id', $offeringId);
        if ($task->type !== SeoTaskType::Question || ! is_array($item)) {
            return;
        }
        $none = $choice === 'none';
        $candidateUrls = collect($item['candidates'] ?? [])->pluck('url')->filter()->all();
        if (! $none && ! in_array($choice, $candidateUrls, true)) {
            $this->flash('Önce listeden bir sayfa seç veya "Sayfası yok" de.', 'error');

            return;
        }

        ServicePageAssignment::query()->updateOrCreate(
            ['digital_asset_id' => $task->digital_asset_id, 'brand_offering_id' => $offeringId],
            [
                'brand_id' => $task->brand_id,
                'page_url' => $none ? null : $choice,
                'status' => $none ? ServicePageAssignment::STATUS_NONE : ServicePageAssignment::STATUS_ASSIGNED,
                'decision_source' => ServicePageAssignment::SOURCE_OPERATOR,
                'score' => null,
                'candidates' => $item['candidates'] ?? [],
                'decided_by' => auth()->id(),
                'decided_at' => now(),
            ],
        );

        $remaining = $services->reject(fn (array $service): bool => (int) $service['offering_id'] === $offeringId)->values()->all();
        $evidence = $task->evidence;
        $evidence['services'] = $remaining;
        $task->forceFill(['evidence' => $evidence, 'title' => sprintf('%d öncelikli hizmetin sayfasını eşleştir', count($remaining))]);
        if ($remaining === []) {
            $task->forceFill(['status' => SeoTaskStatus::Done->value, 'resolved_at' => now(), 'resolved_by' => auth()->id()]);
        }
        $task->save();
        unset($this->mapping[$offeringId]);
        $this->flash(sprintf('"%s" kaydedildi. Bir sonraki planda bu eşleşme kullanılır.', $item['name']));
    }

    /** Answer a legacy per-service question: $choice is a candidate URL or "none". */
    public function answerQuestion(int $id, string $choice): void
    {
        $task = $this->task($id);
        if ($task->type !== SeoTaskType::Question || $task->brand_offering_id === null) {
            return;
        }
        $candidates = collect($task->evidence['candidates'] ?? [])->pluck('url')->filter()->all();
        $none = $choice === 'none';
        if (! $none && ! in_array($choice, $candidates, true)) {
            $this->flash('Geçersiz seçim.', 'error');

            return;
        }

        ServicePageAssignment::query()->updateOrCreate(
            ['digital_asset_id' => $task->digital_asset_id, 'brand_offering_id' => $task->brand_offering_id],
            [
                'brand_id' => $task->brand_id,
                'page_url' => $none ? null : $choice,
                'status' => $none ? ServicePageAssignment::STATUS_NONE : ServicePageAssignment::STATUS_ASSIGNED,
                'decision_source' => ServicePageAssignment::SOURCE_OPERATOR,
                'score' => null,
                'candidates' => $task->evidence['candidates'] ?? [],
                'decided_by' => auth()->id(),
                'decided_at' => now(),
            ],
        );
        $task->forceFill([
            'status' => SeoTaskStatus::Done->value,
            'resolved_at' => now(),
            'resolved_by' => auth()->id(),
            'resolution_note' => $none ? 'Sayfası yok' : $choice,
        ])->save();
        $this->flash('Cevap kaydedildi; bir sonraki planda bu hizmet için tekrar sorulmaz.');
    }

    /** Add an AI/rule-inferred service from the latest plan to the Brand as a real offering. */
    public function adoptService(int $index, BrandOfferingService $offerings): void
    {
        if ($this->websiteId === null) {
            return;
        }
        $site = DigitalAsset::query()->with('brand')->findOrFail($this->websiteId);
        $plan = SeoPlan::query()->where('digital_asset_id', $site->id)->where('status', SeoPlan::STATUS_COMPLETED)->latest('id')->first();
        $service = data_get($plan?->input_summary, 'site_understanding.services.'.$index);
        if ($site->brand === null || ! is_array($service) || blank($service['name'] ?? null)) {
            $this->flash('Hizmet bulunamadı; planı yenileyin.', 'error');

            return;
        }
        try {
            $offering = $offerings->findByLabel($site->brand, (string) $service['name'])
                ?? $offerings->create($site->brand, (string) $service['name'], null, auth()->user());
            foreach ($service['aliases'] ?? [] as $alias) {
                try {
                    $offerings->addAlias($offering, (string) $alias);
                } catch (Throwable) {
                    // alias collisions are not fatal
                }
            }
            if (! empty($service['is_core'])) {
                $offering->forceFill(['is_priority' => true])->save();
            }
            if (filled($service['page_url'] ?? null)) {
                ServicePageAssignment::query()->updateOrCreate(
                    ['digital_asset_id' => $site->id, 'brand_offering_id' => $offering->id],
                    ['brand_id' => $site->brand_id, 'page_url' => $service['page_url'], 'status' => ServicePageAssignment::STATUS_ASSIGNED,
                        'decision_source' => ServicePageAssignment::SOURCE_OPERATOR, 'score' => null, 'candidates' => [],
                        'decided_by' => auth()->id(), 'decided_at' => now()],
                );
            }
        } catch (ValidationException $exception) {
            $this->flash(implode(' ', $exception->validator->errors()->all()), 'error');

            return;
        }
        $this->flash(sprintf('"%s" markaya hizmet olarak eklendi. Bir sonraki planda gerçek hizmet olarak kullanılır.', $service['name']));
    }

    public function refreshPlan(SeoPlanRunner $runner): void
    {
        if ($this->websiteId === null) {
            return;
        }
        $site = DigitalAsset::query()->findOrFail($this->websiteId);
        try {
            $plan = $runner->queue($site, auth()->user(), 'manual');
        } catch (ValidationException $exception) {
            $this->flash(implode(' ', $exception->validator->errors()->all()), 'error');

            return;
        }
        $this->flash(sprintf('Plan #%d kuyruğa alındı. Sayfayı kapatabilirsiniz; sonuç Etkinlik ekranından da izlenir.', $plan->version));
    }

    public function refreshAll(SeoPlanRunner $runner): void
    {
        $plans = $runner->queueAll(auth()->user(), onlyConnected: false, trigger: 'bulk');
        $this->flash(sprintf('%d site için plan kuyruğa alındı.', $plans->count()));
    }

    public function render(): View
    {
        $tasks = $this->query()->paginate(25);
        $site = $this->websiteId !== null ? DigitalAsset::query()->with('brand')->find($this->websiteId) : null;
        $latestPlan = null;
        $pendingPlan = null;
        if ($site !== null) {
            $latestPlan = SeoPlan::query()->where('digital_asset_id', $site->id)->where('status', SeoPlan::STATUS_COMPLETED)->latest('id')->first();
            $pendingPlan = SeoPlan::query()->where('digital_asset_id', $site->id)->whereIn('status', [SeoPlan::STATUS_QUEUED, SeoPlan::STATUS_RUNNING])->latest('id')->first();
        }

        $openInScope = $this->query(ignoreType: true, ignoreStatus: true)->open()->with('digitalAsset')->get();
        $counts = [];
        foreach ($openInScope as $row) {
            $counts[$row->type->value] = ($counts[$row->type->value] ?? 0) + 1;
        }
        $setupTasks = $openInScope->filter(fn (SeoTask $task): bool => $task->type === SeoTaskType::Question)->values();
        $pendingMappings = $setupTasks->where('rule_id', 'service-page-mapping')->sum(fn (SeoTask $task): int => max(1, count($task->evidence['services'] ?? [])));

        $siteIds = $openInScope->pluck('digital_asset_id')->unique()->values();
        $lastPlans = SeoPlan::query()
            ->whereIn('digital_asset_id', $siteIds)
            ->where('status', SeoPlan::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->get(['id', 'digital_asset_id', 'completed_at', 'summary_text', 'input_summary'])
            ->unique('digital_asset_id')
            ->keyBy('digital_asset_id');
        $weeklyTarget = (int) config('moxdop-seo-tasks.create.min_per_site', 4);

        $kpis = [
            'content' => $counts[SeoTaskType::Create->value] ?? 0,
            'content_target' => $weeklyTarget * max(1, $siteIds->count()),
            'extra_clicks' => (int) round($openInScope->filter(fn (SeoTask $task): bool => in_array($task->type, [SeoTaskType::Create, SeoTaskType::Strengthen], true))->sum('estimated_extra_clicks')),
            'critical_fixes' => $openInScope->filter(fn (SeoTask $task): bool => $task->type === SeoTaskType::Fix && in_array($task->severity, ['critical', 'high'], true))->count(),
            'pending_mappings' => $pendingMappings,
        ];

        $siteOverview = $this->websiteId !== null ? collect() : $openInScope
            ->groupBy('digital_asset_id')
            ->map(function ($rows, $assetId) use ($lastPlans, $weeklyTarget): array {
                $first = $rows->first();

                return [
                    'id' => (int) $assetId,
                    'domain' => $first->digitalAsset?->domain ?: $first->digitalAsset?->name,
                    'open' => $rows->where('type', '!=', SeoTaskType::Question)->count(),
                    'content' => $rows->where('type', SeoTaskType::Create)->count(),
                    'target' => $weeklyTarget,
                    'critical' => $rows->filter(fn (SeoTask $task): bool => $task->type === SeoTaskType::Fix && in_array($task->severity, ['critical', 'high'], true))->count(),
                    'mappings' => $rows->where('type', SeoTaskType::Question)->where('rule_id', 'service-page-mapping')->sum(fn (SeoTask $task): int => max(1, count($task->evidence['services'] ?? []))),
                    'clicks' => (int) round($rows->sum('estimated_extra_clicks')),
                    'last_plan_at' => $lastPlans->get($assetId)?->completed_at,
                    'gsc' => data_get($lastPlans->get($assetId)?->input_summary, 'gsc.available'),
                ];
            })
            ->sortByDesc('clicks')
            ->values();

        return view('livewire.operator.seo.seo-tasks-panel', [
            'tasks' => $tasks,
            'site' => $site,
            'latestPlan' => $latestPlan,
            'pendingPlan' => $pendingPlan,
            'counts' => $counts,
            'kpis' => $kpis,
            'setupTasks' => $setupTasks,
            'siteOverview' => $siteOverview,
            'customers' => $this->websiteId === null ? Customer::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'sites' => $this->websiteId === null ? $this->siteOptions() : collect(),
            'types' => SeoTaskType::cases(),
            'statuses' => SeoTaskStatus::cases(),
        ]);
    }

    /** @return Builder<SeoTask> */
    private function query(bool $ignoreType = false, bool $ignoreStatus = false): Builder
    {
        $query = SeoTask::query()->with(['brand.customer', 'digitalAsset', 'offering.primaryName']);

        if ($this->websiteId !== null) {
            $query->where('digital_asset_id', $this->websiteId);
        } else {
            if (ctype_digit($this->customerFilter)) {
                $query->where('customer_id', (int) $this->customerFilter);
            }
            if (ctype_digit($this->siteFilter)) {
                $query->where('digital_asset_id', (int) $this->siteFilter);
            }
        }
        if (! $ignoreType && $this->typeFilter !== '' && SeoTaskType::tryFrom($this->typeFilter) !== null) {
            $query->where('type', $this->typeFilter);
        } elseif (! $ignoreType) {
            $query->where('type', '!=', SeoTaskType::Question->value); // questions live in the setup card
        }
        if (! $ignoreStatus) {
            if ($this->statusFilter === 'all') {
                // no status filter
            } elseif (SeoTaskStatus::tryFrom($this->statusFilter) !== null) {
                $query->where('status', $this->statusFilter);
            } else {
                $query->where('status', SeoTaskStatus::Open->value);
            }
        }

        return $query->orderByDesc('priority_score')->orderBy('id');
    }

    /** @return Collection<int, DigitalAsset> */
    private function siteOptions(): Collection
    {
        return DigitalAsset::query()
            ->with('brand')
            ->where('type', 'website')
            ->whereIn('id', SeoTask::query()->select('digital_asset_id'))
            ->orderBy('domain')
            ->get(['id', 'brand_id', 'domain', 'name']);
    }

    private function task(int $id): SeoTask
    {
        $query = SeoTask::query()->whereKey($id);
        if ($this->websiteId !== null) {
            $query->where('digital_asset_id', $this->websiteId);
        }

        return $query->firstOrFail();
    }

    private function resolve(int $id, SeoTaskStatus $status, string $message): void
    {
        $task = $this->task($id);
        $task->forceFill(['status' => $status->value, 'resolved_at' => now(), 'resolved_by' => auth()->id()])->save();
        $this->flash($message);
    }

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->message = $message;
        $this->messageTone = $tone;
    }

    private function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
    }
}
