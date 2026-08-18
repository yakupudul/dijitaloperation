<?php

namespace App\Services\Prospects;

use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\User;

/**
 * Prospect-native activity timeline (DomainEvent/BrandContextActivity is Customer-bound).
 */
final class ProspectActivityRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        Prospect $prospect,
        string $type,
        string $title,
        ?string $description = null,
        ?User $actor = null,
        array $metadata = [],
    ): ProspectActivity {
        return ProspectActivity::query()->create([
            'prospect_id' => $prospect->id,
            'actor_user_id' => $actor?->id,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }
}
