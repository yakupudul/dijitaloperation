<?php

namespace App\Livewire\Operator\Website\V2;

use App\Livewire\Operator\Website\V2\Concerns\WebsiteTab;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Site\BrandlessSites;
use App\Services\Site\WebsiteConnections;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

/**
 * Web sitesi › Ayarlar › Bağlantılar (Aşama 6): the site's four connections as steps — eklenti, Search Console, GA4,
 * tarama — each done or with its one next action (connect, update, crawl now).
 */
final class ConnectionStepsCard extends Component
{
    use WebsiteTab;

    public function updateConnector(ExternalWriteService $writes): void
    {
        $actor = $this->actor();
        abort_unless($actor->hasRole(Roles::ADMIN), 403);
        try {
            $writes->requestConnectorUpdate($actor, $this->site());
            $this->message = 'Eklenti '.config('moxdop-wordpress.connector_version').' güncellemesi kuyruğa alındı.';
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function crawl(WebsiteCollectionOrchestrator $orchestrator): void
    {
        $actor = $this->actor();
        $site = $this->site();
        if (! BrandlessSites::serviced($site)) {
            $this->message = 'Markaya atanmamış sitede tarama yapılmaz; önce markaya bağla.';

            return;
        }
        try {
            $orchestrator->start(asset: $site, requestedBy: $actor, context: ['trigger' => 'operator.website.connection_steps', 'force_refresh' => true]);
            $this->message = 'Site taraması kuyruğa alındı; birkaç dakika içinde sayfalar ve teknik bulgular görünür.';
        } catch (Throwable $exception) {
            report($exception);
            $this->message = 'Site taraması başlatılamadı.';
        }
    }

    public function render(): View
    {
        $site = $this->site();
        $st = WebsiteConnections::for(collect([$site]))[(int) $site->id];
        $sources = route('operator.asset.sources', ['assetId' => $site->id]);
        $isAdmin = (bool) auth()->user()?->hasRole(Roles::ADMIN);
        $latest = (string) config('moxdop-wordpress.connector_version');
        $crawlDate = $st['crawl_at']?->timezone('Europe/Istanbul')->format('d.m.Y H:i');
        $steps = [
            ['key' => 'eklenti', 'title' => 'WordPress eklentisi', 'done' => $st['paired'] && ! $st['outdated'],
                'line' => match (true) {
                    ! $st['paired'] => 'Eklenti bağlı değil. Bağlanınca düzeltmeler tek tıkla siteye gider.',
                    $st['outdated'] => 'Sürüm '.$st['version'].', güncel sürüm '.$latest.'.',
                    default => 'Bağlı, sürüm '.($st['version'] ?: '—').'.',
                },
                'action' => match (true) {
                    ! $st['paired'] => ['label' => 'Eklentiyi bağla', 'url' => route('operator.integrations.website', ['assetId' => $site->id])],
                    $st['outdated'] && $st['updatable'] && $isAdmin => ['label' => 'Güncelle', 'click' => 'updateConnector'],
                    default => null,
                }],
            ['key' => 'gsc', 'title' => 'Search Console', 'done' => $st['gsc'],
                'line' => $st['gsc'] ? 'Bağlı; tıklama ve sorgular her gün gelir.' : 'Bağlı değil; tıklama ve sorgu verisi gelmez.',
                'action' => $st['gsc'] ? null : ['label' => 'Bağla', 'url' => $sources]],
            ['key' => 'ga4', 'title' => 'Google Analytics 4', 'done' => $st['ga4'],
                'line' => $st['ga4'] ? 'Bağlı; oturum ve talepler her gün gelir.' : 'Bağlı değil; oturum ve talep verisi gelmez.',
                'action' => $st['ga4'] ? null : ['label' => 'Bağla', 'url' => $sources]],
            ['key' => 'tarama', 'title' => 'Site taraması', 'done' => $st['crawl_at'] !== null && ! $st['crawl_failed'] && ! in_array('tarama eski', $st['problems'], true),
                'line' => match (true) {
                    $st['crawl_at'] === null => 'Site henüz taranmadı.',
                    $st['crawl_failed'] => 'Son tarama başarısız ('.$crawlDate.').',
                    default => 'Son tarama '.$crawlDate.'.',
                },
                'action' => ['label' => $st['crawl_at'] === null ? 'Tara' : 'Şimdi tara', 'click' => 'crawl']],
        ];

        return view('livewire.operator.website.v2.connection-steps-card', ['steps' => $steps, 'done' => count(array_filter($steps, fn (array $s): bool => $s['done']))]);
    }
}
