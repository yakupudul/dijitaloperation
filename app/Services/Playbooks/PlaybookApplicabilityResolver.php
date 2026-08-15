<?php

namespace App\Services\Playbooks;

use App\Enums\PlaybookApplicabilityMode;
use App\Enums\PlaybookExecutionScopeKind;
use App\Enums\ServiceScopeStatus;
use App\Enums\TaskScopeKind;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerServiceScope;
use App\Models\DigitalAsset;
use App\Models\Playbook;
use App\Models\PlaybookRevision;
use App\Models\Task;
use App\Support\DigitalAssetTypes;

/**
 * Local DB-only Playbook applicability. No provider calls. No first Brand/Asset fallback.
 * No numeric relevance score.
 */
final class PlaybookApplicabilityResolver
{
    /**
     * @return array{
     *     applicable: bool,
     *     service_match: bool|null,
     *     service_scope_context: string,
     *     execution_scope_compatible: bool|null,
     *     asset_type_compatible: bool|null,
     *     reasons: list<string>,
     * }
     */
    public function resolve(
        Playbook $playbook,
        ?Customer $customer = null,
        ?Brand $brand = null,
        ?Task $task = null,
        ?DigitalAsset $asset = null,
    ): array {
        $revision = $playbook->currentRevision;
        if ($revision === null) {
            return $this->result(false, null, 'SERVICE_NOT_RELEVANT', null, null, ['NO_CURRENT_REVISION']);
        }

        $reasons = [];

        $serviceMatch = $this->serviceMatch($revision);
        $serviceScopeContext = $this->serviceScopeContext($revision, $customer, $brand, $serviceMatch, $reasons);

        $executionCompatible = $this->executionScopeCompatible($revision, $task, $reasons);
        $assetCompatible = $this->assetCompatible($revision, $task, $asset, $reasons);

        $applicable = ($serviceMatch !== false)
            && ($executionCompatible !== false)
            && ($assetCompatible !== false);

        return $this->result(
            $applicable,
            $serviceMatch,
            $serviceScopeContext,
            $executionCompatible,
            $assetCompatible,
            $reasons,
        );
    }

