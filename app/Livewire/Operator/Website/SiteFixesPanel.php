<?php

namespace App\Livewire\Operator\Website;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\SiteFixItem;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\SiteFixes\SiteFixAi;
use App\Services\SiteFixes\SiteFixFinder;
use App\Support\Permissions;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Website › Düzeltmeler (ADR-070). Rules find problems from stored data; AI (on click) proposes values; the operator
 * edits; an Admin approves and the MoxDOP Connector writes them to WordPress. Every write can be undone.
 * Phase 1: SEO title / description, alt text, schema. Phase 2: redirect, noindex, canonical, internal link.
 * Phase 3: page text (draft copy, then a second approval) and new pages (draft).
 */
final class SiteFixesPanel extends Component
{
    #[Locked]
    public int $websiteId;

    #[Url(as: 'fix_phase')]
    public string $phase = '1';

    #[Url(as: 'fix_status')]
    public string $status = 'open';

    /** @var array<int|string, bool> item id => selected */
    public array $selected = [];

    /** @var array<int|string, string> item id => edited value */
    public array $edits = [];

    public ?int $openItem = null;

    public string $message = '';

    public string $tone = 'success';

    public function mount(int $websiteId): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $this->websiteId = $websiteId;
    }

    public function find(SiteFixFinder $finder): void
    {
        $result = $finder->find($this->site());
        $this->say($result['found'] === 0 ? 'Düzeltilecek sorun bulunmadı (son tarama ve WordPress verisine göre).' : $result['found'].' düzeltme bulundu.');
    }

    public function propose(string $kind, SiteFixAi $ai): void
    {
        abort_unless(in_array($kind, [SiteFixAi::KIND_VALUES, SiteFixAi::KIND_LINKS], true), 422);
        try {
            $ai->queue($kind, $this->websiteId);
            $this->say('AI önerileri hazırlıyor; birkaç saniye sonra listede görünür.');
        } catch (ValidationException $exception) {
            $this->say((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function writePage(int $itemId, SiteFixAi $ai): void
    {
        $item = $this->item($itemId);
        abort_unless(in_array($item->type, ['content_update', 'new_page'], true), 422);
        try {
            $ai->queue(SiteFixAi::KIND_PAGE, $item->id);
            $this->say('AI sayfa metnini yazıyor (1–2 dakika sürebilir).');
        } catch (ValidationException $exception) {
            $this->say((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function saveValue(int $itemId): void
    {
        $item = $this->item($itemId);
        abort_unless(in_array($item->status, ['open', 'failed', 'undone'], true), 422);
        $raw = (string) ($this->edits[$itemId] ?? '');
        $value = match ($item->type) {
            'noindex' => $raw === '1',
            'schema' => $raw,
            default => trim($raw),
        };
        if ($item->type === 'schema' && $value !== '' && ! is_array(json_decode($value, true))) {
            $this->say('Yapılandırılmış veri geçerli JSON değil.', 'error');

            return;
        }
        $item->forceFill(['proposed' => array_merge((array) $item->proposed, ['value' => $value]), 'proposed_by' => 'operator'])->save();
        unset($this->edits[$itemId]);
        $this->say('Değer kaydedildi.');
    }

    public function cancelEdit(int $itemId): void
    {
        unset($this->edits[$itemId]);
    }

    public function dismiss(int $itemId): void
    {
        $item = $this->item($itemId);
        $item->forceFill(['status' => $item->status === 'dismissed' ? 'open' : 'dismissed'])->save();
    }

    public function applySelected(ExternalWriteService $writes): void
    {
        $ids = array_map('intval', array_keys(array_filter($this->selected)));
        try {
            $action = $writes->requestSiteFixes(auth()->user(), $this->site(), $ids);
            $this->selected = [];
            $this->say(count((array) $action->request_payload['item_ids']).' düzeltme WordPress’e gönderiliyor.');
        } catch (ValidationException $exception) {
            $this->say((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function sendDraft(int $itemId, ExternalWriteService $writes): void
    {
        try {
            $writes->requestContentDraft(auth()->user(), $this->item($itemId));
            $this->say('Metin WordPress’e taslak olarak gönderiliyor. Yayındaki sayfa değişmez.');
        } catch (ValidationException $exception) {
            $this->say((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function applyContent(int $itemId, ExternalWriteService $writes): void
    {
        try {
            $writes->requestContentApply(auth()->user(), $this->item($itemId));
            $this->say('Yeni sürüm yayındaki sayfanın yerine geçiyor. WordPress eski sürümü saklar; buradan geri alınabilir.');
        } catch (ValidationException $exception) {
            $this->say((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function undo(int $actionId, ExternalWriteService $writes): void
    {
        $action = ExternalWriteAction::query()->where('digital_asset_id', $this->websiteId)->findOrFail($actionId);
        try {
            $writes->requestUndo(auth()->user(), $action);
            $this->say('Geri alınıyor: önceki değerler siteye yazılıyor.');
        } catch (ValidationException $exception) {
            $this->say((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    public function toggle(int $itemId): void
    {
        $this->openItem = $this->openItem === $itemId ? null : $itemId;
    }

    public function render(SiteFixAi $ai): View
    {
        $site = $this->site();
        $base = SiteFixItem::query()->where('digital_asset_id', $site->id);
        $items = (clone $base)
            ->when(in_array($this->phase, ['1', '2', '3'], true), fn ($q) => $q->where('phase', (int) $this->phase))
            ->when($this->status === 'open', fn ($q) => $q->whereIn('status', ['open', 'failed', 'undone', 'queued', 'drafted']))
            ->when($this->status === 'applied', fn ($q) => $q->where('status', 'applied'))
            ->when($this->status === 'dismissed', fn ($q) => $q->where('status', 'dismissed'))
            ->orderBy('phase')->orderBy('type')->orderBy('id')->limit(300)->get();
        $actions = ExternalWriteAction::query()->where('digital_asset_id', $site->id)
            ->whereIn('action', [ExternalWriteAction::ACTION_SITE_FIX, ExternalWriteAction::ACTION_CONTENT_DRAFT, ExternalWriteAction::ACTION_CONTENT_APPLY])
            ->latest('id')->limit(10)->get();
        $running = $ai->state(SiteFixAi::KIND_VALUES, $site->id) === 'running' || $ai->state(SiteFixAi::KIND_LINKS, $site->id) === 'running'
            || $items->contains(fn (SiteFixItem $i): bool => $i->status === 'queued' || $ai->state(SiteFixAi::KIND_PAGE, $i->id) === 'running')
            || $actions->contains(fn (ExternalWriteAction $a): bool => in_array($a->status, ['queued', 'running', 'undoing'], true));

        return view('livewire.operator.website.site-fixes-panel', [
            'items' => $items,
            'counts' => (clone $base)->whereIn('status', ['open', 'failed', 'undone'])->selectRaw('phase, count(*) as c')->groupBy('phase')->pluck('c', 'phase')->all(),
            'actions' => $actions,
            'connector' => $this->connector($site),
            'canWrite' => ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_WORDPRESS),
            'aiState' => ['values' => $ai->state(SiteFixAi::KIND_VALUES, $site->id), 'links' => $ai->state(SiteFixAi::KIND_LINKS, $site->id)],
            'pageStates' => $items->whereIn('type', ['content_update', 'new_page'])->mapWithKeys(fn (SiteFixItem $i): array => [$i->id => $ai->state(SiteFixAi::KIND_PAGE, $i->id)])->all(),
            'polling' => $running,
        ]);
    }

    /** @return array{paired: bool, version: ?string, ready: bool, minimum: string} */
    private function connector(DigitalAsset $site): array
    {
        $connection = CoreConnection::query()->where('digital_asset_id', $site->id)->where('type', 'wordpress_connector')->where('enabled', true)->first();
        $version = $connection !== null ? (string) data_get($connection->config, 'plugin_version', '') : null;
        $minimum = (string) config('moxdop-wordpress.fixes_min_plugin_version', '1.4.0');

        return ['paired' => $connection !== null && data_get($connection->config, 'pairing_state') === 'paired', 'version' => $version ?: null,
            'ready' => $connection !== null && data_get($connection->config, 'pairing_state') === 'paired' && version_compare($version ?: '0', $minimum, '>='), 'minimum' => $minimum];
    }

    private function site(): DigitalAsset
    {
        return DigitalAsset::query()->where('type', 'website')->findOrFail($this->websiteId);
    }

    private function item(int $itemId): SiteFixItem
    {
        return SiteFixItem::query()->where('digital_asset_id', $this->websiteId)->findOrFail($itemId);
    }

    private function say(string $message, string $tone = 'success'): void
    {
        $this->message = $message;
        $this->tone = $tone;
    }
}
