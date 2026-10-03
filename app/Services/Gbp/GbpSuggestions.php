<?php

namespace App\Services\Gbp;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Analyst\AnalystDecisionStore;
use App\Services\Suggestions\AssetSuggestions;
use App\Services\Work\WorkVerifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * İşletme Profili Yapılacaklar: every suggestion of one profile lives in the ONE `suggestions` table (channel `maps`,
 * target gbp × asset), persisted by fingerprint. Groups: `standard` (system checks, no AI), `service` / `category`
 * (Hizmetleri karşılaştır), `description` (Açıklama öner). A new pass of a group refreshes its open rows, reopens a
 * closed row only when its action materially changed, and moves open rows it no longer proposes to `recheck`.
 * Onayla → approved (the operator changes it on Google) → Uygulandı stores the outcome baseline.
 */
final class GbpSuggestions extends AssetSuggestions
{
    public const string CHANNEL = 'maps';

    public const string TARGET = 'gbp';

    /** At most this many failing standards are shown as suggestions. */
    public const int MAX_STANDARDS = 10;

    private const array SEVERITY_PRIORITY = ['high' => 1, 'medium' => 2, 'low' => 3];

    public function __construct(private readonly GbpStandardInput $standards) {}

    /**
     * System checks: the failing Business Profile standards (fail before review, high severity first), at most 10.
     *
     * @return int suggestions now open from standards
     */
    public function syncStandards(DigitalAsset $asset): int
    {
        $resource = app(GbpDailyWorkspace::class)->resource($asset);
        if ($asset->brand_id === null || $resource === null || DB::table('gbp_location_snapshots')->where('external_resource_id', $resource->id)->doesntExist()) {
            return 0;
        }
        $results = collect($this->standards->results($asset, (int) $resource->id));
        $failing = $results
            ->filter(fn (array $r): bool => in_array($r['state'], ['fail', 'review'], true))
            ->sortBy(fn (array $r): string => ($r['state'] === 'fail' ? '0' : '1').(self::SEVERITY_PRIORITY[$r['severity']] ?? 3).$r['id'])
            ->take(self::MAX_STANDARDS);
        $items = $failing->map(fn (array $r): array => [
            'key' => 'standard:'.$r['id'],
            'title' => $r['title'],
            'reason' => $r['finding'] !== '' ? $r['finding'] : 'Profil standardı karşılanmıyor.',
            'priority' => self::SEVERITY_PRIORITY[$r['severity']] ?? 3,
            'evidence' => [['standard' => $r['id'], 'durum' => $r['state'] === 'fail' ? 'Sorun' : 'Kontrol et', 'bulgu' => $r['finding']]],
            'action_type' => 'gbp_standard',
            'action' => ['standard_id' => $r['id'], 'todo' => (string) ($r['solution'] ?? '')],
        ])->values()->all();

        $stored = $this->replaceGroup($asset, 'standard', $items);
        app(WorkVerifier::class)->checks($asset, self::CHANNEL, 'gbp_standard',
            $results->where('state', 'pass')->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
            $results->where('state', 'fail')->pluck('id')->map(fn ($id): string => (string) $id)->values()->all());

        return $stored;
    }

    /**
     * Onayla: the operator agrees and will do it on Google (profile standards, services / categories, description are
     * never written by MoxDOP). It waits in "Uygulanacaklar" until "Uygulandı".
     */
    public function approve(Suggestion $suggestion, ?User $user): void
    {
        $suggestion->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user?->id, 'resolved_at' => now()])->save();
    }

    /** @return Collection<int, Suggestion> approved, waiting for the operator's change on Google */
    public function approved(DigitalAsset $asset): Collection
    {
        return $this->query($asset)->where('status', Suggestion::APPROVED)->orderBy('priority')->orderBy('id')->get();
    }

    /** Uygulandı: done on Google — applied with the outcome baseline from today. */
    public function markApplied(Suggestion $suggestion, ?User $user): void
    {
        app(AnalystDecisionStore::class)->markDone($suggestion, $user);
    }

    protected function channel(): string
    {
        return self::CHANNEL;
    }

    protected function target(): string
    {
        return self::TARGET;
    }

    protected function prefix(): string
    {
        return 'gbp';
    }
}
