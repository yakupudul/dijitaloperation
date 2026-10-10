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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sayfa taraması: renders every operator screen (the menu pages, plus sample brands, customers and one asset of each
 * channel with their tabs) as an active Admin inside the app, and stores per screen the HTTP status, time, query
 * count, the database time with the slowest query patterns (and the app code that ran them), the exception and a
 * short text outline (headings, buttons, table columns, notices). Claude reads it over MCP (screen-checks) to find
 * broken, slow or confusing pages without a browser. Read-only: GET requests only.
 */
final class ScreenChecker
{
    /** Slower than this (ms) or more queries than QUERY_LIMIT is reported as a performance finding. */
    public const int SLOW_MS = 3000;

    public const int QUERY_LIMIT = 150;

    /** How many of a screen's query patterns are kept, slowest (total time) first. */
    public const int SLOW_PATTERNS = 5;

    private int $queries = 0;

    private float $dbMs = 0.0;

    /** @var array<string, array{count: int, ms: float, frame: ?string}> normalized SQL => totals of the screen being checked */
    private array $patterns = [];

    /** @var array<string, string> compiled Blade file => its source path */
    private array $views = [];

    private bool $listening = false;

    /** Only while a screen renders: the listener of an earlier checker in a long-lived worker stays silent. */
    private bool $recording = false;

    private const array STATIC_ROUTES = [
        'operator.dashboard' => 'Bugün', 'operator.repair' => 'Onarım masası', 'operator.gbp-desk' => 'İşletme profilleri', 'operator.gbp-posts' => 'İşletme profilleri › Gönderiler',
        'operator.gbp-branch-pages' => 'İşletme profilleri › Şube sayfaları', 'operator.gbp-profile-fields' => 'İşletme profilleri › Açıklama ve saatler',
        'operator.gbp-photos' => 'İşletme profilleri › Fotoğraflar', 'operator.gbp-reviews' => 'İşletme profilleri › Yorumlar', 'operator.customers' => 'Müşteriler', 'operator.brands' => 'Markalar',
        'operator.assets' => 'Dijital varlıklar', 'operator.websites' => 'Web siteleri', 'operator.library.queries' => 'Sorgular',
        'operator.library.queries.plan' => 'Sorgular › AI ile planla', 'operator.library.services' => 'Sektör ve hizmet kataloğu',
        'operator.library.website-standards' => 'Standartlar', 'operator.integrations' => 'Entegrasyonlar',
        'operator.integrations.discovered' => 'Keşfedilen varlıklar', 'operator.integrations.wordpress-sites' => 'WordPress siteleri',
        'operator.integrations.google' => 'Google bağlantısı', 'operator.integrations.meta' => 'Meta bağlantısı',
        'operator.integrations.dataforseo' => 'DataForSEO', 'operator.integrations.bing' => 'Bing Webmaster', 'operator.integrations.website-duplicates' => 'Kopya web siteleri',
        'operator.data-center' => 'Veri merkezi', 'operator.ai-jobs' => 'AI işleri', 'operator.settings' => 'Ayarlar',
        'operator.settings.ai-operations' => 'AI işlemleri', 'operator.settings.system-health' => 'Sistem',
        'operator.settings.users' => 'Kullanıcılar', 'operator.settings.sector-packs' => 'Sektör paketleri',
        'operator.settings.improvements' => 'Geliştirme havuzu', 'operator.settings.releases' => 'Sürümler', 'operator.files' => 'Dosyalar', 'operator.profile' => 'Profil',
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

    /**
     * @return array{status: int, duration_ms: int, queries: int, db_ms: int, slow_queries: list<array{sql: string, count: int, ms: float, frame: ?string}>,
     *     error: ?string, outline: ?string, release: ?string, checked_at: Carbon}
     */
    public function check(string $path, User $admin): array
    {
        if (! $this->listening) {
            DB::listen(function (QueryExecuted $query): void {
                if ($this->recording) {
                    $this->record($query);
                }
            });
            $this->listening = true;
        }
        $this->queries = 0;
        $this->dbMs = 0.0;
        $this->patterns = [];
        $this->recording = true;
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
            $this->recording = false;
            if ($original !== null) {
                app()->instance('request', $original);
            }
        }
        $ms = (int) round((hrtime(true) - $started) / 1_000_000);

        return [
            'status' => $status, 'duration_ms' => $ms, 'queries' => $this->queries, 'db_ms' => (int) round($this->dbMs), 'slow_queries' => $this->slowQueries(),
            'error' => $error, 'outline' => $status < 300 ? $this->outline($html) : null, 'release' => ReleaseInfo::shortSha(), 'checked_at' => now(),
        ];
    }

