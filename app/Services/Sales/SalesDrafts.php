<?php

namespace App\Services\Sales;

use App\Models\SalesLead;

/**
 * Paste-ready WhatsApp texts by yakup's rules (2026-10-31 → 2026-11-03): a hot lead gets at most five sentences —
 * one finding, one consequence, what we do, the price, one yes/no question; a silent lead gets three or four numbered
 * problems with no price; follow-ups are one line. "Hocam"/"siz", no emoji, never a minimum term. The price line is
 * the agreed offer; nothing here is sent by MoxDOP.
 */
final class SalesDrafts
{
    public const string PRICE = 'Aylık 12.000 TL + KDV';

    /** The text for the lead's current step: who wrote last, how many we sent, what findings we have. */
    public static function forLead(SalesLead $lead): string
    {
        $findings = self::findings($lead);
        $greeting = self::greeting($lead);
        $site = filled($lead->website_host) ? $lead->website_host : 'web siteniz';
        $theyWroteLast = $lead->last_incoming_at !== null && ($lead->last_contact_at === null || $lead->last_incoming_at->gt($lead->last_contact_at));
        $sent = (int) $lead->followup_step;

        if ($theyWroteLast || $sent === 0) {
            if ($findings === []) {
                return $greeting.' '.$site.' ve Google İşletme Profilinizi inceledik. Hangi şehirde hangi tedavi için hasta kazanmak istiyorsunuz? Bir bulguyla ve net bir öneriyle dönelim.';
            }
            if ($lead->source === 'whatsapp' && $theyWroteLast) {
                $first = $findings[0];

                return $greeting.' '.$first['title'].'. '.$first['consequence'].' Biz '.self::service($lead).'. '.self::PRICE.'. Pazartesi başlayalım mı?';
            }
            // A silent or cold lead: numbered problems, no price (yakup 2026-11-03).
            $lines = [$greeting.' '.$site.' ve Google İşletme Profilinizi inceledik.'];
            foreach (array_slice($findings, 0, 4) as $i => $finding) {
                $lines[] = ($i + 1).'. *'.$finding['title'].'* '.$finding['fix'];
            }
            $lines[] = 'Her ay kaç arama, WhatsApp ve form geldiğini tek sayfada raporlarız. Başlamak ister misiniz?';

            return implode("\n", $lines);
        }

        return match (true) {
            $sent === 1 => $greeting.' Dünkü mesajımı görme fırsatınız oldu mu? Tek bir sorunuza cevap vereyim, karar sizin.',
            $sent === 2 => $greeting.' Bu hafta sitenizde bir şey daha fark ettim: '.($findings[1]['title'] ?? $findings[0]['title'] ?? 'telefon numaranız mobilde tek dokunuşla aranmıyor').'. İsterseniz bunu ücretsiz göstereyim, gerisini birlikte konuşuruz.',
            default => $greeting.' Şu an öncelik değilse sorun değil; '.self::nextMonthWord().' tekrar yazayım mı, yoksa kapatalım mı?',
        };
    }

    /** @return list<array{title: string, consequence: string, fix: string}> */
    private static function findings(SalesLead $lead): array
    {
        $rows = [];
        foreach ((array) ($lead->findings ?? []) as $finding) {
            if (! is_array($finding) || ! filled($finding['title'] ?? null)) {
                continue;
            }
            $rows[] = [
                'title' => (string) $finding['title'],
                'consequence' => (string) ($finding['consequence'] ?? 'Bu, arayan hastaların bir kısmını kaybettiriyor.'),
                'fix' => (string) ($finding['fix'] ?? 'İlk hafta düzeltiriz.'),
            ];
        }

        return $rows;
    }

    private static function greeting(SalesLead $lead): string
    {
        $name = trim((string) $lead->name);
        if ($name === '') {
            return 'Merhaba,';
        }
        $first = explode(' ', $name)[0];
        if (preg_match('/^(dr|dt|prof|doç|op|uzm)\.?$/iu', $first)) {
            return 'Hocam merhaba,';
        }

        return $name.' merhaba,';
    }

    private static function service(SalesLead $lead): string
    {
        return match ($lead->branch !== null && preg_match('/diş|dent/iu', (string) $lead->branch) === 1) {
            true => 'kliniğinizi Google aramalarında ve haritada öne çıkarır, her ay kaç hasta aradığını raporlarız',
            default => 'kliniğinizi Google aramalarında ve haritada öne çıkarır, her ay kaç danışan aradığını raporlarız',
        };
    }

    private static function nextMonthWord(): string
    {
        return now('Europe/Istanbul')->addMonth()->locale('tr')->isoFormat('MMMM').' ayında';
    }
}
