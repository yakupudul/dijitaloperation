<?php

namespace App\Services;

use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateTaskFromRecommendation
{
    /**
     * Create a Task that snapshots Recommendation context per ADR-029.
     *
     * @param  array{
     *     title?: string|null,
     *     assignee_id?: int|null,
     *     due_date?: string|null,
     *     priority?: string|null,
     * }  $attributes
     *
     * @throws ValidationException
     * @throws InvalidArgumentException
     */
    public function create(Recommendation $recommendation, array $attributes = []): Task
    {
        $data = Validator::make($attributes, [
            'title' => ['nullable', 'string', 'max:255'],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'string', 'max:255', Rule::in(['critical', 'high', 'medium', 'low'])],
        ])->validate();

        $recommendation->loadMissing(['digitalAsset.brand', 'finding.digitalAsset.brand']);

        $digitalAsset = $recommendation->digitalAsset
            ?? $recommendation->finding?->digitalAsset;

        if ($digitalAsset === null) {
            throw new InvalidArgumentException('Recommendation has no digital asset to snapshot onto a Task.');
        }

        $brand = $digitalAsset->brand;

        if ($brand === null) {
            throw new InvalidArgumentException('Digital asset has no brand to snapshot onto a Task.');
        }

        $title = filled($data['title'] ?? null)
            ? (string) $data['title']
            : $recommendation->title;

        $priority = filled($data['priority'] ?? null)
            ? (string) $data['priority']
            : $recommendation->priority;

        $action = $recommendation->action ?? '';
        $rationale = $recommendation->rationale;

        $snapshot = [
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $digitalAsset->id,
            'recommendation_id' => $recommendation->id,
            'title' => $title,
            'action' => $action,
            'priority' => $priority,
            'rationale' => $rationale,
        ];

        return Task::query()->create([
            'recommendation_id' => $recommendation->id,
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $digitalAsset->id,
            'title' => $title,
            'action' => $action,
            'rationale' => $rationale,
            'priority' => $priority,
            'snapshot_json' => $snapshot,
            'assignee_id' => $data['assignee_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'status' => 'open',
        ]);
    }

    public function userCanConvert(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasRole(Roles::ADMIN)
            || $user->hasRole(Roles::TEAM_MEMBER);
    }
}
