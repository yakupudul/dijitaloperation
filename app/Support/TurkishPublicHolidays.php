<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Turkish official holidays 2026–2027 (national days and the two bayrams, Diyanet calendar). Used by the Business
 * Profile "special hours" standard. Religious holiday dates move every year: extend the table each year.
 */
final class TurkishPublicHolidays
{
    /** @var array<string, array{name: string, dates: list<string>}> */
    private const array HOLIDAYS = [
        '2026-new-year' => ['name' => 'Yılbaşı', 'dates' => ['2026-01-01']],
        '2026-ramazan' => ['name' => 'Ramazan Bayramı', 'dates' => ['2026-03-19', '2026-03-20', '2026-03-21', '2026-03-22']],
        '2026-04-23' => ['name' => 'Ulusal Egemenlik ve Çocuk Bayramı', 'dates' => ['2026-04-23']],
        '2026-05-01' => ['name' => 'Emek ve Dayanışma Günü', 'dates' => ['2026-05-01']],
        '2026-05-19' => ['name' => 'Atatürk’ü Anma, Gençlik ve Spor Bayramı', 'dates' => ['2026-05-19']],
        '2026-kurban' => ['name' => 'Kurban Bayramı', 'dates' => ['2026-05-26', '2026-05-27', '2026-05-28', '2026-05-29', '2026-05-30']],
        '2026-07-15' => ['name' => 'Demokrasi ve Milli Birlik Günü', 'dates' => ['2026-07-15']],
        '2026-08-30' => ['name' => 'Zafer Bayramı', 'dates' => ['2026-08-30']],
        '2026-10-29' => ['name' => 'Cumhuriyet Bayramı', 'dates' => ['2026-10-28', '2026-10-29']],
        '2027-new-year' => ['name' => 'Yılbaşı', 'dates' => ['2027-01-01']],
        '2027-ramazan' => ['name' => 'Ramazan Bayramı', 'dates' => ['2027-03-08', '2027-03-09', '2027-03-10', '2027-03-11']],
        '2027-04-23' => ['name' => 'Ulusal Egemenlik ve Çocuk Bayramı', 'dates' => ['2027-04-23']],
        '2027-05-01' => ['name' => 'Emek ve Dayanışma Günü', 'dates' => ['2027-05-01']],
        '2027-kurban' => ['name' => 'Kurban Bayramı', 'dates' => ['2027-05-15', '2027-05-16', '2027-05-17', '2027-05-18', '2027-05-19']],
        '2027-05-19' => ['name' => 'Atatürk’ü Anma, Gençlik ve Spor Bayramı', 'dates' => ['2027-05-19']],
        '2027-07-15' => ['name' => 'Demokrasi ve Milli Birlik Günü', 'dates' => ['2027-07-15']],
        '2027-08-30' => ['name' => 'Zafer Bayramı', 'dates' => ['2027-08-30']],
        '2027-10-29' => ['name' => 'Cumhuriyet Bayramı', 'dates' => ['2027-10-28', '2027-10-29']],
    ];

    /**
     * Holidays with at least one day in [$from, $from + $days], arefe (half day) included.
     *
     * @return list<array{key: string, name: string, dates: list<string>}>
     */
    public static function upcoming(CarbonImmutable $from, int $days = 30): array
    {
        $start = $from->toDateString();
        $end = $from->addDays($days)->toDateString();
        $out = [];
        foreach (self::HOLIDAYS as $key => $holiday) {
            $inWindow = array_values(array_filter($holiday['dates'], fn (string $date): bool => $date >= $start && $date <= $end));
            if ($inWindow !== []) {
                $out[] = ['key' => $key, 'name' => $holiday['name'], 'dates' => $inWindow];
            }
        }

        return $out;
    }

    /** Last date the table covers; after it the special-hours check cannot say anything. */
    public static function coveredUntil(): string
    {
        return max(array_merge(...array_column(self::HOLIDAYS, 'dates')));
    }
}
