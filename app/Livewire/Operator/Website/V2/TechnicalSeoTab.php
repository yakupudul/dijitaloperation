<?php

namespace App\Livewire\Operator\Website\V2;

use App\Livewire\Operator\Website\V2\Concerns\WebsiteTab;
use App\Services\Gsc\UrlInspectionTargets;
use App\Services\Site\Analysis\TechnicalSeoReader;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Web sitesi › Teknik SEO: tiles (Kritik · Uyarı · Bilgi · Google doğruluyor), the Google index bar, filters (kaynak,
 * önem) and two lists — "Google'ın bildirdikleri" (Search Console) and "Sitenin HTML'inde bulduklarımız" (crawl). Each
 * row says why and the one thing to do, and whether WordPress or a developer fixes it; a row opens a drawer (ne oluyor ·
 * nasıl düzeltilir · etkilenen sayfalar). Site sağlığı (HealthTab) renders below it on the screen. Read only.
 */
final class TechnicalSeoTab extends Component
{
    use WebsiteTab;

    #[Url(as: 'kaynak')]
    public string $source = '';

    #[Url(as: 'onem')]
    public string $severity = '';

    public string $open = '';

    public function show(string $key): void
    {
        $this->open = $key;
    }

    /** "Şimdi denetle": today's Search Console URL Inspection batch (Google API, no AI). */
    public function inspectNow(UrlInspectionTargets $targets): void
    {
        $this->actor();
        try {
            $run = $targets->start($this->site());
            $this->message = $run !== null ? 'Google URL denetimi başladı ('.count((array) data_get($run->request_context, 'context.url_inspection_targets')).' sayfa); sonuçlar birkaç dakika içinde burada.' : 'Bugün denetlenecek sayfa yok ya da Search Console bağlı değil.';
        } catch (\Throwable $e) {
            $this->message = 'Denetim başlatılamadı: '.$e->getMessage();
        }
    }

    public function close(): void
    {
        $this->open = '';
    }

    public function render(TechnicalSeoReader $reader): View
    {
        $site = $this->site();
        $data = $reader->read($site);
        $source = array_key_exists($this->source, TechnicalSeoReader::SOURCES) ? $this->source : '';
        $severity = array_key_exists($this->severity, TechnicalSeoReader::SEVERITIES) ? $this->severity : '';
        $keep = fn (array $finding): bool => $severity === '' || $finding['severity'] === $severity;
        $lists = [];
        foreach (TechnicalSeoReader::SOURCES as $key => $label) {
            if ($source === '' || $source === $key) {
                $lists[$key] = array_values(array_filter($data[$key], $keep));
            }
        }

        return view('livewire.operator.website.v2.technical-seo-tab', [
            'site' => $site,
            'data' => $data,
            'lists' => $lists,
            'activeSource' => $source,
            'activeSeverity' => $severity,
            'opened' => collect([...$data['google'], ...$data['html']])->firstWhere('key', $this->open),
        ]);
    }
}
