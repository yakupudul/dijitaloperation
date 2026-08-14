<?php

namespace App\Services\Recommendations;

use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Content and status updates only. A Recommendation's source is immutable after creation:
 * relinking to another Finding or Opportunity is not supported in V1 and is rejected here.
 */
final class UpdateRecommendation
{
    /** @var list<string> */
    private const array IMMUTABLE_KEYS = [
        'source_kind',
        'finding_id',
        'opportunity_id',
        'idempotency_key',
    ];

    public function __construct(
        private readonly RecommendationSourceGuard $guard,
        private readonly RecommendationActivityRecorder $activity,
    ) {}

    /**
     * @param  array{
     *     title?: string|null,
     *     action?: string|null,
     *     rationale?: string|null,
     *     priority?: string|null,
     *     effort?: string|null,
     *     status?: string|null,
     * }  $content
     *
     * @throws ValidationException
     */
    public function update(Recommendation $recommendation, array $content, ?User $actor = null): Recommendation
    {
        $this->rejectSourceMutation($content);

        $data = Validator::make($content, [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'action' => ['sometimes', 'nullable', 'string'],
            'rationale' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'required', 'string', Rule::in(['critical', 'high', 'medium', 'low'])],
            'effort' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', Rule::in(Recommendation::STATUSES)],
        ])->validate();

        $previousStatus = (string) $recommendation->status;

        return DB::transaction(function () use ($recommendation, $data, $previousStatus, $actor): Recommendation {
            $recommendation->fill($data);
            $statusChanged = array_key_exists('status', $data) && (string) $data['status'] !== $previousStatus;
            $contentChanged = collect($data)->except('status')->isNotEmpty() && $recommendation->isDirty();

            $recommendation->save();
            $this->guard->assertConsistent($recommendation);

            if ($statusChanged) {
                $this->activity->record(
                    $recommendation,
                    RecommendationActivityRecorder::STATUS_CHANGED,
                    null,
                    $actor,
                    ['previous_status' => $previousStatus],
                );
            } elseif ($contentChanged) {
                $this->activity->record($recommendation, RecommendationActivityRecorder::UPDATED, null, $actor);
            }

            return $recommendation->fresh() ?? $recommendation;
        });
    }

    public function setStatus(Recommendation $recommendation, string $status, ?User $actor = null): Recommendation
    {
        return $this->update($recommendation, ['status' => $status], $actor);
    }

    public function accept(Recommendation $recommendation, ?User $actor = null): Recommendation
    {
        return $this->setStatus($recommendation, Recommendation::STATUS_ACCEPTED, $actor);
    }

    public function dismiss(Recommendation $recommendation, ?User $actor = null): Recommendation
    {
        return $this->setStatus($recommendation, Recommendation::STATUS_DISMISSED, $actor);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function rejectSourceMutation(array $content): void
    {
        $attempted = array_values(array_intersect(array_keys($content), self::IMMUTABLE_KEYS));

        if ($attempted === []) {
            return;
        }

        throw ValidationException::withMessages([
            'source_kind' => 'A Recommendation source is immutable: ['.implode(', ', $attempted).'] cannot be updated.',
        ]);
    }
}
