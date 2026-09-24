<?php

namespace App\Services\Operations;

use App\Models\DigitalAsset;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Throwable;

/**
 * Opens every operator page from inside the application as one user, with real data: each page without
 * parameters, each asset page for a few assets of every type and every tab, and detail pages of brands,
 * customers and prospects. Nothing leaves the server: jobs, mail and notifications are faked, outside HTTP
 * calls are blocked (and reported), and each request runs in a database transaction that is rolled back.
 */
final class PageSmokeAudit
{
    /** Routes that download files, print, start OAuth or change state even on GET. */
    private const SKIP_NAME = '/(\.download|\.pdf|\.export|\.kml|\.print|html\.show|whatsapp\.connect|\.convert|work\.show|\.authorize|\.callback|calendar\.feed|logout)$/';

    /** Asset page URI segment → asset types it shows. */
    private const ASSET_SEGMENTS = [
        'analytics' => ['ga4', 'analytics', 'google_analytics'],
        'gbp' => ['google_business_profile', 'gbp'],
        'google-ads' => ['google_ads'],
        'meta' => ['meta_ads'],
        'search-console' => ['gsc', 'search_console'],
        'website' => ['website'],
        'domain' => ['domain'],
        'hosting' => ['hosting'],
        'instagram' => ['instagram'],
    ];

    /** Tables whose writes during a page view are expected (sessions, cache) and not reported. */
    private const QUIET_TABLES = ['sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches'];

    /** @var list<string> */
    private array $writes = [];

    /** @var list<string> */
    private array $httpHosts = [];

    /** Laravel's redirector; a page that fails mid-mount leaves Livewire's own bound for the next request. */
    private mixed $redirector = null;

    /**
     * @param  callable(array<string, mixed>): void|null  $progress
     * @return list<array{url: string, route: string, status: int, ms: int, level: string, error: ?string, where: ?string, writes: list<string>, http: list<string>}>
     */
    public function run(User $user, int $perType = 3, bool $allowHttp = false, bool $useTransaction = true, ?string $only = null, ?callable $progress = null): array
    {
        config(['moxdop.security.require_admin_2fa' => false]);
        Bus::fake();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        if (! $allowHttp) {
            Http::preventStrayRequests();
        }
        Event::listen(RequestSending::class, function (RequestSending $event): void {
            $this->httpHosts[] = (string) parse_url($event->request->url(), PHP_URL_HOST);
        });
        DB::listen(function (QueryExecuted $query): void {
            if (preg_match('/^\s*(insert\s+into|update|delete\s+from)\s+["`]?([a-z0-9_]+)/i', $query->sql, $m) === 1
                && ! in_array(strtolower($m[2]), self::QUIET_TABLES, true)) {
                $this->writes[] = strtolower(explode(' ', $m[1])[0]).' '.strtolower($m[2]);
            }
        });

        $this->redirector = app('redirect');
        $results = [];
        foreach ($this->urls($perType) as [$url, $name]) {
            if ($only !== null && ! str_contains($url, $only) && ! str_contains($name, $only)) {
                continue;
            }
            $result = $this->visit($user, $url, $name, $useTransaction);
            $results[] = $result;
            if ($progress !== null) {
                $progress($result);
            }
        }

        return $results;
    }

    /** @return list<array{0: string, 1: string}> url + route name */
    public function urls(int $perType): array
    {
        $urls = [];
        $ids = fn (string $table, int $limit): array => Schema::hasTable($table)
            ? DB::table($table)->orderByDesc('id')->limit($limit)->pluck('id')->map(fn ($id): string => (string) $id)->all() : [];
        $assetsByType = DigitalAsset::query()->orderByDesc('id')->get(['id', 'type'])->groupBy('type')
            ->map(fn ($group) => $group->take($perType)->pluck('id')->map(fn ($id): string => (string) $id)->all());

        /** @var Route $route */
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            if (! in_array('GET', $route->methods(), true) || $name === '' || preg_match(self::SKIP_NAME, $name) === 1
                || ! collect($route->gatherMiddleware())->contains(fn ($m): bool => is_string($m) && str_contains($m, 'EnsureDemoAppAccess'))) {
                continue;
            }
            $uri = $route->uri();
            preg_match_all('/\{(\w+)(\?)?\}/', $uri, $params, PREG_SET_ORDER);
            $required = array_values(array_filter($params, fn (array $p): bool => ($p[2] ?? '') === ''));
            $tabs = $this->tabs($route);

            if ($params === []) {
                $this->push($urls, $name, url($uri), $tabs);

                continue;
            }
            $names = array_column($params, 1);
            if ($names === ['assetId']) {
                $segment = preg_match('#^(?:integrations/)?(?:assets/)?([a-z-]+)/\{assetId#', $uri, $m) === 1 ? $m[1] : null;
                $types = $segment !== null && isset(self::ASSET_SEGMENTS[$segment]) ? self::ASSET_SEGMENTS[$segment] : $assetsByType->keys()->all();
                if (str_starts_with($uri, 'integrations/website')) {
                    $types = ['website'];
                }
                if ($required === [] && $segment !== null && isset(self::ASSET_SEGMENTS[$segment])) {
                    $this->push($urls, $name, url(preg_replace('#/\{assetId\?\}#', '', $uri)), []);
                }
                foreach ($types as $type) {
                    foreach ($assetsByType->get($type, []) as $assetId) {
                        $this->push($urls, $name, url(str_replace(['{assetId?}', '{assetId}'], $assetId, $uri)), $tabs);
                    }
                }

                continue;
            }
            $fill = match ($names) {
                ['brand'], ['brandId'] => $ids('brands', $perType),
                ['customerId'] => $ids('customers', $perType),
                ['prospectId'] => $ids('prospects', $perType),
                ['profileId'] => $ids('sales_search_profiles', $perType),
                ['signalId'] => $ids('sales_intent_signals', $perType),
                ['report'] => $ids('monthly_reports', 1),
                ['provider'] => ['anthropic', 'openai', 'gemini', 'groq', 'openrouter'],
                ['connector'] => str_contains($uri, 'site-connectors') ? ['wordpress'] : ['ga4', 'gsc', 'google-ads', 'gbp'],
                default => [],
            };
            foreach ($fill as $value) {
                $this->push($urls, $name, url((string) preg_replace('/\{\w+\??\}/', $value, $uri)), $tabs);
            }
        }

