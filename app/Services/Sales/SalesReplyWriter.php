<?php

namespace App\Services\Sales;

use App\Ai\Agents\SalesReplyAgent;
use App\Jobs\Sales\DraftSalesReply;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Support\Sales\LeadKinds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * The next message to a prospect, written by AI (yakup 2026-11-04: the template texts were "çok kötü"). It reads the
 * whole conversation, the operator's notes, the site scan and yakup's own recently sent messages, and answers with the
 * text, the day to send it and a short note to the operator. Always the provider route at once (yakup 2026-11-04:
 * "Claude API yapsın, anında yanıt"): the Claude API key of Ayarlar › AI, counted in the daily TL cap, ChatGPT when
 * Claude fails. While the API worker is down (its daily cap, an error) the subscription Claude writes it in its next
 * round (SalesSubscription, yakup 2026-11-06). Nothing is ever sent from here.
 */
final class SalesReplyWriter
{
    /** Labels for the card. */
    public const array STATUS = [
        'queued' => 'Claude yazıyor…', 'running' => 'Claude yazıyor…', 'waiting' => 'Abonelikteki Claude yazacak', 'ready' => 'Hazır', 'failed' => 'Yazılamadı',
    ];

    public function __construct(private AiRouteResolver $routes, private AiProviderRuntimeConfig $runtime, private SalesSubscription $subscription) {}

    /** Asks for a new text (the page polls until it is ready). */
    public static function queue(SalesLead $lead): void
    {
        $lead->forceFill(['draft_status' => 'queued', 'draft_note' => null])->save();
        SalesSubscription::withdraw(new DraftSalesReply((int) $lead->id));
        DraftSalesReply::dispatch((int) $lead->id);
    }

