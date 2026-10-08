<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Prompts\PromptRegistry;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use InvalidArgumentException;

/**
 * Üçlü AI seçimi (yakup, 2026-10-08): every AI operation runs on GPT (the route model), the Claude API (loaded credit)
 * or the Claude subscription (MCP queue). The choice is the model of the operation's current prompt version, set one by
 * one on Ayarlar › AI işlemleri or for all operations at once with a plan. Each switch publishes a new version with the
 * same template, so the version history shows who switched what and when, and "Bu sürüme dön" undoes it.
 */
final class AiAssignments
{
    public const string HAIKU = 'anthropic:claude-haiku-5-5';

    public const string SONNET = 'anthropic:claude-sonnet-5-5';

    public const string PLAN_RECOMMENDED = 'onerilen';

    public const string PLAN_CLAUDE_API = 'claude_api';

    public const string PLAN_SUBSCRIPTION = 'abonelik';

    public const string PLAN_ROUTE = 'rota';

    public const array PLANS = [
        self::PLAN_RECOMMENDED => 'Önerilen dağılım (toplu işler Haiku 5.5, yazı işleri Sonnet 5.5)',
        self::PLAN_CLAUDE_API => 'Hepsi Claude API (Sonnet 5.5)',
        self::PLAN_SUBSCRIPTION => 'Hepsi Claude abonelik (MCP kuyruğu)',
        self::PLAN_ROUTE => 'Hepsi GPT / rota modeli',
    ];

    /**
     * Never moved by a plan: the 15-minute query autopilot runs inside OpenAI's free daily tokens, WhatsApp stays on
     * OpenAI (yakup, 2026-10-04) and embeddings are not a chat model.
     */
    public const array KEEP = [AiRouteKeys::QUERIES_TRIAGE, 'whatsapp.reply', AiRouteKeys::BRAIN_EMBEDDINGS];

    /** Writing and judgement work: Sonnet 5.5. Everything else (classify, match, extract) is bulk work for Haiku 5.5. */
    public const array WRITING = [
        AiRouteKeys::SITE_WRITE_ARTICLE, AiRouteKeys::SITE_CONTENT_RECIPE, AiRouteKeys::SITE_WEEKLY_CONTENT, AiRouteKeys::SITE_CONTENT_DISCOVERY,
        AiRouteKeys::SITE_APPLY_CHANGE, AiRouteKeys::SITE_STANDARD_FROM_DECISION, AiRouteKeys::CONTENT_IDEAS, AiRouteKeys::CONTENT_LOCALIZE,
        AiRouteKeys::CONTENT_ARTICLE, AiRouteKeys::SITE_FIX_PAGE, AiRouteKeys::GBP_BRANCH_PAGE, AiRouteKeys::GBP_POST_QUEUE, AiRouteKeys::GBP_POST_FROM_PAGE,
        AiRouteKeys::GBP_DESCRIPTION, AiRouteKeys::GBP_REVIEW_REPLY, AiRouteKeys::GBP_PROFILE_PLAN, AiRouteKeys::META_STRATEGY_PLAN, AiRouteKeys::META_CREATIVES,
        AiRouteKeys::META_STRUCTURE, AiRouteKeys::META_LANDING, AiRouteKeys::GOOGLE_ADS_AD_TEXTS, AiRouteKeys::GOOGLE_ADS_STRUCTURE, AiRouteKeys::BRAND_SETUP,
        AiRouteKeys::BRAND_CARE, AiRouteKeys::BRAND_CHIEF, AiRouteKeys::COMPETITORS_ANALYZE, AiRouteKeys::BACKLINKS_SOURCES, AiRouteKeys::MONTHLY_REPORT_COMMENTARY,
        AiRouteKeys::INSIGHT_ALERT_CAUSE, AiRouteKeys::INSIGHT_TECHNICAL_TASKS, AiRouteKeys::INSIGHT_ADVISOR_EXPLAIN, AiRouteKeys::INSIGHT_CUSTOMER_BRIEF,
    ];

    public function __construct(private readonly PromptRegistry $registry, private readonly AiTaskQueue $tasks) {}

    /** "gpt" | "claude_api" | "abonelik" | "rota" | "diger" for a prompt version model value. */
    public static function kind(?string $model): string
    {
        $model = (string) $model;

        return match (true) {
            $model === '' => 'rota',
            $model === AiTaskQueue::MODEL => 'abonelik',
            str_starts_with($model, AiProviderCatalog::ANTHROPIC.':') => 'claude_api',
            str_starts_with($model, AiProviderCatalog::OPENAI.':') => 'gpt',
            default => 'diger',
        };
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'rota' => 'Rota (GPT)', 'abonelik' => 'Claude abonelik', 'claude_api' => 'Claude API', 'gpt' => 'GPT', default => 'Diğer',
        };
    }

    /** The model a plan gives this operation; null = the plan leaves it as it is. */
    public function target(string $plan, string $operation): ?string
    {
        if (in_array($operation, self::KEEP, true)) {
            return null;
        }

        return match ($plan) {
            self::PLAN_RECOMMENDED => in_array($operation, self::WRITING, true) ? self::SONNET : self::HAIKU,
            self::PLAN_CLAUDE_API => self::SONNET,
            self::PLAN_SUBSCRIPTION => AiTaskQueue::enabled() && $this->tasks->supports($operation) ? AiTaskQueue::MODEL : null,
            self::PLAN_ROUTE => '',
            default => throw new InvalidArgumentException('Bilinmeyen dağılım: '.$plan),
        };
    }

    /**
     * Applies a plan to every operation: a new version (same template) only where the model changes.
     *
     * @return array{changed: int, unchanged: int, kept: int}
     */
    public function apply(string $plan, User $by): array
    {
        $counts = ['changed' => 0, 'unchanged' => 0, 'kept' => 0];
        foreach (array_keys($this->registry->definitions()) as $operation) {
            $target = $this->target($plan, $operation);
            if ($target === null) {
                $counts['kept']++;

                continue;
            }
            $current = $this->registry->current($operation);
            if ((string) ($current->model ?? '') === $target) {
                $counts['unchanged']++;

                continue;
            }
            $this->registry->publish($operation, ['template' => (string) $current->template, 'model' => $target], $by);
            $counts['changed']++;
        }

        return $counts;
    }
}
