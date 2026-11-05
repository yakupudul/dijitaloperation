<?php

namespace App\Services\Sales;

use App\Models\AgencySetting;
use App\Models\SalesLead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/**
 * "Paylaş → MoxDOP" from the phone (the installed app is a share target): a site link opens a card and its site
 * check; an exported WhatsApp chat (.txt) lands on the card of that person with every message; plain text with a
 * phone number opens a card. The result is where the operator is sent.
 */
final class SalesShare
{
    public function __construct(private SalesLeads $leads) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return array{lead: ?SalesLead, notice: string}
     */
    public function receive(?string $title, ?string $text, ?string $url, array $files, ?User $user): array
    {
        $title = trim((string) $title);
        $text = trim((string) $text);
        $url = trim((string) $url);
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            $name = (string) $file->getClientOriginalName();
            if (preg_match('/\.(txt|zip)$/iu', $name) || str_starts_with((string) $file->getMimeType(), 'text/')) {
                $content = preg_match('/\.zip$/iu', $name) ? $this->textFromZip($file->getRealPath()) : (string) file_get_contents((string) $file->getRealPath());
                if ($content !== '') {
                    return $this->chat($content, $name, $user);
                }
            }
        }
        $link = $this->firstUrl($url) ?? $this->firstUrl($text) ?? $this->firstUrl($title);
        if ($link !== null) {
            return $this->site($link, $text !== $link ? $text : '', $user);
        }
        if (preg_match('/(?:\+?90|0)?\s*5\d{2}[\s.-]?\d{3}[\s.-]?\d{2}[\s.-]?\d{2}/u', $text.' '.$title, $m)) {
            $lead = $this->leads->quickAdd(['phone' => trim($m[0]), 'name' => $title !== '' && $title !== trim($m[0]) ? $title : null, 'source' => 'phone', 'note' => $text], $user);

            return ['lead' => $lead, 'notice' => 'Telefon numarasından kart açıldı.'];
        }

