<?php

namespace App\Livewire\Operator\Reports;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\MonthlyReport;
use App\Services\MonthlyReport\MonthlyReportBuilder;
use App\Services\MonthlyReport\MonthlyReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Raporlar › Aylık rapor (Faz 9): pick brand + month, prepare the numbers, write the AI commentary on click, edit
 * it, add a note, publish and copy the client link.
 */
#[Layout('operator.layouts.app')]
#[Title('Aylık rapor')]
final class MonthlyReportsPage extends Component
{
    #[Url]
    public ?int $brand = null;

    #[Url]
    public string $month = '';

    /** @var array{summary: string, wins: string, watch: string, next: string, note: string} */
    public array $edit = ['summary' => '', 'wins' => '', 'watch' => '', 'next' => '', 'note' => ''];

    public bool $editing = false;

    public ?string $clientUrl = null;

    public string $message = '';

    public string $error = '';

    public function updatedBrand(): void
    {
        $this->resetState();
    }

    public function updatedMonth(): void
    {
        $this->resetState();
    }

    public function prepare(MonthlyReportService $reports): void
    {
        try {
            $reports->prepare($this->selectedBrand() ?? abort(404), $this->month, auth()->user());
            $this->error = '';
            $this->message = 'Rakamlar hazırlandı.';
        } catch (ValidationException $exception) {
            $this->error = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function writeCommentary(MonthlyReportService $reports): void
    {
        $reports->requestCommentary($this->report() ?? abort(404));
        $this->message = 'AI yorumu yazılıyor; birkaç saniye sonra burada görünür.';
    }

    public function startEdit(): void
    {
        $report = $this->report() ?? abort(404);
        $c = (array) ($report->commentary ?? []);
        $this->edit = [
            'summary' => (string) ($c['summary'] ?? ''), 'wins' => implode("\n", (array) ($c['wins'] ?? [])),
            'watch' => implode("\n", (array) ($c['watch'] ?? [])), 'next' => implode("\n", (array) ($c['next_month'] ?? [])),
            'note' => (string) $report->operator_note,
        ];
        $this->editing = true;
    }

    public function saveEdit(MonthlyReportService $reports): void
    {
        $this->validate(['edit.summary' => ['nullable', 'string', 'max:1500'], 'edit.note' => ['nullable', 'string', 'max:3000']]);
        $reports->saveCommentary($this->report() ?? abort(404), $this->edit['summary'], $this->edit['wins'], $this->edit['watch'], $this->edit['next'], $this->edit['note']);
        $this->editing = false;
        $this->message = 'Yorum kaydedildi.';
    }

    public function publish(MonthlyReportService $reports): void
    {
        $report = $this->report() ?? abort(404);
        $reports->publish($report);
        $this->clientUrl = $reports->clientUrl($report);
        $this->message = 'Rapor yayımlandı. Müşteri bağlantısı '.config('moxdop-reports.client_link_days').' gün geçerli.';
    }

    public function email(MonthlyReportService $reports): void
    {
        try {
            $to = $reports->email($this->report() ?? abort(404));
            $this->error = '';
            $this->message = 'Rapor e-postayla gönderildi: '.implode(', ', $to);
        } catch (ValidationException $exception) {
            $this->error = (string) collect($exception->errors())->flatten()->first();
        } catch (\Throwable $exception) {
            report($exception);
            $this->error = 'E-posta gönderilemedi: '.mb_substr($exception->getMessage(), 0, 200);
        }
    }

    public function render(): View
    {
        $brands = Brand::query()->whereHas('customer', fn ($q) => $q->where('status', CustomerStatus::Active->value))->orderBy('name')->get(['id', 'name']);
        $this->brand ??= $brands->first()?->id;
        if ($this->month === '') {
            $this->month = MonthlyReportBuilder::defaultMonth();
        }
        $months = [];
        for ($i = 0; $i < 13; $i++) {
            $key = now()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m');
            $months[$key] = $key;
        }
        $report = $this->report();

        return view('livewire.operator.reports.monthly-reports', [
            'brands' => $brands,
            'months' => $months,
            'report' => $report,
            'recent' => $this->brand !== null ? MonthlyReport::query()->where('brand_id', $this->brand)->orderByDesc('month')->limit(12)->get(['id', 'month', 'status', 'published_at']) : collect(),
        ]);
    }

    private function report(): ?MonthlyReport
    {
        return $this->brand !== null ? MonthlyReport::query()->where('brand_id', $this->brand)->where('month', $this->month)->first() : null;
    }

    private function selectedBrand(): ?Brand
    {
        return $this->brand !== null ? Brand::query()->find($this->brand) : null;
    }

    private function resetState(): void
    {
        $this->editing = false;
        $this->clientUrl = null;
        $this->message = $this->error = '';
    }
}