    /**
     * ANY matching explicit Service is enough for Service relevance (OR semantics).
     */
    private function serviceMatch(PlaybookRevision $revision): ?bool
    {
        $mode = $revision->service_applicability_mode instanceof PlaybookApplicabilityMode
            ? $revision->service_applicability_mode
            : PlaybookApplicabilityMode::tryFrom((string) $revision->service_applicability_mode);

        if ($mode === PlaybookApplicabilityMode::Any) {
            return null;
        }

        return $revision->services->isNotEmpty() ? true : false;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function serviceScopeContext(
        PlaybookRevision $revision,
        ?Customer $customer,
        ?Brand $brand,
        ?bool $serviceMatch,
        array &$reasons,
    ): string {
        $mode = $revision->service_applicability_mode instanceof PlaybookApplicabilityMode
            ? $revision->service_applicability_mode
            : PlaybookApplicabilityMode::tryFrom((string) $revision->service_applicability_mode);

        if ($mode === PlaybookApplicabilityMode::Any) {
            return 'SERVICE_NOT_RELEVANT';
        }

        if ($customer === null && $brand === null) {
            $reasons[] = 'SERVICE_SCOPE_CONTEXT_UNKNOWN';

            return 'SERVICE_SCOPE_UNKNOWN';
        }

        if ($brand === null && $customer !== null) {
            // Customer-level Task/context: do not aggregate all Brand scopes into one truth.
            $reasons[] = 'CUSTOMER_LEVEL_SCOPE_NOT_AGGREGATED';

            return 'SERVICE_SCOPE_UNKNOWN';
        }

        $customerId = $brand?->customer_id ?? $customer?->id;
        $serviceIds = $revision->services->pluck('id')->all();
        if ($serviceIds === [] || $customerId === null) {
            return 'SERVICE_SCOPE_UNKNOWN';
        }

        $scopes = CustomerServiceScope::query()
            ->where('customer_id', $customerId)
            ->whereIn('service_definition_id', $serviceIds)
            ->with('brands')
            ->get();

        if ($brand !== null) {
            $scopes = $scopes->filter(fn (CustomerServiceScope $scope): bool => $scope->appliesToBrand($brand))->values();
        }

        if ($scopes->isEmpty()) {
            $reasons[] = 'NO_MATCHING_SERVICE_SCOPE';

            return 'OUTSIDE_CURRENT_SCOPE';
        }

        if ($scopes->contains(fn (CustomerServiceScope $scope): bool => $scope->status === ServiceScopeStatus::Active)) {
            return 'IN_CURRENT_SCOPE';
        }

        if ($scopes->contains(fn (CustomerServiceScope $scope): bool => $scope->status === ServiceScopeStatus::Paused)) {
            $reasons[] = 'SERVICE_SCOPE_PAUSED';

            return 'OUTSIDE_CURRENT_SCOPE';
        }

        if ($scopes->contains(fn (CustomerServiceScope $scope): bool => $scope->status === ServiceScopeStatus::Ended)) {
            $reasons[] = 'SERVICE_SCOPE_ENDED';

            return 'OUTSIDE_CURRENT_SCOPE';
        }

        return 'OUTSIDE_CURRENT_SCOPE';
    }

    /**
     * @param  list<string>  $reasons
     */
    private function executionScopeCompatible(PlaybookRevision $revision, ?Task $task, array &$reasons): ?bool
    {
        $mode = $revision->execution_scope_mode instanceof PlaybookApplicabilityMode
            ? $revision->execution_scope_mode
            : PlaybookApplicabilityMode::tryFrom((string) $revision->execution_scope_mode);

        if ($mode === PlaybookApplicabilityMode::Any) {
            return null;
        }

        if ($task === null) {
            $reasons[] = 'EXECUTION_SCOPE_NOT_EVALUATED';

            return null;
        }

        $taskScope = $task->scope_kind instanceof TaskScopeKind
            ? $task->scope_kind->value
            : (string) $task->scope_kind;

        $allowed = $revision->executionScopes->map(
            fn ($row) => $row->scope_kind instanceof PlaybookExecutionScopeKind
                ? $row->scope_kind->value
                : (string) $row->scope_kind
        )->all();

        $ok = in_array($taskScope, $allowed, true);
        if (! $ok) {
            $reasons[] = 'EXECUTION_SCOPE_MISMATCH';
        }

        return $ok;
    }

    /**
     * @param  list<string>  $reasons
     */
    private function assetCompatible(
        PlaybookRevision $revision,
        ?Task $task,
        ?DigitalAsset $asset,
        array &$reasons,
    ): ?bool {
        $mode = $revision->asset_applicability_mode instanceof PlaybookApplicabilityMode
            ? $revision->asset_applicability_mode
            : PlaybookApplicabilityMode::tryFrom((string) $revision->asset_applicability_mode);

        if ($mode === PlaybookApplicabilityMode::Any) {
            return null;
        }

        $type = $asset?->type;
        if ($type === null && $task !== null) {
            if ($task->digital_asset_id !== null) {
                $type = DigitalAsset::query()->whereKey($task->digital_asset_id)->value('type');
            } else {
                $reasons[] = 'ASSET_TYPE_NOT_EVALUABLE_WITHOUT_ASSET';

                return null;
            }
        }

        if ($type === null) {
            $reasons[] = 'ASSET_TYPE_UNKNOWN';

            return null;
        }

        $allowed = array_keys(DigitalAssetTypes::options());
        if (! in_array($type, $allowed, true)) {
            $reasons[] = 'ASSET_TYPE_NOT_CANONICAL';

            return false;
        }

        $playbookTypes = $revision->assetTypes->pluck('asset_type')->all();
        $ok = in_array($type, $playbookTypes, true);
        if (! $ok) {
            $reasons[] = 'ASSET_TYPE_MISMATCH';
        }

        return $ok;
    }

    /**
     * @param  list<string>  $reasons
     * @return array{
     *     applicable: bool,
     *     service_match: bool|null,
     *     service_scope_context: string,
     *     execution_scope_compatible: bool|null,
     *     asset_type_compatible: bool|null,
     *     reasons: list<string>,
     * }
     */
    private function result(
        bool $applicable,
        ?bool $serviceMatch,
        string $serviceScopeContext,
        ?bool $executionCompatible,
        ?bool $assetCompatible,
        array $reasons,
    ): array {
        return [
            'applicable' => $applicable,
            'service_match' => $serviceMatch,
            'service_scope_context' => $serviceScopeContext,
            'execution_scope_compatible' => $executionCompatible,
            'asset_type_compatible' => $assetCompatible,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }
}
