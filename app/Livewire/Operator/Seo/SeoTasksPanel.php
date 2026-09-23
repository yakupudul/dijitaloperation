<?php

namespace App\Livewire\Operator\Seo;

use App\Enums\SeoTaskStatus;
use App\Enums\SeoTaskType;
use App\Models\BrandOffering;
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

    /** Answer a service-page question: $choice is a candidate URL or "none". */
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

        $counts = [];
        foreach ($this->query(ignoreType: true, ignoreStatus: true)->open()->get(['type']) as $row) {
            $counts[$row->type->value] = ($counts[$row->type->value] ?? 0) + 1;
        }

        $brandServiceCount = $site?->brand_id !== null
            ? BrandOffering::query()->where('brand_id', $site->brand_id)->where('status', 'active')->count()
            : null;

        return view('livewire.operator.seo.seo-tasks-panel', [
            'brandServiceCount' => $brandServiceCount,
            'tasks' => $tasks,
            'site' => $site,
            'latestPlan' => $latestPlan,
            'pendingPlan' => $pendingPlan,
            'counts' => $counts,
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
