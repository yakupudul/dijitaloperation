<?php

namespace App\Jobs;

use App\Models\DigitalAsset;
use App\Services\Website\SitemapChangeWatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** 1.4.1: hourly sitemap lastmod check of one website without the WordPress Connector. */
final class CheckSitemapChangesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 3000;

    public function __construct(public int $siteId) {}

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function handle(SitemapChangeWatcher $watcher): void
    {
        $site = DigitalAsset::query()->find($this->siteId);
        if ($site !== null && $site->isOperational()) {
            $watcher->check($site);
        }
    }
}
