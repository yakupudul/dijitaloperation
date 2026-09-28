<?php

namespace App\Services\Queries;

/**
 * What the normalizer strips for one source account: the owning brand's marks (compact brand name, domain root),
 * competitor marks (competitor library names / domains, other portfolio brands), the sector's product brands, the
 * active exclusion rules and the protected (never excluded) core texts.
 */
final class QueryContext
{
    /**
     * @param  list<string>  $ownMarks  compact folded marks (no spaces), ≥ 4 letters
     * @param  list<string>  $competitorMarks  compact folded marks
     * @param  list<string>  $productMarks  folded phrases (words separated by one space)
     * @param  list<array{id: int, label: string, normalized: string}>  $exclusionRules
     * @param  array<string, true>  $protectedHashes
     */
    public function __construct(
        public readonly array $ownMarks = [],
        public readonly array $competitorMarks = [],
        public readonly array $productMarks = [],
        public readonly array $exclusionRules = [],
        public readonly array $protectedHashes = [],
        public readonly ?string $sector = null,
    ) {}

    public function hash(): string
    {
        return hash('sha256', json_encode([
            QueryNormalizer::VERSION, $this->ownMarks, $this->competitorMarks, $this->productMarks,
            array_column($this->exclusionRules, 'normalized'), hash('sha256', implode(',', array_keys($this->protectedHashes))), $this->sector,
        ], JSON_THROW_ON_ERROR));
    }
}
