<?php

namespace App\Support\Operator;

/**
 * Controlled agency/operator Settings values. Machine codes stay English.
 */
final class AgencySettingCatalog
{
    public const string LOCALE_EN = 'en';

    public const string LOCALE_TR = 'tr';

    public const string WEEK_MONDAY = 'monday';

    public const string WEEK_SUNDAY = 'sunday';

    public const string RANGE_LAST_7 = 'last_7';

    public const string RANGE_LAST_14 = 'last_14';

    public const string RANGE_LAST_28 = 'last_28';

    public const string RANGE_LAST_90 = 'last_90';

    public const string CURRENCY_TRY = 'TRY';

    public const string CURRENCY_USD = 'USD';

    public const string CURRENCY_EUR = 'EUR';

    public const string CURRENCY_GBP = 'GBP';

    /**
     * @return list<string>
     */
    public static function locales(): array
    {
        return [self::LOCALE_EN, self::LOCALE_TR];
    }

    /**
     * @return array<string, string>
     */
    public static function localeOptions(): array
    {
        return [
            self::LOCALE_EN => __('operator.languages.en'),
            self::LOCALE_TR => __('operator.languages.tr'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function currencies(): array
    {
        return [self::CURRENCY_TRY, self::CURRENCY_USD, self::CURRENCY_EUR, self::CURRENCY_GBP];
    }

    /**
     * @return array<string, string>
     */
    public static function currencyOptions(): array
    {
        return [
            self::CURRENCY_TRY => 'TRY',
            self::CURRENCY_USD => 'USD',
            self::CURRENCY_EUR => 'EUR',
            self::CURRENCY_GBP => 'GBP',
        ];
    }

    /**
     * @return list<string>
     */
    public static function weekStarts(): array
    {
        return [self::WEEK_MONDAY, self::WEEK_SUNDAY];
    }

    /**
     * @return array<string, string>
     */
    public static function weekStartOptions(): array
    {
        return [
            self::WEEK_MONDAY => __('operator.settings.week.monday'),
            self::WEEK_SUNDAY => __('operator.settings.week.sunday'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function dateRanges(): array
    {
        return [self::RANGE_LAST_7, self::RANGE_LAST_14, self::RANGE_LAST_28, self::RANGE_LAST_90];
    }

    /**
     * @return array<string, string>
     */
    public static function dateRangeOptions(): array
    {
        return [
            self::RANGE_LAST_7 => __('operator.settings.range.last_7'),
            self::RANGE_LAST_14 => __('operator.settings.range.last_14'),
            self::RANGE_LAST_28 => __('operator.settings.range.last_28'),
            self::RANGE_LAST_90 => __('operator.settings.range.last_90'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function timezones(): array
    {
        return timezone_identifiers_list();
    }

    /**
     * @return array<string, string>
     */
    public static function timezoneOptions(): array
    {
        $options = [];
        foreach (self::timezones() as $id) {
            $options[$id] = $id;
        }

        return $options;
    }

    public static function isLocale(string $value): bool
    {
        return in_array($value, self::locales(), true);
    }

    public static function isTimezone(string $value): bool
    {
        return in_array($value, self::timezones(), true);
    }

    public static function isCurrency(string $value): bool
    {
        return in_array($value, self::currencies(), true);
    }

    public static function isWeekStart(string $value): bool
    {
        return in_array($value, self::weekStarts(), true);
    }

    public static function isDateRange(string $value): bool
    {
        return in_array($value, self::dateRanges(), true);
    }
}
