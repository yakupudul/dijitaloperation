<?php

namespace App\Services\Gbp;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Analyst\AnalystDecisionStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * İşletme Profili Yapılacaklar: every suggestion of one profile lives in the ONE `suggestions` table (channel `maps`,
 * target gbp × asset), persisted by fingerprint. Groups: `standard` (system checks, no AI), `service` / `category`
 * (Hizmetleri karşılaştır), `description` (Açıklama öner). A new pass of a group refreshes its open rows, reopens a
 * closed row only when its action materially changed, and moves open rows it no longer proposes to `recheck`.
 */
final class GbpSuggestions
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
        $failing = collect($this->standards->results($asset, (int) $resource->id))
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

        return $this->replaceGroup($asset, 'standard', $items);
    }

    /**
     * Upserts one group's items and moves the group's open rows that were not proposed again to `recheck`.
     *
     * @param  list<array{key: string, title: string, reason: string, priority: int, evidence: array<mixed>, action_type: string, action: array<string, mixed>, prompt_version_id?: ?int}>  $items
     */
    public function replaceGroup(DigitalAsset $asset, string $group, array $items): int
    {
        if ($asset->brand_id === null) {
            return 0;
        }
        $now = now();
        $seen = [];
        DB::transaction(function () use ($asset, $group, $items, $now, &$seen): void {
            foreach ($items as $item) {
                $key = $this->key($asset, $item['key']);
                $fingerprint = AnalystDecisionStore::fingerprint(self::CHANNEL, $key);
                $seen[] = $fingerprint;
                $material = AnalystDecisionStore::materialHash(['type' => $item['action_type'], 'params' => $item['action']]);
                $fields = [
                    'decision_key' => mb_substr($key, 0, 160), 'material_hash' => $material,
                    'title' => mb_substr($item['title'], 0, 160), 'reason' => mb_substr($item['reason'], 0, 240),
                    'priority' => $item['priority'], 'evidence' => $item['evidence'], 'action_type' => $item['action_type'], 'action' => $item['action'],
                    'target_type' => self::TARGET, 'target_id' => $asset->id, 'prompt_version_id' => $item['prompt_version_id'] ?? null, 'last_seen_at' => $now,
                ];
                $existing = Suggestion::query()->where('brand_id', $asset->brand_id)->where('fingerprint', $fingerprint)->first();
                if ($existing === null) {
                    Suggestion::query()->create($fields + ['brand_id' => $asset->brand_id, 'channel' => self::CHANNEL, 'fingerprint' => $fingerprint,
                        'status' => Suggestion::OPEN, 'first_seen_at' => $now]);

                    continue;
                }
                if (in_array($existing->status, [Suggestion::APPLIED, Suggestion::DISMISSED], true)) {
                    if ($existing->material_hash === $material) {
                        $existing->forceFill(['last_seen_at' => $now])->save();

                        continue;
                    }
                    $fields += ['status' => Suggestion::OPEN, 'resolved_at' => null, 'resolved_by' => null];
                } elseif ($existing->status === Suggestion::RECHECK) {
                    $fields['status'] = Suggestion::OPEN;
                }
                $existing->forceFill($fields)->save();
            }
            $this->query($asset)->where('decision_key', 'like', $this->key($asset, $group.':').'%')
                ->where('status', Suggestion::OPEN)->whereNotIn('fingerprint', $seen ?: [''])
                ->update(['status' => Suggestion::RECHECK, 'updated_at' => $now]);
        });

        return count($seen);
    }

    /** @return Collection<int, Suggestion> open (and due snoozed) suggestions of the profile, most important first */
    public function open(DigitalAsset $asset): Collection
    {
        return $this->query($asset)->actionable()->orderBy('priority')->orderBy('id')->get();
    }

    public function find(DigitalAsset $asset, int $id): Suggestion
    {
        return $this->query($asset)->whereKey($id)->firstOrFail();
    }

    /** @return Builder<Suggestion> */
    private function query(DigitalAsset $asset): Builder
    {
        return Suggestion::query()->where('brand_id', (int) $asset->brand_id)->where('channel', self::CHANNEL)
            ->where('target_type', self::TARGET)->where('target_id', $asset->id);
    }

    private function key(DigitalAsset $asset, string $key): string
    {
        return 'gbp:'.$asset->id.':'.$key;
    }
}
