<?php

namespace App\Livewire\Demo\Operations;

use App\Services\Activity\ActivityReadService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Activity')]
class ActivityIndex extends Component
{
    #[Url(as: 'brand', history: true)]
    public string $brand = '';

    #[Url(as: 'customer', history: true)]
    public string $customer = '';

    #[Url(as: 'asset', history: true)]
    public string $asset = '';

    public string $actor = 'all';

    public string $status = 'all';

    public string $period = 'last_28';

    /**
     * @return array<string, string>
     */
    public static function periodOptions(): array
    {
        return [
            'last_7' => __('operator.period.presets.last_7'),
            'last_14' => __('operator.period.presets.last_14'),
            'last_28' => __('operator.period.presets.last_28'),
            'last_90' => __('operator.period.presets.last_90'),
        ];
    }

    public function setActor(string $actor): void
    {
        $this->actor = in_array($actor, ['all', 'system', 'human'], true) ? $actor : 'all';
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function setPeriod(string $period): void
    {
        if (array_key_exists($period, self::periodOptions())) {
            $this->period = $period;
        }
    }

    public function render(): View
    {
        $filters = [
            'period' => $this->period,
            'actor' => $this->actor,
            'limit' => 200,
            'offset' => 0,
        ];

        if (trim($this->brand) !== '' && is_numeric($this->brand)) {
            $filters['brand_id'] = (int) $this->brand;
        }
        if (trim($this->customer) !== '' && is_numeric($this->customer)) {
            $filters['customer_id'] = (int) $this->customer;
        }
        if (trim($this->asset) !== '' && is_numeric($this->asset)) {
            $filters['digital_asset_id'] = (int) $this->asset;
        }

        $rows = app(ActivityReadService::class)->forList($filters);

        $timeline = collect($rows)
            ->map(fn (array $row): array => $this->toTimelineRow($row))
            ->when($this->status !== 'all', fn ($c) => $c->where('status', $this->status))
            ->values()
            ->all();

        return view('livewire.demo.operations.activity-index', [
            'timeline' => $timeline,
            'legacyRuns' => [],
            'periodOptions' => self::periodOptions(),
            'flash' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function toTimelineRow(array $row): array
    {
        $createdAt = isset($row['created_at']) && is_string($row['created_at'])
            ? CarbonImmutable::parse($row['created_at'])
            : CarbonImmutable::now();

        $scopeParts = array_filter([
            $row['customer'] ?? null,
            $row['brand'] ?? null,
        ], static fn ($v): bool => is_string($v) && $v !== '');

        $actorKind = (string) ($row['actor_kind'] ?? 'system');
        if ($actorKind === 'internal_user' || $actorKind === 'client_contact') {
            $actorKind = 'human';
        }

        return [
            'id' => $row['id'] ?? null,
            'when' => $createdAt->toFormattedDateString(),
            'time' => $createdAt->format('H:i'),
            'title' => (string) ($row['title'] ?? $row['event_label'] ?? 'Activity'),
            'scope' => $scopeParts === [] ? '—' : implode(' · ', $scopeParts),
            'detail' => $row['detail'] ?? null,
            'actor' => (string) ($row['actor'] ?? 'System'),
            'actor_kind' => $actorKind,
            'status' => (string) ($row['status'] ?? 'success'),
            'route' => $row['route'] ?? null,
            'route_params' => is_array($row['route_params'] ?? null) ? $row['route_params'] : [],
            'brand_id' => $row['brand_id'] ?? null,
            'customer_id' => $row['customer_id'] ?? null,
            'event' => $row['event'] ?? null,
        ];
    }
}
