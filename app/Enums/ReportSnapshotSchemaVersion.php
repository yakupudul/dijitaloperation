<?php

namespace App\Enums;

/**
 * Code-owned Report Snapshot schema versions (Prompt 59).
 * Old snapshots remain readable; new versions never rewrite old rows.
 */
enum ReportSnapshotSchemaVersion: string
{
    case ClientValueStoryV1 = 'client_value_story_v1';

    public function reportType(): ReportType
    {
        return match ($this) {
            self::ClientValueStoryV1 => ReportType::ClientValueStory,
        };
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function isWritableForNewSnapshots(): bool
    {
        return match ($this) {
            self::ClientValueStoryV1 => true,
        };
    }
}
