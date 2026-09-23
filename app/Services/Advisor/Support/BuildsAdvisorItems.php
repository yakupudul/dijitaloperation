<?php

namespace App\Services\Advisor\Support;

use App\Enums\AdvisorCategory;

/**
 * Shared item shape for advisor rule engines: stable key, priority from severity + money at stake share.
 */
trait BuildsAdvisorItems
{
    abstract protected function channelKey(): string;

    /** Base priority per severity; impact adds up to 300 by share of account spend. */
    protected static function severityBase(string $severity): int
    {
        return ['critical' => 1000, 'high' => 700, 'medium' => 400, 'low' => 100][$severity] ?? 100;
    }

    protected function money(array $input, float $amount): string
    {
        $symbol = match ($input['currency'] ?? null) {
            'TRY' => '₺',
            'USD' => '$',
            'EUR' => '€',
            default => (string) ($input['currency'] ?? '').' ',
        };

        return $symbol.number_format($amount, 0, ',', '.');
    }

    /**
     * @param  list<string>  $keyParts
     * @return array<string, mixed>
     */
    protected function item(
        array $input,
        AdvisorCategory $category,
        string $ruleId,
        array $keyParts,
        string $severity,
        ?float $impact,
        string $impactLabel,
        string $title,
        string $reason,
        array $evidence,
        array $checklist,
        ?string $copyText,
        ?array $baseline,
    ): array {
        $cost = max(1.0, (float) $input['account']['cost']);
        $score = self::severityBase($severity) + ($impact !== null ? min(300.0, $impact / $cost * 1000) : 0.0);

        return [
            'item_key' => hash('sha256', $this->channelKey().'|'.$ruleId.'|'.implode('|', $keyParts)),
            'category' => $category->value,
            'rule_id' => $ruleId,
            'severity' => $severity,
            'priority_score' => round($score, 2),
            'impact_amount' => $impact !== null ? round($impact, 2) : null,
            'impact_label' => mb_substr($impactLabel, 0, 160),
            'currency' => $input['currency'] ?? null,
            'title' => mb_substr($title, 0, 255),
            'reason' => $reason,
            'evidence' => $evidence,
            'checklist' => $checklist,
            'copy_text' => $copyText,
            'baseline' => $baseline !== null ? $baseline + ['period_end' => $input['period']['end']] : null,
        ];
    }
}
