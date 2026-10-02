<?php

namespace App\Services\DataStatus;

use Carbon\CarbonImmutable;

/**
 * The data status of one source (capability) of one digital asset, in the single operator language:
 * Bağlı değil / İlk veri yükleniyor / Güncel / Gecikmiş / Erişim sorunu / Pasif. Built only by DataStatusReader.
 */
final readonly class DataStatus
{
    public const string NOT_BOUND = 'not_bound';

    public const string FIRST_LOAD = 'first_load';

    public const string FRESH = 'fresh';

    public const string STALE = 'stale';

    public const string ACCESS_PROBLEM = 'access_problem';

    public const string PAUSED = 'paused';

    public const string ACTION_REFRESH = 'refresh';

    public const string ACTION_RECONNECT = 'reconnect';

    public const string ACTION_BIND = 'bind';

    public function __construct(
        public int $assetId,
        public string $capability,
        public string $state,
        public ?int $bindingId = null,
        public ?int $externalResourceId = null,
        public ?string $resourceName = null,
        public ?string $provider = null,
        public ?CarbonImmutable $lastDataDate = null,
        public ?CarbonImmutable $lastSuccessAt = null,
        public ?int $ageDays = null,
        public ?string $reason = null,
        public bool $collecting = false,
        public ?int $progressPct = null,
        public string $action = self::ACTION_REFRESH,
        public ?string $actionUrl = null,
    ) {}

    public function sourceLabel(): string
    {
        return __('data_status.sources.'.$this->capability, [], 'tr');
    }

    /** "Güncel", "Gecikmiş · 12 gün", "Erişim sorunu", … */
    public function label(): string
    {
        if ($this->state === self::STALE && $this->ageDays !== null) {
            return __('data_status.stale_days', ['days' => $this->ageDays], 'tr');
        }

        return __('data_status.states.'.$this->state, [], 'tr');
    }

    /** Portföy cell text: the label plus the last data day for a current source ("Güncel · 25.09"). */
    public function shortLabel(): string
    {
        return $this->state === self::FRESH && $this->lastDataDate !== null
            ? $this->label().' · '.$this->lastDataDate->format('d.m')
            : $this->label();
    }

    /** The secondary line: last data day, last successful collection, and the known reason. */
    public function detail(): string
    {
        $parts = [];
        if ($this->lastDataDate !== null) {
            $parts[] = __('data_status.last_data', ['date' => $this->lastDataDate->locale('tr')->translatedFormat('j M')], 'tr');
        } elseif ($this->state !== self::NOT_BOUND && $this->lastSuccessAt !== null) {
            $parts[] = __('data_status.no_rows_yet', [], 'tr');
        }
        if ($this->lastSuccessAt !== null && $this->state !== self::NOT_BOUND) {
            $parts[] = __('data_status.collected', ['when' => $this->lastSuccessAt->locale('tr')->diffForHumans()], 'tr');
        }
        if ($this->reason !== null) {
            $parts[] = $this->reasonLabel();
        } elseif ($this->state === self::PAUSED) {
            $parts[] = __('data_status.paused_hint', [], 'tr');
        }

        return implode(' · ', array_filter($parts));
    }

    public function reasonLabel(): ?string
    {
        if ($this->reason === null) {
            return null;
        }
        $key = 'data_status.reasons.'.$this->reason;
        $text = __($key, [], 'tr');

        return $text !== $key ? $text : null;
    }

    public function actionLabel(): string
    {
        return __('data_status.actions.'.$this->action, [], 'tr');
    }

    /** ok (green) / warn (amber) / bad (red) / muted (grey). */
    public function tone(): string
    {
        return match ($this->state) {
            self::FRESH => 'ok',
            self::FIRST_LOAD, self::STALE => 'warn',
            self::ACCESS_PROBLEM => 'bad',
            default => 'muted',
        };
    }

    public function hasData(): bool
    {
        return $this->lastDataDate !== null;
    }

    public function isBound(): bool
    {
        return $this->state !== self::NOT_BOUND;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'asset_id' => $this->assetId,
            'capability' => $this->capability,
            'source' => $this->sourceLabel(),
            'state' => $this->state,
            'label' => $this->label(),
            'short_label' => $this->shortLabel(),
            'detail' => $this->detail(),
            'tone' => $this->tone(),
            'binding_id' => $this->bindingId,
            'external_resource_id' => $this->externalResourceId,
            'resource_name' => $this->resourceName,
            'last_data_date' => $this->lastDataDate?->toDateString(),
            'last_success_at' => $this->lastSuccessAt?->toIso8601String(),
            'age_days' => $this->ageDays,
            'reason' => $this->reason,
            'collecting' => $this->collecting,
            'progress_pct' => $this->progressPct,
            'action' => $this->action,
            'action_label' => $this->actionLabel(),
            'action_url' => $this->actionUrl,
        ];
    }
}
