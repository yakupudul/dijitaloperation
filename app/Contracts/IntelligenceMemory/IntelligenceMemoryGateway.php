<?php

namespace App\Contracts\IntelligenceMemory;

use App\Support\IntelligenceMemory\Dto\MemoryContextManifest;
use App\Support\IntelligenceMemory\Dto\MemoryContextPack;
use App\Support\IntelligenceMemory\Dto\MemoryContextRequest;

/**
 * Central policy boundary — NOT unrestricted central storage.
 *
 * Must not accept table name, arbitrary model, SQL, or generic filter DSL.
 */
interface IntelligenceMemoryGateway
{
    public function evaluate(MemoryContextRequest $request): MemoryContextManifest;

    /**
     * Prompt 54 owns real retrieval. Prompt 51 returns an empty pack after policy eval.
     */
    public function resolveMemoryContextPack(MemoryContextRequest $request): MemoryContextPack;
}
