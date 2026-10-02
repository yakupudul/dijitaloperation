<?php

namespace App\Services\DataPool\Support;

final class RawPayloadReference
{
    public function __construct(
        public readonly int $rawIngestionObjectId,
        public readonly string $uuid,
        public readonly string $storageDisk,
        public readonly string $objectKey,
        public readonly string $sha256,
        public readonly int $byteSize,
        public readonly ?string $compression,
        public readonly bool $reusedExisting,
    ) {}
}
