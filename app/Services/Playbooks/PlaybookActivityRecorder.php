<?php

namespace App\Services\Playbooks;

use App\Models\Brand;
use App\Models\BrandContextActivity;
use App\Models\Playbook;
use App\Models\PlaybookRevision;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Playbook Activity uses BrandContextActivity only when an optional Brand context is supplied
 * (canonical Activity is brand-scoped). Global Playbook lifecycle remains in revision tables.
 */
final class PlaybookActivityRecorder
{
    public const string CREATED = 'PLAYBOOK_CREATED';

    public const string REVISED = 'PLAYBOOK_REVISED';

    public const string ARCHIVED = 'PLAYBOOK_ARCHIVED';

    public const string RESTORED = 'PLAYBOOK_RESTORED';

    /**
     * @param  array<string, mixed>  $extra
     */
    public function record(
        Playbook $playbook,
        ?PlaybookRevision $revision,
        string $event,
        ?User $actor = null,
        ?Brand $brand = null,
        array $extra = [],
    ): ?BrandContextActivity {
        $allowed = [self::CREATED, self::REVISED, self::ARCHIVED, self::RESTORED];
        if (! in_array($event, $allowed, true) || $brand === null) {
            return null;
        }

        return BrandContextActivity::query()->create([
            'brand_id' => $brand->id,
            'actor_user_id' => $actor?->id,
            'event' => $event,
            'subject_type' => Playbook::class,
            'subject_id' => $playbook->id,
            'payload' => array_merge([
                'playbook_id' => $playbook->id,
                'stable_key' => $playbook->stable_key,
                'revision_id' => $revision?->id,
                'revision_number' => $revision?->revision_number,
                'status' => $playbook->status instanceof \BackedEnum
                    ? $playbook->status->value
                    : $playbook->status,
            ], $extra),
            'created_at' => Carbon::now(),
        ]);
    }
}
