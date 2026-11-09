<?php

namespace App\Support\WhatsApp;

/**
 * A stored message body in words a person reads (yakup 2026-11-09: "çok sistem hissettiriyor"): the placeholders the
 * importers wrote for content WhatsApp did not hand over ("[errors — metin olarak çözümlenmedi]") become a short plain
 * phrase. The stored text is never changed; only what the screens show.
 */
final class MessageText
{
    private const array TYPES = [
        'errors' => 'Okunamayan mesaj, telefonda görün', 'unsupported' => 'Okunamayan mesaj, telefonda görün',
        'media_placeholder' => 'Fotoğraf, video ya da dosya', 'image' => 'Fotoğraf', 'video' => 'Video', 'audio' => 'Sesli mesaj',
        'ptt' => 'Sesli mesaj', 'document' => 'Belge', 'sticker' => 'Çıkartma', 'gif' => 'GIF', 'location' => 'Konum',
        'contacts' => 'Kişi kartı', 'call_log' => 'Arama', 'system' => 'WhatsApp bildirimi', 'deleted' => 'Silinen mesaj',
    ];

    public static function clean(string $body): string
    {
        $body = trim($body);
        if (preg_match('/^\[([a-z_]+) — metin olarak çözümlenmedi\]$/u', $body, $m) === 1) {
            return '('.(self::TYPES[$m[1]] ?? 'Telefonda görülecek bir mesaj').')';
        }
        if (preg_match('/^\[WhatsApp bu mesajın içeriğini paylaşmadı[^\]]*\]$/u', $body) === 1) {
            return '(Okunamayan mesaj, telefonda görün)';
        }

        return $body;
    }
}
