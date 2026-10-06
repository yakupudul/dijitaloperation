<?php

namespace App\Livewire\Operator\Gbp\Desk;

use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\Desk\GbpDesk;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * Shared by the İşletme profilleri tabs: the brand filter (kept in the address as `marka`), the profiles in scope, the
 * Admin check and a one-line result message.
 */
trait DeskScope
{
    #[Url(as: 'marka')]
    public ?int $brand = null;

    public string $message = '';

    public string $messageTone = 'info';

    public function updatedBrand(mixed $value): void
    {
        $this->brand = filled($value) ? (int) $value : null;
    }

    /** @return Collection<int, DigitalAsset> */
    protected function scopedLocations(): Collection
    {
        return app(GbpDesk::class)->locations($this->brand);
    }

    /** @return array<int, string> brand id => name (brands that have a profile) */
    protected function brandOptions(): array
    {
        return app(GbpDesk::class)->locations()->pluck('brand.name', 'brand_id')->filter()->sort()->all();
    }

    protected function canWrite(): bool
    {
        return ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GBP);
    }

    protected function location(int $assetId): DigitalAsset
    {
        return app(GbpDesk::class)->locations()->firstWhere('id', $assetId) ?? abort(404);
    }

    protected function say(string $text, string $tone = 'info'): void
    {
        $this->message = $text;
        $this->messageTone = $tone;
    }

    protected function sayError(ValidationException $exception): void
    {
        $this->say((string) collect($exception->errors())->flatten()->first(), 'error');
    }
}
