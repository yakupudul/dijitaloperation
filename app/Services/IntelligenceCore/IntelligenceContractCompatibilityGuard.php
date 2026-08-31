<?php

namespace App\Services\IntelligenceCore;

use App\Services\Evidence\EvidenceDefinitionRegistry;
use App\Services\Findings\FindingRuleRegistry;
use App\Services\Formulas\FormulaRegistryLoader;
use RuntimeException;

final class IntelligenceContractCompatibilityGuard
{
    public function __construct(
        private readonly IntelligenceCoreRegistryLoader $core,
        private readonly FormulaRegistryLoader $formulas,
        private readonly EvidenceDefinitionRegistry $evidenceDefinitions,
        private readonly FindingRuleRegistry $findingRules,
    ) {}

    public function assertCompatible(): void
    {
        $registry = $this->core->registry();
        $formulaRegistryId = (string) ($registry['formula_contract']['registry_id'] ?? '');
        if ($formulaRegistryId !== $this->formulas->registryId()) {
            throw new RuntimeException('Intelligence Core formula registry contract is incompatible.');
        }

        $findingRegistryId = (string) ($registry['rule_contract']['registry_id'] ?? '');
        if ($findingRegistryId !== $this->findingRules->registryId()) {
            throw new RuntimeException('Intelligence Core Finding registry contract is incompatible.');
        }

        $corePolicies = $registry['global_policies'] ?? [];
        $formulaPolicies = $this->formulas->globalPolicies();
        if (($corePolicies['MISSING_NEVER_EQUALS_ZERO'] ?? false) !== true
            || ($formulaPolicies['MISSING_NEVER_EQUALS_ZERO'] ?? false) !== true) {
            throw new RuntimeException('Intelligence contracts must preserve missing-versus-zero semantics.');
        }

        $this->evidenceDefinitions->all();
        $this->findingRules->validate();
    }
}
