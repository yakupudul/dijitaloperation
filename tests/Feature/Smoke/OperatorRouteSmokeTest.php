<?php

namespace Tests\Feature\Smoke;

use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\BrandSetupProposal;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\OperatorFile;
use App\Models\Task;
use App\Models\User;
use App\Services\Operations\PageSmokeAudit;
use App\Support\Demo\DemoPeriod;
use App\Support\Operator\LivewireActionErrors;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Livewire;
use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/**
 * Every operator page (root routes and operator.* names) opened as an Admin over a realistic portfolio — active and
 * passive customers, every asset type bound and unbound, a brandless website, collected facts, alerts and tasks — must
 * never answer with a server error. Also opens every
 * model-bound route with junk ids and every page with junk query values, and flags queries that would compare an id
 * column with a non-numeric string (PostgreSQL rejects those; SQLite silently matches nothing).
 */
final class OperatorRouteSmokeTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private User $admin;

    /** @var list<string> */
    private array $pgUnsafe = [];

    private string $context = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'email' => 'smoke@example.test']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->seedPortfolio();
        DB::listen(function (QueryExecuted $query): void {
            if (($problem = self::nonNumericIdComparison($query->sql, $query->bindings)) !== null) {
                $this->pgUnsafe[] = $problem.' @ '.$this->context;
            }
            foreach (ColumnLengthGuard::violations($query->sql, $query->bindings) as $tooLong) {
                $this->pgUnsafe[] = 'too long for the column: '.$tooLong.' @ '.$this->context;
            }
        });
    }

    public function test_every_operator_page_and_tab_opens_without_a_server_error(): void
    {
        $results = app(PageSmokeAudit::class)->run($this->admin, 3);

        $this->assertGreaterThan(150, count($results));
        $this->assertSame([], $this->failures($results));
        $this->assertSame([], array_values(array_unique($this->pgUnsafe)), 'queries PostgreSQL would reject');
    }

    public function test_model_bound_routes_with_junk_ids_answer_without_a_server_error(): void
    {
        $urls = [];
        foreach ($this->operatorRoutes() as $route) {
            if (! str_contains($route->uri(), '{')) {
                continue;
            }
            foreach (['abc', '0', '-1', '99999999', '99999999999999999999999', 'x%20y'] as $junk) {
                $urls[] = [url((string) preg_replace('/\{\w+\??\}/', $junk, $route->uri())), (string) $route->getName()];
            }
        }

        $results = app(PageSmokeAudit::class)->run($this->admin, urls: $urls);

        $this->assertNotEmpty($results);
        $this->assertSame([], $this->failures($results));
        $this->assertSame([], array_values(array_unique($this->pgUnsafe)), 'queries PostgreSQL would reject');
    }

    public function test_pages_with_junk_query_values_answer_without_a_server_error(): void
    {
        $query = http_build_query([
            'tab' => 'olmayan-sekme', 'period' => 'abc', 'range' => '-5', 'from' => 'not-a-date', 'to' => '2026-13-45',
            'month' => '2026-99', 'page' => '-1', 'sort' => 'drop table', 'dir' => 'sideways', 'status' => 'bilinmeyen',
            'brand' => 'abc', 'brandId' => 'abc', 'customer' => 'x', 'customerId' => 'x', 'asset' => 'x', 'assetId' => 'x',
            'q' => str_repeat('ğ', 600), 'search' => "' OR 1=1 --", 'filter' => 'x', 'type' => 'x', 'channel' => 'x',
            'severity' => 'x', 'days' => '0', 'limit' => '-10', 'url' => 'http://', 'date' => '31.02.2026', 'week' => 'x',
        ]);
        // A custom period link with dates that do not parse (hand-edited or old bookmark).
        $custom = http_build_query(['period' => 'custom', 'from' => 'dün', 'to' => '2026-02-31', 'compare_mode' => 'x', 'route' => 'bilinmeyen']);
        $urls = [];
        foreach (app(PageSmokeAudit::class)->urls(1) as [$url, $name]) {
            if (! str_contains($url, '?')) {
                $urls[] = [$url.'?'.$query, $name];
                $urls[] = [$url.'?'.$custom, $name];
            }
        }

        $results = app(PageSmokeAudit::class)->run($this->admin, urls: $urls);

        $this->assertNotEmpty($results);
        $this->assertSame([], $this->failures($results));
        $this->assertSame([], array_values(array_unique($this->pgUnsafe)), 'queries PostgreSQL would reject');
    }

    public function test_pages_with_array_query_values_answer_without_a_server_error(): void
    {
        $keys = ['tab', 'period', 'range', 'from', 'to', 'month', 'page', 'sort', 'dir', 'status', 'brand', 'brandId', 'customer',
            'customerId', 'asset', 'assetId', 'q', 'search', 'filter', 'type', 'channel', 'severity', 'days', 'view', 'url', 'scope', 'show'];
        $query = http_build_query(array_fill_keys($keys, ['x' => ['y']]));
        $urls = [];
        foreach (app(PageSmokeAudit::class)->urls(1) as [$url, $name]) {
            if (! str_contains($url, '?')) {
                $urls[] = [$url.'?'.$query, $name];
            }
        }

        $results = app(PageSmokeAudit::class)->run($this->admin, urls: $urls);

        $this->assertNotEmpty($results);
        $this->assertSame([], $this->failures($results));
        $this->assertSame([], array_values(array_unique($this->pgUnsafe)), 'queries PostgreSQL would reject');
    }

    /**
     * Mounts every operator Livewire page and calls each of its own public actions with edge-case arguments (a
     * missing id, an empty string, a huge number). A refusal (403/404/validation) is fine; any other throw is a 500.
     */
    public function test_livewire_page_actions_never_throw_server_errors(): void
    {
        Bus::fake();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        config(['moxdop.security.require_admin_2fa' => false]);
        $this->actingAs($this->admin);

        $failures = [];
        $called = 0;
        foreach ($this->pageComponents() as [$class, $params]) {
            $methods = collect((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC))
                ->filter(fn (\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $class && ! $m->isStatic()
                    && preg_match('/^(mount|render|boot|booted|hydrate|dehydrate|updating|updated|rules|messages|validationAttributes|placeholder|with|getListeners|__)/', $m->getName()) !== 1
                    && ! str_starts_with($m->getName(), 'get') && ! str_starts_with($m->getName(), '_') && $m->getAttributes(Computed::class) === []);
            $textProperties = collect((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC))
                ->filter(fn (\ReflectionProperty $p): bool => ! $p->isStatic() && $p->getAttributes(Locked::class) === []
                    && $p->getType() instanceof \ReflectionNamedType && $p->getType()->getName() === 'string'
                    && ! in_array($p->getName(), ['tab', 'message', 'messageTone', 'period', 'month', 'sort', 'view'], true))
                ->map(fn (\ReflectionProperty $p): string => $p->getName())->values()->all();
            // Form arrays ($invoice = ['number' => '', …]): their text keys too.
            foreach ((new \ReflectionClass($class))->getDefaultProperties() as $name => $default) {
                $property = new \ReflectionProperty($class, $name);
                if (is_array($default) && $property->isPublic() && ! $property->isStatic() && $property->getAttributes(Locked::class) === []) {
                    foreach ($default as $key => $value) {
                        if (is_string($key) && is_string($value)) {
                            $textProperties[] = $name.'.'.$key;
                        }
                    }
                }
            }
            foreach ($methods as $method) {
                $sets = $this->argumentSets($method);
                // Last pass: every free-text field filled with text longer than any varchar column.
                $sets[] = ['__long__' => true] + ($sets[0] ?? []);
                foreach ($sets as $arguments) {
                    $long = isset($arguments['__long__']);
                    unset($arguments['__long__']);
                    if ($long && $textProperties === []) {
                        continue;
                    }
                    DB::beginTransaction();
                    $level = DB::transactionLevel();
                    try {
                        Livewire::flushState();
                        $component = Livewire::test($class, $params);
                        try {
                            $component->assertOk();
                        } catch (ExpectationFailedException) {
                            continue 3; // the page itself refuses (redirect / 403 / 404) with these params
                        }
                        foreach ($long ? $textProperties : [] as $property) {
                            $component->set($property, str_repeat('Uzun metin ğüşiöç ', 40));
                        }
                        $this->context = class_basename($class).'::'.$method->getName().($long ? ' (long text)' : '');
                        $component->call($method->getName(), ...array_values($arguments));
                        $called++;
                    } catch (ValidationException|AuthorizationException|HttpExceptionInterface) {
                        // a refusal, not a server error
                    } catch (\Throwable $exception) {
                        if (! $this->argumentTypeMismatch($exception, $method)) {
                            $failures[] = class_basename($class).'::'.$method->getName().'('.mb_substr((string) json_encode($arguments), 0, 80).') → '.get_class($exception).': '.mb_substr($exception->getMessage(), 0, 200).' @ '.$this->appFrame($exception);
                        }
                    } finally {
                        while (DB::transactionLevel() >= $level && DB::transactionLevel() > 0) {
                            DB::rollBack();
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(100, $called);
        $this->assertSame([], $failures);
        $this->assertSame([], array_values(array_unique($this->pgUnsafe)), 'queries PostgreSQL would reject');
    }

    /** @return list<array{0: class-string, 1: array<string, mixed>}> page component + mount params, for every operator Livewire route */
    private function pageComponents(): array
    {
        $ids = [
            'brand' => (string) Brand::query()->where('name', 'Atlas Diş')->value('id'),
            'customerId' => (string) Customer::query()->where('name', 'Atlas Sağlık')->value('id'),
            'taskId' => (string) Task::query()->value('id'),
            'provider' => 'anthropic',
        ];
        $ids['brandId'] = $ids['brand'];
        $assetFor = fn (string $uri): ?string => (string) DigitalAsset::query()->whereNotNull('brand_id')->where('type', match (true) {
            str_contains($uri, 'google-ads') => 'google_ads',
            str_contains($uri, 'meta') => 'meta_ads',
            str_contains($uri, 'gbp') => 'google_business_profile',
            default => 'website',
        })->orderBy('id')->value('id');
        $pages = [];
        foreach ($this->operatorRoutes() as $route) {
            $class = $route->getAction('livewire_component');
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            preg_match_all('/\{(\w+)\??\}/', $route->uri(), $m);
            $params = [];
            foreach ($m[1] as $name) {
                $params[$name] = match ($name) {
                    'assetId' => $assetFor($route->uri()),
                    'connector' => str_contains($route->uri(), 'site-connectors') ? 'wordpress' : 'ga4',
                    default => $ids[$name] ?? null,
                };
            }
            if (in_array(null, $params, true)) {
                continue;
            }
            $pages[$class.json_encode($params)] = [$class, $params];
        }

        return array_values($pages);
    }

    /** @return list<list<mixed>> */
    private function argumentSets(\ReflectionMethod $method): array
    {
        $required = array_values(array_filter($method->getParameters(), fn (\ReflectionParameter $p): bool => ! $p->isOptional()));
        if ($required === []) {
            return [[]];
        }
        $sets = [];
        foreach (['missing', 'empty'] as $variant) {
            $arguments = [];
            foreach ($required as $parameter) {
                $type = $parameter->getType();
                $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
                $arguments[] = match (true) {
                    $name === 'int' => ['missing' => 999999, 'empty' => 0, 'huge' => PHP_INT_MAX][$variant],
                    $name === 'float' => ['missing' => 1.5, 'empty' => 0.0, 'huge' => 1e18][$variant],
                    $name === 'bool' => $variant !== 'empty',
                    $name === 'array' => ['missing' => ['x' => 'y'], 'empty' => [], 'huge' => array_fill(0, 3, str_repeat('x', 300))][$variant],
                    default => ['missing' => '999999', 'empty' => '', 'huge' => str_repeat('ğ', 400)][$variant],
                };
            }
            $sets[] = $arguments;
        }

        return $sets;
    }

    /** Our guessed argument did not fit the declared type (a Livewire call would be rejected the same way). */
    private function argumentTypeMismatch(\Throwable $exception, \ReflectionMethod $method): bool
    {
        return $exception instanceof \TypeError && str_contains($exception->getMessage(), $method->getName().'(): Argument #');
    }

    private function appFrame(\Throwable $exception): string
    {
        foreach (array_merge([['file' => $exception->getFile(), 'line' => $exception->getLine()]], $exception->getTrace()) as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file !== '' && ! str_contains($file, '/vendor/') && ! str_contains($file, '/tests/')) {
                return str_replace(base_path().'/', '', $file).':'.($frame['line'] ?? '?');
            }
        }

        return '?';
    }

    public function test_an_action_on_a_missing_record_shows_a_notice_while_a_missing_page_stays_404(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(BrandShow::class, ['brand' => 999999])->assertStatus(404);
        $this->get('/customers/999999')->assertNotFound();
        $this->get('/assets/99999999999999999999999/sources')->assertNotFound();
        $this->get('/brands/abc/setup')->assertNotFound();
    }

    public function test_custom_period_with_dates_that_do_not_parse_falls_back_to_the_default_window(): void
    {
        $bounds = DemoPeriod::bounds('custom', 'dün', 'yarın', null, now(), 'Europe/Istanbul');

        $this->assertSame('last_28', $bounds['preset']);
        $this->assertSame(28, $bounds['days']);
    }

    public function test_postgres_bad_identifier_on_a_read_is_a_missing_record_not_a_server_error(): void
    {
        $pdo = fn (string $code): \PDOException => tap(new \PDOException('invalid input syntax for type bigint'), function (\PDOException $e) use ($code): void {
            (new \ReflectionProperty(\Exception::class, 'code'))->setValue($e, $code);
        });

        $this->assertTrue(LivewireActionErrors::isBadIdentifier(new QueryException('pgsql', 'select * from "brands" where "id" = ?', ['abc'], $pdo('22P02'))));
        $this->assertTrue(LivewireActionErrors::isBadIdentifier(new QueryException('pgsql', 'select * from "brands" where "id" = ?', ['99999999999999999999'], $pdo('22003'))));
        $this->assertFalse(LivewireActionErrors::isBadIdentifier(new QueryException('pgsql', 'insert into "brands" ("id") values (?)', ['abc'], $pdo('22P02'))), 'a bad write stays an error');
        $this->assertFalse(LivewireActionErrors::isBadIdentifier(new QueryException('pgsql', 'select 1', [], $pdo('23505'))));
    }

    public function test_detector_flags_non_numeric_id_comparisons(): void
    {
        $this->assertNotNull(self::nonNumericIdComparison('select * from "brands" where "id" = ? limit 1', ['abc']));
        $this->assertNotNull(self::nonNumericIdComparison('select * from "tasks" where "brand_id" in (?, ?)', [1, 'x']));
        $this->assertNull(self::nonNumericIdComparison('select * from "brands" where "id" = ? and "name" = ?', ['12', 'abc']));
        $this->assertNull(self::nonNumericIdComparison('select * from "brands" where "external_id" = ?', ['act_1']));
    }

    /**
     * A bound string that is not an integer, compared with an integer id column ("id" or "…_id" with an integer key).
     *
     * @param  array<int, mixed>  $bindings
     */
    public static function nonNumericIdComparison(string $sql, array $bindings): ?string
    {
        $offset = 0;
        $index = 0;
        while (($position = strpos($sql, '?', $offset)) !== false) {
            $binding = $bindings[$index] ?? null;
            $before = substr($sql, max(0, $position - 160), min(160, $position));
            if (is_string($binding) && preg_match('/^-?\d+$/', $binding) !== 1
                && preg_match('/(?:^|[\s(."])("?)(id|[a-z_]*_id)\1\s*(?:=|!=|<>|in\s*\((?:\?\s*,\s*)*)\s*$/i', $before, $m) === 1
                && self::isIntegerColumn($sql, strtolower($m[2]))) {
                return $m[2].' ← '.mb_substr($binding, 0, 40).' | '.mb_substr($sql, 0, 160);
            }
            $offset = $position + 1;
            $index++;
        }

        return null;
    }

    /** @var array<string, array<string, string>> table → column → type */
    private static array $columnTypes = [];

    /** The column is an integer column in one of the statement's tables (text ids such as "pack_id" are fine). */
    private static function isIntegerColumn(string $sql, string $column): bool
    {
        preg_match_all('/\b(?:from|join|update|into)\s+"?([a-z0-9_]+)"?/i', $sql, $tables);
        foreach (array_unique($tables[1]) as $table) {
            if (! isset(self::$columnTypes[$table])) {
                self::$columnTypes[$table] = [];
                try {
                    foreach (DB::connection()->getSchemaBuilder()->getColumns($table) as $definition) {
                        self::$columnTypes[$table][strtolower($definition['name'])] = strtolower((string) $definition['type_name']);
                    }
                } catch (\Throwable) {
                    // a view or an unknown table: nothing to check
                }
            }
            if (in_array(self::$columnTypes[$table][$column] ?? '', ['integer', 'bigint', 'int', 'int4', 'int8', 'smallint'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Route> */
    private function operatorRoutes(): array
    {
        return array_values(array_filter(app('router')->getRoutes()->getRoutes(), function (Route $route): bool {
            $name = (string) $route->getName();

            return in_array('GET', $route->methods(), true) && str_starts_with($name, 'operator.')
                && preg_match('/(\.download|\.pdf|\.export|\.kml|\.print|html\.show|whatsapp\.connect|\.authorize|\.callback)$/', $name) !== 1;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<string>
     */
    private function failures(array $results): array
    {
        return collect($results)->filter(fn (array $r): bool => $r['status'] >= 500 || in_array($r['level'], ['error', 'http'], true))
            ->map(fn (array $r): string => $r['url'].' ['.$r['status'].'] → '.$r['error'].' @ '.$r['where'])->values()->all();
    }

    private function seedPortfolio(): void
    {
        $active = Customer::factory()->create(['name' => 'Atlas Sağlık', 'status' => 'active', 'monthly_fee' => 10000]);
        $passive = Customer::factory()->create(['name' => 'Pasif Firma', 'status' => 'inactive']);
        $archived = Customer::factory()->create(['name' => 'Arşiv Firma', 'status' => 'archived']);
        $atlas = Brand::factory()->create(['customer_id' => $active->id, 'name' => 'Atlas Diş']);
        $empty = Brand::factory()->create(['customer_id' => $active->id, 'name' => 'Boş Marka']);
        $sleep = Brand::factory()->create(['customer_id' => $passive->id, 'name' => 'Uyuyan Marka']);
        Brand::factory()->create(['customer_id' => $archived->id, 'name' => 'Arşiv Marka']);

        $google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $meta = CoreIntegration::query()->firstOrCreate(['provider' => 'meta'], ['name' => 'Meta', 'status' => 'active', 'config' => []]);
        $resource = fn (CoreIntegration $integration, string $type, string $externalId, array $meta = []): CoreExternalResource => CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => $integration->provider, 'resource_type' => $type, 'external_id' => $externalId,
            'display_name' => $externalId, 'metadata' => $meta + ['timezone' => 'Europe/Istanbul', 'currency_code' => 'TRY'], 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $bind = fn (DigitalAsset $asset, CoreExternalResource $resource, string $capability) => CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $capability, 'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        $asset = fn (?Brand $brand, string $type, array $extra = []): DigitalAsset => DigitalAsset::factory()->create(array_merge([
            'brand_id' => $brand?->id, 'type' => $type, 'status' => 'active', 'name' => ($brand?->name ?? 'Markasız').' '.$type,
        ], $extra));

        $site = $asset($atlas, 'website', ['domain' => 'atlasdis.test', 'primary_url' => 'https://atlasdis.test/']);
        $gsc = $resource($google, 'search_console', 'sc-domain:atlasdis.test', ['site_url' => 'sc-domain:atlasdis.test']);
        $ga4 = $resource($google, 'ga4', 'properties/123', ['property_id' => '123']);
        $bind($site, $gsc, 'search_console');
        $bind($site, $ga4, 'ga4');
        $ads = $asset($atlas, 'google_ads');
        $adsResource = $resource($google, 'google_ads', '1112223333');
        $bind($ads, $adsResource, 'google_ads');
        $metaAds = $asset($atlas, 'meta_ads');
        $metaResource = $resource($meta, 'meta_ads', 'act_555');
        $bind($metaAds, $metaResource, 'meta_ads');
        $gbp = $asset($atlas, 'google_business_profile');
        $bind($gbp, $resource($google, 'google_business_profile', 'locations/1'), 'google_business_profile');
        foreach (['website', 'google_ads', 'meta_ads', 'google_business_profile', 'search_console', 'ga4'] as $type) {
            $asset($sleep, $type, $type === 'website' ? ['domain' => 'uyuyan.test', 'primary_url' => 'https://uyuyan.test/'] : []);
        }
        $asset($empty, 'google_ads');
        $asset(null, 'website', ['domain' => 'markasiz.test', 'primary_url' => 'https://markasiz.test/']);

        $provenance = fn (array $values): array => $values + [
            'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'source_timezone' => 'Europe/Istanbul',
            'record_fingerprint' => hash('sha256', json_encode($values)), 'created_at' => now(), 'updated_at' => now(),
        ];
        for ($offset = 1; $offset <= 10; $offset++) {
            $day = now()->subDays($offset)->toDateString();
            $this->insertFact('gsc_property_daily', $provenance(['digital_asset_id' => null, 'external_resource_id' => $gsc->id, 'site_url' => 'sc-domain:atlasdis.test', 'reporting_date' => $day, 'clicks' => 10 * $offset, 'impressions' => 400, 'search_type' => 'web', 'metadata' => '{}']));
            $this->insertFact('gsc_query_page_daily', $provenance(['digital_asset_id' => null, 'external_resource_id' => $gsc->id, 'site_url' => 'sc-domain:atlasdis.test', 'reporting_date' => $day, 'query' => 'ankara implant', 'page' => 'https://atlasdis.test/implant/', 'clicks' => 3, 'impressions' => 90]));
            $this->insertFact('ga4_property_daily', $provenance(['digital_asset_id' => null, 'external_resource_id' => $ga4->id, 'property_id' => '123', 'reporting_date' => $day, 'sessions' => $offset === 3 ? 0 : 40, 'engagedSessions' => 20]));
            $this->insertFact('google_ads_account_daily', $provenance(['digital_asset_id' => null, 'external_resource_id' => $adsResource->id, 'customer_id' => '1112223333', 'reporting_date' => $day, 'impressions' => $offset === 2 ? 0 : 1000, 'clicks' => $offset === 2 ? 0 : 50, 'cost_micros' => 0, 'cost_amount' => $offset === 2 ? 0 : 120.5, 'conversions' => 0, 'currency' => 'TRY']));
            $this->insertFact('meta_account_daily', $provenance(['digital_asset_id' => null, 'external_resource_id' => $metaResource->id, 'account_id' => '555', 'reporting_date' => $day, 'spend' => 40, 'impressions' => 0, 'clicks' => 0, 'reach' => 0, 'currency' => 'TRY']));
        }

        AssetAlert::query()->create([
            'digital_asset_id' => $site->id, 'brand_id' => $atlas->id, 'alert_key' => hash('sha256', 'a1'), 'kind' => 'ga4_conversions_drop',
            'severity' => 'high', 'title' => 'Site dönüşümleri düştü', 'message' => 'Son 7 günde 4 dönüşüm.', 'data' => [],
            'first_detected_at' => now()->subDay(), 'last_detected_at' => now(),
        ]);
        Task::factory()->count(2)->create(['brand_id' => $atlas->id, 'customer_id' => $active->id, 'digital_asset_id' => $site->id]);
        OperatorFile::factory()->create();
        BrandSetupProposal::query()->create([
            'brand_id' => $atlas->id, 'status' => BrandSetupProposal::STATUS_READY, 'website_url' => 'https://atlasdis.test/',
            'items' => [['key' => 'asset:website', 'kind' => 'asset', 'group' => 'website', 'label' => 'Web sitesi', 'asset_id' => $site->id, 'status' => 'already']],
            'services' => [['name' => 'İmplant'], ['bozuk']], 'services_status' => 'ready', 'summary' => ['business_context' => ['business_model' => str_repeat('x', 200)]],
        ]);
    }
}
