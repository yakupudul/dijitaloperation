<?php

namespace App\Services\Sales;

use App\Ai\Agents\SalesResearchAgent;
use App\Jobs\Sales\ResearchSalesLead;
use App\Models\SalesLead;
use App\Models\WhatsAppMessage;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use App\Support\Ai\AiProviderCatalog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Yeni aday araştırması (yakup 2026-11-08): an unknown number writes on the live line → Claude searches the web from the
 * name and the number, finds the business's site, Instagram and Google harita kaydı, and the card keeps what it saw.
 * Then the site check reads the site, the full analysis is written with the research in hand, and its customer message
 * becomes the card's draft: it waits in the Gelen kutusu for the operator's "Gönder", nothing goes out by itself.
 */
final class LeadResearcher
{
    /** Researches per day (each is a handful of web searches on the Claude API). */
    public const int DAILY_MAX = 15;

    /** Only new prospects: a card older than this is not "someone who just wrote". */
    public const int NEW_DAYS = 3;

    public const array STATUS = [
        'queued' => 'Claude araştırıyor…', 'running' => 'Claude araştırıyor…', 'ready' => 'Araştırıldı', 'failed' => 'Araştırılamadı', 'skipped' => 'Araştırılmadı',
    ];

    public function __construct(private AiRouteResolver $routes, private AiProviderRuntimeConfig $runtime) {}

    public static function doneToday(): int
    {
        return SalesLead::query()->where('research_at', '>=', now('Europe/Istanbul')->startOfDay()->utc())->count();
    }

    /** The operator's click ("Araştır") or the automatic start: false when the day's limit is reached. */
    public static function queue(SalesLead $lead): bool
    {
        if (self::doneToday() + SalesLead::query()->whereIn('research_status', ['queued', 'running'])->count() >= self::DAILY_MAX) {
            return false;
        }
        $lead->forceFill(['research_status' => 'queued', 'research_note' => null])->save();
        ResearchSalesLead::dispatch((int) $lead->id);

        return true;
    }

    /**
     * By itself: a prospect who just wrote to the live number for the first time, whom we have not written to yet, gets
     * one research. A chat from the phone backup or the history sync never starts one.
     */
    public static function queueIfNew(SalesLead $lead): bool
    {
        if ($lead->research_status !== null || ! $lead->isOpen() || $lead->isCustomer() || $lead->source !== 'whatsapp'
            || $lead->conversation_id === null || $lead->created_at === null || $lead->created_at->lt(now()->subDays(self::NEW_DAYS))) {
            return false;
        }
        $conversation = $lead->conversation;
        if ($conversation === null || $conversation->phone_number_id === WhatsAppBackupImporter::LINE || $conversation->customer_id !== null) {
            return false;
        }
        $messages = WhatsAppMessage::query()->where('conversation_id', $conversation->id);
        if ((clone $messages)->where('direction', 'outgoing')->exists()
            || ! (clone $messages)->where('direction', 'incoming')->where('is_history', false)->exists()) {
            return false;
        }

        return self::queue($lead);
    }

    /** The sweep: new chats whose start was missed (a queue hiccup, a message stored before the card). */
    public static function sweep(): int
    {
        $count = 0;
        SalesLead::query()->whereNull('research_status')->where('source', 'whatsapp')->whereNotNull('conversation_id')
            ->where('created_at', '>=', now()->subDays(self::NEW_DAYS))->orderByDesc('id')->limit(20)->get()
            ->each(function (SalesLead $lead) use (&$count): void {
                $count += self::queueIfNew($lead) ? 1 : 0;
            });

        return $count;
    }

