<?php

namespace App\Services\Sales;

use App\Models\SalesLead;
use App\Models\User;
use App\Services\Calendar\QuickCapture;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Yaz ya da söyle" (Satış Düzeni 2.7): one sentence typed or dictated on Satış, "Serap Hanım perşembe arayacak", finds
 * the person, is kept on the record as a note (or a call when it says so) and, when it names a day, becomes the next
 * step on that day; the text for that step is written on its morning like every due step. Plain rules, no AI: the
 * person is found by the words of the name or business (or the last four digits of the number), the day by the same
 * reader as Takvim's quick note. When more than one person fits, the caller asks which.
 */
final class SayIt
{
    /** Words that say nothing about who it is. */
    private const array STOP = [
        'dr', 'dt', 'uzm', 'op', 'prof', 'doc', 'bey', 'hanim', 'hoca', 'hocam', 'abi', 'abla', 'sayin', 'klinik', 'klinigi', 'dis', 'poliklinigi',
        'poliklinik', 'merkezi', 'merkez', 'saglik', 'ozel', 'agiz', 've', 'ile', 'icin', 'bir', 'bugun', 'yarin', 'hafta', 'haftaya', 'gun', 'sonra',
        'pazartesi', 'sali', 'carsamba', 'persembe', 'cuma', 'cumartesi', 'pazar', 'arayacak', 'arayacagim', 'ara', 'aradi', 'aradim', 'yaz', 'yazacak',
    ];

    /**
     * @return array{matches: Collection<int, SalesLead>, at: ?CarbonImmutable, what: string}
     */
    public function understand(string $text): array
    {
        $parsed = QuickCapture::parse($text);
        $said = self::words($text);
        $digits = preg_match_all('/(?<!\d)\d{4}(?!\d)/', $text, $m) > 0 ? $m[0] : [];
        $scored = SalesLead::query()->where('lifecycle', '!=', 'irrelevant')->get(['id', 'name', 'company', 'phone', 'phone_key', 'website_host', 'stage', 'lifecycle', 'archived_at', 'updated_at'])
            ->map(function (SalesLead $lead) use ($said, $digits): array {
                $own = array_unique(array_merge(self::words((string) $lead->name), self::words((string) $lead->company)));
                $hits = count(array_intersect($own, $said));
                // Every word of a short name must be said ("Serap" alone finds "Serap Hanım", not "Serap Yıldız Kliniği" first).
                $score = $own === [] ? 0 : $hits + ($hits === count($own) ? 1 : 0);
                foreach ($digits as $four) {
                    $score += str_ends_with((string) preg_replace('/\D+/', '', (string) $lead->phone), $four) ? 3 : 0;
                }
                // An open, live record wins a tie against a closed or archived one.
                $score = $score > 0 ? $score + ($lead->isOpen() && $lead->archived_at === null ? 0.5 : 0) : 0;

                return ['lead' => $lead, 'score' => $score];
            })->filter(fn (array $row): bool => $row['score'] > 0)->sortByDesc('score')->values();
        $top = (float) ($scored->first()['score'] ?? 0);

        return [
            'matches' => $scored->filter(fn (array $row): bool => $row['score'] === $top)->take(6)->map(fn (array $row): SalesLead => $row['lead'])->values(),
            'at' => $parsed['at'],
            'what' => $parsed['title'] !== '' ? $parsed['title'] : trim($text),
        ];
    }

    /** Writes the sentence on the record; with a day in it the record's next step moves to that day. */
    public function apply(SalesLead $lead, string $text, SalesLeads $leads, ?User $user = null): string
    {
        $lead = SalesLead::query()->findOrFail($lead->id);
        $read = $this->understand($text);
        $text = trim($text);
        $called = preg_match('/(?<![\p{L}])(aradım|aradim|aradı|aradi|görüştüm|gorustum|görüştük|gorustuk|konuştum|konustum|konuştuk|konustuk)(?![\p{L}])/iu', $text) === 1;
        $leads->event($lead, $called ? 'call' : 'note', mb_substr($text, 0, 4000), meta: ['by' => $user?->id, 'said' => true]);
        if ($called) {
            $lead->last_contact_at = now();
            $lead->save();
        }
        if ($read['at'] !== null && $lead->isOpen()) {
            $leads->setNextStep($lead, mb_substr($read['what'], 0, 255), $read['at']->toDateString(), $user);

            return $lead->title().': not işlendi, adım '.$read['at']->locale('tr')->isoFormat('D MMMM dddd').' gününe kuruldu. Metni o sabah hazır olur.';
        }

        return $lead->title().': not işlendi.'.($read['at'] !== null ? ' Kayıt kapalı olduğu için adım kurulmadı.' : '');
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        return array_values(array_filter(explode(' ', LeadClassifier::fold($text)), fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, self::STOP, true) && ! ctype_digit($w)));
    }
}
