<?php

namespace App\Enums;

enum ReportDeliveryMode: string
{
    case AuthenticatedSecureLink = 'authenticated_secure_link';
    case AuthenticatedSecureLinkWithPdf = 'authenticated_secure_link_with_pdf_access';
}
