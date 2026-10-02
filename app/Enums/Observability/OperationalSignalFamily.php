<?php

namespace App\Enums\Observability;

enum OperationalSignalFamily: string
{
    case Collection = 'COLLECTION';
    case Dataset = 'DATASET';
    case Queue = 'QUEUE';
    case Worker = 'WORKER';
    case Scheduler = 'SCHEDULER';
    case ProviderApi = 'PROVIDER_API';
    case Credential = 'CREDENTIAL';
    case Reporting = 'REPORTING';
    case Intelligence = 'INTELLIGENCE';
    case AiProvider = 'AI_PROVIDER';
    case Database = 'DATABASE';
    case Storage = 'STORAGE';
}
