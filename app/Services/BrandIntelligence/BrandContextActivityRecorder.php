<?php

namespace App\Services\BrandIntelligence;

use App\Models\Brand;
use App\Models\BrandContextActivity;
use App\Models\User;

final class BrandContextActivityRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Brand $brand,
        string $event,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $payload = [],
        ?User $actor = null,
    ): BrandContextActivity {
        return BrandContextActivity::query()->create([
            'brand_id' => $brand->id,
            'actor_user_id' => $actor?->id,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }
}
