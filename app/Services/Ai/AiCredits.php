<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Support\Ai\AiProviderCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Kredi esaslı AI (yakup, 2026-10-08): loaded API credit per provider minus the recorded cost of that provider's calls
 * since the first top-up. Providers report spend, not the remaining balance, to a normal API key, so this is MoxDOP's own
 * running balance; a provider without any top-up is not credit-limited (only the monthly budget and the daily ceiling).
 */
final class AiCredits
{
    /** Providers whose credit the operator tracks on Ayarlar › AI işlemleri. */
    public const array PROVIDERS = [AiProviderCatalog::ANTHROPIC, AiProviderCatalog::OPENAI];

    public static function label(string $provider): string
    {
        return $provider === AiProviderCatalog::ANTHROPIC ? 'Claude API' : AiProviderCatalog::label($provider);
    }

    public function topUp(string $provider, float $amount, ?string $note = null, ?User $by = null): void
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException('Bu sağlayıcı için kredi tutulmaz: '.$provider);
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Kredi tutarı sıfırdan büyük olmalı.');
        }
        DB::table('ai_credit_topups')->insert([
            'provider' => $provider, 'amount_usd' => round($amount, 2), 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 190) : null,
            'user_id' => $by?->id, 'created_at' => now(),
        ]);
    }

    /**
     * @return array{provider: string, label: string, tracked: bool, loaded: float, spent: float, remaining: float, low: bool, exhausted: bool, since: ?string}
     */
    public function status(string $provider): array
    {
        $empty = ['provider' => $provider, 'label' => self::label($provider), 'tracked' => false, 'loaded' => 0.0, 'spent' => 0.0, 'remaining' => 0.0, 'low' => false, 'exhausted' => false, 'since' => null];
        if (! Schema::hasTable('ai_credit_topups')) {
            return $empty;
        }
        $topups = DB::table('ai_credit_topups')->where('provider', $provider);
        $since = (clone $topups)->min('created_at');
        if ($since === null) {
            return $empty;
        }
        $loaded = (float) (clone $topups)->sum('amount_usd');
        $spent = Schema::hasTable('ai_usage_records')
            ? (float) DB::table('ai_usage_records')->where('provider', $provider)->where('created_at', '>=', $since)->sum('cost_usd') : 0.0;
        $remaining = round($loaded - $spent, 4);

        return ['provider' => $provider, 'label' => self::label($provider), 'tracked' => true, 'loaded' => round($loaded, 2), 'spent' => round($spent, 4),
            'remaining' => max(0.0, $remaining), 'low' => $remaining > 0 && $remaining < (float) config('moxdop-ai-pricing.credit_low_usd', 10),
            'exhausted' => $remaining <= 0, 'since' => (string) $since];
    }

    /** @return list<array{provider: string, label: string, tracked: bool, loaded: float, spent: float, remaining: float, low: bool, exhausted: bool, since: ?string}> */
    public function all(): array
    {
        return array_map(fn (string $provider): array => $this->status($provider), self::PROVIDERS);
    }

    /** A tracked provider whose loaded credit is used up: its paid calls must not start. */
    public function exhausted(?string $provider): bool
    {
        return $provider !== null && in_array($provider, self::PROVIDERS, true) && $this->status($provider)['exhausted'];
    }
}
