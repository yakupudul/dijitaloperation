<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\ContentStudio\TopicMapBuilder;
use App\Services\Demand\BrandDemandBuilder;
use App\Support\ServiceScope;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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
        if ($brand === null) {
            return;
        }
        $builder->build($brand);
        // Faz 3: the topic map of each operational website follows the rebuilt hub.
        $scope = app(ServiceScope::class);
        foreach (DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->get() as $site) {
            if (! $scope->isAssetOperational($site->id)) {
                continue;
            }
            try {
                app(TopicMapBuilder::class)->queue($site, 'hub');
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
