<?php

return [
    'registry_path' => env(
        'MOXDOP_INTELLIGENCE_CORE_REGISTRY_PATH',
        resource_path('intelligence/MOXDOP_INTELLIGENCE_CORE_V1.json'),
    ),

    'registry_id' => 'MOXDOP_INTELLIGENCE_CORE',

    'supported_registry_versions' => [1],
];