        return $urls;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $urls
     * @param  list<string>  $tabs
     */
    private function push(array &$urls, string $name, string $url, array $tabs): void
    {
        $urls[] = [$url, $name];
        foreach ($tabs as $tab) {
            $urls[] = [$url.'?tab='.urlencode($tab), $name];
        }
    }

    /** @return list<string> the page's own tabs (public $allowedTabs), without the default first one */
    private function tabs(Route $route): array
    {
        $class = $route->getAction('livewire_component');
        if (! is_string($class) || ! class_exists($class)) {
            return [];
        }
        try {
            $defaults = (new \ReflectionClass($class))->getDefaultProperties();
        } catch (Throwable) {
            return [];
        }
        $tabs = $defaults['allowedTabs'] ?? [];

        return is_array($tabs) ? array_values(array_slice(array_filter($tabs, 'is_string'), 1)) : [];
    }

    /** @return array{url: string, route: string, status: int, ms: int, level: string, error: ?string, where: ?string, writes: list<string>, http: list<string>} */
    private function visit(User $user, string $url, string $name, bool $useTransaction): array
    {
        $this->writes = [];
        $this->httpHosts = [];
        // One process, many requests: reset what a browser request would start fresh with.
        Livewire::flushState();
        if ($this->redirector !== null) {
            app()->instance('redirect', $this->redirector);
        }
        $kernel = app(Kernel::class);
        Auth::guard('web')->setUser($user);
        $request = Request::create($url, 'GET');
        $started = microtime(true);
        $error = null;
        $where = null;
        $status = 0;
        $level = DB::transactionLevel();
        if ($useTransaction) {
            DB::beginTransaction();
        }
        try {
            $response = $kernel->handle($request);
            $status = $response->getStatusCode();
            $exception = $response->exception ?? null;
            if ($exception instanceof Throwable) {
                [$error, $where] = $this->describe($exception);
            }
            $kernel->terminate($request, $response);
        } catch (Throwable $exception) {
            $status = 500;
            [$error, $where] = $this->describe($exception);
        } finally {
            if ($useTransaction) {
                try {
                    while (DB::transactionLevel() > $level) {
                        DB::rollBack();
                    }
                } catch (Throwable) {
                    DB::reconnect();
                }
            }
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        $blockedHttp = $error !== null && str_contains($error, 'Attempted request to');
        $outcome = match (true) {
            $status >= 500 && $blockedHttp => 'http',
            $status >= 500 => 'error',
            $status === 403 => 'forbidden',
            $status === 404 => 'missing',
            $ms > 5000 => 'slow',
            default => 'ok',
        };

        return [
            'url' => ((string) preg_replace('#^https?://[^/]+#', '', $url)) ?: '/', 'route' => $name, 'status' => $status, 'ms' => $ms, 'level' => $outcome,
            'error' => $error, 'where' => $where,
            'writes' => array_values(array_unique($this->writes)), 'http' => array_values(array_unique(array_filter($this->httpHosts))),
        ];
    }

    /** @return array{0: string, 1: ?string} message (first line, trimmed) and the first frame inside app/ */
    private function describe(Throwable $exception): array
    {
        $root = $exception;
        while ($root->getPrevious() !== null && ! str_contains($root->getMessage(), 'Attempted request to')) {
            $root = $root->getPrevious();
        }
        $message = get_class($root).': '.mb_substr(strtok($root->getMessage(), "\n") ?: '', 0, 300);
        $where = null;
        foreach (array_merge([['file' => $root->getFile(), 'line' => $root->getLine()]], $root->getTrace()) as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file !== '' && ! str_contains($file, '/vendor/') && (str_contains($file, '/app/') || str_contains($file, '/resources/views/') || str_contains($file, '/app-modules/') || str_contains($file, '/storage/framework/views/'))) {
                $where = str_replace(base_path().'/', '', $file).':'.($frame['line'] ?? '?');
                // Compiled Blade: name the source template (Laravel appends /**PATH … ENDPATH**/).
                if (str_contains($file, '/storage/framework/views/') && is_readable($file)
                    && preg_match('#/\*\*PATH (.+?) ENDPATH\*\*/#', (string) file_get_contents($file, false, null, max(0, (int) filesize($file) - 600)), $m) === 1) {
                    $where = str_replace(base_path().'/', '', $m[1]).' (derlenmiş satır '.($frame['line'] ?? '?').')';
                }
                break;
            }
        }

        return [$message, $where];
    }
}
