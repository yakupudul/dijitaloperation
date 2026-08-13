<?php

namespace App\Livewire\Demo\Integrations;

use App\Support\Demo\DemoState;
use App\Support\Demo\SiteConnectorFixtures;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('operator.layouts.app')]
#[Title('Site Connector')]
class SiteConnectorShow extends Component
{
    public string $connector = 'wordpress';

    #[Url(as: 'tab', history: true)]
    public string $tab = 'overview';

    /**
     * @var list<string>
     */
    private const TABS = ['overview', 'releases', 'install', 'connected', 'activity'];

    public function mount(string $connector): void
    {
        $this->connector = $connector;

        if (SiteConnectorFixtures::connector($connector) === null) {
            abort(404);
        }

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    public function downloadDemoPackage(): StreamedResponse
    {
        $absolute = SiteConnectorFixtures::ensureDemoZip();
        $filename = SiteConnectorFixtures::demoZipDownloadName();

        return response()->streamDownload(function () use ($absolute): void {
            $stream = fopen($absolute, 'rb');
            if ($stream === false) {
                return;
            }
            fpassthru($stream);
            fclose($stream);
        }, $filename, [
            'Content-Type' => 'application/zip',
            'X-MoxDOP-Package' => 'DEMO CONNECTOR PACKAGE — NOT PRODUCTION INSTALLABLE',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function render(): View
    {
        $data = SiteConnectorFixtures::connector($this->connector);
        abort_if($data === null, 404);

        return view('livewire.demo.integrations.site-connector-show', [
            'data' => $data,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
