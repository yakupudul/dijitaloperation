<?php

namespace App\Services\Advisor;

use App\Services\Advisor\Cross\CrossChannelAdvisorChannel;
use App\Services\Advisor\Gbp\GbpAdvisorChannel;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorChannel;
use App\Services\Advisor\MetaAds\MetaAdsAdvisorChannel;

/**
 * Registry of advisor channels, keyed by channel id.
 */
final class AdvisorChannels
{
    /** Legacy asset type values that mean the same channel. */
    private const array TYPE_ALIASES = ['gbp' => 'google_business_profile'];

    /** @var array<string, AdvisorChannel> */
    private array $channels = [];

    public function __construct(GoogleAdsAdvisorChannel $googleAds, MetaAdsAdvisorChannel $metaAds, GbpAdvisorChannel $gbp, CrossChannelAdvisorChannel $cross)
    {
        foreach ([$googleAds, $metaAds, $gbp, $cross] as $channel) {
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
        $type = self::TYPE_ALIASES[$type] ?? $type;
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
        $types = array_values(array_map(static fn (AdvisorChannel $c): string => $c->assetType(), $this->channels));

        return array_values(array_merge($types, array_keys(array_filter(self::TYPE_ALIASES, static fn (string $canonical): bool => in_array($canonical, $types, true)))));
    }
}
