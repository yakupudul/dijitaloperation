<?php

namespace App\Services\Site\Competitors;

use App\Ai\Agents\Site\CompetitorClassifyAgent;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Site\SiteDomains;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Class of each SERP result domain: kendi site (the brand's own domains) · dizin / haber / bilgi (config lists) · a
 * stored class (`competitor_domains`, classified once) · the rest in batched AI calls (`competitors.classify`). A domain
 * the AI cannot place stays unclassified (null).
 */
final class CompetitorClassifier
{
    public const string OWN = 'kendi';

    public const array LABELS = ['kendi' => 'kendi site', 'ticari' => 'ticari rakip', 'bilgi' => 'bilgi rakibi', 'dizin' => 'dizin', 'haber' => 'haber'];

    private const int BATCH = 60;

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    /**
     * @param  array<string, list<array{url: string, title: ?string}>>  $domains  host => sample results
     * @param  list<string>  $ownDomains  registrable domains of the brand's websites
     * @return array<string, ?string> host => class
     */
    public function classify(array $domains, array $ownDomains, ?string $sector): array
    {
        $out = [];
        $unknown = [];
        $stored = DB::table('competitor_domains')->whereIn('domain', array_keys($domains) ?: [''])->pluck('class', 'domain')->all();
        foreach ($domains as $host => $samples) {
            $host = (string) $host;
            $out[$host] = match (true) {
                SiteDomains::isOwn($host, $ownDomains) => self::OWN,
                SiteDomains::inList($host, (array) config('moxdop-site.competitors.directory_domains', [])) => 'dizin',
                SiteDomains::inList($host, (array) config('moxdop-site.competitors.news_domains', [])) => 'haber',
                SiteDomains::inList($host, (array) config('moxdop-site.competitors.info_domains', [])) => 'bilgi',
                isset($stored[$host]) => (string) $stored[$host],
                default => null,
            };
            if ($out[$host] === null) {
                $unknown[$host] = array_slice($samples, 0, 3);
            }
        }
        foreach (array_chunk($unknown, self::BATCH, true) as $batch) {
            foreach ($this->ask($batch, $sector) as $host => $row) {
                $out[$host] = $row['class'];
                DB::table('competitor_domains')->updateOrInsert(['domain' => $host], [
                    'class' => $row['class'], 'method' => 'ai', 'reason' => mb_substr($row['reason'], 0, 240), 'updated_at' => now(), 'created_at' => now(),
                ]);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<array{url: string, title: ?string}>>  $batch
     * @return array<string, array{class: string, reason: string}>
     */
    private function ask(array $batch, ?string $sector): array
    {
        try {
            $route = $this->routes->resolve(CompetitorClassifyAgent::OPERATION);
            if ($route->isEmpty()) {
                return [];
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $payload = ['sector' => $sector, 'domains' => []];
            foreach ($batch as $host => $samples) {
                $payload['domains'][] = ['domain' => $host, 'results' => $samples];
            }
            $structured = (new CompetitorClassifyAgent)->prompt(
                "DATA_JSON\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 180,
            )->toArray();
        } catch (Throwable $error) {
            Log::warning('site.competitors.classify_failed', ['error' => $error->getMessage()]);

            return [];
        }
        $out = [];
        foreach ((array) ($structured['domains'] ?? []) as $row) {
            $host = mb_strtolower(trim((string) ($row['domain'] ?? '')));
            $class = (string) ($row['class'] ?? '');
            if (isset($batch[$host]) && in_array($class, CompetitorClassifyAgent::CLASSES, true)) {
                $out[$host] = ['class' => $class, 'reason' => trim((string) ($row['reason'] ?? ''))];
            }
        }

        return $out;
    }
}
