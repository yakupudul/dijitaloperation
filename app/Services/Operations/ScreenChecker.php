<?php

namespace App\Services\Operations;

use App\Livewire\Operator\Portfolio\BrandShow;
use App\Livewire\Operator\Website\V2\WebsiteScreen;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ScreenCheck;
use App\Models\User;
use App\Support\Roles;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sayfa taraması: renders every operator screen (the menu pages, plus sample brands, customers and one asset of each
 * channel with their tabs) as an active Admin inside the app, and stores per screen the HTTP status, time, query
 * count, the exception and a short text outline (headings, buttons, table columns, notices). Claude reads it over MCP
 * (screen-checks) to find broken, slow or confusing pages without a browser. Read-only: GET requests only.
 */
final class ScreenChecker
{
    /** Slower than this (ms) or more queries than QUERY_LIMIT is reported as a performance finding. */
    public const int SLOW_MS = 3000;

    public const int QUERY_LIMIT = 150;

    private int $queries = 0;

    private bool $listening = false;

    private const array STATIC_ROUTES = [
        'operator.dashboard' => 'Bugün', 'operator.work' => 'Genel işler', 'operator.gbp-posts' => 'İşletme gönderileri', 'operator.customers' => 'Müşteriler', 'operator.brands' => 'Markalar',
        'operator.assets' => 'Dijital varlıklar', 'operator.websites' => 'Web siteleri', 'operator.library.queries' => 'Sorgular',
        'operator.library.queries.plan' => 'Sorgular › AI ile planla', 'operator.library.services' => 'Sektör ve hizmet kataloğu',
        'operator.library.website-standards' => 'Standartlar', 'operator.integrations' => 'Entegrasyonlar',
        'operator.integrations.discovered' => 'Keşfedilen varlıklar', 'operator.integrations.wordpress-sites' => 'WordPress siteleri',
        'operator.integrations.google' => 'Google bağlantısı', 'operator.integrations.meta' => 'Meta bağlantısı',
        'operator.integrations.dataforseo' => 'DataForSEO', 'operator.integrations.website-duplicates' => 'Kopya web siteleri',
        'operator.data-center' => 'Veri merkezi', 'operator.ai-jobs' => 'AI işleri', 'operator.settings' => 'Ayarlar',
        'operator.settings.ai-operations' => 'AI işlemleri', 'operator.settings.system-health' => 'Sistem',
        'operator.settings.users' => 'Kullanıcılar', 'operator.settings.sector-packs' => 'Sektör paketleri',
        'operator.settings.improvements' => 'Geliştirme havuzu', 'operator.files' => 'Dosyalar', 'operator.profile' => 'Profil',
    ];

    /** Asset type => [route, label]. */
    private const array ASSET_ROUTES = [
        'search_console' => ['operator.search-console', 'Search Console'], 'ga4' => ['operator.analytics', 'Google Analytics'],
        'google_business_profile' => ['operator.gbp', 'İşletme Profili'], 'google_ads' => ['operator.google-ads.overview', 'Google Ads'],
        'meta_ads' => ['operator.meta.overview', 'Meta Ads'],
    ];

    /** @return list<array{path: string, label: string}> */
    public function screens(): array
    {
        $out = [];
        foreach (self::STATIC_ROUTES as $name => $label) {
            if (app('router')->has($name)) {
                $out[] = ['path' => route($name, [], false), 'label' => $label];
            }
        }
        foreach (Brand::query()->operational()->orderBy('id')->limit(5)->get() as $index => $brand) {
            $out[] = ['path' => route('operator.brand', $brand, false), 'label' => 'Marka · '.$brand->name];
            if ($index === 0) {
                foreach (array_keys(BrandShow::TABS) as $tab) {
                    if ($tab !== 'ozet') {
                        $out[] = ['path' => route('operator.brand', ['brand' => $brand, 'tab' => $tab], false), 'label' => 'Marka · '.$brand->name.' › '.BrandShow::TABS[$tab]];
                    }
                }
                $out[] = ['path' => route('operator.brand.setup', $brand, false), 'label' => 'Marka · '.$brand->name.' › Kurulum'];
            }
        }
        foreach (Customer::query()->orderBy('id')->limit(3)->get() as $customer) {
            $out[] = ['path' => route('operator.customer', ['customerId' => $customer->id], false), 'label' => 'Müşteri · '.$customer->name];
        }
        $site = DigitalAsset::query()->operational()->where('type', 'website')->orderBy('id')->first();
        if ($site !== null) {
            foreach (WebsiteScreen::TABS as $tab => $tabLabel) {
                $out[] = ['path' => route('operator.website', ['assetId' => $site->id, 'tab' => $tab], false), 'label' => 'Web sitesi · '.$site->domain.' › '.$tabLabel];
            }
            $out[] = ['path' => route('operator.asset.sources', ['assetId' => $site->id], false), 'label' => 'Veri kaynakları · '.$site->domain];
        }
        foreach (self::ASSET_ROUTES as $type => [$route, $label]) {
            $asset = DigitalAsset::query()->operational()->where('type', $type)->orderBy('id')->first();
            if ($asset !== null && app('router')->has($route)) {
                $out[] = ['path' => route($route, ['assetId' => $asset->id], false), 'label' => $label.' · '.($asset->name ?: '#'.$asset->id)];
            }
        }

        return $out;
    }