    /** Writes the text now (the page waits ~10-30 s, or the job in the background); true when a text was stored. */
    public function write(int $leadId, int $timeout = 120, ?DraftSalesReply $job = null): bool
    {
        $lead = SalesLead::query()->find($leadId);
        if ($lead === null) {
            return false;
        }
        // The subscription Claude answered the question this job left on the queue.
        if ($job !== null && ($answer = $this->subscription->answered(new DraftSalesReply($leadId), SalesReplyAgent::ROUTE)) !== null) {
            if ($answer === []) {
                $this->fail($lead, 'Abonelikteki Claude yazamadı; "Yeniden yaz" ile tekrar dene.');

                return false;
            }

            return $this->store($lead, $answer);
        }
        $payload = $this->payload($lead);
        if ($this->subscription->takesOver()) {
            return $this->wait($lead, $payload);
        }
        $lead->forceFill(['draft_status' => 'running'])->save();
        try {
            $route = $this->routes->resolve(SalesReplyAgent::ROUTE);
            if ($route->isEmpty()) {
                if ($this->subscription->canWait()) {
                    return $this->wait($lead, $payload, 'Claude API bağlı değil');
                }
                $this->fail($lead, 'Claude API bağlantısı yok ya da günlük AI tavanı doldu (Ayarlar › AI).');

                return false;
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $answer = (new SalesReplyAgent)->prompt("LEAD_JSON\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels, timeout: $timeout)->toArray();

            return $this->store($lead, (array) $answer);
        } catch (Throwable $exception) {
            Log::warning('Sales reply draft failed.', ['lead_id' => $lead->id, 'error' => $exception->getMessage()]);
            if ($this->subscription->canWait()) {
                return $this->wait($lead, $payload, 'Claude API hata verdi');
            }
            $this->fail($lead, 'Yapay zekâ isteği başarısız oldu; "Yeniden yaz" ile tekrar dene.');

            return false;
        }
    }

    /** @param  array<string, mixed>  $answer */
    private function store(SalesLead $lead, array $answer): bool
    {
        $valid = Validator::make($answer, [
            'action' => ['required', 'in:reply,wait'], 'reply' => ['required', 'string', 'min:5', 'max:4000'],
            'send_on' => ['nullable', 'string', 'max:20'], 'note' => ['nullable', 'string', 'max:2000'], 'summary' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($valid->fails()) {
            $this->fail($lead, 'Yapay zekâ geçersiz bir cevap döndürdü; "Yeniden yaz" ile tekrar dene.');

            return false;
        }
        $today = CarbonImmutable::now('Europe/Istanbul')->startOfDay();
        try {
            $sendOn = CarbonImmutable::parse((string) ($answer['send_on'] ?? ''), 'Europe/Istanbul')->startOfDay();
        } catch (Throwable) {
            $sendOn = $today;
        }
        if ($sendOn->lt($today) || $sendOn->gt($today->addDays(120))) {
            $sendOn = $today;
        }
        $text = trim(preg_replace('/^-{3,}\s*|\s*-{3,}$/u', '', trim((string) $answer['reply'])) ?? '');
        $lead->forceFill([
            'draft' => $text, 'draft_ai' => $text, 'draft_status' => 'ready', 'draft_at' => now(),
            'draft_note' => trim((string) ($answer['note'] ?? '')) ?: null, 'draft_summary' => trim((string) ($answer['summary'] ?? '')) ?: null,
            'draft_send_on' => $sendOn->toDateString(),
        ]);
        // The AI's day decides when the card comes up (wait = not today), unless the operator set a later step by hand.
        if ($lead->isOpen()) {
            $lead->next_step = $answer['action'] === 'wait' ? 'Mesajı gönder ('.$sendOn->locale('tr')->isoFormat('D MMMM dddd').')' : ($lead->next_step ?: 'Mesajı gönder');
            $lead->next_step_at = $sendOn->setTime(10, 0);
        }
        $lead->save();

        return true;
    }

    /**
     * The subscription Claude writes it in its next round; the card says when (no polling meanwhile).
     *
     * @param  array<string, mixed>  $payload
     */
    private function wait(SalesLead $lead, array $payload, ?string $why = null): bool
    {
        $note = $this->subscription->ask(new DraftSalesReply((int) $lead->id), new SalesReplyAgent, $payload, 'Satış mesajı: '.$lead->title(), $why);
        $lead->forceFill(['draft_status' => 'waiting', 'draft_note' => $note])->save();

        return false;
    }

    private function fail(SalesLead $lead, string $reason): void
    {
        $lead->forceFill(['draft_status' => 'failed', 'draft_note' => $reason])->save();
    }

    /** @return array<string, mixed> the LEAD_JSON pack */
    public function payload(SalesLead $lead): array
    {
        $now = CarbonImmutable::now('Europe/Istanbul')->locale('tr');
        $conversation = [];
        $quoted = [];
        foreach (app(SalesLeads::class)->timeline($lead, 120)->reverse()->values() as $row) {
            if (! in_array($row['kind'], ['message_in', 'message_out'], true) || trim($row['body']) === '') {
                continue;
            }
            $conversation[] = ['dir' => $row['kind'] === 'message_in' ? 'in' : 'out', 'at' => $row['at']->timezone('Europe/Istanbul')->format('d.m.Y H:i'),
                'text' => mb_substr($row['body'], 0, 3000)];
            if ($row['kind'] === 'message_out' && preg_match_all('/\d{1,3}(?:[.\s]\d{3})+\s*(?:TL|₺)/iu', $row['body'], $m)) {
                array_push($quoted, ...$m[0]);
            }
        }
        $notes = $lead->events()->whereIn('kind', ['note', 'call', 'meeting', 'offer'])->orderBy('happened_at')->limit(30)->get()
            ->map(fn (SalesLeadEvent $e): array => ['at' => CarbonImmutable::instance($e->happened_at)->timezone('Europe/Istanbul')->format('d.m.Y H:i'), 'kind' => $e->kind, 'text' => mb_substr((string) $e->body, 0, 2000)])->all();
        $recent = SalesLeadEvent::query()->where('kind', 'message_out')->where('lead_id', '!=', $lead->id)->whereNotNull('meta')
            ->orderByDesc('happened_at')->limit(40)->get()
            ->filter(fn (SalesLeadEvent $e): bool => (bool) data_get($e->meta, 'edited') && mb_strlen((string) $e->body) > 40)
            ->take(5)->map(fn (SalesLeadEvent $e): string => mb_substr((string) $e->body, 0, 2500))->values()->all();

        if ($lead->kind === null) {
            app(SalesLeads::class)->classify($lead);
        }
        $kind = LeadKinds::get($lead->kind);

        return [
            'current_time' => $now->isoFormat('YYYY-MM-DD HH:mm, dddd'),
            // Sabit kararlar (yakup 2026-11-08): the operator's standing rules win over everything else.
            'standing_rules' => StandingRules::text(),
            'lead' => array_filter([
                'name' => $lead->name, 'company' => $lead->company, 'city' => $lead->city, 'branch' => $lead->branch,
                'kind' => $kind['label'] ?? 'bilinmiyor', 'sector' => $lead->sectorCategory?->name, 'lifecycle' => $lead->lifecycleLabel(),
                'website' => $lead->website, 'source' => $lead->sourceLabel(), 'stage' => $lead->stageLabel(),
                'card_notes' => $lead->notes, 'opened' => $lead->created_at?->timezone('Europe/Istanbul')->format('d.m.Y'),
            ], fn ($v): bool => filled($v)),
            // What the Tür decides: how to address them, whether moximu.com's clinic prices may be written, health rules.
            'kind_rules' => [
                'known' => $kind !== null,
                'address' => LeadKinds::address($lead->kind) === 'hocam' ? 'X Hocam' : 'X Bey / X Hanım',
                'prices' => LeadKinds::pricesApply($lead->kind) ? 'moximu.com klinik fiyat listesi yazılabilir' : 'FİYAT YAZMA: [fiyat] bırak, note içinde yakup\'a fiyatı kendisinin yazacağını söyle',
                'health_rules' => LeadKinds::isHealth($lead->kind),
            ],
            'open_opportunity' => ($open = $lead->openOpportunity()) !== null ? $open->title().' · '.$open->amountLabel() : null,
            'operator_notes' => $notes,
            'conversation' => array_slice($conversation, -80),
            'conversation_truncated' => count($conversation) > 80,
            'quoted_prices' => array_values(array_unique($quoted)),
            // Yeni aday araştırması: what Claude found on the web (site, Instagram, Google harita kaydı).
            'research' => $lead->research_status === 'ready' ? $lead->research : null,
            'site_scan' => $lead->site_scan ?? null,
            'findings' => array_values(array_filter((array) ($lead->findings ?? []), 'is_array')),
            'site_read' => $lead->website_host === null ? 'no_site' : ($lead->findings_status === 'failed' ? 'could_not_read' : ($lead->findings_status === 'ready' ? 'read' : 'not_yet')),
            'recent_sent' => $recent,
        ];
    }
}
