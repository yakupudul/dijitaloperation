<?php

namespace App\Services\Playbooks;

use App\Enums\PlaybookApplicabilityMode;
use App\Enums\PlaybookReferenceKind;
use App\Enums\PlaybookStatus;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Playbook;
use App\Models\PlaybookRevision;
use App\Models\Task;
use Illuminate\Support\Facades\Route;

final class PlaybookReadService
{
    public function __construct(
        private readonly PlaybookApplicabilityResolver $applicability,
    ) {}

    /**
     * @param  array{status?: string|null, service_code?: string|null, asset_type?: string|null, search?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function forList(array $filters = [], int $limit = 200): array
    {
        $query = Playbook::query()
            ->with($this->currentRevisionEagerLoad())
            ->orderBy('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        } else {
            $query->where('status', PlaybookStatus::Active->value);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->whereHas('currentRevision', function ($q) use ($term): void {
                $q->where('title', 'like', $term)
                    ->orWhere('summary', 'like', $term);
            });
        }

        if (! empty($filters['service_code'])) {
            $code = (string) $filters['service_code'];
            $query->whereHas('currentRevision.services', fn ($q) => $q->where('code', $code));
        }

        if (! empty($filters['asset_type'])) {
            $type = (string) $filters['asset_type'];
            $query->whereHas('currentRevision.assetTypes', fn ($q) => $q->where('asset_type', $type));
        }

        return $query->limit($limit)->get()
            ->map(fn (Playbook $playbook): array => $this->toListPresentation($playbook))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPresentation(string|int $idOrKey): ?array
    {
        $playbook = $this->resolve($idOrKey);
        if ($playbook === null) {
            return null;
        }

        return $this->toDetailPresentation($playbook);
    }

    public function resolve(string|int $idOrKey): ?Playbook
    {
        $query = Playbook::query()->with($this->currentRevisionEagerLoad());

        if (is_numeric($idOrKey)) {
            return $query->whereKey((int) $idOrKey)->first();
        }

        return $query->where('stable_key', (string) $idOrKey)->first();
    }

    /**
     * @return list<array{id: int, revision_number: int, title: string, created_at: ?string}>
     */
    public function history(Playbook $playbook, int $limit = 50): array
    {
        return PlaybookRevision::query()
            ->where('playbook_id', $playbook->id)
            ->orderByDesc('revision_number')
            ->limit($limit)
            ->get(['id', 'revision_number', 'title', 'created_at'])
            ->map(fn (PlaybookRevision $revision): array => [
                'id' => $revision->id,
                'revision_number' => $revision->revision_number,
                'title' => $revision->title,
                'created_at' => $revision->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toListPresentation(Playbook $playbook): array
    {
        $revision = $playbook->currentRevision;
        $services = $revision?->services ?? collect();
        $primary = $services->first();

        return [
            'id' => (string) ($playbook->stable_key ?? $playbook->id),
            'playbook_id' => $playbook->id,
            'stable_key' => $playbook->stable_key,
            'name' => $revision?->title ?? '',
            'title' => $revision?->title ?? '',
            'purpose' => $revision?->summary
                ?? (is_array($revision?->knowledge) ? ($revision->knowledge['purpose'] ?? null) : null),
            'service_code' => $primary?->code,
            'service_label' => $primary?->name,
            'service_codes' => $services->pluck('code')->values()->all(),
            'asset_types' => $revision?->assetTypes->pluck('asset_type')->values()->all() ?? [],
            'cadence' => $revision?->cadence,
            'default_owner_name' => null,
            'default_owner_id' => null,
            'status' => $playbook->status instanceof PlaybookStatus
                ? $playbook->status->value
                : (string) $playbook->status,
            'revision_number' => $revision?->revision_number,
            'active' => $playbook->status === PlaybookStatus::Active,
            'source_state' => 'REAL',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDetailPresentation(Playbook $playbook, ?Customer $customer = null, ?Brand $brand = null, ?Task $task = null): array
    {
        $revision = $playbook->currentRevision;
        $base = $this->toListPresentation($playbook);
        $knowledge = is_array($revision?->knowledge) ? $revision->knowledge : [];

        $references = ($revision?->references ?? collect())->map(function ($ref): array {
            $kind = $ref->kind instanceof PlaybookReferenceKind ? $ref->kind : PlaybookReferenceKind::tryFrom((string) $ref->kind);
            $href = null;
            if ($kind === PlaybookReferenceKind::ExternalUrl) {
                $href = $ref->url;
            } elseif ($kind === PlaybookReferenceKind::InternalRoute && is_string($ref->route_name) && Route::has($ref->route_name)) {
                $href = route($ref->route_name);
            }

            return [
                'kind' => $kind?->value,
                'label' => $ref->label,
                'url' => $ref->url,
                'route' => $ref->route_name,
                'route_name' => $ref->route_name,
                'href' => $href,
                'description' => $ref->description,
                'position' => $ref->position,
            ];
        })->values()->all();

        $checklist = ($revision?->instructions ?? collect())
            ->sortBy('position')
            ->values()
            ->map(fn ($row): string => (string) $row->body)
            ->all();

        $applicability = null;
        if ($customer !== null || $brand !== null || $task !== null) {
            $applicability = $this->applicability->resolve($playbook, $customer, $brand, $task);
        }

        return array_merge($base, [
            'summary' => $revision?->summary,
            'knowledge' => $knowledge,
            'purpose' => $knowledge['purpose'] ?? $revision?->summary,
            'when_to_use' => $knowledge['when_to_use'] ?? [],
            'when_not_to_use' => $knowledge['when_not_to_use'] ?? [],
            'methodology' => $knowledge['methodology'] ?? [],
            'qa_guidance' => $knowledge['qa_guidance'] ?? [],
            'related_ai_skill' => isset($knowledge['related_ai_skill_label'])
                ? [
                    'name' => $knowledge['related_ai_skill_label'],
                    'note' => $knowledge['related_ai_skill_note'] ?? null,
                ]
                : null,
            'checklist' => $checklist,
            'instructions' => $checklist[0] ?? ($revision?->summary ?? ''),
            'instruction_rows' => ($revision?->instructions ?? collect())->map(fn ($row): array => [
                'id' => $row->id,
                'position' => $row->position,
                'title' => $row->title,
                'body' => $row->body,
            ])->values()->all(),
            'references' => $references,
            'service_applicability_mode' => $revision?->service_applicability_mode instanceof PlaybookApplicabilityMode
                ? $revision->service_applicability_mode->value
                : $revision?->service_applicability_mode,
            'asset_applicability_mode' => $revision?->asset_applicability_mode instanceof PlaybookApplicabilityMode
                ? $revision->asset_applicability_mode->value
                : $revision?->asset_applicability_mode,
            'execution_scope_mode' => $revision?->execution_scope_mode instanceof PlaybookApplicabilityMode
                ? $revision->execution_scope_mode->value
                : $revision?->execution_scope_mode,
            'execution_scopes' => $revision?->executionScopes->map(
                fn ($row) => $row->scope_kind instanceof \BackedEnum ? $row->scope_kind->value : $row->scope_kind
            )->values()->all() ?? [],
            'current_revision_id' => $revision?->id,
            'applicability' => $applicability,
            'default_owner_name' => null,
            'default_owner_id' => null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function currentRevisionEagerLoad(): array
    {
        return [
            'currentRevision.services:id,code,name',
            'currentRevision.assetTypes',
            'currentRevision.executionScopes',
            'currentRevision.instructions',
            'currentRevision.references',
        ];
    }
}
