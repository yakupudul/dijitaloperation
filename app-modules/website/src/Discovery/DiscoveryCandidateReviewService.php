<?php

namespace MoxDop\Website\Discovery;

use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\User;
use App\Services\Website\PublicDiscovery\DiscoveryCandidateApplicationService;
use App\Services\Website\PublicDiscovery\StoredDiscoverySource;
use App\Services\Website\PublicDiscovery\StoredHtmlReader;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DiscoveryCandidateReviewService
{
    public const string SOURCE_PUBLIC_DISCOVERY = 'public_discovery';

    public const string SOURCE_PUBLIC_DISCOVERY_EDITED = 'public_discovery_edited';

    public function __construct(private readonly DiscoveryCandidateApplicationService $applications) {}

    public function accept(DiscoveryCandidate $candidate, User $actor, ?string $editedValue = null, array $options = []): DiscoveryCandidate
    {
        abort_unless($actor->is_active && $actor->can(Permissions::ACCESS_APP), 403);

        return DB::transaction(function () use ($candidate, $actor, $editedValue, $options): DiscoveryCandidate {
            $candidate = DiscoveryCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($candidate->status === DiscoveryCandidate::STATUS_ACCEPTED) {
                if (in_array(data_get($candidate->support_json, 'application.state'), ['applied', 'integration_ready'], true) || ! ($options['apply_reviewed'] ?? false)) {
                    return $candidate;
                }
            }
            $value = trim($editedValue ?? $candidate->accepted_value ?? $candidate->proposed_value);
            if ($value === '' || mb_strlen($value) > 2000) {
                throw ValidationException::withMessages(['editedValue' => '1–2000 karakter arasında bir değer girin.']);
            }
            $this->assertCurrentSource($candidate);
            $previous = data_get($candidate->support_json, 'application');
            $receipt = $this->applications->apply($candidate, $value, $actor, $options);
            if ($previous !== null) {
                $history = data_get($candidate->support_json, 'application_history', []);
                $history[] = $previous;
                $candidate->support_json = array_merge($candidate->support_json ?? [], ['application_history' => $history]);
            }
            $candidate->forceFill([
                'status' => DiscoveryCandidate::STATUS_ACCEPTED, 'reviewed_by_id' => $actor->id,
                'reviewed_at' => now(), 'accepted_value' => $value,
                'was_edited' => $value !== $candidate->proposed_value,
                'support_json' => array_merge($candidate->support_json ?? [], ['application' => $receipt]),
            ])->save();

            return $candidate->refresh();
        });
    }

    public function ignore(DiscoveryCandidate $candidate, User $actor): DiscoveryCandidate
    {
        abort_unless($actor->is_active && $actor->can(Permissions::ACCESS_APP), 403);

        return DB::transaction(function () use ($candidate, $actor): DiscoveryCandidate {
            $candidate = DiscoveryCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($candidate->status === DiscoveryCandidate::STATUS_ACCEPTED) {
                throw ValidationException::withMessages(['candidate' => 'Aktarılmış kayıt burada geri alınamaz; ilgili kaydı kendi ekranından düzenleyin.']);
            }
            $candidate->update(['status' => DiscoveryCandidate::STATUS_IGNORED, 'reviewed_by_id' => $actor->id, 'reviewed_at' => now()]);

            return $candidate->refresh();
        });
    }

    private function assertCurrentSource(DiscoveryCandidate $candidate): void
    {
        // Legacy decisions can be explicitly transferred but are never relabelled as fresh observations.
        if (data_get($candidate->support_json, 'normalization_version') !== DiscoveryConfig::VERSION) {
            return;
        }
        $asset = DigitalAsset::query()->where('brand_id', $candidate->brand_id)->findOrFail($candidate->digital_asset_id);
        foreach (data_get($candidate->support_json, 'sources', []) as $source) {
            $snapshot = DB::table('website_html_snapshot')->where('digital_asset_id', $asset->id)
                ->where('url', $source['url'] ?? '')->orderByDesc('observed_at')->orderByDesc('id')->first();
            if ($snapshot === null || (int) $snapshot->id !== (int) ($source['snapshot_id'] ?? 0)
                || ! app(StoredDiscoverySource::class)->isFresh($snapshot->observed_at)) {
                continue;
            }
            try {
                if (app(StoredHtmlReader::class)->read($asset, $snapshot->url, (int) $snapshot->id) !== null) {
                    return;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        throw ValidationException::withMessages(['editedValue' => 'Bu adayın kaynağı eski veya değişmiş. Keşfi yeniden çalıştırıp güncel adayı inceleyin.']);
    }
}
