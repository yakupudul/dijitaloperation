<?php

namespace App\Livewire\Operator\Website\V2\Concerns;

use App\Models\DigitalAsset;
use App\Models\User;
use Livewire\Attributes\Locked;

/** A standalone tab of the website asset screen: bound to one website asset (active operators only). */
trait WebsiteTab
{
    #[Locked]
    public int $websiteId = 0;

    public string $message = '';

    public function mount(int $websiteId): void
    {
        $this->actor();
        $site = DigitalAsset::query()->where('type', 'website')->findOrFail($websiteId);
        $this->websiteId = (int) $site->id;
    }

    protected function site(): DigitalAsset
    {
        return DigitalAsset::query()->with('brand.customer')->where('type', 'website')->findOrFail($this->websiteId);
    }

    protected function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return $actor;
    }

    /** Brand served (customer active) and the site active: paid / AI / automatic work may run. */
    protected function operational(DigitalAsset $site): bool
    {
        return $site->brand !== null && $site->brand->isOperational() && $site->isOperational();
    }
}
