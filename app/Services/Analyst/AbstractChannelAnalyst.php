<?php

namespace App\Services\Analyst;

use App\Models\Brand;
use App\Models\Suggestion;
use App\Services\Analyst\Contracts\ChannelAnalyst;
use App\Services\Compliance\BriefCompliance;

/**
 * Shared validation of AI decisions. A decision is dropped (with its reason) when:
 *  - the title is empty or longer than TITLE_MAX, the why is empty, longer than WHY_MAX or more than one sentence;
 *  - the why quotes no number, or a number that is not in the pack (AnalystNumbers::inPack tolerance);
 *  - an evidence ref is unknown (at least one is required);
 *  - the action type is not allowed for the channel, or its target is missing / unknown / of the wrong kind;
 *  - the title breaks the brand's sector compliance rules (forbidden phrases);
 *  - the key repeats an earlier decision of the same answer;
 *  - the channel's own check (extraCheck) refuses it.
 */
abstract class AbstractChannelAnalyst implements ChannelAnalyst
{
    public const int TITLE_MAX = 90;

    public const int WHY_MAX = 160;

    public const array EFFORTS = ['low', 'medium', 'high'];

    public function routeKey(): string
    {
        return 'analyst.'.$this->channel();
    }

    public function validate(array $decisions, AnalystPack $pack): array
    {
        $numbers = $pack->numbers();
        $brand = Brand::query()->find($pack->brandId);
        $compliance = BriefCompliance::forBrand($brand);
        $allowed = $this->allowedActions();
        $kept = [];
        $dropped = [];
        $seen = [];
        foreach ($decisions as $raw) {
            $decision = $this->normalize(is_array($raw) ? $raw : []);
            $reason = $this->check($decision, $pack, $numbers, $allowed, $compliance, $seen);
            if ($reason !== null) {
                $dropped[] = ['key' => $decision['key'] !== '' ? $decision['key'] : mb_substr($decision['title_tr'], 0, 60), 'reason' => $reason];

                continue;
            }
            $seen[$decision['key']] = true;
            $kept[] = $decision;
        }

        return ['kept' => $kept, 'dropped' => $dropped];
    }

    /** Channel-specific refusal reason, or null. */
    protected function extraCheck(array $decision, AnalystPack $pack): ?string
    {
        return null;
    }

    public function baseline(Suggestion $decision): array
    {
        return [];
    }

    /** The decision text checked against the sector rules (a channel may drop its own technical terms first). */
    protected function complianceText(array $decision): string
    {
        return $decision['title_tr'];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{key: string, title_tr: string, why_tr: string, priority: int, impact: array{estimate: string, basis: string}, effort: string, evidence_refs: list<string>, action: array{type: string, params: array<string, mixed>}}
     */
    protected function normalize(array $raw): array
    {
        $clean = static fn (mixed $v): string => trim(preg_replace('/\s+/u', ' ', strip_tags((string) (is_scalar($v) ? $v : ''))) ?? '');
        $action = is_array($raw['action'] ?? null) ? $raw['action'] : [];
        $params = is_array($action['params'] ?? null) ? $action['params'] : [];
        $impact = is_array($raw['impact'] ?? null) ? $raw['impact'] : [];

        return [
            'key' => mb_substr(mb_strtolower($clean($raw['key'] ?? '')), 0, 160),
            'title_tr' => $clean($raw['title_tr'] ?? ''),
            'why_tr' => $clean($raw['why_tr'] ?? ''),
            'priority' => max(1, min(5, (int) ($raw['priority'] ?? 3))),
            'impact' => ['estimate' => mb_substr($clean($impact['estimate'] ?? ''), 0, 120), 'basis' => mb_substr($clean($impact['basis'] ?? ''), 0, 200)],
            'effort' => in_array($raw['effort'] ?? null, self::EFFORTS, true) ? (string) $raw['effort'] : 'medium',
            'evidence_refs' => array_values(array_unique(array_filter(array_map(fn ($r): string => $clean($r), (array) ($raw['evidence_refs'] ?? []))))),
            'action' => ['type' => $clean($action['type'] ?? ''), 'params' => array_map(fn ($v) => is_scalar($v) ? $clean($v) : null, $params)],
        ];
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  list<float>  $numbers
     * @param  array<string, array{label: string, kind: string, targets: list<string>}>  $allowed
     * @param  array<string, true>  $seen
     */
    private function check(array $decision, AnalystPack $pack, array $numbers, array $allowed, BriefCompliance $compliance, array $seen): ?string
    {
        if ($decision['key'] === '') {
            return 'anahtar yok';
        }
        if (isset($seen[$decision['key']])) {
            return 'tekrar eden karar';
        }
        if ($decision['title_tr'] === '' || mb_strlen($decision['title_tr']) > self::TITLE_MAX) {
            return 'başlık boş ya da uzun';
        }
        $why = $decision['why_tr'];
        if ($why === '' || mb_strlen($why) > self::WHY_MAX) {
            return 'gerekçe boş ya da '.self::WHY_MAX.' karakterden uzun';
        }
        if (preg_match('/[.!?…](\s+)\S/u', (string) preg_replace('/[\s.!?…]+$/u', '', $why))) {
            return 'gerekçe tek cümle değil';
        }
        $quoted = AnalystNumbers::extract($why);
        if ($quoted === []) {
            return 'gerekçede sayı yok';
        }
        foreach ($quoted as $number) {
            if (! AnalystNumbers::inPack($number, $numbers)) {
                return 'gerekçedeki sayı veride yok: '.rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
            }
        }
        if ($decision['evidence_refs'] === []) {
            return 'kanıt yok';
        }
        foreach ($decision['evidence_refs'] as $ref) {
            if (! $pack->has($ref)) {
                return 'bilinmeyen kanıt: '.mb_substr($ref, 0, 40);
            }
        }
        $type = $decision['action']['type'];
        if (! isset($allowed[$type])) {
            return 'izin verilmeyen aksiyon: '.mb_substr($type, 0, 40);
        }
        $targets = $allowed[$type]['targets'];
        if ($targets !== []) {
            $target = (string) ($decision['action']['params']['target'] ?? '');
            if ($target === '' || ! $pack->has($target)) {
                return 'aksiyon hedefi veride yok';
            }
            if (! in_array(strstr($target, ':', true), $targets, true)) {
                return 'aksiyon hedefi bu aksiyona uymuyor';
            }
        }
        if (! $compliance->isCompliant($this->complianceText($decision))) {
            return 'sektör kuralına aykırı başlık';
        }

        return $this->extraCheck($decision, $pack);
    }
}
