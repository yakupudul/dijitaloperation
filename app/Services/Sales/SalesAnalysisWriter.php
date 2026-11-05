<?php

namespace App\Services\Sales;

use App\Ai\Agents\SalesAnalysisAgent;
use App\Jobs\Sales\WriteSalesAnalysis;
use App\Models\SalesLead;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Tam analiz (yakup 2026-11-05): a full written analysis of a prospect, like the audits written in the threads, not
 * "three findings". It is written when the operator asks on the card, or by itself once a prospect really talks (their
 * reply, a form, a meeting), at most DAILY_AUTO a day. The customer only ever gets the one-finding message.
 */
final class SalesAnalysisWriter
{
    /** Analyses written by themselves per day (the operator's clicks are not counted here). */
    public const int DAILY_AUTO = 8;

    /** Every analysis per day, clicks included: a runaway loop cannot spend the AI cap. */
    public const int DAILY_MAX = 25;

    public function __construct(private AiRouteResolver $routes, private AiProviderRuntimeConfig $runtime, private SalesReplyWriter $reply) {}

    public static function writtenToday(): int
    {
        return SalesLead::query()->where('analysis_at', '>=', now('Europe/Istanbul')->startOfDay()->utc())->count();
    }

    /** The operator's click: always, unless the day's hard limit is reached. */
    public static function queue(SalesLead $lead): bool
    {
        if (self::writtenToday() >= self::DAILY_MAX) {
            return false;
        }
        $lead->forceFill(['analysis_status' => 'queued', 'analysis_note' => null])->save();
        WriteSalesAnalysis::dispatch((int) $lead->id);

        return true;
    }

    /** By itself: a prospect who talks gets an analysis once, while the day's automatic share lasts. */
    public static function queueIfWorth(SalesLead $lead): bool
    {
        if (! $lead->isOpen() || filled($lead->analysis) || in_array($lead->analysis_status, ['queued', 'running'], true)
            // A site still being read waits: the scan's end asks again, so the analysis sees the site.
            || ($lead->website_host !== null && ! in_array($lead->findings_status, ['ready', 'failed'], true))
            || ($lead->website_host === null && $lead->events()->whereIn('kind', ['message_in', 'meeting', 'call'])->count() < 2)) {
            return false;
        }
        $auto = SalesLead::query()->where('analysis_at', '>=', now('Europe/Istanbul')->startOfDay()->utc())->where('analysis_note', 'like', 'auto%')->count();
        if ($auto >= self::DAILY_AUTO || self::writtenToday() >= self::DAILY_MAX) {
            return false;
        }
        $lead->forceFill(['analysis_status' => 'queued', 'analysis_note' => 'auto'])->save();
        WriteSalesAnalysis::dispatch((int) $lead->id);

        return true;
    }

    public function write(int $leadId, int $timeout = 150): bool
    {
        $lead = SalesLead::query()->find($leadId);
        if ($lead === null) {
            return false;
        }
        $auto = $lead->analysis_note === 'auto';
        $lead->forceFill(['analysis_status' => 'running'])->save();
        try {
            $route = $this->routes->resolve(SalesAnalysisAgent::ROUTE);
            if ($route->isEmpty()) {
                return $this->fail($lead, 'Claude API bağlantısı yok ya da günlük AI tavanı doldu (Ayarlar › AI).');
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $payload = $this->reply->payload($lead);
            $answer = (array) (new SalesAnalysisAgent)->prompt("LEAD_JSON\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels, timeout: $timeout)->toArray();
        } catch (Throwable $exception) {
            Log::warning('Sales analysis failed.', ['lead_id' => $lead->id, 'error' => $exception->getMessage()]);

            return $this->fail($lead, 'Yapay zekâ isteği başarısız oldu; "Yeniden yaz" ile tekrar dene.');
        }
        if (Validator::make($answer, ['analysis' => ['required', 'string', 'min:200', 'max:30000'], 'message' => ['required', 'string', 'min:10', 'max:4000'], 'headline' => ['nullable', 'string', 'max:500']])->fails()) {
            return $this->fail($lead, 'Yapay zekâ eksik bir analiz döndürdü; "Yeniden yaz" ile tekrar dene.');
        }
        $lead->forceFill([
            'analysis' => trim((string) $answer['analysis']), 'analysis_message' => trim((string) $answer['message']),
            'analysis_headline' => mb_substr(trim((string) ($answer['headline'] ?? '')), 0, 500) ?: null,
            'analysis_status' => 'ready', 'analysis_at' => now(), 'analysis_note' => $auto ? 'auto' : null,
        ])->save();
        app(SalesLeads::class)->event($lead, 'report', 'Tam analiz yazıldı'.($lead->analysis_headline ? ': '.$lead->analysis_headline : '.'), meta: ['analysis' => true]);

        return true;
    }

    private function fail(SalesLead $lead, string $reason): bool
    {
        $lead->forceFill(['analysis_status' => 'failed', 'analysis_note' => $reason])->save();

        return false;
    }
}
