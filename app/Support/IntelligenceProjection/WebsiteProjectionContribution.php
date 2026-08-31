<?php

namespace App\Support\IntelligenceProjection;

use InvalidArgumentException;

final class WebsiteProjectionContribution
{
    /**
     * @param  list<array{identity_id:int, source_state:array<string,mixed>, observed_at:?string}>  $pages
     * @param  list<array{identity_id:int, source_state:array<string,mixed>, observed_at:?string}>  $searchTerms
     * @param  list<array{identity_id:int, source_state:array<string,mixed>, observed_at:?string}>  $entities
     * @param  list<array{identity_id:int, source_state:array<string,mixed>, observed_at:?string}>  $outcomes
     * @param  array<string, mixed>  $coverage
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly array $pages = [],
        public readonly array $searchTerms = [],
        public readonly array $entities = [],
        public readonly array $outcomes = [],
        public readonly array $coverage = [],
        public readonly ?string $watermark = null,
    ) {
        if (trim($sourceId) === '') {
            throw new InvalidArgumentException('Website Projection contribution source must not be empty.');
        }
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return [
            'pages' => count($this->pages),
            'search_terms' => count($this->searchTerms),
            'entities' => count($this->entities),
            'outcomes' => count($this->outcomes),
        ];
    }
}
