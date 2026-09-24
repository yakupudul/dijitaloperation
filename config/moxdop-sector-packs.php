<?php

use App\Services\Compliance\Packs\EducationSectorPack;
use App\Services\Compliance\Packs\FinanceSectorPack;
use App\Services\Compliance\Packs\FoodSupplementSectorPack;
use App\Services\Compliance\Packs\HealthSectorPack;
use App\Services\Compliance\Packs\LegalSectorPack;
use App\Services\Compliance\Packs\RealEstateSectorPack;

/*
 * Sector packs (Faz 5). Each class implements App\Services\Compliance\SectorPack; adding a sector means adding
 * a class here. Packs can be switched off and their rules edited in Ayarlar › Sektör paketleri.
 */
return [
    'packs' => [
        HealthSectorPack::class,
        // Faz 14: draft starter packs — review with a lawyer before relying on them.
        LegalSectorPack::class,
        FinanceSectorPack::class,
        RealEstateSectorPack::class,
        EducationSectorPack::class,
        FoodSupplementSectorPack::class,
    ],

    // Daily compliance scan (no provider calls, no AI).
    'scan_time' => env('MOXDOP_COMPLIANCE_SCAN_TIME', '06:45'),
    'website_pages_per_brand' => 150,
];
