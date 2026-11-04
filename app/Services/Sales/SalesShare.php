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
        $parsed = WhatsAppChatExport::parse($content, $fileName);
        if ($parsed['messages'] === []) {
            return ['lead' => null, 'notice' => 'Dosyada WhatsApp mesajı okunamadı. Sohbeti "medya olmadan" dışa aktarıp yeniden paylaş.'];
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
        $lead = $contact !== null ? SalesLead::query()->whereRaw('lower(name) = ?', [mb_strtolower($contact)])->orderByDesc('id')->first() : null;
        if ($lead === null) {
            $lead = $this->leads->quickAdd(['name' => $contact ?? 'WhatsApp sohbeti', 'source' => 'whatsapp'], $user);
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
        if ($outgoing > 0 && $lead->stage === 'new') {
            $lead->stage = 'contacted';
            $lead->followup_step = max($lead->followup_step, 1);
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
        $this->leads->event($lead, 'share', 'Sohbet dosyası paylaşıldı: '.$added.' yeni mesaj ('.$fileName.').');

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
