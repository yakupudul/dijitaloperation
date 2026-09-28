<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Services\Demand\BrandDemandBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** "Şimdi yenile" on the brand query hub: rebuilds one brand from stored data (no provider or paid call). */
class BuildBrandDemandJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public int $brandId) {}

    public function uniqueId(): string
    {
        return (string) $this->brandId;
    }

    public function handle(BrandDemandBuilder $builder): void
    {
        $brand = Brand::query()->find($this->brandId);
        if ($brand !== null) {
            $builder->build($brand);
        }
    }
}
