<?php

namespace App\Services\Sales;

use App\Models\Prospect;
use App\Models\SalesIntentActivity;
use App\Models\SalesIntentRadarRun;
use App\Models\SalesIntentSignal;
use App\Models\SalesSearchProfile;
use App\Models\User;

final class IntentActivityRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $type,
        string $title,
        ?SalesSearchProfile $profile = null,
        ?SalesIntentRadarRun $run = null,
        ?SalesIntentSignal $signal = null,
        ?Prospect $prospect = null,
        ?User $actor = null,
        ?string $description = null,
        array $metadata = [],
    ): SalesIntentActivity {
        return SalesIntentActivity::query()->create([
            'sales_search_profile_id' => $profile?->id,
            'sales_intent_radar_run_id' => $run?->id,
            'sales_intent_signal_id' => $signal?->id,
            'prospect_id' => $prospect?->id,
            'actor_user_id' => $actor?->id,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }
}
