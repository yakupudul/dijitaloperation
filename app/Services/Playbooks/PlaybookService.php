<?php

namespace App\Services\Playbooks;

use App\Enums\PlaybookApplicabilityMode;
use App\Enums\PlaybookExecutionScopeKind;
use App\Enums\PlaybookReferenceKind;
use App\Enums\PlaybookStatus;
use App\Exceptions\PlaybookValidationException;
use App\Models\Playbook;
use App\Models\PlaybookInstruction;
use App\Models\PlaybookReference;
use App\Models\PlaybookRevision;
use App\Models\PlaybookRevisionAssetType;
use App\Models\PlaybookRevisionExecutionScope;
use App\Models\User;
use App\Support\DigitalAssetTypes;
use App\Support\Playbooks\PlaybookReferenceUrl;
use App\Support\Playbooks\PlaybookRevisionFingerprint;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Canonical Playbook write boundary. Never creates Task/QA/Approval/Recommendation.
 * Never mutates Service Scope. Never fetches reference URLs. Never invokes AI.
 */
final class PlaybookService
{
    public function __construct(
        private readonly PlaybookActivityRecorder $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, ?User $actor = null, ?string $idempotencyKey = null, bool $systemBootstrap = false): Playbook
    {
        if (! $systemBootstrap) {
            $this->assertCanManage($actor);
        }

        if ($idempotencyKey !== null) {
            $existing = PlaybookRevision::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing instanceof PlaybookRevision) {
                return $existing->playbook()->firstOrFail();
            }
        }

        $data = $this->validateContentInput($input, requireStableKey: false);

