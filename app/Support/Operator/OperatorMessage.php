<?php

namespace App\Support\Operator;

use Carbon\CarbonInterface;

/**
 * The one shape of an operator-facing warning (bell, Komuta merkezi drawer, Sistem sağlığı, Uyarılar): every message
 * answers four questions in plain Turkish.
 *
 * - `what`   Ne oldu: which brand / asset / account and which data, specifically.
 * - `why`    Neden önemli: the impact on reports, recommendations, ads or the client.
 * - `action` Ne yapmalısın: one concrete step.
 * - `link`   Nereden: the exact page (asset data sources, reconnect screen, Komuta merkezi topic), plus an optional
 *            one-click `button` (a URL, `run_now` on a ResourceAutomation for "Şimdi güncelle", or `mark_inactive` on a
 *            Digital Asset for "Kullanılmıyor olarak işaretle").
 *
 * `occurrences` / `firstSeen` let a repeating condition read "3. kez · ilk 24 Eyl" on one row.
 */
final readonly class OperatorMessage
{
    /**
     * @param  array{label: string, url?: string|null, run_now?: int|null, mark_inactive?: int|null}|null  $button
     */
    public function __construct(
        public string $title,
        public string $what,
        public string $why,
        public string $action,
        public ?string $linkUrl = null,
        public ?string $linkLabel = null,
        public ?array $button = null,
        public int $occurrences = 1,
        public ?CarbonInterface $firstSeen = null,
        public ?int $assetId = null,
        public ?int $brandId = null,
        public ?string $topic = null,
    ) {}

    /** "3. kez · ilk 24 Eyl" when the condition came back; empty for the first time. */
    public function repeatLabel(): string
    {
        if ($this->occurrences < 2) {
            return '';
        }

        return $this->occurrences.'. kez'.($this->firstSeen !== null ? ' · ilk '.self::shortDate($this->firstSeen) : '');
    }

    /** Ne oldu / Neden önemli / Ne yapmalısın as one paragraph (push bodies, plain summaries). */
    public function plainText(): string
    {
        return trim(implode(' ', array_filter([$this->what, $this->why, $this->action], fn (string $part): bool => $part !== '')));
    }

    /**
     * @return array{title: string, what: string, why: string, action: string, link_url: ?string, link_label: ?string, button: array<string, mixed>|null, occurrences: int, first_seen: ?string, repeat_label: string, asset_id: ?int, brand_id: ?int, topic: ?string}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'what' => $this->what,
            'why' => $this->why,
            'action' => $this->action,
            'link_url' => $this->linkUrl,
            'link_label' => $this->linkLabel,
            'button' => $this->button,
            'occurrences' => $this->occurrences,
            'first_seen' => $this->firstSeen?->toIso8601String(),
            'repeat_label' => $this->repeatLabel(),
            'asset_id' => $this->assetId,
            'brand_id' => $this->brandId,
            'topic' => $this->topic,
        ];
    }

    /** "24 Eyl" in Turkish, in the operator's timezone. */
    public static function shortDate(CarbonInterface $date): string
    {
        $months = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
        $local = $date->copy()->timezone((string) config('app.timezone'));

        return $local->day.' '.$months[$local->month - 1];
    }

    /**
     * "a, b, c, d, e +3": up to $max names, then the rest as a count.
     *
     * @param  list<string>  $names
     */
    public static function nameList(array $names, int $max = 5): string
    {
        $names = array_values(array_unique(array_filter($names, fn (string $n): bool => trim($n) !== '')));
        $shown = array_slice($names, 0, $max);
        $rest = count($names) - count($shown);

        return implode(', ', $shown).($rest > 0 ? ' +'.$rest : '');
    }
}
