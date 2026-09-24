<?php

namespace App\Services\Ai\Insights;

use App\Ai\Agents\Insights\InsightAgent;
use Illuminate\Database\Eloquent\Model;

/** One on-click AI insight: what it reads, which agent writes it, and where it is filed in the archive. */
interface InsightDefinition
{
    /** Archive kind and cache namespace, e.g. `advisor.explain`. */
    public function kind(): string;

    /** AI route key (AI Control Plane). */
    public function routeKey(): string;

    /** Button / heading label. */
    public function label(): string;

    /** Tag => [label, badge classes]. @return array<string, array{0: string, 1: string}> */
    public function tagStyles(): array;

    /** @return class-string<Model> */
    public function subjectClass(): string;

    public function agent(): InsightAgent;

    /** @return array<string, mixed> */
    public function context(Model $subject): array;

    /** @return array{brand_id: ?int, digital_asset_id: ?int, title: string} */
    public function meta(Model $subject): array;

    /** Rough token sizes for the cost label. @return array{0: int, 1: int} */
    public function tokens(): array;

    /** How many days an earlier answer is shown before a new call is needed. */
    public function freshDays(): int;
}
