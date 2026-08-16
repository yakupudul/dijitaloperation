<?php

namespace App\Services\IntelligenceScheduling;

use App\Enums\AutomaticIntelligencePolicyStatus;
use App\Enums\Intelligence\IntelligenceTriggerSource;
use App\Models\AutomaticIntelligencePolicy;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Human-controlled Automatic Intelligence Policy CRUD (Prompt 63).
 * AI / Assistant cannot create or enable policies.
 */
final class AutomaticIntelligencePolicyService
{
    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    public function create(
        Brand $brand,
        array $input,
        ?User $actor = null,
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
        ?DigitalAsset $asset = null,
    ): AutomaticIntelligencePolicy {
        $this->assertAuthorized($brand, $authorizedCustomerIds, $authorizedBrandIds);

        $agentSlug = (string) ($input['agent_slug'] ?? '');
        $agentVersion = (string) ($input['agent_version'] ?? '');
        $skillSignature = (string) ($input['skill_signature'] ?? '');
        $skillVersion = (string) ($input['skill_version'] ?? '');
        $routeKey = (string) ($input['route_key'] ?? '');
        $routeSignature = (string) ($input['route_signature'] ?? '');

        foreach (['agent_slug' => $agentSlug, 'agent_version' => $agentVersion, 'skill_signature' => $skillSignature, 'skill_version' => $skillVersion, 'route_key' => $routeKey, 'route_signature' => $routeSignature] as $field => $value) {
            if ($value === '') {
                throw ValidationException::withMessages([$field => 'REQUIRED']);
            }
        }

        // Reject silent "latest" tokens.
        foreach ([$agentVersion, $skillVersion, $routeSignature] as $versionToken) {
            if (in_array(strtolower($versionToken), ['latest', '*', 'auto'], true)) {
                throw ValidationException::withMessages(['version' => 'EXACT_VERSION_REQUIRED']);
            }
        }

        $allowedTriggers = $input['allowed_trigger_kinds'] ?? [
            IntelligenceTriggerSource::EvidenceAnalyticalStateChanged->value,
        ];
        if (! is_array($allowedTriggers) || $allowedTriggers === []) {
            throw ValidationException::withMessages(['allowed_trigger_kinds' => 'REQUIRED']);
        }

        $fingerprintPayload = [
            'brand_id' => (int) $brand->id,
            'digital_asset_id' => $asset?->id,
            'agent' => $agentSlug.'@'.$agentVersion,
            'skill' => $skillSignature,
            'route' => $routeKey.'|'.$routeSignature,
            'triggers' => $allowedTriggers,
            'required' => (bool) ($input['trigger_on_required_evidence_change'] ?? true),
            'optional' => (bool) ($input['trigger_on_optional_evidence_change'] ?? false),
            'max_runs' => (int) ($input['max_automatic_runs_per_window'] ?? 5),
            'window' => (int) ($input['window_minutes'] ?? 1440),
            'min_interval' => (int) ($input['min_interval_minutes'] ?? 60),
            'fanout' => (int) ($input['max_fanout_per_plan'] ?? 3),
        ];

        return DB::transaction(function () use ($brand, $asset, $actor, $agentSlug, $agentVersion, $skillSignature, $skillVersion, $routeKey, $routeSignature, $allowedTriggers, $input, $fingerprintPayload): AutomaticIntelligencePolicy {
            return AutomaticIntelligencePolicy::query()->create([
                'customer_id' => (int) $brand->customer_id,
                'brand_id' => (int) $brand->id,
                'digital_asset_id' => $asset?->id,
                'agent_slug' => $agentSlug,
                'agent_version' => $agentVersion,
                'skill_signature' => $skillSignature,
                'skill_version' => $skillVersion,
                'route_key' => $routeKey,
                'route_signature' => $routeSignature,
                'allowed_trigger_kinds' => array_values($allowedTriggers),
                'trigger_on_required_evidence_change' => (bool) ($input['trigger_on_required_evidence_change'] ?? true),
                'trigger_on_optional_evidence_change' => (bool) ($input['trigger_on_optional_evidence_change'] ?? false),
                'max_automatic_runs_per_window' => max(1, (int) ($input['max_automatic_runs_per_window'] ?? 5)),
                'window_minutes' => max(1, (int) ($input['window_minutes'] ?? 1440)),
                'min_interval_minutes' => max(1, (int) ($input['min_interval_minutes'] ?? 60)),
                'max_fanout_per_plan' => max(1, (int) ($input['max_fanout_per_plan'] ?? 3)),
                'status' => AutomaticIntelligencePolicyStatus::Active,
                'policy_fingerprint' => hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR)),
                'policy_version' => 1,
                'created_by' => $actor?->id,
            ]);
        });
    }

    public function disable(AutomaticIntelligencePolicy $policy): void
    {
        $policy->status = AutomaticIntelligencePolicyStatus::Disabled;
        $policy->save();
    }

    public function pause(AutomaticIntelligencePolicy $policy): void
    {
        $policy->status = AutomaticIntelligencePolicyStatus::Paused;
        $policy->save();
    }

    public function resume(AutomaticIntelligencePolicy $policy): void
    {
        $policy->status = AutomaticIntelligencePolicyStatus::Active;
        $policy->save();
    }

    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    private function assertAuthorized(Brand $brand, array $authorizedCustomerIds, array $authorizedBrandIds): void
    {
        if ($authorizedBrandIds !== [] && ! in_array((int) $brand->id, array_map('intval', $authorizedBrandIds), true)) {
            throw ValidationException::withMessages(['brand' => 'UNAUTHORIZED_BRAND']);
        }
        if ($authorizedCustomerIds !== [] && ! in_array((int) $brand->customer_id, array_map('intval', $authorizedCustomerIds), true)) {
            throw ValidationException::withMessages(['customer' => 'UNAUTHORIZED_CUSTOMER']);
        }
    }
}
