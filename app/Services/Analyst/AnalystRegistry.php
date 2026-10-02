<?php

namespace App\Services\Analyst;

use App\Services\Analyst\Contracts\ChannelAnalyst;

/**
 * The analyst channels in tab order. A channel is live when its analyst class exists; v2 (Faz 4–7) rebuilds every
 * channel on the pages / clusters / suggestions model, so all four are reserved until then. To add a channel: create
 * the class below (extend AbstractChannelAnalyst) and nothing else — route and schedule follow.
 */
final class AnalystRegistry
{
    /** channel => [tab label, analyst class, AI route name, AI route description] */
    public const array CHANNELS = [
        'search' => ['Arama', 'App\\Services\\Analyst\\Search\\SearchAnalyst', 'Analyst: Search', 'Weekly per operational brand or on demand: decides the week\'s search (SEO / content / technical) work from the brand\'s queries, clusters, pages and standards.'],
        'maps' => ['Harita', 'App\\Services\\Analyst\\Maps\\MapsAnalyst', 'Analyst: Maps', 'Weekly per operational brand or on demand: decides the week\'s Business Profile work for local map rankings.'],
        'google_ads' => ['Google Ads', 'App\\Services\\Analyst\\GoogleAds\\GoogleAdsAnalyst', 'Analyst: Google Ads', 'Weekly per operational brand or on demand: decides the week\'s Google Ads work like a professional consultant.'],
        'meta' => ['Meta', 'App\\Services\\Analyst\\Meta\\MetaAnalyst', 'Analyst: Meta', 'Weekly per operational brand or on demand: decides the week\'s Meta ads work like a professional consultant.'],
    ];

    /** @return list<string> */
    public function channels(): array
    {
        return array_keys(self::CHANNELS);
    }

    /** @return list<string> channels whose analyst class exists */
    public function liveChannels(): array
    {
        return array_values(array_filter($this->channels(), fn (string $channel): bool => $this->has($channel)));
    }

    public function has(string $channel): bool
    {
        $class = self::CHANNELS[$channel][1] ?? null;

        return is_string($class) && class_exists($class) && is_subclass_of($class, ChannelAnalyst::class);
    }

    public function get(string $channel): ChannelAnalyst
    {
        if (! $this->has($channel)) {
            throw new \InvalidArgumentException('Unknown or not ready analyst channel: '.$channel);
        }

        return app(self::CHANNELS[$channel][1]);
    }

    public function label(string $channel): string
    {
        return self::CHANNELS[$channel][0] ?? $channel;
    }

    public static function routeKey(string $channel): string
    {
        return 'analyst.'.$channel;
    }
}
