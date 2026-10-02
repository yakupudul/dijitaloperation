<?php

namespace App\Services\Advisor;

use App\Models\DigitalAsset;

/**
 * One advertising / listing channel the advisor understands (Google Ads, Meta Ads, …).
 * collect() reads stored data only; evaluate() is pure rules.
 */
interface AdvisorChannel
{
    /** Stored on advisor_plans / advisor_items.channel. */
    public function channel(): string;

    /** DigitalAsset.type this channel advises on. */
    public function assetType(): string;

    /** CoreAssetBinding.capability that marks the asset as connected. */
    public function bindingCapability(): string;

    public function label(): string;

    /** Operator page of the asset, on its advisor tab. */
    public function assetUrl(int $assetId): string;

    /** Rule ids whose items offer an operator-triggered AI draft. @return list<string> */
    public function draftRules(): array;

    /** Queue the AI draft job for one item (the caller already marked it queued). */
    public function dispatchDraft(int $itemId): void;

    /** @return array<string, mixed> */
    public function collect(DigitalAsset $asset): array;

    /**
     * @param  array<string, mixed>  $input
     * @return array{items: list<array<string, mixed>>, silenced: list<string>, summary: array<string, mixed>}
     */
    public function evaluate(array $input): array;

    /**
     * Counts per data source for the run record ("what did the advisor see").
     *
     * @param  array<string, mixed>  $input
     * @return array<string, int|bool>
     */
    public function sources(array $input): array;
}
