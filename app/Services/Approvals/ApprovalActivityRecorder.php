<?php

namespace App\Services\Approvals;

use App\Models\Approval;
use App\Models\BrandContextActivity;
use App\Models\User;
use Illuminate\Support\Carbon;

final class ApprovalActivityRecorder
{
    public const string REQUESTED = 'APPROVAL_REQUESTED';

    public const string APPROVED = 'APPROVAL_APPROVED';

    public const string REJECTED = 'APPROVAL_REJECTED';

    public const string CHANGES_REQUESTED = 'APPROVAL_CHANGES_REQUESTED';

    public const string CANCELLED = 'APPROVAL_CANCELLED';

    /**
     * @param  array<string, mixed>  $extra
     */
    public function record(Approval $approval, string $event, ?User $actor = null, array $extra = []): ?BrandContextActivity
    {
        $allowed = [
            self::REQUESTED,
            self::APPROVED,
            self::REJECTED,
            self::CHANGES_REQUESTED,
            self::CANCELLED,
        ];
        if (! in_array($event, $allowed, true)) {
            return null;
        }

        if ($approval->brand_id === null) {
            return null;
        }

        return BrandContextActivity::query()->create([
            'brand_id' => $approval->brand_id,
            'actor_user_id' => $actor?->id,
            'event' => $event,
            'subject_type' => Approval::class,
            'subject_id' => $approval->id,
            'payload' => array_merge([
                'approval_id' => $approval->id,
                'task_id' => $approval->task_id,
                'customer_id' => $approval->customer_id,
                'kind' => $approval->kind instanceof \BackedEnum ? $approval->kind->value : $approval->kind,
                'status' => $approval->status instanceof \BackedEnum ? $approval->status->value : $approval->status,
                'decision' => $approval->decision instanceof \BackedEnum ? $approval->decision->value : $approval->decision,
            ], $extra),
            'created_at' => Carbon::now(),
        ]);
    }
}