    public function run(int $leadId, int $timeout = 160): bool
    {
        $lead = SalesLead::query()->find($leadId);
        if ($lead === null) {
            return false;
        }
        $lead->forceFill(['research_status' => 'running'])->save();
        try {
            $route = $this->routes->resolve(SalesResearchAgent::ROUTE);
            $model = $route->providerModels[AiProviderCatalog::ANTHROPIC] ?? null;
            if ($model === null) {
                return $this->fail($lead, 'Claude API bağlı değil ya da günlük tavanı doldu; araştırma yapılmadı.');
            }
            $this->runtime->prepare([AiProviderCatalog::ANTHROPIC]);
            $answer = (array) (new SalesResearchAgent)->prompt("LEAD_JSON\n".json_encode($this->payload($lead), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: [AiProviderCatalog::ANTHROPIC => (string) $model], timeout: $timeout)->toArray();
        } catch (Throwable $exception) {
            Log::warning('Sales research failed.', ['lead_id' => $lead->id, 'error' => $exception->getMessage()]);

            return $this->fail($lead, 'Araştırma isteği başarısız oldu: '.mb_substr($exception->getMessage(), 0, 160));
        }
        $this->store($lead, $answer);

        return true;
    }

    /** @param  array<string, mixed>  $answer */
    public function store(SalesLead $lead, array $answer): void
    {
        $research = self::clean($answer);
        $sure = $research['confidence'] !== 'low';
        $lead->forceFill(['research' => $research, 'research_status' => 'ready', 'research_at' => now(),
            'research_note' => $sure ? null : 'Claude emin değil: '.mb_substr($research['note'], 0, 400)]);
        // Only a sure match fills the card; an unsure one stays in the research for the operator to judge.
        if ($sure) {
            $fill = array_filter(['company' => $research['business_name'], 'city' => $research['city'], 'website' => $lead->website ?: $research['website']]);
            app(SalesLeads::class)->fill($lead, $fill);
        }
        $lead->save();
        $found = array_filter(['site' => $research['website'], 'Instagram' => $research['instagram'], 'harita kaydı' => $research['google_maps']]);
        app(SalesLeads::class)->event($lead, 'finding', 'Claude araştırdı'.($research['business_name'] !== '' ? ': '.$research['business_name'] : '')
            .($found !== [] ? ' ('.implode(', ', array_keys($found)).' bulundu)' : ' (site, Instagram ya da harita kaydı bulunamadı)')
            .($sure ? '' : '. Emin değil, kontrol et.'), meta: ['research' => true, 'confidence' => $research['confidence']]);
        app(SalesLeads::class)->classify($lead);
        if ($sure && $lead->website_host !== null && ! in_array($lead->findings_status, ['ready', 'queued', 'running'], true)) {
            ProspectSiteCheck::queue($lead); // its end calls afterSiteRead()
        } else {
            self::analyse($lead);
        }
    }

    /** The site check finished (read or not): a researched prospect gets the analysis and its message next. */
    public static function afterSiteRead(SalesLead $lead): void
    {
        if ($lead->research_status === 'ready' && ! filled($lead->analysis) && ! in_array($lead->analysis_status, ['queued', 'running', 'waiting'], true)) {
            self::analyse($lead);
        }
    }

    private static function analyse(SalesLead $lead): void
    {
        if ($lead->isOpen() && data_get($lead->research, 'confidence') !== 'low' && filled(data_get($lead->research, 'business_name'))) {
            SalesAnalysisWriter::queue($lead, auto: true);
        }
    }

    /**
     * After the analysis: its customer message becomes the card's draft, unless the operator already wrote to them or is
     * editing a text of their own.
     */
    public static function draftFromAnalysis(SalesLead $lead): void
    {
        if ($lead->research_status !== 'ready' || ! filled($lead->analysis_message) || ! $lead->isOpen() || $lead->last_contact_at !== null
            || (filled($lead->draft) && $lead->draft !== $lead->draft_ai)) {
            return;
        }
        $text = trim((string) $lead->analysis_message);
        $lead->forceFill(['draft' => $text, 'draft_ai' => $text, 'draft_status' => 'ready', 'draft_at' => now(),
            'draft_note' => 'İlk mesaj: Claude '.self::inspected($lead).' inceledi; tam analiz kayıt kartında. Onaylarsan Gönder.',
            'draft_send_on' => now('Europe/Istanbul')->toDateString()])->save();
    }

    private static function inspected(SalesLead $lead): string
    {
        $parts = array_keys(array_filter(['siteyi' => data_get($lead->research, 'website'), 'Instagram\'ı' => data_get($lead->research, 'instagram'),
            'Google harita kaydını' => data_get($lead->research, 'google_maps')]));

        return $parts === [] ? 'web aramasını' : implode(', ', $parts);
    }

    /** @return array<string, mixed> */
    private function payload(SalesLead $lead): array
    {
        $conversation = $lead->conversation;
        $first = $conversation === null ? [] : WhatsAppMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'incoming')
            ->orderBy('sent_at')->limit(8)->get()->map(fn (WhatsAppMessage $m): string => mb_substr((string) $m->body, 0, 1000))->filter()->values()->all();
        $phone = (string) ($lead->phone ?? '');
        $local = $lead->phone_key !== null ? '0'.substr($lead->phone_key, 0, 3).' '.substr($lead->phone_key, 3, 3).' '.substr($lead->phone_key, 6, 2).' '.substr($lead->phone_key, 8, 2) : '';

        return array_filter([
            'whatsapp_name' => $conversation?->contact_name, 'card_name' => $lead->name, 'company' => $lead->company, 'city' => $lead->city,
            'website' => $lead->website, 'phone' => $phone, 'phone_local' => $local, 'first_messages' => $first,
            'from_ad' => is_array($conversation?->referral) ? ($conversation->referral['headline'] ?? null) : null,
        ], fn ($v): bool => filled($v));
    }

