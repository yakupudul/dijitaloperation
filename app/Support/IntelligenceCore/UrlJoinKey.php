<?php

namespace App\Support\IntelligenceCore;

final class UrlJoinKey
{
    public function __construct(
        public readonly string $url,
        public readonly string $hash,
        public readonly string $scheme,
        public readonly string $host,
        public readonly string $path,
        public readonly ?string $query,
        public readonly string $normalizationVersion,
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'hash' => $this->hash,
            'scheme' => $this->scheme,
            'host' => $this->host,
            'path' => $this->path,
            'query' => $this->query,
            'normalization_version' => $this->normalizationVersion,
        ];
    }
}
