<?php

namespace App\Services\Ads;

use App\Enums\Collection\ActivityTier;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Collection\Activity\ActivityTierService;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Services\Meta\MetaSuggestions;

/**
 * Kullanılmayan reklam hesapları (yakup 2026-10-20): an ad account that has not spent for QUIET_DAYS (or that the
 * operator paused as the customer's decision) produces no work. Its open suggestions are snoozed with a marker, so the
 * desk, the brand file and the badges count only accounts that run; once the account spends again they open by
 * themselves. Nothing is disconnected and collection goes on (weekly, as for every dormant account).
 */
final class DormantAdWork
{
    public const int QUIET_DAYS = 60;

    /** Marker in the suggestion's action: why and since when it is quiet. */
    public const string MARKER = 'dormant_account';

    /** A quiet suggestion's snooze is renewed every night; it runs out by itself if the job stops. */
    private const int SNOOZE_DAYS = 3;

    private const array CHANNELS = [GoogleAdsSuggestions::CHANNEL => GoogleAdsSuggestions::TARGET, MetaSuggestions::CHANNEL => MetaSuggestions::TARGET];

    public function __construct(private readonly ActivityTierService $tiers) {}

    /** @return array<int, string> asset id => last spend day ('' when none) of every ad asset that is not used */
    public function dormantAssets(): array
    {
        $since = now()->subDays(self::QUIET_DAYS)->toDateString();
        $out = [];
        foreach (DigitalAsset::query()->operational()->whereIn('type', ['google_ads', 'meta_ads'])->pluck('id') as $assetId) {
            $activity = $this->tiers->forAsset((int) $assetId);
            if ($activity === null || $activity['tier'] !== ActivityTier::Dormant->value) {
                continue;
            }
            $last = (string) ($activity['last_active_on'] ?? '');
            if ($activity['operator_paused'] || $last === '' || $last < $since) {
                $out[(int) $assetId] = $last;
            }
        }

        return $out;
    }

    /** @return array{quiet: int, reopened: int} */
    public function sync(): array
    {
        $dormant = $this->dormantAssets();
        $quiet = 0;
        foreach (self::CHANNELS as $channel => $target) {
            Suggestion::query()->where('channel', $channel)->whereIn('target_type', [$target, 'asset'])->whereIn('target_id', array_keys($dormant) ?: [0])
                ->where(fn ($q) => $q->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])
                    ->orWhere(fn ($s) => $s->where('status', Suggestion::SNOOZED)->whereNotNull('action->'.self::MARKER)))
                ->lazyById(200)->each(function (Suggestion $suggestion) use ($dormant, &$quiet): void {
                    $action = (array) $suggestion->action;
                    $action[self::MARKER] ??= ['since' => now()->toIso8601String(), 'last_spend' => $dormant[(int) $suggestion->target_id] ?: null, 'status' => $suggestion->status];
                    $suggestion->forceFill(['status' => Suggestion::SNOOZED, 'snoozed_until' => now()->addDays(self::SNOOZE_DAYS), 'action' => $action])->save();
                    $quiet++;
                });
        }

        $reopened = 0;
        Suggestion::query()->whereIn('channel', array_keys(self::CHANNELS))->where('status', Suggestion::SNOOZED)->whereNotNull('action->'.self::MARKER)
            ->whereNotIn('target_id', array_keys($dormant) ?: [0])
            ->lazyById(200)->each(function (Suggestion $suggestion) use (&$reopened): void {
                $action = (array) $suggestion->action;
                $status = (string) data_get($action, self::MARKER.'.status', Suggestion::OPEN);
                unset($action[self::MARKER]);
                $suggestion->forceFill(['status' => $status, 'snoozed_until' => null, 'action' => $action])->save();
                $reopened++;
            });

        return ['quiet' => $quiet, 'reopened' => $reopened];
    }
}
