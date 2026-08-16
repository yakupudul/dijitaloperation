<?php

namespace App\Support\ReportDelivery;

/**
 * CLIENT_VALUE_STORY_PDF_V1 renderer identity (Prompt 60).
 * Distinct from Snapshot schema version.
 */
final class ReportPdfRendererVersion
{
    public const string CLIENT_VALUE_STORY_PDF_V1 = 'client_value_story_pdf_v1';

    public static function current(): string
    {
        return (string) config('report_delivery.pdf.renderer_version', self::CLIENT_VALUE_STORY_PDF_V1);
    }
}