    /** Renders every screen and stores the result; returns the number of failed screens. */
    public function run(): int
    {
        $admin = User::query()->where('is_active', true)->role(Roles::ADMIN)->orderBy('id')->first();
        if ($admin === null) {
            return 0;
        }
        config(['moxdop.security.require_admin_2fa' => false]);
        $failed = 0;
        $seen = [];
        foreach ($this->screens() as $screen) {
            $check = $this->check($screen['path'], $admin);
            ScreenCheck::query()->updateOrCreate(['path' => $screen['path']], ['label' => mb_substr($screen['label'], 0, 200)] + $check);
            $seen[] = $screen['path'];
            $failed += ($check['status'] >= 400 || $check['error'] !== null) ? 1 : 0;
        }
        ScreenCheck::query()->whereNotIn('path', $seen)->where('checked_at', '<', now()->subDays(7))->delete();

        return $failed;
    }

    /** @return array{status: int, duration_ms: int, queries: int, error: ?string, outline: ?string, release: ?string, checked_at: Carbon} */
    public function check(string $path, User $admin): array
    {
        if (! $this->listening) {
            DB::listen(function (): void {
                $this->queries++;
            });
            $this->listening = true;
        }
        $this->queries = 0;
        $kernel = app(HttpKernel::class);
        $started = hrtime(true);
        $status = 0;
        $error = null;
        $html = '';
        $original = app()->bound('request') ? app('request') : null;
        try {
            Auth::guard('web')->setUser($admin);
            $request = Request::create($path, 'GET');
            $request->setUserResolver(fn () => $admin);
            $response = $kernel->handle($request);
            $status = $response->getStatusCode();
            $html = (string) $response->getContent();
            $exception = $response->exception ?? null;
            if ($exception instanceof Throwable) {
                $error = $this->describe($exception);
            }
            $kernel->terminate($request, $response);
        } catch (Throwable $exception) {
            $status = 500;
            $error = $this->describe($exception);
        } finally {
            if ($original !== null) {
                app()->instance('request', $original);
            }
        }
        $ms = (int) round((hrtime(true) - $started) / 1_000_000);

        return [
            'status' => $status, 'duration_ms' => $ms, 'queries' => $this->queries, 'error' => $error,
            'outline' => $status < 300 ? $this->outline($html) : null, 'release' => ReleaseInfo::shortSha(), 'checked_at' => now(),
        ];
    }

    private function describe(Throwable $exception): string
    {
        $file = str_replace(base_path().'/', '', $exception->getFile());

        return mb_substr(class_basename($exception).': '.$exception->getMessage().' @ '.$file.':'.$exception->getLine(), 0, 1000);
    }

    /** A short Markdown outline of the page's main area: headings, notices, table columns, buttons, empty states. */
    public function outline(string $html): ?string
    {
        if (trim($html) === '') {
            return null;
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $main = $xpath->query('//main')->item(0) ?? $xpath->query('//body')->item(0);
        if ($main === null) {
            return null;
        }
        $text = fn ($node): string => trim((string) preg_replace('/\s+/u', ' ', (string) $node->textContent));
        $lines = [];
        foreach ($xpath->query('.//h1|.//h2|.//h3|.//*[@role="status"]|.//*[@role="alert"]|.//table|.//button|.//select', $main) as $node) {
            $name = strtolower($node->nodeName);
            if ($name === 'table') {
                $heads = [];
                foreach ($xpath->query('.//th', $node) as $th) {
                    $heads[] = $text($th);
                }
                $rows = $xpath->query('.//tbody/tr', $node)->length;
                $lines[] = '- Tablo ('.$rows.' satır): '.implode(' | ', array_filter($heads));
            } elseif (in_array($name, ['h1', 'h2', 'h3'], true)) {
                $lines[] = str_repeat('#', (int) $name[1]).' '.mb_substr($text($node), 0, 120);
            } elseif ($name === 'button') {
                $label = mb_substr($text($node), 0, 60);
                if ($label !== '') {
                    $lines[] = '- [Buton] '.$label;
                }
            } elseif ($name === 'select') {
                $lines[] = '- [Seçim] '.mb_substr((string) ($node->getAttribute('aria-label') ?: $text($node)), 0, 60);
            } else {
                $lines[] = '> '.mb_substr($text($node), 0, 200);
            }
        }
        $lines = array_values(array_unique($lines));
        $lines[] = '_Metin uzunluğu: '.mb_strlen($text($main)).' karakter_';

        return mb_substr(implode("\n", $lines), 0, 6000);
    }
}
