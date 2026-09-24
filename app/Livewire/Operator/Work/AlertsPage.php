<?php

namespace App\Livewire\Operator\Work;

use App\Models\AssetAlert;
use App\Models\Brand;
use App\Services\Operator\OperatorPortfolioPresenter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * İşler › Uyarılar (Faz 11a): every open asset alert of the portfolio, most severe first, with brand / severity
 * filters. An alert can be snoozed for 1, 7 or 30 days; it comes back after the date, and a resolved alert that is
 * detected again starts unsnoozed. Resolving stays automatic (the scanner closes alerts whose condition is gone).
 */
#[Layout('operator.layouts.app')]
#[Title('Uyarılar')]
final class AlertsPage extends Component
{
    use WithPagination;

    public const array SNOOZE_DAYS = [1, 7, 30];

    #[Url]
    public string $show = 'active';

    #[Url]
    public string $severity = '';

    #[Url]
    public string $brand = '';

    public string $message = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['show', 'severity', 'brand'], true)) {
            $this->resetPage();
        }
    }

    public function snooze(int $id, int $days): void
    {
        abort_unless(in_array($days, self::SNOOZE_DAYS, true), 422);
        $alert = AssetAlert::query()->open()->findOrFail($id);
        $alert->forceFill(['snoozed_until' => now()->addDays($days), 'snoozed_by' => auth()->id()])->save();
        $this->message = '"'.$alert->title.'" '.$days.' gün sessize alındı.';
    }

    public function unsnooze(int $id): void
    {
        $alert = AssetAlert::query()->findOrFail($id);
        $alert->forceFill(['snoozed_until' => null, 'snoozed_by' => null])->save();
        $this->message = '"'.$alert->title.'" yeniden etkin.';
    }

    public function render(): View
    {
        $query = AssetAlert::query()->with(['digitalAsset', 'brand'])
            ->when($this->show === 'active', fn ($q) => $q->active())
            ->when($this->show === 'snoozed', fn ($q) => $q->open()->where('snoozed_until', '>', now()))
            ->when($this->show === 'resolved', fn ($q) => $q->whereNotNull('resolved_at')->where('resolved_at', '>=', now()->subDays(30)))
            ->when(in_array($this->severity, ['critical', 'high', 'medium', 'low'], true), fn ($q) => $q->where('severity', $this->severity))
            ->when(ctype_digit($this->brand), fn ($q) => $q->where('brand_id', (int) $this->brand));
        $query = $this->show === 'resolved'
            ? $query->orderByDesc('resolved_at')
            : $query->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")->orderByDesc('first_detected_at');

        $counts = AssetAlert::query()->active()->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity');

        return view('livewire.operator.work.alerts', [
            'alerts' => $query->paginate(30),
            'counts' => $counts,
            'snoozedCount' => AssetAlert::query()->open()->where('snoozed_until', '>', now())->count(),
            'brands' => Brand::query()->whereIn('id', AssetAlert::query()->open()->whereNotNull('brand_id')->select('brand_id'))->orderBy('name')->pluck('name', 'id'),
            'assetUrl' => fn (AssetAlert $alert): ?string => $alert->digitalAsset ? OperatorPortfolioPresenter::specialistUrl($alert->digitalAsset) : null,
        ]);
    }
}
