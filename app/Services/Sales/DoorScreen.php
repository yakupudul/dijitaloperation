<?php

namespace App\Services\Sales;

use App\Models\SalesLead;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;

/**
 * Kapıda eleme (Satış Düzeni 2.2): who writes to the agency's line is read at the door, by plain rules (no AI, no cost).
 * A patient looking for an appointment, a job seeker and a wrong number are not sales work: they do not become a card
 * in Adaylar, get no ready answer and no research; they wait on one line under Satış › Aday olmayanlar, where one tap
 * ("Adaya çevir") brings a wrong call back. Only somebody we have never written to is judged, and any word of a
 * business owner (site, reklam, klinik sahibi…) keeps the record a prospect: when in doubt it stays a card.
 */
final class DoorScreen
{
    /** @var array<string, string> */
    public const array KINDS = ['patient' => 'Hasta', 'job' => 'İş arayan', 'wrong' => 'Yanlış numara'];

    /** How many of their first messages are read. */
    private const int READ = 6;

    /** Words of somebody who owns or runs a business and asks about our work: never screened out. */
    private const array PROSPECT_WORDS = [
        'site', 'web', 'seo', 'google', 'reklam', 'ajans', 'klinigim', 'klinigimiz', 'muayenehanem', 'muayenehanemiz', 'isletme', 'isyerim', 'firma',
        'sirket', 'harita', 'instagram', 'sosyal medya', 'teklif', 'paket', 'hizmetleriniz', 'analiz', 'denetim', 'moximu', 'dijital', 'tanitim',
        'musteri', 'hasta kazan', 'hasta sayi', 'marka', 'logo', 'tasarim', 'yazilim', 'e ticaret', 'kampanya', 'aylik', 'danismanlik', 'pazarlama',
    ];

    private const array RULES = [
        'job' => ['is ariyorum', 'is basvuru', 'is ilani', 'ozgecmis', 'cv mi', 'cv gonder', 'cvmi', 'staj', 'eleman ariyor', 'eleman aliyor',
            'personel aliyor', 'calismak istiyorum', 'is imkani', 'acik pozisyon'],
        'wrong' => ['yanlis numara', 'yanlis yazdim', 'yanlis yazmisim', 'yanlislikla', 'yanlis kisi', 'yanlis oldu', 'yanlis mesaj'],
        'patient' => ['randevu almak', 'randevu alabilir', 'randevu istiyorum', 'randevu olusturmak', 'randevum', 'muayene olmak', 'muayene ucreti',
            'disim', 'dis agri', 'agriyor', 'agrim var', 'tedavi olmak', 'ameliyat olmak', 'doktor bey musait', 'hocam musait mi', 'nobetci',
            'implant yaptirmak', 'dolgu yaptirmak', 'dis cektirmek', 'sac ektirmek', 'botoks yaptirmak', 'kontrole gel'],
    ];

    /**
     * @return array{kind: string, why: string}|null null = a prospect (or not enough to tell)
     */
    public static function judge(string $text): ?array
    {
        $folded = ' '.LeadClassifier::fold($text).' ';
        if (trim($folded) === '') {
            return null;
        }
        foreach (self::PROSPECT_WORDS as $word) {
            if (str_contains($folded, ' '.$word)) {
                return null;
            }
        }
        foreach (self::RULES as $kind => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($folded, LeadClassifier::fold($phrase))) {
                    return ['kind' => $kind, 'why' => $phrase];
                }
            }
        }

        return null;
    }

    /**
     * Judges a card from its chat. True when it was put aside at the door (the caller stops: no answer, no research).
     */
    public function screen(SalesLead $lead, WhatsAppConversation $conversation, SalesLeads $leads): bool
    {
        $door = (array) data_get($lead->classification, 'door', []);
        // Judged once at most per verdict; the operator's "Adaya çevir" (kept) ends it for good.
        if (($door['kept'] ?? false) === true || isset($door['kind']) || ! $lead->exists || ! $lead->isOpen() || $lead->isCustomer() || $lead->history_only
            || $lead->isArchived() || $lead->source !== 'whatsapp' || $lead->stage !== 'new' || $lead->last_contact_at !== null || $lead->heard_from === 'referral') {
            return false;
        }
        $messages = WhatsAppMessage::query()->where('conversation_id', $conversation->id);
        if ((clone $messages)->where('direction', 'outgoing')->exists()) {
            return false;
        }
        $text = (clone $messages)->where('direction', 'incoming')->where('is_history', false)->orderBy('sent_at')->limit(self::READ)->get()
            ->map(fn (WhatsAppMessage $m): string => (string) $m->body)->implode("\n");
        $verdict = self::judge($text);
        if ($verdict === null) {
            return false;
        }
        $lead->classification = array_merge((array) $lead->classification, ['door' => [
            'kind' => $verdict['kind'], 'why' => $verdict['why'], 'at' => now()->toIso8601String(), 'said' => mb_substr(trim($text), 0, 240),
        ]]);
        $lead->save();
        $leads->markIrrelevant($lead);
        $leads->event($lead, 'system', 'Kapıda elendi: '.self::KINDS[$verdict['kind']].' ("'.$verdict['why'].'"). Kart açılmadı; yanlışsa "Adaya çevir".');

        return true;
    }

    /** The door verdict a record carries, or null (a prospect, or brought back by the operator). */
    public static function verdict(SalesLead $lead): ?string
    {
        $door = (array) data_get($lead->classification, 'door', []);

        return ($door['kept'] ?? false) !== true && isset($door['kind'], self::KINDS[$door['kind']]) && $lead->lifecycle === 'irrelevant' ? self::KINDS[$door['kind']] : null;
    }

    /** "Adaya çevir" / any open status chosen by hand: the door never judges this record again. */
    public static function keep(SalesLead $lead): void
    {
        if (data_get($lead->classification, 'door.kind') !== null) {
            $lead->classification = array_merge((array) $lead->classification, ['door' => array_merge((array) data_get($lead->classification, 'door', []), ['kept' => true])]);
        }
    }
}
