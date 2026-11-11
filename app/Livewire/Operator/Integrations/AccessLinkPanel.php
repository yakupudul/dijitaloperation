<?php

namespace App\Livewire\Operator\Integrations;

use App\Livewire\Concerns\AdminOnly;
use App\Models\Brand;
use App\Services\Access\AccessLinks;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Bağlantı haritası › "Erişim iste" (Bağlantı Düzeni 4.1): the operator ticks which accesses a brand owes, gets the
 * brand's one link with a ready message and sends it himself; what the customer marked as given shows here.
 */
final class AccessLinkPanel extends Component
{
    use AdminOnly;

    #[Locked]
    public int $brandId;

    /** @var list<string> */
    public array $channels = [];

    public string $google = '';

    public string $meta = '';

    public string $message = '';

    public function mount(int $brandId, AccessLinks $links): void
    {
        $this->brandId = $brandId;
        $brand = Brand::query()->operational()->findOrFail($brandId);
        $this->channels = (array) ($links->of($brand)?->asked ?? $links->suggested($brand));
        ['google' => $this->google, 'meta' => $this->meta] = $links->identities();
    }

    public function save(AccessLinks $links): void
    {
        $this->adminOnly();
        try {
            $links->saveIdentities($this->google, $this->meta);
            $links->ask(Brand::query()->operational()->findOrFail($this->brandId), $this->channels, auth()->user());
            ['google' => $this->google, 'meta' => $this->meta] = $links->identities();
            $this->message = 'Bağlantı hazır. Müşteriye sen gönderirsin; sistem kendiliğinden göndermez.';
        } catch (InvalidArgumentException $error) {
            $this->message = $error->getMessage();
        }
    }

    public function render(AccessLinks $links): View
    {
        $link = $links->of(Brand::query()->operational()->findOrFail($this->brandId));

        return view('livewire.operator.integrations.access-link-panel', [
            'link' => $link, 'items' => $link !== null ? collect($links->items($link))->keyBy('channel') : collect(),
            'text' => $link !== null ? $links->message($link) : '', 'guide' => AccessLinks::CHANNELS,
        ]);
    }
}
