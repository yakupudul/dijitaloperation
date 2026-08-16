<?php

namespace App\Enums;

enum ReportDeliveryOccurrenceStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case SnapshotReady = 'snapshot_ready';
    case ArtifactReady = 'artifact_ready';
    case Distributing = 'distributing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