        return ['lead' => null, 'notice' => $text !== '' || $title !== '' ? 'Paylaşılan metinde site, telefon ya da sohbet dosyası yok. Hızlı ekle ile kartı kendin açabilirsin.' : 'Paylaşımda içerik yoktu.'];
    }

    /** @return array{lead: SalesLead, notice: string} */
    private function site(string $link, string $note, ?User $user): array
    {
        $host = SalesLeads::host($link);
        $existing = $host !== null ? $this->leads->match(null, $host) : null;
        $lead = $this->leads->quickAdd(['website' => $link, 'source' => 'link', 'note' => $note !== '' ? $note : null], $user);
        if ($existing !== null) {
            ProspectSiteCheck::queue($lead);

            return ['lead' => $lead, 'notice' => $host.' zaten kayıtlıydı; site yeniden kontrol ediliyor.'];
        }
        $this->leads->event($lead, 'share', 'Site linki paylaşıldı: '.$link);

        return ['lead' => $lead, 'notice' => ($host ?? 'Site').' için kart açıldı; bulgular ve ilk mesaj birkaç saniye içinde hazır.'];
    }

    /** @return array{lead: ?SalesLead, notice: string} */
    private function chat(string $content, string $fileName, ?User $user): array
    {
        return $this->importChat($content, $fileName, $user);
    }

    /**
     * A WhatsApp conversation (an exported file or text copied from WhatsApp Web / the phone) onto a card: the given
     * card, else the card with the contact's phone or name, else a new one. Messages already on the card are skipped.
     *
     * @return array{lead: ?SalesLead, notice: string}
     */
    public function importChat(string $content, string $fileName, ?User $user, ?SalesLead $into = null): array
    {
        $parsed = WhatsAppChatExport::parse($content, $fileName);
        if ($parsed['messages'] === []) {
            return ['lead' => $into, 'notice' => 'Metinde WhatsApp mesajı okunamadı. Mesajları tarih ve saatleriyle birlikte kopyala ("[22:35, 30.10.2026] Ad: mesaj" biçiminde).'];
        }
        $own = WhatsAppChatExport::ownSender($parsed['messages'], $parsed['contact'], array_filter([
            (string) (AgencySetting::query()->value('agency_name') ?? ''), (string) ($user?->name ?? ''), 'Moximu', 'Yakup Udul', 'Siz', 'You',
        ]));
        $contact = $parsed['contact'];
        if ($contact === null) {
            // No name in the file: the other sender is the contact.
            foreach (array_unique(array_column($parsed['messages'], 'sender')) as $sender) {
                if ($sender !== $own) {
                    $contact = $sender;
                    break;
                }
            }
        }
        // A contact saved without a name shows as its number ("+90 533 500 03 25"): that is the card's phone.
        $contactPhone = $contact !== null && preg_match('/^\+?[\d\s().-]{10,20}$/', $contact) ? $contact : null;
        $lead = $into
            ?? ($contactPhone !== null ? $this->leads->match(SalesLeads::phoneKey($contactPhone), null) : null)
            ?? ($contact !== null ? SalesLead::query()->whereRaw('lower(name) = ?', [mb_strtolower($contact)])->orderByDesc('id')->first() : null);
        if ($lead === null) {
            $lead = $this->leads->quickAdd($contactPhone !== null ? ['phone' => $contactPhone, 'source' => 'whatsapp'] : ['name' => $contact ?? 'WhatsApp sohbeti', 'source' => 'whatsapp'], $user);
        }
        if ($contactPhone !== null && ! filled($lead->phone)) {
            $lead->phone = $contactPhone;
            $lead->phone_key = SalesLeads::phoneKey($contactPhone);
        }
        $added = 0;
        $lastIn = $lead->last_incoming_at;
        $lastOut = $lead->last_contact_at;
        $outgoing = 0;
        foreach ($parsed['messages'] as $message) {
            $isOwn = $own !== null && $message['sender'] === $own;
            $fingerprint = hash('sha256', $message['at']->format('YmdHi').'|'.$message['sender'].'|'.$message['body']);
            $event = $this->leads->event($lead, $isOwn ? 'message_out' : 'message_in', $message['body'], $message['at'], ['sender' => $message['sender'], 'export' => true], $fingerprint);
            $added += $event !== null ? 1 : 0;
            if ($isOwn) {
                $outgoing++;
                $lastOut = $lastOut === null || $message['at']->gt($lastOut) ? $message['at'] : $lastOut;
            } else {
                $lastIn = $lastIn === null || $message['at']->gt($lastIn) ? $message['at'] : $lastIn;
            }
        }
        $lead->last_incoming_at = $lastIn;
        $lead->last_contact_at = $lastOut;
        // A site named in the chat lands on the card and is read at once (the next text then uses its numbers).
        $siteFound = false;
        if ($lead->website_host === null) {
            foreach ($parsed['messages'] as $message) {
                $link = $this->firstUrl((string) $message['body']);
                $host = $link !== null ? SalesLeads::host($link) : null;
                if ($host !== null && preg_match('/\.(com|net|org|tr|de|nl|uk|eu|info|clinic|health|dental|dentist|doctor|med|io|co)$/i', $host) && ! preg_match('/(^|\.)(moximu\.com|wa\.me|whatsapp\.com|google\.[a-z.]+|goo\.gl|g\.page|instagram\.com|facebook\.com|youtube\.com|youtu\.be|tiktok\.com|linkedin\.com|doktortakvimi\.com|maps\.app\.goo\.gl)$/i', $host)) {
                    $lead->website = preg_match('#^https?://#i', $link) ? $link : 'https://'.$link;
                    $lead->website_host = $host;
                    $siteFound = true;
                    break;
                }
            }
        }
        if ($outgoing > 0) {
            $lead->advanceTo('contacted');
            $lead->followup_step = max($lead->followup_step, 1);
            $ours = collect($parsed['messages'])->filter(fn (array $m): bool => $own !== null && $m['sender'] === $own);
            $theirs = collect($parsed['messages'])->reject(fn (array $m): bool => $own !== null && $m['sender'] === $own);
            if ($theirs->isNotEmpty()) {
                $lead->advanceTo('talking');
            }
            if ($ours->contains(fn (array $m): bool => preg_match('/\d{1,3}[\.\s]?\d{3}\s*(tl|₺|\+\s*kdv)/iu', (string) $m['body']) === 1)) {
                $lead->advanceTo('priced');
            }
            $last = collect($parsed['messages'])->last();
            if ($last !== null && ($own === null || $last['sender'] !== $own) && SalesLeads::isThinking((string) $last['body'])) {
                $lead->advanceTo('thinking');
            }
        }
        if ($lead->isOpen()) {
            $theyWroteLast = $lastIn !== null && ($lastOut === null || $lastIn->gt($lastOut));
            [$lead->next_step, $lead->next_step_at] = $theyWroteLast ? FollowUpSequence::answerNow()
                : ($lastOut !== null ? FollowUpSequence::afterOurMessage(max(1, $lead->followup_step), CarbonImmutable::instance($lastOut)) : FollowUpSequence::firstMessage());
            if ($lead->next_step_at->lt(now())) {
                $lead->next_step_at = CarbonImmutable::now('Europe/Istanbul');
            }
        }
        $lead->save();
        if ($siteFound) {
            ProspectSiteCheck::queue($lead);
        }
        $this->leads->event($lead, 'share', ($fileName !== '' ? 'Sohbet dosyası paylaşıldı' : 'Sohbet yapıştırıldı').': '.$added.' yeni mesaj'.($fileName !== '' ? ' ('.$fileName.')' : '').'.');

        return ['lead' => $lead, 'notice' => $added.' mesaj karta işlendi.'.($own === null ? ' Hangi mesajların senin olduğu anlaşılamadı; Ayarlar › Genel\'deki ajans adı dosyadaki gönderen adıyla aynı olmalı.' : '')];
    }

    private function firstUrl(string $text): ?string
    {
        if ($text === '') {
            return null;
        }
        if (preg_match('#https?://[^\s<>"\']+#iu', $text, $m)) {
            return rtrim($m[0], '.,;)');
        }
        if (preg_match('/\b((?:www\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,})(?:\/\S*)?\b/iu', $text, $m) && ! str_contains($m[0], '@')) {
            return 'https://'.rtrim($m[0], '.,;)');
        }

        return null;
    }

    private function textFromZip(string $path): string
    {
        if (! class_exists(\ZipArchive::class)) {
            return '';
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return '';
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('/\.txt$/iu', $name)) {
                    return (string) $zip->getFromIndex($i);
                }
            }
        } finally {
            $zip->close();
        }

        return '';
    }
}
