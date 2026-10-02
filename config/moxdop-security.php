<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prompt 64 — Security & Credential Hardening
    |--------------------------------------------------------------------------
    */

    'enabled' => env('MOXDOP_SECURITY_HARDENING_ENABLED', true),

    /*
    | Secret classification (documented taxonomy — not an EAV store).
    | RECOVERABLE_CREDENTIAL | NON_RECOVERABLE_AUTH_SECRET |
    | DEPLOYMENT_SECRET | NON_SECRET_SECURITY_METADATA
    */

    'forbid_plaintext_credential_view' => true,

    'forbid_agent_credential_access' => true,

    'forbid_ai_permission_mutation' => true,

    /*
    | Share / OTP hashing remains SHA-256 of high-entropy material (Prompt 60).
    | Optional pepper is reserved for future keyed digests; do not flip without
    | a dual-read migration of existing hashes.
    */

    'share_secret_pepper_enabled' => env('MOXDOP_SHARE_SECRET_PEPPER_ENABLED', false),

    /*
    | Google OAuth compatibility cache must never key by raw state.
    */

    'oauth_state_cache_uses_hash' => true,

    /*
    | Report delivery locator cache stores a short-lived raw locator for email
    | send only (Prompt 60). Never treat as durable secret store.
    */

    'allow_transient_locator_cache' => true,

    'sensitive_field_names' => [
        'access_token',
        'refresh_token',
        'token',
        'api_key',
        'api_secret',
        'client_secret',
        'password',
        'application_password',
        'authorization',
        'cookie',
        'set-cookie',
        'otp',
        'verification_code',
        'locator_token',
        'session_token',
        'developer_token',
        'encrypted_payload',
    ],

    'sensitive_headers' => [
        'authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'proxy-authorization',
    ],
];
