<?php

namespace App\Livewire\Operator\GoogleAds\Desk;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Analyst\AnalystDecisionStore;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\GoogleAds\Desk\GoogleAdsDesk;
use App\Services\GoogleAds\GoogleAdsChanges;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Services\Repair\RepairDesk;
use App\Support\Roles;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Shared by the Google Ads menu tabs: the brand filter (`marka` in the address), the accounts in scope, a one-line
 * result message and the row decisions. A shared-list negative and a setting change go through the Onarım masası
 * approval (RepairDesk::queueApproval: queued, logged, undoable); every other draft is approved for the Editor file
 * exactly as on the account page. Decisions are the Admin's.
 */
trait DeskScope
{
    #[Url(as: 'marka')]
    public ?int $brand = null;

    public string $message = '';

    public string $messageTone = 'info';

    /** @var list<int> rows ticked for "Seçilenleri onayla" */
    public array $selected = [];

    public function updatedBrand(mixed $value): void
    {
        $this->brand = filled($value) ? (int) $value : null;
        $this->selected = [];
    }

    /** @return Collection<int, DigitalAsset> */
    protected function scopedAccounts(): Collection
    {
        return app(GoogleAdsDesk::class)->accounts($this->brand);
    }

    public function approve(int $id): void
    {
        $this->decide([$id]);
    }

    public function approveSelected(): void
    {
        $this->decide(array_map('intval', $this->selected));
        $this->selected = [];
    }

    public function dismiss(int $id, AnalystDecisionStore $store): void
    {
        $this->authorizeAdmin();
        $store->dismiss($this->suggestion($id), auth()->user());
        $this->selected = array_values(array_diff($this->selected, [$id]));
        $this->say('Reddedildi; kanıt değişmedikçe tekrar önerilmez.');
    }

    public function snooze(int $id, AnalystDecisionStore $store): void
    {
        $this->authorizeAdmin();
        $store->snooze($this->suggestion($id), 7);
        $this->say('7 gün ertelendi.');
    }

    /** @param  list<int>  $ids */
    private function decide(array $ids): void
    {
        $this->authorizeAdmin();
        $user = auth()->user();
        $desk = [];
        $editor = 0;
        foreach ($ids as $id) {
            $suggestion = $this->suggestion($id);
            if ($suggestion->action_type === GoogleAdsChanges::TYPE || GoogleAdsSuggestions::isSharedNegative($suggestion)) {
                $desk[] = (int) $suggestion->id;
            } elseif (! in_array($suggestion->action_type, ['ads_check', GoogleAdsSuggestions::ADVICE_TYPE], true)) {
                app(GoogleAdsSuggestions::class)->approve($suggestion, $user);
                $editor++;
            }
        }
        $parts = [];
        if ($desk !== []) {
            $result = app(RepairDesk::class)->queueApproval($desk, $user);
            if ($result['applied'] > 0) {
                $parts[] = ExternalWriteService::adsWritesOn()
                    ? $result['applied'].' değişiklik Google Ads’e gönderiliyor (kayıtlı, geri alınabilir; gönderilemeyen nedeniyle geri döner)'
                    : $result['applied'].' iş "yaptım" olarak işaretlendi; gece kontrolü Google Ads’te sonucu görecek';
            }
            if ($result['skipped_high'] > 0) {
                $parts[] = $result['skipped_high'].' yüksek riskli iş tek tek onaylanmalı';
            }
        }
        if ($editor > 0) {
            $parts[] = $editor.' taslak onaylandı; hesabın Editor dosyasına eklendi';
        }
        $this->say($parts === [] ? 'Onaylanacak iş yok.' : implode('. ', $parts).'.', 'success');
    }

    private function suggestion(int $id): Suggestion
    {
        return Suggestion::query()->whereKey($id)->where('channel', GoogleAdsSuggestions::CHANNEL)->where('target_type', GoogleAdsSuggestions::TARGET)
            ->whereIn('target_id', $this->scopedAccounts()->pluck('id')->all() ?: [0])->firstOrFail();
    }

    protected function say(string $text, string $tone = 'info'): void
    {
        $this->message = $text;
        $this->messageTone = $tone;
    }

    protected function isAdmin(): bool
    {
        return (bool) auth()->user()?->hasRole(Roles::ADMIN);
    }

    private function authorizeAdmin(): void
    {
        abort_unless($this->isAdmin(), 403);
    }
}
