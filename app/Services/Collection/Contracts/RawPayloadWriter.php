<?php

namespace App\Services\Collection\Contracts;

use App\Services\DataPool\Support\RawPayloadEnvelope;
use App\Services\DataPool\Support\RawPayloadReference;

/**
 * Provider-neutral raw ingestion port (Prompt 9 boundary; Prompt 10 physical impl).
 */
interface RawPayloadWriter
{
    public function write(RawPayloadEnvelope $envelope): RawPayloadReference;
}
