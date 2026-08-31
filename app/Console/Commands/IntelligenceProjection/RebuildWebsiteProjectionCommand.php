<?php

namespace App\Console\Commands\IntelligenceProjection;

use App\Jobs\IntelligenceProjection\RebuildWebsiteProjectionJob;
use App\Models\DigitalAsset;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class RebuildWebsiteProjectionCommand extends Command
{
    protected $signature = 'intelligence:website-projection:rebuild
        {asset? : Website Digital Asset ID}
        {--all : Rebuild every Website Digital Asset}
        {--sync : Run in this process instead of dispatching queue jobs}
        {--start= : Optional inclusive period start (YYYY-MM-DD)}
        {--end= : Optional inclusive period end (YYYY-MM-DD)}';

    protected $description = 'Rebuild provider-neutral Website Intelligence Projection profiles from canonical source facts.';

    public function handle(WebsiteProjectionRebuilder $rebuilder): int
    {
        $assetOption = $this->argument('asset');
        $all = (bool) $this->option('all');
        if (! $all && (! is_string($assetOption) || ! ctype_digit($assetOption))) {
            $this->error('Provide a numeric Website asset ID or use --all.');

            return self::INVALID;
        }
        if ($all && is_string($assetOption) && $assetOption !== '') {
            $this->error('Use either an asset ID or --all, not both.');

            return self::INVALID;
        }

        try {
            $start = $this->dateOption('start');
            $end = $this->dateOption('end');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        if ($start !== null && $end !== null && $start->isAfter($end)) {
            $this->error('--start must not be after --end.');

            return self::INVALID;
        }

        $query = DigitalAsset::query()->with('brand')->where('type', 'website')->orderBy('id');
        if (! $all) {
            $query->whereKey((int) $assetOption);
        }
        $assets = $query->get();
        if ($assets->isEmpty()) {
            $this->error('No matching Website Digital Asset found.');

            return self::FAILURE;
        }

        foreach ($assets as $asset) {
            if ((bool) $this->option('sync')) {
                $run = $rebuilder->rebuild(
                    asset: $asset,
                    trigger: 'operator_command',
                    periodStart: $start,
                    periodEnd: $end,
                );
                $this->line("asset={$asset->id} run={$run->id} status={$run->status}");

                continue;
            }

            RebuildWebsiteProjectionJob::dispatch(
                websiteAssetId: (int) $asset->getKey(),
                trigger: 'operator_command',
                periodStart: $start?->toDateString(),
                periodEnd: $end?->toDateString(),
            );
            $this->line("asset={$asset->id} queued");
        }

        return self::SUCCESS;
    }

    private function dateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value), 'UTC');
        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== trim($value)) {
            throw new \InvalidArgumentException("--{$name} must use YYYY-MM-DD.");
        }

        return $date;
    }
}
