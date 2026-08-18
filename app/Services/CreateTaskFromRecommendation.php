<?php

namespace App\Services;

use App\Enums\TaskScopeKind;
use App\Enums\TaskSourceKind;
use App\Models\Brand;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\CreateTask;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Explicit Recommendation → Task handoff. Delegates to canonical CreateTask.
 * Does not copy Finding/Opportunity/Evidence IDs onto Task.
 */
class CreateTaskFromRecommendation
{
    public function __construct(
        private readonly CreateTask $createTask,
    ) {}

    /**
     * @param  array{
     *     title?: string|null,
     *     action?: string|null,
     *     assignee_id?: int|null,
     *     due_date?: string|null,
     *     priority?: string|null,
     *     scope_kind?: string|null,
     *     digital_asset_id?: int|null,
     *     brand_id?: int|null,
     * }  $attributes
     *
     * @throws ValidationException
     * @throws InvalidArgumentException
     */
    public function create(Recommendation $recommendation, array $attributes = [], ?User $actor = null, ?string $idempotencyKey = null): Task
    {
        $data = Validator::make($attributes, [
            'title' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string'],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'string', 'max:255', Rule::in(['critical', 'high', 'medium', 'low'])],
            'scope_kind' => ['nullable', 'string', Rule::in(array_column(TaskScopeKind::cases(), 'value'))],
            'digital_asset_id' => ['nullable', 'integer', Rule::exists('digital_assets', 'id')],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
        ])->validate();

        $recommendation->loadMissing([
            'digitalAsset.brand',
            'finding.digitalAsset.brand',
            'finding.brand',
            'opportunity.digitalAsset.brand',
            'opportunity.brand',
        ]);

        $digitalAsset = $recommendation->digitalAsset
            ?? $recommendation->finding?->digitalAsset
            ?? $recommendation->opportunity?->digitalAsset;

        $brand = $digitalAsset?->brand
            ?? $recommendation->finding?->brand
            ?? $recommendation->opportunity?->brand
            ?? (isset($data['brand_id']) ? Brand::query()->find((int) $data['brand_id']) : null);

        if ($brand === null) {
            throw new InvalidArgumentException('Recommendation has no Brand context to scope a Task.');
        }

        // Never invent or pick a first DigitalAsset when Recommendation lacks one.

        $requestedScope = isset($data['scope_kind'])
            ? TaskScopeKind::from((string) $data['scope_kind'])
            : ($digitalAsset !== null ? TaskScopeKind::DigitalAsset : TaskScopeKind::Brand);

        $assetId = null;
        if ($requestedScope === TaskScopeKind::DigitalAsset) {
            $assetId = isset($data['digital_asset_id'])
                ? (int) $data['digital_asset_id']
                : ($digitalAsset?->id);
            if ($assetId === null) {
                throw new InvalidArgumentException('DIGITAL_ASSET Task scope requires an explicit DigitalAsset.');
            }
        }

        $title = filled($data['title'] ?? null) ? (string) $data['title'] : $recommendation->title;
        $priority = filled($data['priority'] ?? null) ? (string) $data['priority'] : $recommendation->priority;
        $action = filled($data['action'] ?? null) ? (string) $data['action'] : ($recommendation->action ?? $title);

        $finding = $recommendation->finding;
        $opportunity = $recommendation->opportunity;

        $snapshot = [
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $assetId,
            'scope_kind' => $requestedScope->value,
            // Recommendation intelligence source (finding|opportunity) — not TaskSourceKind.
            'source_kind' => $recommendation->source_kind instanceof \BackedEnum
                ? $recommendation->source_kind->value
                : $recommendation->source_kind,
            'task_source_kind' => TaskSourceKind::Recommendation->value,
            'recommendation_id' => $recommendation->id,
            'title' => $title,
            'action' => $action,
            'priority' => $priority,
            'rationale' => $recommendation->rationale,
            'recommendation_source_kind' => $recommendation->source_kind instanceof \BackedEnum
                ? $recommendation->source_kind->value
                : $recommendation->source_kind,
            'finding' => $finding === null ? null : [
                'id' => $finding->id,
                'fingerprint' => $finding->fingerprint,
                'source_module' => $finding->source_module,
                'status' => $finding->status,
                'severity' => $finding->severity,
                'last_run_id' => $finding->last_run_id,
                'last_seen_at' => optional($finding->last_seen_at)?->toIso8601String(),
            ],
            'opportunity' => $opportunity === null ? null : [
                'id' => $opportunity->id,
                'fingerprint' => $opportunity->fingerprint,
                'rule_id' => $opportunity->rule_id,
                'rule_version' => $opportunity->rule_version,
                'category' => $opportunity->category,
                'status' => $opportunity->status,
                'qualitative_priority' => $opportunity->qualitative_priority,
                'service_definition_code' => $opportunity->service_definition_code,
                'last_detected_at' => optional($opportunity->last_detected_at)?->toIso8601String(),
            ],
        ];

        return $this->createTask->create([
            'title' => $title,
            'action' => $action,
            'rationale' => $recommendation->rationale,
            'priority' => $priority,
            'assignee_id' => $data['assignee_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $assetId,
            'scope_kind' => $requestedScope->value,
            'source_kind' => TaskSourceKind::Recommendation->value,
            'recommendation_id' => $recommendation->id,
            'client_request_id' => null,
            'snapshot_json' => $snapshot,
        ], $actor, $idempotencyKey);
    }

    public function userCanConvert(?User $user): bool
    {
        return $this->createTask->userCanCreate($user);
    }
}
