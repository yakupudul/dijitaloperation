<?php

namespace App\Services\Ai;

use App\Models\AgencySetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Kota denetimi: reads OpenAI's real costs (Costs API, the organization's Admin key) for yesterday and today (UTC) and
 * compares them with what we recorded. When the free quota is on but OpenAI billed clearly more than our estimate for a
 * finished day, the quota is not applying (sharing off, another project, other apps): the quota accounting is turned off
 * at once, so the daily ceiling counts the list price again. Today's real cost is also a floor for the daily ceiling.
 */
final class OpenAiCostAudit
{
    public const string ENDPOINT = 'https://api.openai.com/v1/organization/costs';

    /** Billed more than estimate × this + MARGIN_USD: the quota is not applying. */
    private const float TOLERANCE = 1.25;

    private const float MARGIN_USD = 0.05;

    /** A finished day is judged once OpenAI had this long to report it. */
    private const int SETTLE_HOURS = 3;

    /** @return array<string, mixed> the stored audit */
    public function run(): array
    {
        $setting = AgencySetting::query()->orderBy('id')->first();
        if ($setting === null || ! Schema::hasColumn('agency_settings', 'ai_openai_admin_key')) {
            return ['status' => 'no_key'];
        }
        $key = (string) $setting->ai_openai_admin_key;
        if ($key === '') {
            return $this->store($setting, ['status' => 'no_key', 'message' => 'Denetim için OpenAI Admin anahtarı gerekli.']);
        }
        $today = CarbonImmutable::now('UTC')->startOfDay();
        try {
            $response = Http::withToken($key)->acceptJson()->timeout(20)
                ->get(self::ENDPOINT, ['start_time' => $today->subDay()->getTimestamp(), 'bucket_width' => '1d', 'limit' => 2]);
        } catch (Throwable $exception) {
            Log::warning('OpenAI cost audit failed.', ['error' => $exception->getMessage()]);

            return $this->store($setting, ['status' => 'error', 'message' => 'OpenAI\'a bağlanılamadı.'] + $this->previous($setting));
        }
        if (! $response->successful()) {
            return $this->store($setting, ['status' => 'error', 'message' => 'OpenAI yanıtı: HTTP '.$response->status().($response->status() === 401 || $response->status() === 403 ? ' (Admin anahtarı geçersiz ya da yetkisiz)' : '')] + $this->previous($setting));
        }

        $days = [];
        foreach ((array) $response->json('data', []) as $bucket) {
            $start = CarbonImmutable::createFromTimestampUTC((int) ($bucket['start_time'] ?? 0));
            $actual = collect((array) ($bucket['results'] ?? []))->sum(fn ($r): float => (float) data_get($r, 'amount.value', 0));
            $days[] = ['date' => $start->toDateString(), 'actual' => round($actual, 4)] + $this->recorded($start, $start->addDay());
        }

        $quotaOn = (bool) $setting->ai_openai_free_quota;
        $finished = collect($days)->first(fn (array $d): bool => $d['date'] === $today->subDay()->toDateString());
        $status = 'ok';
        $message = $quotaOn ? 'OpenAI faturası tahminle uyumlu: ücretsiz kota uygulanıyor.' : 'Ücretsiz kota hesabı kapalı; maliyetler liste fiyatından sayılıyor.';
        if ($quotaOn && $finished !== null && now('UTC')->gte($today->addHours(self::SETTLE_HOURS))
            && $finished['actual'] > $finished['estimated'] * self::TOLERANCE + self::MARGIN_USD) {
            $status = 'mismatch';
            $message = sprintf('Dün OpenAI $%.2f faturaladı, tahminimiz $%.2f idi: ücretsiz kota uygulanmıyor görünüyor (paylaşım kapalı, başka proje ya da aynı hesapta başka uygulama). Kota hesabı kapatıldı; liste fiyatı sayılıyor.',
                $finished['actual'], $finished['estimated']);
            $setting->forceFill(['ai_openai_free_quota' => false]);
        }
        $todayRow = collect($days)->first(fn (array $d): bool => $d['date'] === $today->toDateString());

        return $this->store($setting, ['status' => $status, 'message' => $message, 'days' => $days, 'today_date' => $today->toDateString(),
            'today_actual' => $todayRow['actual'] ?? null]);
    }

    /** The last audit (null when none ran). @return array<string, mixed>|null */
    public static function last(): ?array
    {
        if (! Schema::hasColumn('agency_settings', 'ai_openai_audit')) {
            return null;
        }
        $audit = AgencySetting::query()->orderBy('id')->first()?->ai_openai_audit;

        return is_array($audit) ? $audit : null;
    }

    /** OpenAI's real cost of today (UTC) from an audit of the last 3 hours, a floor for the daily ceiling. */
    public static function todayActual(): ?float
    {
        $audit = self::last();
        if ($audit === null || ! isset($audit['today_actual'], $audit['checked_at']) || ($audit['today_date'] ?? null) !== CarbonImmutable::now('UTC')->toDateString()
            || CarbonImmutable::parse((string) $audit['checked_at'])->lt(now()->subHours(3))) {
            return null;
        }

        return (float) $audit['today_actual'];
    }

    /** @return array{estimated: float, list: float} our recorded OpenAI cost of a UTC day: billed estimate and list price */
    private function recorded(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('ai_live_operations')->where('kind', 'call')->where('provider', 'openai')
            ->where('started_at', '>=', $from)->where('started_at', '<', $to);
        $list = Schema::hasColumn('ai_live_operations', 'list_cost_usd')
            ? (float) (clone $rows)->sum(DB::raw('coalesce(list_cost_usd, cost_usd)')) : (float) (clone $rows)->sum('cost_usd');

        return ['estimated' => round((float) (clone $rows)->sum('cost_usd'), 4), 'list' => round($list, 4)];
    }

    /** @return array<string, mixed> */
    private function previous(AgencySetting $setting): array
    {
        $audit = (array) ($setting->ai_openai_audit ?? []);

        return array_intersect_key($audit, array_flip(['days', 'today_date', 'today_actual']));
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return array<string, mixed>
     */
    private function store(AgencySetting $setting, array $audit): array
    {
        $audit['checked_at'] = now()->toIso8601String();
        $setting->forceFill(['ai_openai_audit' => $audit])->save();

        return $audit;
    }
}
