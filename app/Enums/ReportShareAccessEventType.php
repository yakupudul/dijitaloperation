<?php

namespace App\Enums;

enum ReportShareAccessEventType: string
{
    case VerificationRequested = 'verification_requested';
    case VerificationSucceeded = 'verification_succeeded';
    case VerificationFailed = 'verification_failed';
    case ReportViewed = 'report_viewed';
    case PdfDownloaded = 'pdf_downloaded';
    case AccessDenied = 'access_denied';
    case GrantRevoked = 'grant_revoked';
}