    /** @return array<string, mixed> */
    public static function clean(array $answer): array
    {
        $str = static fn ($v, int $max = 300): string => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, $max);
        $url = static function ($v) use ($str): string {
            $v = $str($v, 500);

            return preg_match('#^(https?://)?[\w.-]+\.[a-z]{2,}#iu', $v) ? $v : '';
        };
        $confidence = in_array($answer['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $answer['confidence'] : 'low';

        return [
            'business_name' => $str($answer['business_name'] ?? ''), 'person_name' => $str($answer['person_name'] ?? ''),
            'kind' => $str($answer['kind'] ?? '', 120), 'city' => $str($answer['city'] ?? '', 120), 'district' => $str($answer['district'] ?? '', 120),
            'website' => $url($answer['website'] ?? ''), 'instagram' => $url($answer['instagram'] ?? ''), 'google_maps' => $url($answer['google_maps'] ?? ''),
            'maps' => array_map(fn ($v) => $str($v), array_intersect_key((array) ($answer['maps'] ?? []), array_flip(['rating', 'reviews', 'category', 'address', 'website_linked']))),
            'instagram_facts' => array_map(fn ($v) => $str($v, 500), array_intersect_key((array) ($answer['instagram_facts'] ?? []), array_flip(['followers', 'posts', 'last_post', 'bio']))),
            'site_notes' => array_values(array_slice(array_filter(array_map(fn ($v) => $str($v, 500), (array) ($answer['site_notes'] ?? []))), 0, 15)),
            'findings' => array_values(array_slice(array_filter(array_map(fn ($f): array => is_array($f) ? ['title' => $str($f['title'] ?? ''), 'detail' => $str($f['detail'] ?? '', 600), 'source' => $str($f['source'] ?? '', 40)] : [],
                (array) ($answer['findings'] ?? [])), fn (array $f): bool => ($f['title'] ?? '') !== ''), 0, 12)),
            'confidence' => $confidence,
            'sources' => array_values(array_slice(array_filter(array_map($url, (array) ($answer['sources'] ?? []))), 0, 12)),
            'note' => $str($answer['note'] ?? '', 1000),
        ];
    }

    private function fail(SalesLead $lead, string $reason): bool
    {
        $lead->forceFill(['research_status' => 'failed', 'research_note' => $reason, 'research_at' => now()])->save();

        return false;
    }
}
