<?php

namespace App\Services\Recommendations;

use App\Models\BrandContextActivity;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Meaningful Recommendation Activity only. Content refreshes by deterministic writers
 * do not spam the Brand timeline — only create, explicit operator edits, and status changes.
 */
final class RecommendationActivityRecorder
{
    public const string CREATED = 'RECOMMENDATION_CREATED';

    public const string UPDATED = 'RECOMMENDATION_UPDATED';

    public const string STATUS_CHANGED = 'RECOMMENDATION_STATUS_CHANGED';

    /**
     * @param  array<string, mixed>  $extraPayload
     */
    public function record(
        Recommendation $recommendation,
        string $event,
        ?int $brandId = null,
        ?User $actor = null,
        array $extraPayload = [],
    ): ?BrandContextActivity {
        if (! in_array($event, [self::CREATED, self::UPDATED, self::STATUS_CHANGED], true)) {
            return null;
        }

        $brandId ??= $this->resolveBrandId($recommendation);

        if ($brandId === null) {
            return null;
        }

        return BrandContextActivity::query()->create([
            'brand_id' => $brandId,
            'actor_user_id' => $actor?->id,
            'event' => $event,
            'subject_type' => Recommendation::class,
            'subject_id' => $recommendation->id,
            'payload' => array_merge([
                'recommendation_id' => $recommendation->id,
                'source_kind' => $recommendation->source_kind,
                'source_id' => $recommendation->sourceId(),
                'source_module' => $recommendation->source_module,
                'origin' => $recommendation->origin,
                'status' => $recommendation->status,
                'priority' => $recommendation->priority,
                'generated_by_ai' => false,
            ], $extraPayload),
            'created_at' => Carbon::now(),
        ]);
    }

    private function resolveBrandId(Recommendation $recommendation): ?int
    {
        $recommendation->loadMissing(['digitalAsset', 'finding', 'opportunity']);

        $brandId = $recommendation->digitalAsset?->brand_id
            ?? $recommendation->finding?->brand_id
            ?? $recommendation->opportunity?->brand_id;

        return $brandId === null ? null : (int) $brandId;
    }
}
