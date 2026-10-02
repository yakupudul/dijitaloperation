<?php

return [

    'formula_registry_path' => env(
        'MOXDOP_FORMULA_REGISTRY_PATH',
        base_path('docs/data-contracts/MOXDOP_FORMULA_REGISTRY_V1.json')
    ),

    'formula_registry_id' => 'MOXDOP_FORMULA_REGISTRY',

    'supported_formula_registry_versions' => [1],

];
