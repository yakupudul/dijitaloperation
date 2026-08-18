<?php

namespace App\Enums;

/**
 * Bounded production report types (Prompt 59).
 * Not a generic report-builder catalog.
 */
enum ReportType: string
{
    case ClientValueStory = 'client_value_story';

    public function displayLabel(string $locale = 'en'): string
    {
        return match ($this) {
            self::ClientValueStory => $locale === 'tr'
                ? 'Müşteri Değer Hikayesi'
                : 'Client Value Story',
        };
    }

    public function defaultSchemaVersion(): ReportSnapshotSchemaVersion
    {
        return match ($this) {
            self::ClientValueStory => ReportSnapshotSchemaVersion::ClientValueStoryV1,
        };
    }
}
