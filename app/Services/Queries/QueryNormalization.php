<?php

namespace App\Services\Queries;

/** Result of normalizing one raw provider query into its core query (or the separate list it belongs to). */
final class QueryNormalization
{
    public const string CORE = 'core';

    /** Only the own brand (navigational): not a core query. */
    public const string BRAND = 'brand';

    /** Names a competitor brand: kept for Ads insights, not a core query. */
    public const string COMPETITOR = 'competitor';

    /** Matches an exclusion rule: negative candidate, not a core query. */
    public const string BANNED = 'banned';

    /** Nothing left after stripping (place / product name only). */
    public const string EMPTY = 'empty';

    /** The core query was removed by an operator (never recreated). */
    public const string SUPPRESSED = 'suppressed';

    /** @param  list<string>  $removed */
    public function __construct(
        public readonly string $kind,
        public readonly string $core,
        public readonly bool $hadLocation = false,
        public readonly bool $hadOwnBrand = false,
        public readonly bool $hadCompetitorBrand = false,
        public readonly bool $hadProductBrand = false,
        public readonly array $removed = [],
    ) {}

    public function isCore(): bool
    {
        return $this->kind === self::CORE;
    }
}
