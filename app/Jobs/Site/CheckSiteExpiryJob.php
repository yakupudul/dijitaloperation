<?php

namespace App\Jobs\Site;

use App\Models\DigitalAsset;
use App\Services\Site\Health\SiteExpiryChecker;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** SSL certificate + domain (RDAP) expiry of one website ("Şimdi kontrol et"; the daily run uses the command). */
final class CheckSiteExpiryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct(public int $siteId, public bool $force = false) {}

    public function uniqueId(): string
    {
        return (string) $this->siteId;
    }

    public function handle(SiteExpiryChecker $checker): void
    {
        $site = DigitalAsset::query()->where('type', 'website')->find($this->siteId);
        if ($site !== null) {
            $checker->check($site, $this->force);
        }
    }
}
