<?php

namespace App\Support\ClientValueStory\Dto;

use App\Enums\ClientValueStoryClaimType;

final class ClientValueStoryClaim
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly ClientValueStoryClaimType $type,
        public readonly string $text,
        public readonly array $params = [],
        public readonly bool $attribution = false,
        public readonly bool $causal = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'text' => $this->text,
            'params' => $this->params,
            'attribution' => $this->attribution,
            'causal' => $this->causal,
        ];
    }
}
