<?php

namespace App\Livewire\Operator\Content;

use App\Models\Brand;
use App\Models\ClientApproval;
use App\Models\ContentCalendarItem;
use App\Models\DigitalAsset;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use App\Support\ServiceScope;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * İçerik takvimi: planned content for every brand. Approved Business Profile posts are published by MoxDOP at their
 * time (ADR-073); other channels are reminders that show up in the command center on their day.
 */
#[Layout('operator.layouts.app')]
#[Title('İçerik takvimi')]
final class ContentCalendarPage extends Component
{
    #[Url]
    public ?int $brand = null;

    #[Url]
    public bool $showDone = false;

    public ?int $editingId = null;

    public ?string $clientLink = null;

    public ?int $clientLinkFor = null;

    /** @var array{brand_id: ?int, channel: string, digital_asset_id: ?int, title: string, body: string, url: string, action_type: string, scheduled_for: string} */
    public array $form = ['brand_id' => null, 'channel' => 'gbp_post', 'digital_asset_id' => null, 'title' => '', 'body' => '', 'url' => '', 'action_type' => 'LEARN_MORE', 'scheduled_for' => ''];

    public function startNew(): void
    {
        $this->editingId = 0;
        $this->form = ['brand_id' => $this->brand, 'channel' => 'gbp_post', 'digital_asset_id' => null, 'title' => '', 'body' => '', 'url' => '', 'action_type' => 'LEARN_MORE',
            'scheduled_for' => now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i')];
    }

    public function edit(int $id): void
    {
        $item = ContentCalendarItem::query()->findOrFail($id);
        $this->editingId = $item->id;
        $this->form = ['brand_id' => $item->brand_id, 'channel' => $item->channel, 'digital_asset_id' => $item->digital_asset_id, 'title' => $item->title, 'body' => (string) $item->body,
            'url' => (string) $item->url, 'action_type' => (string) ($item->action_type ?? 'LEARN_MORE'), 'scheduled_for' => $item->scheduled_for->timezone('Europe/Istanbul')->format('Y-m-d\TH:i')];
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        $data = $this->validate([
            'form.brand_id' => ['required', 'integer', Rule::exists('brands', 'id')],
            'form.channel' => ['required', Rule::in(array_keys(ContentCalendarItem::CHANNELS))],
            'form.digital_asset_id' => ['nullable', 'integer', Rule::requiredIf($this->form['channel'] === 'gbp_post')],
            'form.title' => ['required', 'string', 'max:200'],
            'form.body' => ['nullable', 'string', 'max:1400'],
            'form.url' => ['nullable', 'url', 'max:500'],
            'form.action_type' => ['nullable', Rule::in(['LEARN_MORE', 'BOOK', 'CALL', 'ORDER', 'SIGN_UP'])],
            'form.scheduled_for' => ['required', 'date'],
        ])['form'];
        if ($data['channel'] === 'gbp_post') {
            abort_unless(DigitalAsset::query()->whereKey($data['digital_asset_id'])->where('brand_id', $data['brand_id'])->where('type', 'google_business_profile')->exists(), 422);
        }
        $values = [
            'brand_id' => $data['brand_id'], 'channel' => $data['channel'], 'digital_asset_id' => $data['channel'] === 'gbp_post' ? $data['digital_asset_id'] : null,
            'title' => $data['title'], 'body' => $data['body'] ?: null, 'url' => $data['url'] ?: null, 'action_type' => $data['action_type'] ?: null,
            'scheduled_for' => CarbonImmutable::parse($data['scheduled_for'], 'Europe/Istanbul')->utc(),
        ];
        if ($this->editingId) {
            $item = ContentCalendarItem::query()->findOrFail($this->editingId);
            abort_if(in_array($item->status, ['published'], true) || $item->write_action_id !== null, 422);
            // An edit needs a new approval.
            $item->forceFill($values + ['status' => 'draft', 'approved_by' => null, 'error' => null])->save();
        } else {
            ContentCalendarItem::query()->create($values + ['status' => 'draft', 'created_by' => auth()->id()]);
        }
        $this->editingId = null;
        DemoState::flash('Takvime kaydedildi. İşletme Profili gönderileri onaylanınca zamanında yayınlanır.');
    }

    public function approve(int $id): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        ContentCalendarItem::query()->whereKey($id)->whereIn('status', ['draft', 'failed'])->update(['status' => 'approved', 'approved_by' => auth()->id(), 'error' => null, 'write_action_id' => null, 'updated_at' => now()]);
        DemoState::flash('Onaylandı.');
    }

    /** ADR-075: create a signed, 14-day link the operator sends to the client (WhatsApp / e-mail). */
    public function requestClientApproval(int $id): void
    {
        $item = ContentCalendarItem::query()->whereKey($id)->whereIn('status', ['draft', 'failed'])->firstOrFail();
        $approval = ClientApproval::requestFor($item, auth()->user());
        $this->clientLink = $approval->link();
        $this->clientLinkFor = $item->id;
        DemoState::flash('Müşteri onay bağlantısı hazır; kopyalayıp müşteriye gönderin.');
    }

    public function markPublished(int $id): void
    {
        ContentCalendarItem::query()->whereKey($id)->where('channel', '!=', 'gbp_post')->whereNotIn('status', ['published'])
            ->update(['status' => 'published', 'published_at' => now(), 'updated_at' => now()]);
    }

    public function skip(int $id): void
    {
        ContentCalendarItem::query()->whereKey($id)->whereNull('write_action_id')->whereNotIn('status', ['published'])->update(['status' => 'skipped', 'updated_at' => now()]);
    }

    public function render(): View
    {
        $items = ContentCalendarItem::query()->with(['brand', 'digitalAsset', 'clientApproval'])
            // Service scope: the full calendar lists operational brands; a passive brand stays reachable by ?brand=.
            ->when($this->brand !== null, fn ($q) => $q->where('brand_id', $this->brand), fn ($q) => app(ServiceScope::class)->constrain($q, null))
            ->when(! $this->showDone, fn ($q) => $q->whereNotIn('status', ['published', 'skipped']))
            ->where('scheduled_for', '>=', now()->subDays($this->showDone ? 60 : 30))->orderBy('scheduled_for')->limit(300)->get();

        return view('livewire.operator.content.content-calendar', [
            'weeks' => $items->groupBy(fn (ContentCalendarItem $i): string => $i->scheduled_for->timezone('Europe/Istanbul')->startOfWeek()->format('Y-m-d')),
            'brands' => Brand::query()->operational()->orderBy('name')->pluck('name', 'id'),
            'profiles' => ($this->form['brand_id'] ?? null) !== null
                ? DigitalAsset::query()->operational()->where('brand_id', $this->form['brand_id'])->where('type', 'google_business_profile')->pluck('name', 'id') : collect(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
