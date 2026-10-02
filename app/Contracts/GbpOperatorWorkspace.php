<?php

namespace App\Contracts;

use App\Models\DigitalAsset;

interface GbpOperatorWorkspace
{
    /**
     * @param  int  $days  performance window (compared with the previous window of the same length)
     * @return array<string, mixed>
     */
    public function for(DigitalAsset $asset, int $days = 28): array;
}
