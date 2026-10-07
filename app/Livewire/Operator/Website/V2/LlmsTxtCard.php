<?php

namespace App\Livewire\Operator\Website\V2;

use App\Livewire\Operator\Website\V2\Concerns\WebsiteTab;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Site\LlmsTxt;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Web sitesi › Teknik › llms.txt: the rule-built llms.txt preview; "Siteye gönder" (Admin) sends it as an ADR-070 site
 * fix and the connector (≥ 1.11.0) serves it at /llms.txt; "Geri al" puts the previous text back.
 */
final class LlmsTxtCard extends Component
{
    use WebsiteTab;

    public bool $preview = false;

    public function send(LlmsTxt $builder, ExternalWriteService $writes): void
    {
        $actor = $this->actor();
        abort_unless($actor->hasRole(Roles::ADMIN), 403);
        $site = $this->site();
        $version = (string) data_get($site->connections()->where('type', 'wordpress_connector')->where('enabled', true)->first()?->config, 'plugin_version', '0.0.0');
        if (version_compare($version, LlmsTxt::MIN_PLUGIN_VERSION, '<')) {
            throw ValidationException::withMessages(['write' => 'WordPress Connector '.$version.'; llms.txt için en az '.LlmsTxt::MIN_PLUGIN_VERSION.' gerekli. Eklentiyi güncelle.']);
        }
        $text = $builder->build($site);
        $writes->requestSiteFixes($actor, $site, [['type' => 'llms_txt', 'object_id' => 0, 'reference' => 'llms-txt', 'value' => $text]]);
        $this->message = 'llms.txt siteye gönderildi.';
    }

    public function undo(int $id, ExternalWriteService $writes): void
    {
        $actor = $this->actor();
        abort_unless($actor->hasRole(Roles::ADMIN), 403);
        $last = $this->lastSend();
        abort_unless($last !== null && $last->id === $id && $last->isUndoable(), 404);
        $writes->requestUndo($actor, $last);
        $this->message = 'Önceki llms.txt geri alınıyor.';
    }

    private function lastSend(): ?ExternalWriteAction
    {
        return ExternalWriteAction::query()->where('digital_asset_id', $this->websiteId)->where('action', ExternalWriteAction::ACTION_SITE_FIX)
            ->where('request_payload', 'like', '%"llms_txt"%')->latest('id')->first();
    }

    public function render(LlmsTxt $builder): View
    {
        $site = $this->site();

        return view('livewire.operator.website.v2.llms-txt-card', [
            'text' => $this->preview ? $builder->build($site) : '',
            'last' => $this->lastSend(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'url' => rtrim((string) ($site->primary_url ?: 'https://'.$site->domain), '/').'/llms.txt',
        ]);
    }
}
