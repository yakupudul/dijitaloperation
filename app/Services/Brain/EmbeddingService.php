<?php

namespace App\Services\Brain;

use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\SeoTasks\SeoText;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;
use Throwable;

/**
 * Text embeddings for the Brain (query ↔ service similarity, clustering). Vectors are cached by text + model, stored
 * unit-length so cosine similarity is a dot product. Returns null when no embedding-capable provider is usable
 * (no key or AI budget exhausted); callers then fall back to rules only. Cost is recorded like any AI call.
 */
final class EmbeddingService
{
    /** Providers laravel/ai can embed with, among the ones this product supports. */
    private const array EMBEDDING_PROVIDERS = [AiProviderCatalog::OPENAI, AiProviderCatalog::GEMINI, AiProviderCatalog::OPENROUTER];

    /** USD per 1M input tokens, used when the pricing table has no embedding row. */
    private const float FALLBACK_PRICE_PER_MILLION = 0.02;

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    /** @return array{0: string, 1: string}|null [provider, model] */
    public function provider(): ?array
    {
        try {
            $route = $this->routes->resolve(AiRouteKeys::BRAIN_EMBEDDINGS);
        } catch (Throwable) {
            return null;
        }
        foreach ($route->providerModels as $provider => $model) {
            if (in_array($provider, self::EMBEDDING_PROVIDERS, true)) {
                return [(string) $provider, (string) $model];
            }
        }

        return null;
    }

    public function available(): bool
    {
        return $this->provider() !== null;
    }

    /**
     * Vectors for the given texts, keyed like the input. Texts are folded (Turkish-aware) before embedding so
     * "İmplant" and "implant" share one vector.
     *
     * @param  array<array-key, string>  $texts
     * @return array<array-key, list<float>>|null
     */
    public function embed(array $texts): ?array
    {
        $provider = $this->provider();
        if ($provider === null) {
            return null;
        }
        [$name, $model] = $provider;
        $folded = array_map(static fn (string $t): string => trim(SeoText::fold($t)), $texts);
        $hashes = array_map(static fn (string $t): string => hash('sha256', $t), $folded);
        $cached = [];
        foreach (array_chunk(array_values(array_unique($hashes)), 500) as $chunk) {
            foreach (DB::table('brain_embeddings')->where('model', $model)->whereIn('text_hash', $chunk)->get(['text_hash', 'vector']) as $row) {
                $cached[$row->text_hash] = array_map('floatval', (array) json_decode((string) $row->vector, true));
            }
        }
        $missing = [];
        foreach ($hashes as $key => $hash) {
            if (! isset($cached[$hash]) && $folded[$key] !== '') {
                $missing[$hash] = $folded[$key];
            }
        }
        if ($missing !== []) {
            $this->runtime->prepare([$name]);
            foreach (array_chunk($missing, 100, true) as $chunk) {
                $response = Embeddings::for(array_values($chunk))->timeout(60)->generate($name, $model);
                $rows = [];
                foreach (array_keys($chunk) as $i => $hash) {
                    $vector = self::normalize(array_map('floatval', $response->embeddings[$i] ?? []));
                    if ($vector === []) {
                        continue;
                    }
                    $cached[$hash] = $vector;
                    $rows[] = ['text_hash' => $hash, 'model' => $model, 'dimensions' => count($vector), 'vector' => json_encode($vector), 'created_at' => now()];
                }
                DB::table('brain_embeddings')->insertOrIgnore($rows);
                $this->recordUsage($name, $model, (int) $response->tokens);
            }
        }
        $out = [];
        foreach ($hashes as $key => $hash) {
            if (isset($cached[$hash])) {
                $out[$key] = $cached[$hash];
            }
        }

        return $out;
    }

    /** Cosine similarity of two unit vectors. */
    public static function similarity(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /**
     * Unit-length mean of several vectors (a "centroid").
     *
     * @param  list<list<float>>  $vectors
     * @return list<float>
     */
    public static function centroid(array $vectors): array
    {
        if ($vectors === []) {
            return [];
        }
        $sum = array_fill(0, count($vectors[0]), 0.0);
        foreach ($vectors as $vector) {
            foreach ($vector as $i => $value) {
                $sum[$i] = ($sum[$i] ?? 0.0) + $value;
            }
        }

        return self::normalize($sum);
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        return $norm > 0 ? array_map(static fn (float $v): float => round($v / $norm, 6), $vector) : [];
    }

    private function recordUsage(string $provider, string $model, int $tokens): void
    {
        try {
            DB::table('ai_usage_records')->insert([
                'route_key' => AiRouteKeys::BRAIN_EMBEDDINGS, 'agent' => 'Embeddings', 'provider' => $provider, 'model' => mb_substr($model, 0, 190),
                'input_tokens' => max(0, $tokens), 'output_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0,
                'cost_usd' => round(max(0, $tokens) / 1_000_000 * self::FALLBACK_PRICE_PER_MILLION, 6), 'invocation_id' => null, 'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Accounting must never break the Brain.
        }
    }
}
