<?php

namespace App\Services\Recommendations;

use App\Enums\RecommendationOrigin;
use App\Models\Recommendation;
use App\Models\User;
use App\Support\Recommendations\RecommendationSourceReference;
use App\Support\Recommendations\ResolvedRecommendationSource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The single production writer for operator/deterministic Recommendation creation.
 *
 * Creates exactly one Recommendation row bound to one server-resolved source.
 * It never creates a Task, Work item, Approval, Service Scope, Goal or Offering,
 * and never calls a provider or an AI route.
 */
final class CreateRecommendation
{
    public const string DEFAULT_OPPORTUNITY_SOURCE_MODULE = 'operations';

    public function __construct(
        private readonly RecommendationSourceResolver $resolver,
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
     *     digital_asset_id?: int|null,
     *     source_module?: string|null,
     * }  $content
     *
     * @throws ValidationException
     */
    public function create(
        RecommendationSourceReference $source,
        array $content,
        RecommendationOrigin $origin,
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): Recommendation {
        if ($idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($idempotencyKey);
            if ($existing instanceof Recommendation) {
                return $existing;
            }
        }

        $data = Validator::make($content, [
            'title' => ['required', 'string', 'max:255'],
            'action' => ['nullable', 'string'],
            'rationale' => ['nullable', 'string'],
            'priority' => ['nullable', 'string', Rule::in(['critical', 'high', 'medium', 'low'])],
            'effort' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(Recommendation::STATUSES)],
            'digital_asset_id' => ['nullable', 'integer', Rule::exists('digital_assets', 'id')],
            'source_module' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $resolved = $this->resolver->resolve($source);

        $digitalAssetId = $data['digital_asset_id'] ?? $resolved->viewData->digitalAssetId;
        $this->guard->assertTenantMatch($resolved, $digitalAssetId);

        $sourceModule = filled($data['source_module'] ?? null)
            ? (string) $data['source_module']
            : $this->deriveSourceModule($resolved);

        $attributes = array_merge($source->toColumns(), [
            'digital_asset_id' => $digitalAssetId,
            'source_module' => $sourceModule,
            'origin' => $origin->value,
            'idempotency_key' => $idempotencyKey,
            'title' => (string) $data['title'],
            'action' => $data['action'] ?? null,
            'rationale' => $data['rationale'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'effort' => $data['effort'] ?? null,
            'status' => $data['status'] ?? Recommendation::STATUS_OPEN,
        ]);

        try {
            return DB::transaction(function () use ($attributes, $resolved, $actor): Recommendation {
                $recommendation = Recommendation::query()->create($attributes);

                $this->activity->record(
                    $recommendation,
                    RecommendationActivityRecorder::CREATED,
                    $resolved->viewData->brandId,
                    $actor,
                );

                return $recommendation;
            });
        } catch (QueryException $exception) {
            // Concurrent create with the same idempotency key: the winner's row is the answer.
            if ($idempotencyKey !== null) {
                $existing = $this->findByIdempotencyKey($idempotencyKey);
                if ($existing instanceof Recommendation) {
                    return $existing;
                }
            }

            throw $exception;
        }
    }

    private function findByIdempotencyKey(string $idempotencyKey): ?Recommendation
    {
        return Recommendation::query()->where('idempotency_key', $idempotencyKey)->first();
    }

    private function deriveSourceModule(ResolvedRecommendationSource $resolved): string
    {
        $finding = $resolved->finding();

        if ($finding !== null && filled($finding->source_module)) {
            return (string) $finding->source_module;
        }

        return self::DEFAULT_OPPORTUNITY_SOURCE_MODULE;
    }
}
