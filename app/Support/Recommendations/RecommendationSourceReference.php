<?php

namespace App\Support\Recommendations;

use App\Enums\RecommendationSourceKind;
use App\Models\Finding;
use App\Models\Opportunity;
use Illuminate\Validation\ValidationException;

/**
 * Immutable pointer to the single source a Recommendation is derived from.
 * Construction itself enforces the Finding XOR Opportunity invariant.
 */
final readonly class RecommendationSourceReference
{
    private function __construct(
        public RecommendationSourceKind $kind,
        public int $id,
    ) {}

    public static function fromFinding(Finding|int $finding): self
    {
        return new self(RecommendationSourceKind::Finding, self::normalizeId($finding instanceof Finding ? $finding->id : $finding));
    }

    public static function fromOpportunity(Opportunity|int $opportunity): self
    {
        return new self(RecommendationSourceKind::Opportunity, self::normalizeId($opportunity instanceof Opportunity ? $opportunity->id : $opportunity));
    }

    public static function make(RecommendationSourceKind|string $kind, int $id): self
    {
        $resolved = $kind instanceof RecommendationSourceKind
            ? $kind
            : RecommendationSourceKind::tryFrom($kind);

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'source_kind' => 'Unsupported Recommendation source kind ['.(is_string($kind) ? $kind : $kind->value).'].',
            ]);
        }

        return new self($resolved, self::normalizeId($id));
    }

    /**
     * Builds a reference from a raw column pair, rejecting both-set and neither-set shapes.
     */
    public static function fromColumns(?int $findingId, ?int $opportunityId): self
    {
        if ($findingId !== null && $opportunityId !== null) {
            throw ValidationException::withMessages([
                'source_kind' => 'A Recommendation cannot be sourced by both a Finding and an Opportunity.',
            ]);
        }

        if ($findingId !== null) {
            return self::fromFinding($findingId);
        }

        if ($opportunityId !== null) {
            return self::fromOpportunity($opportunityId);
        }

        throw ValidationException::withMessages([
            'source_kind' => 'A Recommendation requires exactly one source: a Finding or an Opportunity.',
        ]);
    }

    public function isFinding(): bool
    {
        return $this->kind === RecommendationSourceKind::Finding;
    }

    public function isOpportunity(): bool
    {
        return $this->kind === RecommendationSourceKind::Opportunity;
    }

    public function findingId(): ?int
    {
        return $this->isFinding() ? $this->id : null;
    }

    public function opportunityId(): ?int
    {
        return $this->isOpportunity() ? $this->id : null;
    }

    public function key(): string
    {
        return $this->kind->value.':'.$this->id;
    }

    /**
     * @return array{source_kind: string, finding_id: int|null, opportunity_id: int|null}
     */
    public function toColumns(): array
    {
        return [
            'source_kind' => $this->kind->value,
            'finding_id' => $this->findingId(),
            'opportunity_id' => $this->opportunityId(),
        ];
    }

    private static function normalizeId(?int $id): int
    {
        if ($id === null || $id <= 0) {
            throw ValidationException::withMessages([
                'source_id' => 'A Recommendation source reference requires a persisted source id.',
            ]);
        }

        return $id;
    }
}
