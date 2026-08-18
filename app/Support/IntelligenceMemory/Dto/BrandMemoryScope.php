<?php

namespace App\Support\IntelligenceMemory\Dto;

/**
 * Brand Memory ownership scope — Customer + Brand required; Brand ID is identity.
 */
final class BrandMemoryScope
{
    public function __construct(
        public readonly int $customerId,
        public readonly int $brandId,
    ) {
        if ($this->customerId <= 0 || $this->brandId <= 0) {
            throw new \InvalidArgumentException('BrandMemoryScope requires positive customer and brand ids.');
        }
    }

    public function matches(int $customerId, int $brandId): bool
    {
        return $this->customerId === $customerId && $this->brandId === $brandId;
    }

    public function equals(self $other): bool
    {
        return $this->matches($other->customerId, $other->brandId);
    }
}
