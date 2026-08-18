<?php

namespace App\Services\Prospects;

use App\Support\Prospects\ProspectResearchFixtures;
use InvalidArgumentException;
use MoxDop\Website\Discovery\PublicUrlNormalizer;
use MoxDop\Website\Discovery\PublicUrlSafety;

/**
 * Validates and normalizes Prospect website URLs with SSRF protection.
 */
final class ProspectWebsiteValidator
{
    public function __construct(
        private readonly PublicUrlNormalizer $normalizer = new PublicUrlNormalizer,
        private readonly PublicUrlSafety $safety = new PublicUrlSafety,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public function normalize(?string $websiteUrl): ?string
    {
        if ($websiteUrl === null || trim($websiteUrl) === '') {
            return null;
        }

        $normalized = $this->normalizer->normalizeAbsolute(trim($websiteUrl));
        if ($normalized === null) {
            throw new InvalidArgumentException('Website URL is not valid.');
        }

        return $normalized;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function assertSafe(?string $websiteUrl): ?string
    {
        $normalized = $this->normalize($websiteUrl);
        if ($normalized === null) {
            return null;
        }

        if (ProspectResearchFixtures::isFixtureUrl($normalized)) {
            return $normalized;
        }

        $this->safety->assertSafePublicHttpUrl($normalized);

        return $normalized;
    }
}