    /**
     * One query shape per pattern: string and number literals become ?, IN lists and multi-row VALUES collapse to
     * one (?, …), whitespace is single.
     */
    public static function normalize(string $sql): string
    {
        $replacements = [
            "/'(?:[^']++|'')*+'/" => '?',
            '/(?<![\w."$])\d+(?:\.\d+)?\b/' => '?',
            '/\(\s*\?(?:\s*,\s*\?)*\s*\)/' => '(?, …)',
            '/\(\?, …\)(?:\s*,\s*\(\?, …\))+/u' => '(?, …)',
            '/\s+/' => ' ',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $sql = preg_replace($pattern, $replacement, $sql) ?? $sql;
        }

        return trim($sql);
    }

    private function record(QueryExecuted $query): void
    {
        $this->queries++;
        $this->dbMs += (float) $query->time;
        $pattern = self::normalize($query->sql);
        $this->patterns[$pattern] ??= ['count' => 0, 'ms' => 0.0, 'frame' => $this->appFrame()];
        $this->patterns[$pattern]['count']++;
        $this->patterns[$pattern]['ms'] += (float) $query->time;
    }

    /** @return list<array{sql: string, count: int, ms: float, frame: ?string}> */
    private function slowQueries(): array
    {
        $patterns = $this->patterns;
        uasort($patterns, fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
        $out = [];
        foreach (array_slice($patterns, 0, self::SLOW_PATTERNS, true) as $sql => $totals) {
            $out[] = ['sql' => mb_substr((string) $sql, 0, 300), 'count' => $totals['count'], 'ms' => round($totals['ms'], 1), 'frame' => $totals['frame']];
        }

        return $out;
    }

    /**
     * The innermost application code on the stack (file:line relative to the project; a compiled Blade view as its
     * source file). vendor/ and this class are skipped; the search stops at check(), so what started the scan (job,
     * command) is never reported for a query only the framework ran.
     */
    private function appFrame(): ?string
    {
        $base = base_path().DIRECTORY_SEPARATOR;
        $compiled = rtrim((string) config('view.compiled'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? null) === self::class && ($frame['function'] ?? null) === 'check') {
                break;
            }
            $file = (string) ($frame['file'] ?? '');
            if ($file === '' || $file === __FILE__) {
                continue;
            }
            if ($compiled !== DIRECTORY_SEPARATOR && str_starts_with($file, $compiled)) {
                return $this->viewSource($file);
            }
            if (! str_starts_with($file, $base) || str_starts_with($file, $base.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            return substr($file, strlen($base)).':'.(int) ($frame['line'] ?? 0);
        }

        return null;
    }

    /**
     * A compiled view's Blade source (from the PATH footer the compiler appends), relative to the project. The read
     * never throws: a view recompiled or cleared under the scan would otherwise fail the screen from inside the
     * query listener and be reported as the screen's error.
     */
    private function viewSource(string $compiled): string
    {
        if (! isset($this->views[$compiled])) {
            $source = $compiled;
            try {
                $size = (int) filesize($compiled);
                $tail = (string) file_get_contents($compiled, false, null, max(0, $size - 1024));
                $source = preg_match('#/\*\*PATH (.+?) ENDPATH\*\*/#', $tail, $match) === 1 ? $match[1] : $compiled;
            } catch (Throwable) {
                // The compiled file went away; the compiled path is still a usable pointer.
            }
            $this->views[$compiled] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $source);
        }

        return $this->views[$compiled];
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
