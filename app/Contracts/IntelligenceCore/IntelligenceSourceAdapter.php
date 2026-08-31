<?php

namespace App\Contracts\IntelligenceCore;

interface IntelligenceSourceAdapter
{
    public function sourceId(): string;

    /** @return list<string> */
    public function capabilityIds(): array;

    /** @return list<string> */
    public function profileIds(): array;

    /** @return list<string> */
    public function metricIds(): array;
}
