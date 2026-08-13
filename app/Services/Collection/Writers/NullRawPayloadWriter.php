<?php

namespace App\Services\Collection\Writers;

use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use App\Services\DataPool\Support\RawPayloadReference;

/**
 * No-op writer for environments that have not bound physical raw storage.
 */
final class NullRawPayloadWriter implements RawPayloadWriter
{
    public function write(RawPayloadEnvelope $envelope): RawPayloadReference
    {
        return new RawPayloadReference(
            rawIngestionObjectId: 0,
            uuid: '00000000-0000-0000-0000-000000000000',
            storageDisk: 'null',
            objectKey: 'null/'.$envelope->batchKey,
            sha256: hash('sha256', ''),
            byteSize: 0,
            compression: null,
            reusedExisting: false,
        );
    }
}
