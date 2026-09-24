<?php

namespace App\Services\Ai\Insights;

use App\Jobs\WriteAiInsightJob;
use App\Models\AiProduction;
use App\Services\Ai\AiCostEstimator;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Archive\ProductionArchive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Runs the on-click AI insights (advisor explanation, search-term triage, review themes, alert causes, ad ↔
 * landing fit, customer brief, lead score, developer task list). Each click queues one job; the answer is filed
 * in the production archive (versioned, 👍/👎) and shown on the page. Nothing runs without a click.
 */
final class AiInsightService
{
    /** @var array<string, InsightDefinition> */
    private array $definitions = [];

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ProductionArchive $archive,
    ) {}

    public function register(InsightDefinition $definition): void
    {
        $this->definitions[$definition->kind()] = $definition;
    }

    public function definition(string $kind): InsightDefinition
    {
        return $this->definitions[$kind] ?? throw new InvalidArgumentException("Unknown AI insight [{$kind}]");
    }

    /** @return array<string, InsightDefinition> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /** Queue one insight; throws a validation error when no AI route is usable. */
    public function queue(string $kind, Model $subject): void
    {
        $definition = $this->definition($kind);
        if (! $subject instanceof ($definition->subjectClass())) {
            throw new InvalidArgumentException('Wrong subject for '.$kind);
        }
        if ($this->routes->resolve($definition->routeKey())->isEmpty()) {
            throw ValidationException::withMessages(['insight' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).']);
        }
        Cache::put($this->stateKey($kind, (int) $subject->getKey()), 'running', now()->addMinutes(10));
        WriteAiInsightJob::dispatch($kind, (int) $subject->getKey());
    }

    public function write(string $kind, int $subjectId): void
    {
        $definition = $this->definition($kind);
        $subject = $definition->subjectClass()::query()->find($subjectId);
        if (! $subject instanceof Model) {
            Cache::forget($this->stateKey($kind, $subjectId));

            return;
        }
        try {
            $route = $this->routes->resolve($definition->routeKey());
            if ($route->isEmpty()) {
                throw new RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $input = json_encode($definition->context($subject), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $response = (array) $definition->agent()->prompt("INPUT_JSON\n".$input, provider: $route->providerModels, timeout: 120)->toArray();
            $content = $this->clean($response, $definition);
            if ($content['summary'] === '' && $content['items'] === []) {
                throw new RuntimeException('AI boş yanıt döndürdü.');
            }
            $meta = $definition->meta($subject);
            $this->archive->record($kind, $subject, $content + [
                'provider' => $route->primaryProvider(), 'model' => $route->primaryModel(), 'prompt_version' => $kind.'-v1', 'created_at' => now()->toIso8601String(),
            ], $meta);
            Cache::forget($this->stateKey($kind, $subjectId));
        } catch (Throwable $exception) {
            Cache::put($this->stateKey($kind, $subjectId), 'failed: '.mb_substr($exception->getMessage(), 0, 200), now()->addHour());
        }
    }

    /**
     * Everything the page needs to show the insight block.
     *
     * @return array{kind: string, subject_id: int, label: string, state: ?string, estimate: ?string, production: ?AiProduction, tags: array<string, array{0: string, 1: string}>}
     */
    public function view(string $kind, Model $subject): array
    {
        $definition = $this->definition($kind);
        [$in, $out] = $definition->tokens();

        return [
            'kind' => $kind,
            'subject_id' => (int) $subject->getKey(),
            'label' => $definition->label(),
            'state' => $this->state($kind, (int) $subject->getKey()),
            'estimate' => app(AiCostEstimator::class)->label($definition->routeKey(), $in, $out),
            'production' => $this->archive->fresh($kind, $subject, $definition->freshDays()),
            'tags' => $definition->tagStyles(),
        ];
    }

    /**
     * The same as view() for many subjects of one list (one cost estimate, one archive query).
     *
     * @param  iterable<Model>  $subjects
     * @return array<int, array<string, mixed>> subject id => view
     */
    public function viewMany(string $kind, iterable $subjects): array
    {
        $definition = $this->definition($kind);
        $subjects = collect($subjects)->keyBy(fn (Model $m): int => (int) $m->getKey());
        if ($subjects->isEmpty()) {
            return [];
        }
        [$in, $out] = $definition->tokens();
        $estimate = app(AiCostEstimator::class)->label($definition->routeKey(), $in, $out);
        $latest = AiProduction::query()->where('kind', $kind)->where('subject_type', class_basename($subjects->first()->getMorphClass()))
            ->whereIn('subject_id', $subjects->keys())->where('status', '!=', AiProduction::STATUS_DISCARDED)
            ->where('created_at', '>=', now()->subDays($definition->freshDays()))->orderByDesc('version')->get()->unique('subject_id')->keyBy('subject_id');

        return $subjects->map(fn (Model $m, int $id): array => [
            'kind' => $kind, 'subject_id' => $id, 'label' => $definition->label(), 'state' => $this->state($kind, $id),
            'estimate' => $estimate, 'production' => $latest->get($id), 'tags' => $definition->tagStyles(),
        ])->all();
    }

    public function state(string $kind, int $subjectId): ?string
    {
        $state = Cache::get($this->stateKey($kind, $subjectId));

        return is_string($state) ? $state : null;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{summary: string, items: list<array{title: string, detail: string, tag: string}>}
     */
    private function clean(array $response, InsightDefinition $definition): array
    {
        $tags = $definition->agent()->tags();
        $items = [];
        foreach (array_slice(is_array($response['items'] ?? null) ? $response['items'] : [], 0, 20) as $item) {
            if (! is_array($item) || blank($item['title'] ?? null)) {
                continue;
            }
            $tag = (string) ($item['tag'] ?? '');
            $items[] = [
                'title' => mb_substr(trim(strip_tags((string) $item['title'])), 0, 200),
                'detail' => mb_substr(trim(strip_tags((string) ($item['detail'] ?? ''))), 0, 600),
                'tag' => in_array($tag, $tags, true) ? $tag : $tags[array_key_last($tags)],
            ];
        }

        return ['summary' => mb_substr(trim(strip_tags((string) ($response['summary'] ?? ''))), 0, 1200), 'items' => $items];
    }

    private function stateKey(string $kind, int $subjectId): string
    {
        return 'ai-insight:'.$kind.':'.$subjectId;
    }
}
