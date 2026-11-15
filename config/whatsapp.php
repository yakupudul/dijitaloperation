<?php

return [
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),

    /* Faz 12: Meta Embedded Signup configuration id of the agency app (was hard-coded in the inbox). */
    'signup_config_id' => env('WHATSAPP_SIGNUP_CONFIG_ID', ''),

    /* Excel exports with more messages than this are written by a heavy-queue job while a page waits (2026-11-15). */
    'export_inline_limit' => (int) env('WHATSAPP_EXPORT_INLINE_LIMIT', 15000),
];
