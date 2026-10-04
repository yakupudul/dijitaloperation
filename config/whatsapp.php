<?php

return [
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),

    /* Faz 12: Meta Embedded Signup configuration id of the agency app (was hard-coded in the inbox). */
    'signup_config_id' => env('WHATSAPP_SIGNUP_CONFIG_ID', ''),
];
