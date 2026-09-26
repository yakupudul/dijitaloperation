<?php

namespace App\Livewire\Operator\Reports;

use App\Enums\CustomerStatus;
use App\Jobs\SendMonthlyReportJob;
use App\Models\Brand;
use App\Models\MonthlyReport;
use App\Services\MonthlyReport\MonthlyReportBuilder;
use App\Services\MonthlyReport\MonthlyReportService;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Rapor kuyruğu: one month's report for every active brand in one list — prepare the missing ones, review, then
 * publish and e-mail the selected reports in one go (sent in the background, errors shown per brand).
 */
#[Layout('operator.layouts.app')]
#[Title('Rapor kuyruğu')]
final class ReportQueuePage extends Component
{
    #[Url]
    public string $month = '';

    /** @var list<int> report ids */
    public array $bulkIds = [];

    public function mount(): void
    {
        if ($this->month === '') {
            $this->month = MonthlyReportBuilder::defaultMonth();
        }
    }

    public function updatedMonth(): void
    {
        $this->bulkIds = [];
    }

    public function prepareMissing(MonthlyReportService $reports): void
    {
        $this->authorizeAdmin();
        $done = 0;
        foreach ($this->brands() as $brand) {
            if (MonthlyReport::query()->where('brand_id', $brand->id)->where('month', $this->month)->exists()) {
                continue;
            }
            try {
                $report = $reports->prepare($brand, $this->month, auth()->user());
                if ((bool) config('moxdop-reports.auto_commentary', true)) {
                    $reports->requestCommentary($report);
                }
                $done++;
            } catch (Throwable $error) {
                report($error);
            }
        }
        DemoState::flash($done.' marka için rapor hazırlandı; AI yorumları arka planda yazılıyor.');
    }

    public function publishSelected(MonthlyReportService $reports): void
    {
        $this->authorizeAdmin();
        $reportsToPublish = MonthlyReport::query()->whereIn('id', $this->bulkIds)->where('month', $this->month)->get();
        $reportsToPublish->each(fn (MonthlyReport $report) => $report->status === 'published' ? null : $reports->publish($report));
        $this->bulkIds = [];
        DemoState::flash($reportsToPublish->count().' rapor yayınlandı (müşteri bağlantısı açık).');
    }

    public function sendSelected(): void
    {
        $this->authorizeAdmin();
        $ids = MonthlyReport::query()->whereIn('id', $this->bulkIds)->where('month', $this->month)->pluck('id');
        $ids->each(fn (int $id) => SendMonthlyReportJob::dispatch($id));
        $this->bulkIds = [];
        DemoState::flash($ids->count().' rapor yayınlanıp müşterilere e-postalanıyor. Gönderilemeyenler listede nedeniyle görünür.');
    }

    public function render(): View
    {
        $brands = $this->brands();
        $reports = MonthlyReport::query()->whereIn('brand_id', $brands->pluck('id'))->where('month', $this->month)->get()->keyBy('brand_id');
        $rows = $brands->map(fn (Brand $brand): array => ['brand' => $brand, 'report' => $reports->get($brand->id)]);
        $months = [];
        for ($i = 0; $i < 13; $i++) {
            $key = now()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m');
            $months[$key] = $key;
        }

        return view('livewire.operator.reports.report-queue', [
            'rows' => $rows,
            'months' => $months,
            'counts' => [
                'missing' => $rows->whereNull('report')->count(),
                'draft' => $rows->filter(fn ($r) => $r['report'] !== null && $r['report']->status !== 'published')->count(),
                'published' => $rows->filter(fn ($r) => $r['report']?->status === 'published' && $r['report']->emailed_at === null)->count(),
                'sent' => $rows->filter(fn ($r) => $r['report']?->emailed_at !== null)->count(),
            ],
            'selectable' => $reports->pluck('id')->all(),
        ]);
    }

    /** @return Collection<int, Brand> */
    private function brands()
    {
        return Brand::query()->with('customer')->whereHas('customer', fn ($q) => $q->where('status', CustomerStatus::Active->value))
            ->whereHas('digitalAssets', fn ($q) => $q->operational())->orderBy('name')->get();
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
