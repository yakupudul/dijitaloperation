<?php

return [

    'opportunity_rule_registry_path' => env(
        'MOXDOP_OPPORTUNITY_RULE_REGISTRY_PATH',
        base_path('docs/data-contracts/MOXDOP_OPPORTUNITY_RULES_V1.json')
    ),

    'opportunity_rule_registry_id' => 'MOXDOP_OPPORTUNITY_RULES',

    'supported_opportunity_rule_registry_versions' => [1],

    'evaluate_after_findings' => filter_var(
        env('OPPORTUNITIES_EVALUATE_AFTER_FINDINGS', true),
        FILTER_VALIDATE_BOOLEAN
    ),

];