        try {
            return DB::transaction(function () use ($data, $actor, $idempotencyKey): Playbook {
                $playbook = Playbook::query()->create([
                    'stable_key' => $data['stable_key'] ?? null,
                    'status' => PlaybookStatus::Active->value,
                    'created_by' => $actor?->id,
                ]);

                $revision = $this->insertRevision($playbook, $data, 1, $actor, $idempotencyKey);
                $playbook->forceFill(['current_revision_id' => $revision->id])->save();
                $this->activity->record($playbook, $revision, PlaybookActivityRecorder::CREATED, $actor);

                return $playbook->fresh(['currentRevision']) ?? $playbook;
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey !== null) {
                $existing = PlaybookRevision::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing instanceof PlaybookRevision) {
                    return $existing->playbook()->firstOrFail();
                }
            }

            throw $exception;
        }
    }

    /**
     * Create a new immutable revision and make it current (frozen Edit/Save semantics).
     *
     * @param  array<string, mixed>  $input
     */
    public function revise(
        Playbook $playbook,
        array $input,
        ?User $actor = null,
        ?string $idempotencyKey = null,
        ?int $expectedCurrentRevisionId = null,
    ): Playbook {
        $this->assertCanManage($actor);
        $playbook = Playbook::query()->findOrFail($playbook->id);

        if ($idempotencyKey !== null) {
            $existing = PlaybookRevision::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing instanceof PlaybookRevision) {
                return $existing->playbook()->firstOrFail();
            }
        }

        $data = $this->validateContentInput($input, requireStableKey: false);
        unset($data['stable_key']);

        return DB::transaction(function () use ($playbook, $data, $actor, $idempotencyKey, $expectedCurrentRevisionId): Playbook {
            /** @var Playbook $locked */
            $locked = Playbook::query()->lockForUpdate()->findOrFail($playbook->id);

            if ($expectedCurrentRevisionId !== null
                && (int) $locked->current_revision_id !== $expectedCurrentRevisionId) {
                throw new PlaybookValidationException('Playbook was revised by another operator. Reload and retry.');
            }

            $fingerprint = PlaybookRevisionFingerprint::for($data);
            $current = $locked->currentRevision;
            if ($current instanceof PlaybookRevision && $current->content_fingerprint === $fingerprint) {
                return $locked->fresh(['currentRevision']) ?? $locked;
            }

            $nextNumber = (int) PlaybookRevision::query()
                ->where('playbook_id', $locked->id)
                ->max('revision_number') + 1;

            $revision = $this->insertRevision($locked, $data, $nextNumber, $actor, $idempotencyKey);
            $locked->forceFill(['current_revision_id' => $revision->id])->save();
            $this->activity->record($locked, $revision, PlaybookActivityRecorder::REVISED, $actor);

            return $locked->fresh(['currentRevision']) ?? $locked;
        });
    }

    public function archive(Playbook $playbook, ?User $actor = null): Playbook
    {
        $this->assertCanManage($actor);
        $playbook = Playbook::query()->findOrFail($playbook->id);

        if ($playbook->status === PlaybookStatus::Archived) {
            return $playbook;
        }

        return DB::transaction(function () use ($playbook, $actor): Playbook {
            $playbook->forceFill(['status' => PlaybookStatus::Archived->value])->save();
            $this->activity->record(
                $playbook,
                $playbook->currentRevision,
                PlaybookActivityRecorder::ARCHIVED,
                $actor,
            );

            return $playbook->fresh(['currentRevision']) ?? $playbook;
        });
    }

    public function restore(Playbook $playbook, ?User $actor = null): Playbook
    {
        $this->assertCanManage($actor);
        $playbook = Playbook::query()->findOrFail($playbook->id);

        if ($playbook->status === PlaybookStatus::Active) {
            return $playbook;
        }

        return DB::transaction(function () use ($playbook, $actor): Playbook {
            $playbook->forceFill(['status' => PlaybookStatus::Active->value])->save();
            $this->activity->record(
                $playbook,
                $playbook->currentRevision,
                PlaybookActivityRecorder::RESTORED,
                $actor,
            );

            return $playbook->fresh(['currentRevision']) ?? $playbook;
        });
    }

    public function userCanManage(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasRole(Roles::ADMIN) || $user->hasRole(Roles::TEAM_MEMBER);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateContentInput(array $input, bool $requireStableKey): array
    {
        $allowedAssetTypes = array_keys(DigitalAssetTypes::options());
        $data = Validator::make($input, [
            'stable_key' => [$requireStableKey ? 'required' : 'nullable', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'knowledge' => ['nullable', 'array'],
            'cadence' => ['nullable', 'string', 'max:32'],
            'service_applicability_mode' => ['required', 'string', Rule::in(array_column(PlaybookApplicabilityMode::cases(), 'value'))],
            'asset_applicability_mode' => ['required', 'string', Rule::in(array_column(PlaybookApplicabilityMode::cases(), 'value'))],
            'execution_scope_mode' => ['required', 'string', Rule::in(array_column(PlaybookApplicabilityMode::cases(), 'value'))],
            'service_definition_ids' => ['nullable', 'array'],
            'service_definition_ids.*' => ['integer', Rule::exists('service_definitions', 'id')],
            'asset_types' => ['nullable', 'array'],
            'asset_types.*' => ['string', Rule::in($allowedAssetTypes)],
            'execution_scopes' => ['nullable', 'array'],
            'execution_scopes.*' => ['string', Rule::in(array_column(PlaybookExecutionScopeKind::cases(), 'value'))],
            'instructions' => ['nullable', 'array'],
            'instructions.*.title' => ['nullable', 'string', 'max:255'],
            'instructions.*.body' => ['required', 'string'],
            'references' => ['nullable', 'array'],
            'references.*.kind' => ['required', 'string', Rule::in(array_column(PlaybookReferenceKind::cases(), 'value'))],
            'references.*.label' => ['required', 'string', 'max:255'],
            'references.*.url' => ['nullable', 'string', 'max:2048'],
            'references.*.route_name' => ['nullable', 'string', 'max:191'],
            'references.*.description' => ['nullable', 'string'],
        ])->validate();

        $serviceMode = PlaybookApplicabilityMode::from($data['service_applicability_mode']);
        $assetMode = PlaybookApplicabilityMode::from($data['asset_applicability_mode']);
        $scopeMode = PlaybookApplicabilityMode::from($data['execution_scope_mode']);

        $serviceIds = array_values(array_unique(array_map('intval', $data['service_definition_ids'] ?? [])));
        $assetTypes = array_values(array_unique($data['asset_types'] ?? []));
        $scopes = array_values(array_unique($data['execution_scopes'] ?? []));

        if ($serviceMode === PlaybookApplicabilityMode::Explicit && $serviceIds === []) {
            throw ValidationException::withMessages([
                'service_definition_ids' => 'EXPLICIT service applicability requires at least one Service Definition.',
            ]);
        }
        if ($serviceMode === PlaybookApplicabilityMode::Any && $serviceIds !== []) {
            throw ValidationException::withMessages([
                'service_definition_ids' => 'ANY service applicability cannot include Service relations.',
            ]);
        }
        if ($assetMode === PlaybookApplicabilityMode::Explicit && $assetTypes === []) {
            throw ValidationException::withMessages([
                'asset_types' => 'EXPLICIT asset applicability requires at least one DigitalAsset type.',
            ]);
        }
        if ($assetMode === PlaybookApplicabilityMode::Any && $assetTypes !== []) {
            throw ValidationException::withMessages([
                'asset_types' => 'ANY asset applicability cannot include asset type relations.',
            ]);
        }
        if ($scopeMode === PlaybookApplicabilityMode::Explicit && $scopes === []) {
            throw ValidationException::withMessages([
                'execution_scopes' => 'EXPLICIT execution scope requires at least one scope kind.',
            ]);
        }
        if ($scopeMode === PlaybookApplicabilityMode::Any && $scopes !== []) {
            throw ValidationException::withMessages([
                'execution_scopes' => 'ANY execution scope cannot include scope relations.',
            ]);
        }

        $references = [];
        foreach ($data['references'] ?? [] as $index => $ref) {
            $kind = PlaybookReferenceKind::from($ref['kind']);
            if ($kind === PlaybookReferenceKind::ExternalUrl) {
                if (empty($ref['url'])) {
                    throw ValidationException::withMessages([
                        "references.$index.url" => 'External URL reference requires a URL.',
                    ]);
                }
                $ref['url'] = PlaybookReferenceUrl::assertSafeExternalUrl((string) $ref['url']);
                $ref['route_name'] = null;
            } else {
                $routeName = (string) ($ref['route_name'] ?? '');
                if ($routeName === '' || ! Route::has($routeName)) {
                    throw ValidationException::withMessages([
                        "references.$index.route_name" => 'Internal route reference must name an existing application route.',
                    ]);
                }
                $ref['route_name'] = $routeName;
                $ref['url'] = null;
            }
            $references[] = $ref;
        }

        $data['service_definition_ids'] = $serviceIds;
        $data['asset_types'] = $assetTypes;
        $data['execution_scopes'] = $scopes;
        $data['references'] = $references;
        $data['instructions'] = array_values($data['instructions'] ?? []);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function insertRevision(
        Playbook $playbook,
        array $data,
        int $revisionNumber,
        ?User $actor,
        ?string $idempotencyKey,
    ): PlaybookRevision {
        $fingerprint = PlaybookRevisionFingerprint::for($data);

        $revision = PlaybookRevision::query()->create([
            'playbook_id' => $playbook->id,
            'revision_number' => $revisionNumber,
            'title' => $data['title'],
            'summary' => $data['summary'] ?? null,
            'knowledge' => $data['knowledge'] ?? null,
            'cadence' => $data['cadence'] ?? null,
            'service_applicability_mode' => $data['service_applicability_mode'],
            'asset_applicability_mode' => $data['asset_applicability_mode'],
            'execution_scope_mode' => $data['execution_scope_mode'],
            'content_fingerprint' => $fingerprint,
            'created_by' => $actor?->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        foreach ($data['instructions'] as $position => $instruction) {
            PlaybookInstruction::query()->create([
                'playbook_revision_id' => $revision->id,
                'position' => $position + 1,
                'title' => $instruction['title'] ?? null,
                'body' => $instruction['body'],
            ]);
        }

        foreach ($data['references'] as $position => $reference) {
            PlaybookReference::query()->create([
                'playbook_revision_id' => $revision->id,
                'kind' => $reference['kind'],
                'label' => $reference['label'],
                'url' => $reference['url'] ?? null,
                'route_name' => $reference['route_name'] ?? null,
                'description' => $reference['description'] ?? null,
                'position' => $position + 1,
            ]);
        }

        if ($data['service_definition_ids'] !== []) {
            $revision->services()->sync($data['service_definition_ids']);
        }

        foreach ($data['asset_types'] as $assetType) {
            PlaybookRevisionAssetType::query()->create([
                'playbook_revision_id' => $revision->id,
                'asset_type' => $assetType,
            ]);
        }

        foreach ($data['execution_scopes'] as $scope) {
            PlaybookRevisionExecutionScope::query()->create([
                'playbook_revision_id' => $revision->id,
                'scope_kind' => $scope,
            ]);
        }

        return $revision;
    }

    private function assertCanManage(?User $actor): void
    {
        if (! $this->userCanManage($actor)) {
            throw ValidationException::withMessages([
                'actor' => 'You are not allowed to manage Playbooks.',
            ]);
        }
    }
}
