<?php

namespace App\Services\Prospects;

use App\Models\Prospect;
use App\Models\ProspectReportShareGrant;
use App\Models\ProspectReportSnapshot;
use App\Models\User;
use App\Support\ReportDelivery\SecretHasher;
use Illuminate\Validation\ValidationException;

final class ProspectReportShareService
{
    public function createGrant(
        ProspectReportSnapshot $snapshot,
        ?User $actor = null,
        int $ttlDays = 14,
    ): array {
        if (! $snapshot->isClientShareable()) {
            throw ValidationException::withMessages([
                'share' => [__('operator.prospects.reports.share_internal_forbidden')],
            ]);
        }

        $raw = SecretHasher::randomToken();
        $grant = ProspectReportShareGrant::query()->create([
            'prospect_report_snapshot_id' => $snapshot->id,
            'locator_token_hash' => SecretHasher::hash($raw),
            'expires_at' => now()->addDays($ttlDays),
            'created_by' => $actor?->id,
        ]);

        $prospect = $snapshot->prospect;
        if ($prospect instanceof Prospect) {
            app(ProspectActivityRecorder::class)->record(
                $prospect,
                'prospect.report_shared',
                __('operator.prospects.activity.report_shared'),
                null,
                $actor,
                ['snapshot_id' => $snapshot->id, 'grant_id' => $grant->id],
            );
        }

        return [
            'grant' => $grant,
            'locator_token' => $raw,
            'url' => route('prospect-reports.share.locator', ['token' => $raw]),
        ];
    }

    public function resolveActiveGrant(string $rawToken): ProspectReportShareGrant
    {
        $hash = SecretHasher::hash($rawToken);
        $grant = ProspectReportShareGrant::query()
            ->with('snapshot')
            ->where('locator_token_hash', $hash)
            ->first();

        if (! $grant instanceof ProspectReportShareGrant || ! $grant->isActive()) {
            abort(404);
        }

        $snapshot = $grant->snapshot;
        if (! $snapshot instanceof ProspectReportSnapshot || ! $snapshot->isClientShareable()) {
            abort(404);
        }

        return $grant;
    }
}
