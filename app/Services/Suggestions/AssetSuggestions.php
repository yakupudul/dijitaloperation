<?php

namespace App\Services\Suggestions;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Analyst\AnalystDecisionStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Suggestions of one asset screen in the ONE `suggestions` table (channel × target × asset), persisted by fingerprint.
 * A new pass of a group refreshes its open rows, reopens a closed row only when its action materially changed, and
 * moves open rows it no longer proposes to `recheck`. A row the operator edited (`action.locked`) is never
 * overwritten: a materially different new AI version is only attached as `action.proposal` (değişiklik önerisi).
 */
abstract class AssetSuggestions
{
    abstract protected function channel(): string;

    abstract protected function target(): string;

    /** Prefix of the decision key (e.g. `gbp`). */
    abstract protected function prefix(): string;

    /**
     * Upserts one group's items and (with $sweep) moves the group's open rows that were not proposed again to `recheck`.
     *
     * @param  list<array{key: string, title: string, reason: string, priority: int, evidence: array<mixed>, action_type: string, action: array<string, mixed>, prompt_version_id?: ?int}>  $items
     */
    public function replaceGroup(DigitalAsset $asset, string $group, array $items, bool $sweep = true): int
    {
        if ($asset->brand_id === null) {
            return 0;
        }
        $now = now();
        $seen = [];
        DB::transaction(function () use ($asset, $group, $items, $now, $sweep, &$seen): void {
            foreach ($items as $item) {
                $key = $this->key($asset, $item['key']);
                $fingerprint = AnalystDecisionStore::fingerprint($this->channel(), $key);
                $seen[] = $fingerprint;
                $material = AnalystDecisionStore::materialHash(['type' => $item['action_type'], 'params' => $item['action']]);
                $fields = [
                    'decision_key' => mb_substr($key, 0, 160), 'material_hash' => $material,
                    'title' => mb_substr($item['title'], 0, 160), 'reason' => mb_substr($item['reason'], 0, 240),
                    'priority' => $item['priority'], 'evidence' => $item['evidence'], 'action_type' => $item['action_type'], 'action' => $item['action'],
                    'target_type' => $this->target(), 'target_id' => $asset->id, 'prompt_version_id' => $item['prompt_version_id'] ?? null, 'last_seen_at' => $now,
                ];
                $existing = Suggestion::query()->where('brand_id', $asset->brand_id)->where('fingerprint', $fingerprint)->first();
                if ($existing === null) {
                    Suggestion::query()->create($fields + ['brand_id' => $asset->brand_id, 'channel' => $this->channel(), 'fingerprint' => $fingerprint,
                        'status' => Suggestion::OPEN, 'first_seen_at' => $now]);

                    continue;
                }
                if ((bool) ($existing->action['locked'] ?? false)) {
                    // Operator-edited: keep the edit, leave the new AI version as a change proposal.
                    $action = $existing->action;
                    unset($action['proposal']);
                    if ($existing->material_hash !== $material) {
                        $action['proposal'] = $item['action'];
                    }
                    $existing->forceFill(['action' => $action, 'evidence' => $item['evidence'], 'last_seen_at' => $now])->save();

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
            if (! $sweep) {
                return;
            }
            $stale = $this->query($asset)->where('decision_key', 'like', $this->key($asset, $group.':').'%')
                ->where('status', Suggestion::OPEN)->whereNotIn('fingerprint', $seen ?: [''])->get(['id', 'action'])
                ->reject(fn (Suggestion $s): bool => (bool) ($s->action['locked'] ?? false))->pluck('id');
            if ($stale->isNotEmpty()) {
                Suggestion::query()->whereIn('id', $stale)->update(['status' => Suggestion::RECHECK, 'updated_at' => $now]);
            }
        });

        return count($seen);
    }

    /** @return Collection<int, Suggestion> open (and due snoozed) suggestions of the asset, most important first */
    public function open(DigitalAsset $asset): Collection
    {
        return $this->query($asset)->actionable()->orderBy('priority')->orderBy('id')->get();
    }

    public function find(DigitalAsset $asset, int $id): Suggestion
    {
        return $this->query($asset)->whereKey($id)->firstOrFail();
    }

    /** @return Builder<Suggestion> */
    protected function query(DigitalAsset $asset): Builder
    {
        return Suggestion::query()->where('brand_id', (int) $asset->brand_id)->where('channel', $this->channel())
            ->where('target_type', $this->target())->where('target_id', $asset->id);
    }

    protected function key(DigitalAsset $asset, string $key): string
    {
        return $this->prefix().':'.$asset->id.':'.$key;
    }
}
