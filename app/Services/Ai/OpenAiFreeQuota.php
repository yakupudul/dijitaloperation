<?php

namespace App\Services\Ai;

use App\Models\AgencySetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OpenAI ücretsiz paylaşım kotası: with "Giriş ve çıkışları OpenAI ile paylaşın" on, OpenAI does not bill shared traffic up
 * to a daily token amount per model group (UTC day). When the operator says the setting is on, a call's tokens inside the
 * day's remaining quota cost nothing; the rest is billed at the list price. The quota is counted at `safety` of the
 * limit, and OpenAiCostAudit compares the estimate with OpenAI's real costs and turns this off when they disagree.
 */
final class OpenAiFreeQuota
{
    public function enabled(): bool
    {
        return Schema::hasColumn('agency_settings', 'ai_openai_free_quota')
            && (bool) AgencySetting::query()->orderBy('id')->value('ai_openai_free_quota');
    }

    /** The quota group of a model ("gpt-5-mini-2025-08-07" → small), null when the quota does not cover it. */
    public static function tier(?string $model): ?string
    {
        if ($model === null || $model === '') {
            return null;
        }
        $model = (string) preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', strtolower(trim($model)));
        foreach ((array) config('moxdop-ai-pricing.openai_free_quota.tiers', []) as $key => $tier) {
            if (in_array($model, (array) $tier['models'], true)) {
                return (string) $key;
            }
        }

        return null;
    }

    /** Tokens of the group's free quota we count with (the limit × safety). */
    public static function limit(string $tier): int
    {
        $tokens = (int) config("moxdop-ai-pricing.openai_free_quota.tiers.{$tier}.tokens", 0);

        return (int) floor($tokens * (float) config('moxdop-ai-pricing.openai_free_quota.safety', 0.9));
    }

    /**
     * The billed part of an OpenAI call: [billed cost, tokens the quota covered]. Off, another provider, a model outside
     * the quota or an unknown price: the list price, nothing free.
     *
     * @return array{0: ?float, 1: int}
     */
    public function bill(string $provider, string $model, int $tokens, ?float $listCost, ?int $exceptRowId = null): array
    {
        $tier = self::tier($model);
        if ($listCost === null || $tokens <= 0 || $provider !== 'openai' || $tier === null || ! $this->enabled()) {
            return [$listCost, 0];
        }
        $free = min($tokens, max(0, self::limit($tier) - $this->usedToday($tier, $exceptRowId)));

        return [round($listCost * (1 - $free / $tokens), 6), $free];
    }

    /**
     * Önce ücretsiz kota: an OpenAI call whose model still has free tokens today runs even when the day's paid ceiling
     * is spent (it costs nothing); once the group's quota is used up, the paid ceiling applies.
     */
    public function hasRoom(?string $provider, ?string $model): bool
    {
        $tier = self::tier($model);

        return $provider === 'openai' && $tier !== null && $this->enabled() && $this->usedToday($tier) < self::limit($tier);
    }

    /**
     * Tokens of the group's OpenAI calls since 00:00 UTC made while the quota was on (only shared traffic counts at
     * OpenAI: calls before the sharing was switched on never used it up).
     */
    public function usedToday(string $tier, ?int $exceptRowId = null): int
    {
        return (int) $this->todayCalls($exceptRowId)->filter(fn (object $row): bool => self::tier($row->model) === $tier)
            ->sum(fn (object $row): int => (int) $row->input_tokens + (int) $row->output_tokens);
    }

    /**
     * Today's quota per group and what it saved, for the AI işlemleri page.
     *
     * @return array{enabled: bool, tiers: list<array{key: string, label: string, used: int, limit: int, tokens: int}>, saved: float}
     */
    public function status(): array
    {
        $calls = $this->todayCalls();
        $tiers = [];
        foreach ((array) config('moxdop-ai-pricing.openai_free_quota.tiers', []) as $key => $tier) {
            $tiers[] = ['key' => (string) $key, 'label' => (string) $tier['label'], 'tokens' => (int) $tier['tokens'], 'limit' => self::limit((string) $key),
                'used' => (int) $calls->filter(fn (object $row): bool => self::tier($row->model) === $key)->sum(fn (object $row): int => (int) $row->input_tokens + (int) $row->output_tokens)];
        }

        return ['enabled' => $this->enabled(), 'tiers' => $tiers,
            'saved' => round((float) $calls->sum(fn (object $row): float => max(0.0, (float) $row->list_cost_usd - (float) $row->cost_usd)), 4)];
    }

    /** @return Collection<int, object> */
    private function todayCalls(?int $exceptRowId = null)
    {
        if (! Schema::hasColumn('ai_live_operations', 'list_cost_usd')) {
            return collect();
        }

        return DB::table('ai_live_operations')->where('kind', 'call')->where('provider', 'openai')
            ->where('started_at', '>=', CarbonImmutable::now('UTC')->startOfDay())
            ->when($exceptRowId !== null, fn ($q) => $q->where('id', '!=', $exceptRowId))
            ->whereNotNull('input_tokens')->whereNotNull('list_cost_usd')
            ->get(['model', 'input_tokens', 'output_tokens', 'cost_usd', 'list_cost_usd']);
    }
}
