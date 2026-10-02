<?php

namespace App\Services\Analyst;

/**
 * The compact, bounded facts one channel analyst hands to the AI: every entity and number has a stable id
 * ("q:12", "tc:4", "u:ab12cd34", "stat:organic_clicks"), so a decision can cite them and the validator can check it.
 *
 *  - context: brand name, sector, cities, window days — small, always kept.
 *  - stats:   the Durum numbers of the tab (4–6), each {id, label, value, display, delta_pct?, note?}.
 *  - facts:   id => fact (flat array of scalars / short lists) grouped by section; sections are trimmed to the token
 *             budget from the end (sections are added most important first, rows most important first).
 *  - missing: one line when the channel has no data ("Veri yok: Search Console bağlı değil") — the run is skipped.
 */
final class AnalystPack
{
    /** Rough token budget of the facts sent to the AI. */
    public const int DEFAULT_TOKEN_BUDGET = 40000;

    /**
     * @param  array<string, mixed>  $context
     * @param  list<array{id: string, label: string, value: int|float|null, display: string, delta_pct?: int|float|null, note?: string|null}>  $stats
     * @param  array<string, array<string, array<string, mixed>>>  $sections  section => id => fact
     */
    public function __construct(
        public readonly string $channel,
        public readonly int $brandId,
        public readonly array $context,
        public readonly array $stats,
        private array $sections = [],
        public readonly ?string $missing = null,
    ) {}

    public static function missing(string $channel, int $brandId, string $reason, array $stats = [], array $context = []): self
    {
        return new self($channel, $brandId, $context, $stats, [], $reason);
    }

    public function hasData(): bool
    {
        return $this->missing === null;
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    public function sections(): array
    {
        return $this->sections;
    }

    /** @return array<string, array<string, mixed>> every fact by id (stats included as facts "stat:<id>") */
    public function facts(): array
    {
        $facts = [];
        foreach ($this->stats as $stat) {
            $facts['stat:'.$stat['id']] = $stat;
        }
        foreach ($this->sections as $rows) {
            foreach ($rows as $id => $fact) {
                $facts[(string) $id] = $fact;
            }
        }

        return $facts;
    }

    public function has(string $ref): bool
    {
        return array_key_exists($ref, $this->facts());
    }

    /** @return array<string, mixed>|null */
    public function fact(string $ref): ?array
    {
        return $this->facts()[$ref] ?? null;
    }

    /**
     * Every number in the pack (context, stats, facts; nested lists included) — the numbers a decision may quote.
     *
     * @return list<float>
     */
    public function numbers(): array
    {
        $out = [];
        $walk = function (mixed $value, string $key = '') use (&$walk, &$out): void {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $walk($v, (string) $k);
                }

                return;
            }
            if ((is_int($value) || is_float($value)) && $key !== 'id') {
                $out[] = (float) $value;
            } elseif (is_string($value) && $key !== 'id') {
                foreach (AnalystNumbers::extract($value) as $number) {
                    $out[] = $number;
                }
            }
        };
        $walk($this->context);
        $walk($this->stats);
        $walk($this->sections);

        return array_values(array_unique($out, SORT_REGULAR));
    }

    /**
     * What the AI sees.
     *
     * @return array{channel: string, context: array<string, mixed>, stats: list<array<string, mixed>>, facts: array<string, list<array<string, mixed>>>}
     */
    public function toPrompt(): array
    {
        $facts = [];
        foreach ($this->sections as $section => $rows) {
            foreach ($rows as $id => $fact) {
                $facts[$section][] = ['id' => (string) $id] + $fact;
            }
        }

        return ['channel' => $this->channel, 'context' => $this->context, 'stats' => array_map(fn (array $s): array => ['id' => 'stat:'.$s['id']] + $s, $this->stats), 'facts' => $facts];
    }

    public function json(): string
    {
        return (string) json_encode($this->toPrompt(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public function hash(): string
    {
        return hash('sha256', $this->json());
    }

    /** ~4 characters per token (Turkish text with JSON punctuation). */
    public function tokens(): int
    {
        return (int) ceil(mb_strlen($this->json()) / 4);
    }

    /**
     * Drop rows from the end of the largest sections until the pack fits the budget. Sections listed in $keep are
     * never trimmed below $minRows rows.
     */
    public function trimTo(int $budget = self::DEFAULT_TOKEN_BUDGET, int $minRows = 5): self
    {
        $guard = 0;
        while ($this->tokens() > $budget && $guard++ < 10000) {
            $largest = null;
            foreach ($this->sections as $section => $rows) {
                if (count($rows) > $minRows && ($largest === null || count($rows) > count($this->sections[$largest]))) {
                    $largest = $section;
                }
            }
            if ($largest === null) {
                break;
            }
            $drop = max(1, (int) floor(count($this->sections[$largest]) / 10));
            $this->sections[$largest] = array_slice($this->sections[$largest], 0, count($this->sections[$largest]) - $drop, true);
        }

        return $this;
    }

    /** @return array{id: string, label: string, value: int|float|null, display: string, delta_pct?: int|float|null, note?: string|null}|null */
    public function stat(string $id): ?array
    {
        foreach ($this->stats as $stat) {
            if ($stat['id'] === $id) {
                return $stat;
            }
        }

        return null;
    }
}
