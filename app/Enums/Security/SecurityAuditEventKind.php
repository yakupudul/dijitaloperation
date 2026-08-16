<?php

namespace App\Enums\Security;

/**
 * Bounded security audit kinds — never include secret values.
 */
enum SecurityAuditEventKind: string
{
    case CredentialCreated = 'CREDENTIAL_CREATED';
    case CredentialRotated = 'CREDENTIAL_ROTATED';
    case CredentialRevoked = 'CREDENTIAL_REVOKED';
    case IntegrationReconnected = 'INTEGRATION_RECONNECTED';
    case IntegrationDisconnected = 'INTEGRATION_DISCONNECTED';
    case PermissionChanged = 'PERMISSION_CHANGED';
    case UserAccessChanged = 'USER_ACCESS_CHANGED';
    case ShareRevoked = 'SHARE_REVOKED';
    case SecuritySettingChanged = 'SECURITY_SETTING_CHANGED';
    case EncryptionReencryptBatch = 'ENCRYPTION_REENCRYPT_BATCH';
}
