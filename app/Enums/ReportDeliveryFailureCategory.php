<?php

namespace App\Enums;

enum ReportDeliveryFailureCategory: string
{
    case SnapshotGenerationFailed = 'snapshot_generation_failed';
    case PdfGenerationFailed = 'pdf_generation_failed';
    case ShareCreationFailed = 'share_creation_failed';
    case EmailConfigurationMissing = 'email_configuration_missing';
    case EmailTransportTransient = 'email_transport_transient';
    case EmailTransportPermanent = 'email_transport_permanent';
    case RecipientInvalid = 'recipient_invalid';
    case ShareExpiredBeforeSend = 'share_expired_before_send';
    case AuthorizationInvalidated = 'authorization_invalidated';
}
