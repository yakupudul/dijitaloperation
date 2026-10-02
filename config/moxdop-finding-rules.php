<?php

return [

    'finding_rule_registry_path' => env(
        'MOXDOP_FINDING_RULE_REGISTRY_PATH',
        base_path('docs/data-contracts/MOXDOP_FINDING_RULES_V1.json')
    ),

    'finding_rule_registry_id' => 'MOXDOP_FINDING_RULES',

    'supported_finding_rule_registry_versions' => [1],

    'evaluate_after_canonicalization' => filter_var(
        env('FINDINGS_EVALUATE_AFTER_EVIDENCE', true),
        FILTER_VALIDATE_BOOLEAN
    ),

];
