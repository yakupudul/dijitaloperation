<?php

namespace App\Services\CommandCenter\Activity;

use App\Contracts\Collection\ActivityTierReader;
use App\Services\CommandCenter\TopicCatalog;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Hides budget / spend items of ad accounts that stopped advertising on purpose.
 *
 * An item whose topic is in `activity.suppressed_topics` (budget exhausted, zero delivery, spend anomalies…) and whose
 * asset's activity tier is dormant, or that the operator marked paused on the client's decision, leaves the inbox; the
 * page shows only a "Duraklatılmış hesaplar" note with the count.
 */
final class ActivitySuppression
{
    /** @var array<int, array{tier: string, last_active_on: ?string, operator_paused: bool}|null> */
    private array $tiers = [];

    public function __construct(private readonly ActivityTierReader $reader) {}

    /** @param  array<string, mixed>  $item  an item with its topic */
    public static function isBudgetTopic(array $item): bool
    {
        return TopicCatalog::matches((string) ($item['topic'] ?? ''), (array) config('moxdop-command-center.activity.suppressed_topics', []));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array{0: Collection<int, array<string, mixed>>, 1: Collection<int, array<string, mixed>>} [visible, suppressed]
     */
    public function split(Collection $items): array
    {
        $suppressed = $items->filter(fn (array $item): bool => $this->suppressed($item));

        return [$items->reject(fn (array $item): bool => $this->suppressed($item))->values(), $suppressed->values()];
    }

    /** @param  array<string, mixed>  $item */
    private function suppressed(array $item): bool
    {
        $assetId = (int) ($item['asset_id'] ?? 0);
        if ($assetId <= 0 || ! self::isBudgetTopic($item)) {
            return false;
        }
        if (! array_key_exists($assetId, $this->tiers)) {
            try {
                $this->tiers[$assetId] = $this->reader->forAsset($assetId);
            } catch (Throwable $error) {
                report($error);
                $this->tiers[$assetId] = null;
            }
        }
        $tier = $this->tiers[$assetId];

        return $tier !== null && (($tier['operator_paused'] ?? false) || in_array($tier['tier'] ?? null, (array) config('moxdop-command-center.activity.suppressed_tiers', ['dormant']), true));
    }
}
