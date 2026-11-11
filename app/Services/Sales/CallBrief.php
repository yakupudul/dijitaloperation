<?php

namespace App\Services\Sales;

use App\Models\SalesLead;
use App\Models\SalesLesson;
use App\Models\WhatsAppMessage;
use App\Support\SchemaState;
use App\Support\WhatsApp\MessageText;

/**
 * Görüşme öncesi tek sayfa (Satış Düzeni 3.5): who it is, what was found, what they will likely object to and the
 * price, on one screen before a call. Put together by plain rules from what the record already holds (the research,
 * the site check, their own words, the offer, the price list); nothing is written by AI here, so it opens at once.
 */
final class CallBrief
{
    /** Their own words that tell an objection is coming: pattern => what to be ready for. */
    private const array OBJECTIONS = [
        '/pahal[ıi]|bütçe|butce|indirim|uygun fiyat|ne kadar|fiyat/iu' => 'Fiyatı konuşacak. Listedeki fiyat geçerli; indirim sözü verme.',
        '/ajans/iu' => 'Bir ajansla çalışıyor ya da çalışmış. Neyin eksik kaldığını sor.',
        '/düşün|dusun|bakar[ıi]z|dönüş yap|donus yap|haber ver|sonra konuş/iu' => 'Kararı erteleyebilir. Görüşmeyi bir sonraki adımın günüyle bitir.',
        '/garanti|kaç ayda|kac ayda|ne zaman sonuç|ne zaman sonuc|ilk sayfa/iu' => 'Sonuç süresi ya da garanti soracak. Garanti verilmez; ilk ayda nelerin yapılacağını anlat.',
        '/sözleşme|sozlesme|taahhüt|taahhut/iu' => 'Süre taahhüdünü soracak. Asgari süre yok.',
    ];

    /**
     * @return array{who: list<string>, said: list<array{at: string, text: string}>, findings: list<array{title: string, detail: string}>, objections: list<array{note: string, quote: string}>, rules: list<string>, price: array{line: string, list: list<string>}}
     */
    public function of(SalesLead $lead): array
    {
        $research = is_array($lead->research) ? $lead->research : [];
        $maps = (array) ($research['maps'] ?? []);
        $insta = (array) ($research['instagram_facts'] ?? []);
        $who = array_values(array_filter([
            collect([filled($lead->name) ? $lead->company : null, $lead->city, $lead->kindLabel()])->filter()->unique()->implode(' · '),
            collect([SalesLead::SOURCES[$lead->source] ?? null, filled($lead->heard_from) ? 'bizi '.mb_strtolower(SalesLead::HEARD_FROM[$lead->heard_from] ?? (string) $lead->heard_from).' ile buldu' : null])->filter()->implode(', '),
            filled($lead->website) ? 'Site: '.$lead->website : 'Sitesi kayıtlı değil',
            filled($maps['rating'] ?? null) || filled($maps['reviews'] ?? null) ? 'Haritalar: '.trim(($maps['rating'] ?? '').' puan, '.($maps['reviews'] ?? '').' yorum', ' ,').(filled($maps['category'] ?? null) ? ' · '.$maps['category'] : '') : null,
            filled($insta['followers'] ?? null) ? 'Instagram: '.$insta['followers'].' takipçi'.(filled($insta['last_post'] ?? null) ? ', son gönderi '.$insta['last_post'] : '') : null,
            ($research['confidence'] ?? null) === 'low' ? 'Araştırma bu kişinin kim olduğundan emin değil; görüşmede işletme adını doğrula.' : null,
        ]));

        $words = $this->theirWords($lead);
        $objections = [];
        foreach (self::OBJECTIONS as $pattern => $note) {
            $hit = collect($words)->first(fn (array $w): bool => preg_match($pattern, $w['text']) === 1);
            if ($hit !== null) {
                $objections[] = ['note' => $note, 'quote' => mb_strimwidth($hit['text'], 0, 140, '…')];
            }
        }

        $findings = collect((array) ($research['findings'] ?? []))->filter(fn ($f): bool => is_array($f) && filled($f['title'] ?? null))
            ->map(fn (array $f): array => ['title' => (string) $f['title'], 'detail' => (string) ($f['detail'] ?? '')]);
        if ($findings->isEmpty()) {
            $findings = collect((array) ($lead->findings ?? []))->filter(fn ($f): bool => is_array($f) && filled($f['title'] ?? null) && ($f['severity'] ?? 'info') !== 'info')
                ->map(fn (array $f): array => ['title' => (string) $f['title'], 'detail' => (string) ($f['consequence'] ?? '')]);
        }

        $offer = (int) $lead->offer_amount;

        return [
            'who' => $who,
            'said' => array_slice($words, 0, 3),
            'findings' => $findings->take(5)->values()->all(),
            'objections' => $objections,
            'rules' => SchemaState::hasTable('sales_lessons') ? SalesLesson::query()->approved()->whereIn('kind', ['karar', 'yontem'])->orderByDesc('id')->limit(3)->pluck('text')->map(fn ($t): string => (string) $t)->all() : [],
            'price' => [
                'line' => $offer > 0 ? 'Verilen teklif: '.number_format($offer, 0, ',', '.').' ₺/ay'.($lead->serviceLabel() ? ' · '.$lead->serviceLabel() : '').($lead->offer_confirmed ? '' : ' (mesajdan okundu, doğrula)') : 'Henüz fiyat verilmedi. Listeden söyle:',
                'list' => $offer > 0 ? [] : array_slice(array_values(array_filter(array_map('trim', explode("\n", StandingRules::prices())))), 0, 8),
            ],
        ];
    }

    /**
     * What they wrote or said, newest first: WhatsApp messages and the record's own incoming notes.
     *
     * @return list<array{at: string, text: string}>
     */
    private function theirWords(SalesLead $lead): array
    {
        $rows = $lead->events()->whereIn('kind', ['message_in', 'call', 'meeting'])->orderByDesc('happened_at')->orderByDesc('id')->limit(20)->get()
            ->map(fn ($e): array => ['ts' => $e->happened_at?->getTimestamp() ?? 0, 'text' => trim((string) $e->body)]);
        if ($lead->conversation_id !== null) {
            $rows = $rows->concat(WhatsAppMessage::query()->where('conversation_id', $lead->conversation_id)->where('direction', '!=', 'outgoing')->whereNotNull('body')
                ->orderByDesc('sent_at')->orderByDesc('id')->limit(30)->get(['body', 'sent_at'])
                ->map(fn (WhatsAppMessage $m): array => ['ts' => $m->sent_at?->getTimestamp() ?? 0, 'text' => MessageText::clean((string) $m->body)]));
        }

        return $rows->filter(fn (array $r): bool => $r['text'] !== '')->unique('text')->sortByDesc('ts')->values()
            ->map(fn (array $r): array => ['at' => $r['ts'] > 0 ? date('d.m', $r['ts'] + 3 * 3600) : '', 'text' => (string) preg_replace('/\s+/u', ' ', $r['text'])])->all();
    }
}
