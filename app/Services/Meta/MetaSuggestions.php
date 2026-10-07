<?php

namespace App\Services\Meta;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Analyst\AnalystDecisionStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Meta Yapılacaklar: every suggestion of one ad account lives in the ONE `suggestions` table (channel `meta`, target
 * meta × asset), persisted by fingerprint. Groups: `check` (system checks, no AI), `creative`, `structure`, `landing`
 * (AI). Nothing is written to Meta: Onayla → the item becomes a copyable instruction / CSV row; Uygulandı → status
 * applied with the baseline (28-day account numbers) for the outcome follow-up. An operator edit locks the item: a
 * later AI pass never overwrites it and only leaves its new version as a "değişiklik önerisi".
 */
final class MetaSuggestions
{
    public const string CHANNEL = 'meta';

    public const string TARGET = 'meta';

    public const array GROUP_LABELS = ['check' => 'Sistem kontrolü', 'creative' => 'Kreatif', 'structure' => 'Kampanya yapısı', 'landing' => 'Form / açılış sayfası', 'plan' => 'Strateji planı'];

    /**
     * Upserts one group's items and moves the group's open, unlocked rows that were not proposed again to `recheck`.
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
                $key = $this->key($asset, $group.':'.$item['key']);
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
                if ((bool) ($existing->action['locked'] ?? false)) {
                    // Operator edit: kept as is; the new AI / system version waits as a change proposal.
                    if ($existing->material_hash !== $material) {
                        $existing->forceFill(['action' => array_merge($existing->action, ['change_proposal' => $item['action']]), 'last_seen_at' => $now])->save();
                    }

                    continue;
                }
                if (in_array($existing->status, [Suggestion::APPLIED, Suggestion::DISMISSED, Suggestion::APPROVED], true)) {
                    if ($existing->material_hash === $material || $existing->status === Suggestion::APPROVED) {
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
                ->get()->reject(fn (Suggestion $s): bool => (bool) ($s->action['locked'] ?? false))
                ->each(fn (Suggestion $s) => $s->forceFill(['status' => Suggestion::RECHECK])->save());
        });

        return count($seen);
    }

    /** @return Collection<int, Suggestion> open (and due snoozed) suggestions, optionally of one group, most important first */
    public function open(DigitalAsset $asset, ?string $group = null): Collection
    {
        $query = $this->query($asset)->actionable();
        if ($group !== null) {
            $query->where('decision_key', 'like', $this->key($asset, $group.':').'%');
        }

        return $query->orderBy('priority')->orderBy('id')->get();
    }

    /** @return Collection<int, Suggestion> approved, waiting for the manual apply in Ads Manager */
    public function approved(DigitalAsset $asset): Collection
    {
        return $this->query($asset)->where('status', Suggestion::APPROVED)->orderBy('priority')->orderBy('id')->get();
    }

    public function find(DigitalAsset $asset, int $id): Suggestion
    {
        return $this->query($asset)->whereKey($id)->firstOrFail();
    }

    public function approve(Suggestion $suggestion, ?User $user): void
    {
        $suggestion->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user?->id])->save();
    }

    /** The operator applied it in Ads Manager: status applied with the outcome baseline (28-day account numbers). */
    public function markApplied(DigitalAsset $asset, Suggestion $suggestion, ?User $user): void
    {
        app(AnalystDecisionStore::class)->markDone($suggestion, $user);
    }

    /** Operator edit of an item's text: stored and locked (AI never overwrites it). */
    public function edit(Suggestion $suggestion, string $text): void
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 3000) {
            throw ValidationException::withMessages(['meta' => 'Metin 1–3000 karakter olmalı.']);
        }
        $action = $suggestion->action ?? [];
        unset($action['change_proposal']);
        $suggestion->forceFill(['action' => array_merge($action, ['text' => $text, 'locked' => true])])->save();
    }

    /** Takes the pending change proposal of a locked item (unlocks it). */
    public function takeProposal(Suggestion $suggestion): void
    {
        $proposal = $suggestion->action['change_proposal'] ?? null;
        if (! is_array($proposal)) {
            return;
        }
        $suggestion->forceFill(['action' => $proposal, 'material_hash' => AnalystDecisionStore::materialHash(['type' => $suggestion->action_type, 'params' => $proposal])])->save();
    }

    /** @return list<list<string>> CSV rows (header first) of the approved items for the manual apply */
    public function csv(DigitalAsset $asset): array
    {
        $lines = [['Tür', 'Başlık', 'Hizmet', 'Reklam metni', 'Reklam başlığı', 'Açıklama', 'Video kancası', 'Talimat']];
        foreach ($this->approved($asset) as $s) {
            $a = $s->action ?? [];
            $lines[] = [self::GROUP_LABELS[$this->group($s)] ?? '', $s->title, (string) ($a['service'] ?? ''), (string) ($a['primary_text'] ?? ''),
                (string) ($a['headline'] ?? ''), (string) ($a['description'] ?? ''), (string) ($a['video_hook'] ?? ''), (string) ($a['text'] ?? '')];
        }

        return $lines;
    }

    public function group(Suggestion $suggestion): string
    {
        $parts = explode(':', (string) $suggestion->decision_key);

        return $parts[2] ?? '';
    }

    /** @return Builder<Suggestion> */
    private function query(DigitalAsset $asset): Builder
    {
        return Suggestion::query()->where('brand_id', (int) $asset->brand_id)->where('channel', self::CHANNEL)
            ->where('target_type', self::TARGET)->where('target_id', $asset->id);
    }

    private function key(DigitalAsset $asset, string $key): string
    {
        return 'meta:'.$asset->id.':'.$key;
    }
}
