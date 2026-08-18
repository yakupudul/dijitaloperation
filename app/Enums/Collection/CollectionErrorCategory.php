<?php

namespace App\Enums\Collection;

enum CollectionErrorCategory: string
{
    case Authentication = 'authentication';
    case Authorization = 'authorization';
    case RateLimit = 'rate_limit';
    case Quota = 'quota';
    case Timeout = 'timeout';
    case Network = 'network';
    case Provider5xx = 'provider_5xx';
    case InvalidRequest = 'invalid_request';
    case ContractMismatch = 'contract_mismatch';
    case UnimplementedCapability = 'unimplemented_capability';
    case Normalization = 'normalization';
    case Persistence = 'persistence';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
