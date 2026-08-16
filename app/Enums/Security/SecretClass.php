<?php

namespace App\Enums\Security;

/**
 * Prompt 64 secret taxonomy — storage rules differ by class.
 */
enum SecretClass: string
{
    case RecoverableCredential = 'RECOVERABLE_CREDENTIAL';
    case NonRecoverableAuthSecret = 'NON_RECOVERABLE_AUTH_SECRET';
    case DeploymentSecret = 'DEPLOYMENT_SECRET';
    case NonSecretSecurityMetadata = 'NON_SECRET_SECURITY_METADATA';
}
