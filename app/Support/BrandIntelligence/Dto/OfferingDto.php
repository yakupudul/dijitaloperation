<?php

namespace App\Support\BrandIntelligence\Dto;

final class OfferingDto
{
    /**
     * @param  list<string>  $aliases
     */
    public function __construct(
        public readonly int $id,
        public readonly string $primaryLabel,
        public readonly array $aliases,
        public readonly string $status,
        public readonly ?int $priorityRank,
        public readonly bool $isPriority,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'primary_label' => $this->primaryLabel,
            'aliases' => $this->aliases,
            'status' => $this->status,
            'priority_rank' => $this->priorityRank,
            'is_priority' => $this->isPriority,
        ];
    }
}
