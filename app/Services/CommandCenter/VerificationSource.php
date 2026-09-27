<?php

namespace App\Services\CommandCenter;

use App\Services\Verification\DataConsistencyChecker;
use App\Services\Verification\LiveVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Proof items in the command center: a failing live verification (moxdop:verify:live — the connection path does
 * not answer) and "Veri şüpheli" findings of the daily data consistency check. Both clear themselves: a later
 * successful check / a run that no longer finds the condition removes the item.
 */
final class VerificationSource implements CommandCenterSource
{
    private const array CHANNELS = ['ga4' => 'GA4', 'search_console' => 'Search Console', 'google_ads' => 'Google Ads', 'meta_ads' => 'Meta Ads'];

    public function items(): Collection
    {
        return $this->liveFailures()->merge($this->suspiciousData());
    }

    /** @return Collection<int, array<string, mixed>> */
    private function liveFailures(): Collection
    {
        if (! Schema::hasTable('live_checks')) {
            return collect();
        }
        $staleBefore = now()->subHours((int) config('moxdop-verification.live.stale_hours', 48));

        return collect(LiveVerifier::latest())
            ->filter(fn (array $check): bool => $check['status'] === LiveVerifier::FAIL && CarbonImmutable::parse($check['checked_at'])->gte($staleBefore))
            ->map(fn (array $check): array => CommandCenter::item('live', md5($check['check_key']),
                // A dead token stops every account behind it.
                $check['capability'] === 'token' ? 'critical' : 'high',
                'Canlı doğrulama başarısız: '.$check['label'], [
                    'detail' => $check['message'],
                    'rule' => $check['capability'] === 'token' ? 'token' : 'check',
                    'channel' => 'Entegrasyon',
                    'url' => route('operator.settings.system-health'),
                    'age' => CarbonImmutable::parse($check['checked_at']),
                ]))
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function suspiciousData(): Collection
    {
        if (! Schema::hasTable('data_consistency_issues')) {
            return collect();
        }

        return DataConsistencyChecker::open()->map(function (object $issue): array {
            $data = json_decode((string) $issue->data, true);
            $capability = is_array($data) ? (string) ($data['capability'] ?? '') : '';

            return CommandCenter::item('data', (int) $issue->id, (string) $issue->severity, (string) $issue->title, [
                'detail' => $issue->detail,
                'brand_id' => $issue->brand_id !== null ? (int) $issue->brand_id : null,
                'brand' => $issue->brand_name,
                'asset' => $issue->asset_domain ?: $issue->asset_name,
                'asset_id' => $issue->digital_asset_id !== null ? (int) $issue->digital_asset_id : null,
                'asset_type' => $issue->asset_type,
                'rule' => (string) $issue->kind,
                'channel' => 'Veri şüpheli'.(isset(self::CHANNELS[$capability]) ? ' · '.self::CHANNELS[$capability] : ''),
                'url' => route('operator.settings.system-health'),
                'age' => $issue->first_detected_at !== null ? CarbonImmutable::parse((string) $issue->first_detected_at) : null,
            ]);
        })->values();
    }
}
