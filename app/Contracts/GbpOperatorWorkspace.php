<?php

namespace App\Contracts;

use App\Models\DigitalAsset;

interface GbpOperatorWorkspace
{
    /** @return array<string, mixed> */
    public function for(DigitalAsset $asset): array;
}
