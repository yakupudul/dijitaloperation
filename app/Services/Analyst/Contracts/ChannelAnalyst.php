<?php

namespace App\Services\Analyst\Contracts;

use App\Models\Brand;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Analyst\AnalystPack;

/**
 * One channel of the brand workspace (search, maps, google_ads, meta). Rule code prepares the facts (buildPack) and
 * checks the AI's numbers (validate); the AI decides. Register a new channel in AnalystRegistry::CHANNELS — extend
 * AbstractChannelAnalyst to get the shared validation (numbers in pack, refs, allowed actions, compliance, length).
 */
interface ChannelAnalyst
{
    /** search | maps | google_ads | meta */
    public function channel(): string;

    /** AI route key (analyst.<channel>). */
    public function routeKey(): string;

    /** Compact, bounded facts with stable ids; `missing` set when the channel has no data. */
    public function buildPack(Brand $brand): AnalystPack;

    /**
     * Keep only valid decisions; every dropped one is returned with its reason by the engine's logger.
     *
     * @param  list<array<string, mixed>>  $decisions  raw AI decisions
     * @return array{kept: list<array<string, mixed>>, dropped: list<array{key: string, reason: string}>}
     */
    public function validate(array $decisions, AnalystPack $pack): array;

    /**
     * Allowed action types: type => [label (button text), kind (link | run), target prefixes (pack id prefixes the
     * `params.target` may reference; [] = no target)].
     *
     * @return array<string, array{label: string, kind: string, targets: list<string>}>
     */
    public function allowedActions(): array;

    /** Channel-specific part of the AI instructions (what a professional consultant looks at). */
    public function instructions(): string;

    /**
     * Button of the decision's action: a link (url) or a run action handled by perform().
     *
     * @return array{label: string, kind: string, url: string|null}|null
     */
    public function presentAction(Suggestion $decision): ?array;

    /** Run a `run` action (queue an article, prepare a page update, …). Returns the Turkish result line. */
    public function perform(Suggestion $decision, User $user): string;
}
