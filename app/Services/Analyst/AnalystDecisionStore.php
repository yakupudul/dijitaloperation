<?php

namespace App\Services\Analyst;

use App\Models\AnalystRun;
use App\Models\Suggestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Decisions persist by fingerprint (brand × channel × key). A new run:
 *  - creates unseen decisions (open), refreshes open / snoozed ones (text, numbers, evidence);
 *  - leaves done / dismissed ones closed unless the action materially changed (other type or target) → reopened;
 *  - expires open decisions the run did not propose again (the problem is gone or no longer a priority).
 * Operator: done (baseline stored for the outcome follow-up), snooze (days), dismiss ("Gereksiz"), note.
 */
final class AnalystDecisionStore
{
    public const int EVIDENCE_ROWS = 20;

    /** @param  list<array<string, mixed>>  $decisions  validated decisions */
    public function persist(AnalystRun $run, array $decisions, AnalystPack $pack): void
    {
        $now = now();
        $seen = [];
        DB::transaction(function () use ($run, $decisions, $pack, $now, &$seen): void {
            foreach ($decisions as $decision) {
                $fingerprint = self::fingerprint($run->channel, (string) $decision['key']);
                $material = self::materialHash($decision['action']);
                $seen[] = $fingerprint;
                $fields = [
                    'analyst_run_id' => $run->id, 'decision_key' => mb_substr((string) $decision['key'], 0, 160), 'material_hash' => $material,
                    'title' => mb_substr((string) $decision['title_tr'], 0, 160), 'reason' => mb_substr((string) $decision['why_tr'], 0, 240),
                    'priority' => (int) $decision['priority'], 'impact' => $decision['impact'], 'effort' => $decision['effort'],
                    'evidence_refs' => $decision['evidence_refs'], 'evidence' => $this->evidence($decision['evidence_refs'], $pack),
                    'action_type' => (string) $decision['action']['type'], 'action' => $this->params($decision['action']['params'], $pack), 'last_seen_at' => $now,
                ];
                $existing = Suggestion::query()->where('brand_id', $run->brand_id)->where('fingerprint', $fingerprint)->first();
                if ($existing === null) {
                    Suggestion::query()->create($fields + [
                        'brand_id' => $run->brand_id, 'channel' => $run->channel, 'fingerprint' => $fingerprint, 'status' => Suggestion::OPEN, 'first_seen_at' => $now,
                    ]);

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
            Suggestion::query()->where('brand_id', $run->brand_id)->where('channel', $run->channel)
                ->where('status', Suggestion::OPEN)->whereNotIn('fingerprint', $seen ?: [''])
                ->update(['status' => Suggestion::RECHECK, 'updated_at' => now()]);
        });
    }

    public function markDone(Suggestion $decision, ?User $user, ?string $note = null): void
    {
        $baseline = ['at' => now()->toIso8601String(), 'facts' => $decision->evidence ?? []];
        try {
            $registry = app(AnalystRegistry::class);
            if ($registry->has($decision->channel)) {
                $baseline['metric'] = $registry->get($decision->channel)->baseline($decision);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
        $decision->forceFill([
            'status' => Suggestion::APPLIED, 'resolved_at' => now(), 'resolved_by' => $user?->id, 'applied_at' => now(), 'baseline' => $baseline,
            'operator_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 2000) : $decision->operator_note,
        ])->save();
    }

    public function dismiss(Suggestion $decision, ?User $user, ?string $note = null): void
    {
        $decision->forceFill([
            'status' => Suggestion::DISMISSED, 'resolved_at' => now(), 'resolved_by' => $user?->id,
            'operator_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 2000) : $decision->operator_note,
        ])->save();
    }

    public function snooze(Suggestion $decision, int $days): void
    {
        if ($days < 1 || $days > 180) {
            throw ValidationException::withMessages(['analyst' => 'Erteleme 1–180 gün olabilir.']);
        }
        $decision->forceFill(['status' => Suggestion::SNOOZED, 'snoozed_until' => now()->addDays($days)])->save();
    }

    public static function fingerprint(string $channel, string $key): string
    {
        return hash('sha256', $channel.'|'.mb_strtolower(trim($key)));
    }

    /** @param  array{type: string, params: array<string, mixed>}  $action */
    public static function materialHash(array $action): string
    {
        $params = (array) ($action['params'] ?? []);
        ksort($params);

        return hash('sha256', ($action['type'] ?? '').'|'.json_encode($params, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Action params plus `_target`: the pack fact of the target, so links (URL path, cluster) resolve without the pack.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function params(array $params, AnalystPack $pack): array
    {
        $target = (string) ($params['target'] ?? '');
        if ($target !== '' && ($fact = $pack->fact($target)) !== null) {
            $params['_target'] = array_filter($fact, fn ($v): bool => is_scalar($v) || $v === null);
        }

        return $params;
    }

    /**
     * The pack facts behind the card (Kanıt), snapshotted so the table shows what the AI saw.
     *
     * @param  list<string>  $refs
     * @return list<array<string, mixed>>
     */
    private function evidence(array $refs, AnalystPack $pack): array
    {
        $rows = [];
        foreach (array_slice($refs, 0, self::EVIDENCE_ROWS) as $ref) {
            $fact = $pack->fact($ref);
            if ($fact !== null) {
                $rows[] = ['id' => $ref] + array_filter($fact, fn ($v): bool => ! is_array($v) || count($v) <= 10);
            }
        }

        return $rows;
    }
}
