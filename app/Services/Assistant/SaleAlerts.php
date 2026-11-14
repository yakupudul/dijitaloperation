<?php

namespace App\Services\Assistant;

use App\Models\DigitalAsset;
use App\Services\Sales\LeadIntake;

/**
 * Kasa sesi (yakup 2026-11-14, "ikas'taki gibi sipariş gelince ses"): the agency's own shop (hesap.moximu.com, theme
 * moximu-hesap) writes two events into the connector outbox: `commerce.order_paid` (a paid order) and
 * `commerce.application` (a free ön değerlendirme). Each becomes one phone notification. A paid order always rings
 * (also in izin modu and above the daily limit, PushPolicy OWN) and carries the cash register sound; an application
 * carries a soft chime and is silent at night. The sound itself is played by the open panel (public/sw.js →
 * resources/js/operator.js); with the app closed the phone's own notification sound rings.
 */
final class SaleAlerts
{
    public const string PAID = 'sale:paid:';

    public const string LEAD = 'sale:lead:';

    public const array TYPES = ['commerce.order_paid', 'commerce.application'];

    public function __construct(private readonly PushNotifier $push) {}

    /** The sound a notification carries, by its dedupe key ('' = none). */
    public static function soundOf(string $dedupeKey): string
    {
        return str_starts_with($dedupeKey, self::PAID) ? 'kasa' : (str_starts_with($dedupeKey, self::LEAD) ? 'zil' : '');
    }

    /** @param  list<array<string, mixed>>  $events  events just stored for this site */
    public function fromEvents(int $assetId, array $events): int
    {
        $sent = 0;
        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '');
            if (! in_array($type, self::TYPES, true)) {
                continue;
            }
            $site = $this->siteName($assetId);
            $title = trim((string) ($event['title'] ?? ''));
            $url = filled($event['url'] ?? null) && str_starts_with((string) $event['url'], 'https://') ? (string) $event['url'] : null;
            $id = $assetId.':'.(string) $event['object_id'];
            if ($type === 'commerce.order_paid') {
                $sent += $this->push->browser(self::PAID.$id, 'Yeni sipariş geldi', ($title !== '' ? $title : 'Ödeme alındı').' · '.$site, 'high', $url);

                continue;
            }
            $sent += $this->push->browser(self::LEAD.$id, 'Yeni ön değerlendirme başvurusu', ($title !== '' ? $title : 'Ücretsiz başvuru').' · '.$site,
                LeadIntake::quiet() ? 'low' : 'high', $url);
        }

        return $sent;
    }

    /** Sends the notification of a test order, the same way a real one goes (Bildirim › Kasa sesini dene). */
    public function test(int $userId): int
    {
        return $this->push->browser(self::PAID.'test:'.$userId.':'.now()->format('YmdHis'), 'Yeni sipariş geldi',
            'Deneme: Mevzuat denetimi · 3.000 ₺ · hesap.moximu.com', 'high', null);
    }

    private function siteName(int $assetId): string
    {
        $asset = DigitalAsset::query()->find($assetId);
        $host = (string) ($asset?->domain ?: (parse_url((string) $asset?->primary_url, PHP_URL_HOST) ?: $asset?->name));

        return $host !== '' ? (string) preg_replace('/^www\./', '', $host) : 'site';
    }
}
