<?php

namespace App\Services\Operator;

use App\Models\AgencySetting;
use App\Support\Operator\AgencySettingCatalog;
use App\Support\SchemaState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AgencySettingService
{
    public const string BRANDING_DISK = 'public';

    public const string BRANDING_DIRECTORY = 'branding';

    /** The row read in this request / job (null until the first current() or after forget()). */
    private ?AgencySetting $current = null;

    /**
     * Agency-workspace singleton. Database is canonical; session is never truth.
     * Read once per request / job and kept (the service is bound scoped, so a Horizon worker reads it again for every
     * job); the defaults used while the table is missing are not kept.
     */
    public function current(): AgencySetting
    {
        if ($this->current !== null) {
            return $this->current;
        }

        try {
            if (! SchemaState::hasTable('agency_settings')) {
                return $this->ephemeralDefaults();
            }
        } catch (\Throwable) {
            return $this->ephemeralDefaults();
        }

        /** @var AgencySetting $settings */
        $settings = AgencySetting::query()->firstOrCreate(
            ['id' => 1],
            [
                'agency_name' => 'MoxDOP',
                'portal_name' => 'MoxDOP',
                'locale' => AgencySettingCatalog::appDefaultLocale(),
                'timezone' => 'Europe/Istanbul',
                'display_currency' => AgencySettingCatalog::CURRENCY_TRY,
                'week_starts_on' => AgencySettingCatalog::WEEK_MONDAY,
                'analytical_date_range' => AgencySettingCatalog::RANGE_LAST_28,
            ],
        );

        return $this->current = $settings;
    }

    /**
     * The stored row without creating one (app boot reads the mail settings: a boot must never write — on PostgreSQL
     * the test run showed every boot committing a row before the test's transaction began). Kept like current().
     */
    public function peek(): ?AgencySetting
    {
        if ($this->current !== null) {
            return $this->current;
        }
        $row = AgencySetting::query()->orderBy('id')->first();

        return $row instanceof AgencySetting ? $this->current = $row : null;
    }

    /** Drops the kept row: the next current() reads the database again (after a write that bypassed this service). */
    public function forget(): void
    {
        $this->current = null;
    }

    /**
     * @return array{agency_name: string, portal_name: string, logo_url: string|null, favicon_url: string|null, display_initial: string}
     */
    public function branding(): array
    {
        $settings = $this->current();
        $agencyName = trim((string) $settings->agency_name) !== '' ? (string) $settings->agency_name : 'MoxDOP';
        $portalName = trim((string) $settings->portal_name) !== '' ? (string) $settings->portal_name : $agencyName;

        return [
            'agency_name' => $agencyName,
            'portal_name' => $portalName,
            'logo_url' => $this->publicUrl($settings->logo_path),
            'favicon_url' => $this->publicUrl($settings->favicon_path),
            'display_initial' => mb_strtoupper(mb_substr($portalName, 0, 1)),
        ];
    }

    /**
     * @param  array{
     *     agency_name: string,
     *     portal_name: string,
     *     locale: string,
     *     timezone: string,
     *     display_currency: string,
     *     week_starts_on: string,
     *     analytical_date_range: string
     * }  $attributes
     */
    public function updateGeneral(array $attributes, ?UploadedFile $logo = null, ?UploadedFile $favicon = null): AgencySetting
    {
        $this->assertControlled($attributes);

        if (! SchemaState::hasTable('agency_settings')) {
            throw new InvalidArgumentException('Agency settings are not available.');
        }

        $settings = $this->current();
        try {
            $settings->fill([
                'agency_name' => trim($attributes['agency_name']),
                'portal_name' => trim($attributes['portal_name']),
                'locale' => $attributes['locale'],
                'timezone' => $attributes['timezone'],
                'display_currency' => $attributes['display_currency'],
                'week_starts_on' => $attributes['week_starts_on'],
                'analytical_date_range' => $attributes['analytical_date_range'],
            ]);

            if ($logo !== null) {
                $settings->logo_path = $this->storeBrandingFile($logo, $settings->logo_path);
            }

            if ($favicon !== null) {
                $settings->favicon_path = $this->storeBrandingFile($favicon, $settings->favicon_path);
            }

            $settings->save();
        } catch (\Throwable $exception) {
            // The kept row holds the unsaved values: read the stored ones again.
            $this->forget();

            throw $exception;
        }

        return $this->current = $settings->fresh() ?? $settings;
    }

    /** Moximu logo colors: the yellow tile and the teal mark. */
    public const string THEME_ACCENT = '#fdcf3c';

    public const string THEME_SECOND = '#1e7a78';

    /**
     * Panel colors from Ayarlar › Görünüm; empty means the Moximu logo colors. The ink is the text color that reads
     * on the accent (dark on a light accent, white on a dark one).
     *
     * @return array{accent: string, accent_ink: string, second: string}
     */
    public function theme(): array
    {
        $settings = $this->current();
        $accent = self::validHex((string) ($settings->theme_accent ?? '')) ?? self::THEME_ACCENT;
        $second = self::validHex((string) ($settings->theme_second ?? '')) ?? self::THEME_SECOND;

        return ['accent' => $accent, 'accent_ink' => self::inkOn($accent), 'second' => $second];
    }

    /** Saves the two panel colors; null resets one to the Moximu logo color. */
    public function updateTheme(?string $accent, ?string $second): AgencySetting
    {
        if (! SchemaState::hasTable('agency_settings')) {
            throw new InvalidArgumentException('Agency settings are not available.');
        }

        $settings = $this->current();
        $settings->theme_accent = $accent === null ? null : self::validHex($accent);
        $settings->theme_second = $second === null ? null : self::validHex($second);
        $settings->save();

        return $this->current = $settings;
    }

    public static function validHex(string $value): ?string
    {
        $value = strtolower(trim($value));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }

    /** Dark text on a light color, white on a dark one (WCAG relative luminance). */
    public static function inkOn(string $hex): string
    {
        $channel = function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $channel(substr($hex, 1, 2)) + 0.7152 * $channel(substr($hex, 3, 2)) + 0.0722 * $channel(substr($hex, 5, 2));

        return $luminance > 0.32 ? '#141416' : '#ffffff';
    }

    public function defaultLocale(): string
    {
        $locale = (string) $this->current()->locale;

        return AgencySettingCatalog::isLocale($locale) ? $locale : AgencySettingCatalog::appDefaultLocale();
    }

    public function defaultTimezone(): string
    {
        $timezone = (string) $this->current()->timezone;

        return AgencySettingCatalog::isTimezone($timezone) ? $timezone : 'Europe/Istanbul';
    }

    public function defaultAnalyticalDateRange(): string
    {
        $range = (string) $this->current()->analytical_date_range;

        return AgencySettingCatalog::isDateRange($range) ? $range : AgencySettingCatalog::RANGE_LAST_28;
    }

    public function weekStartsOn(): string
    {
        $value = (string) $this->current()->week_starts_on;

        return AgencySettingCatalog::isWeekStart($value) ? $value : AgencySettingCatalog::WEEK_MONDAY;
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function assertControlled(array $attributes): void
    {
        if (! AgencySettingCatalog::isLocale($attributes['locale'])) {
            throw new InvalidArgumentException('Invalid locale.');
        }
        if (! AgencySettingCatalog::isTimezone($attributes['timezone'])) {
            throw new InvalidArgumentException('Invalid timezone.');
        }
        if (! AgencySettingCatalog::isCurrency($attributes['display_currency'])) {
            throw new InvalidArgumentException('Invalid currency.');
        }
        if (! AgencySettingCatalog::isWeekStart($attributes['week_starts_on'])) {
            throw new InvalidArgumentException('Invalid week start.');
        }
        if (! AgencySettingCatalog::isDateRange($attributes['analytical_date_range'])) {
            throw new InvalidArgumentException('Invalid analytical date range.');
        }
    }

    private function storeBrandingFile(UploadedFile $file, ?string $previousPath): string
    {
        $extension = strtolower((string) $file->guessExtension());
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new InvalidArgumentException('Unsupported branding file type.');
        }

        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs(self::BRANDING_DIRECTORY, $filename, self::BRANDING_DISK);

        if (! is_string($path) || $path === '') {
            throw new InvalidArgumentException('Unable to store branding file.');
        }

        if (is_string($previousPath) && $previousPath !== '') {
            Storage::disk(self::BRANDING_DISK)->delete($previousPath);
        }

        return $path;
    }

    private function ephemeralDefaults(): AgencySetting
    {
        $settings = new AgencySetting;
        $settings->forceFill([
            'agency_name' => 'MoxDOP',
            'portal_name' => 'MoxDOP',
            'locale' => AgencySettingCatalog::appDefaultLocale(),
            'timezone' => 'Europe/Istanbul',
            'display_currency' => AgencySettingCatalog::CURRENCY_TRY,
            'week_starts_on' => AgencySettingCatalog::WEEK_MONDAY,
            'analytical_date_range' => AgencySettingCatalog::RANGE_LAST_28,
            'mail_enabled' => false,
        ]);

        return $settings;
    }

    private function publicUrl(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        return Storage::disk(self::BRANDING_DISK)->url($path);
    }
}
