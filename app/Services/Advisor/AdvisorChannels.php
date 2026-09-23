<?php

namespace App\Services\Advisor;

use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorChannel;
use App\Services\Advisor\MetaAds\MetaAdsAdvisorChannel;

/**
 * Registry of advisor channels, keyed by channel id.
 */
final class AdvisorChannels
{
    /** @var array<string, AdvisorChannel> */
    private array $channels = [];

    public function __construct(GoogleAdsAdvisorChannel $googleAds, MetaAdsAdvisorChannel $metaAds)
    {
        foreach ([$googleAds, $metaAds] as $channel) {
            $this->channels[$channel->channel()] = $channel;
        }
    }

    /** @return array<string, AdvisorChannel> */
    public function all(): array
    {
        return $this->channels;
    }

    public function get(string $channel): AdvisorChannel
    {
        return $this->channels[$channel] ?? throw new \InvalidArgumentException('Unknown advisor channel: '.$channel);
    }

    public function forAssetType(string $type): ?AdvisorChannel
    {
        foreach ($this->channels as $channel) {
            if ($channel->assetType() === $type) {
                return $channel;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function assetTypes(): array
    {
        return array_values(array_map(static fn (AdvisorChannel $c): string => $c->assetType(), $this->channels));
    }
}
