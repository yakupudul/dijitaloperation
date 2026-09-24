<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Services\Intel\ServiceAreaGeocoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Geocode a brand's service areas in the background (Nominatim allows one request per second). */
final class GeocodeServiceAreasJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public function __construct(public int $brandId) {}

    public function handle(ServiceAreaGeocoder $geocoder): void
    {
        $brand = Brand::query()->find($this->brandId);
        if ($brand !== null) {
            $geocoder->geocodeBrand($brand);
        }
    }
}
