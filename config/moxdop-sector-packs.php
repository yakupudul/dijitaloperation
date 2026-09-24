<?php

use App\Services\Compliance\Packs\HealthSectorPack;

/*
 * Sector packs (Faz 5). Each class implements App\Services\Compliance\SectorPack; adding a sector means adding
 * a class here. Packs can be switched off and their rules edited in Ayarlar › Sektör paketleri.
 */
return [
    'packs' => [
        HealthSectorPack::class,
    ],

    // Daily compliance scan (no provider calls, no AI).
    'scan_time' => env('MOXDOP_COMPLIANCE_SCAN_TIME', '06:45'),
    'website_pages_per_brand' => 150,
];
