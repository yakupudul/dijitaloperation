<?php

namespace App\Services\Ads;

use App\Enums\Collection\ActivityTier;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Collection\Activity\ActivityTierService;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Services\Meta\MetaSuggestions;
use Illuminate\Support\Facades\Log;

/**
 * Reklam hesabı harcama kuralı (yakup 2026-10-20, one rule 2026-11-07): an ad account's state follows its spend, by
 * itself, and nobody is asked about it.
 *   Aktif  — spend in the last `active_days` (7): daily collection, produces work.
 *   Durgun — no spend for 7..`dormant_days` (30): weekly light collection, still produces work.
 *   Pasif  — no spend for `dormant_days`+ (30), or paused by the operator as the customer's decision: only the weekly
 *            spend check runs; its open suggestions are snoozed with a marker so the desk, the brand file, the badges
 *            and the alarms count only accounts that run; no question to the operator.
 * The weekly check sees new spend → the tier becomes Aktif and the snoozed work opens again the same night. Nothing is
 * ever disconnected. The tier itself is computed by ActivityTierService (config moxdop-collection-activity).
 */
final class DormantAdWork
{
    /** Days without spend after which an account is Pasif (the same number the activity tier uses). */
    public static function quietDays(): int
    {
        return max(1, (int) config('moxdop-collection-activity.dormant_days', 30));
    }

    /** Marker in the suggestion's action: why and since when it is quiet. */
    public const string MARKER = 'dormant_account';

    /** A quiet suggestion's snooze is renewed every night; it runs out by itself if the job stops. */
    private const int SNOOZE_DAYS = 3;

    private const array CHANNELS = [GoogleAdsSuggestions::CHANNEL => GoogleAdsSuggestions::TARGET, MetaSuggestions::CHANNEL => MetaSuggestions::TARGET];

    public function __construct(private readonly ActivityTierService $tiers) {}

    /** @return array<int, string> asset id => last spend day ('' when none) of every ad asset that is not used */
    public function dormantAssets(): array
    {
        $out = [];
        foreach (DigitalAsset::query()->operational()->whereIn('type', ['google_ads', 'meta_ads'])->pluck('id') as $assetId) {
            $activity = $this->tiers->forAsset((int) $assetId);
            // Pasif = the dormant tier (dormant_days without spend) or the operator's pause; nothing else.
            if ($activity === null || $activity['tier'] !== ActivityTier::Dormant->value) {
                continue;
            }
            $out[(int) $assetId] = (string) ($activity['last_active_on'] ?? '');
        }
        // The old copy of a same-named working account produces no work either.
        foreach (array_keys(SupersededAdAccounts::map()) as $assetId) {
            $out[$assetId] ??= '';
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
        $reopenedAssets = [];
        Suggestion::query()->whereIn('channel', array_keys(self::CHANNELS))->where('status', Suggestion::SNOOZED)->whereNotNull('action->'.self::MARKER)
            ->whereNotIn('target_id', array_keys($dormant) ?: [0])
            ->lazyById(200)->each(function (Suggestion $suggestion) use (&$reopened, &$reopenedAssets): void {
                $action = (array) $suggestion->action;
                $status = (string) data_get($action, self::MARKER.'.status', Suggestion::OPEN);
                unset($action[self::MARKER]);
                $suggestion->forceFill(['status' => $status, 'snoozed_until' => null, 'action' => $action])->save();
                $reopened++;
                $reopenedAssets[(int) $suggestion->target_id] = true;
            });
        if ($reopenedAssets !== []) {
            // The account spends again: its work is back on the desk; the log keeps the day it happened.
            Log::info('Reklam hesabı yeniden aktif: harcama başladı, bekleyen işleri açıldı.', ['asset_ids' => array_keys($reopenedAssets), 'suggestions' => $reopened]);
        }

        return ['quiet' => $quiet, 'reopened' => $reopened];
    }
}
