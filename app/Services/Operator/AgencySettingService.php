<?php

namespace App\Services\Operator;

use App\Models\AgencySetting;
use App\Support\Operator\AgencySettingCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AgencySettingService
{
    public const string BRANDING_DISK = 'public';

    public const string BRANDING_DIRECTORY = 'branding';

    /**
     * Agency-workspace singleton. Database is canonical; session is never truth.
     */
    public function current(): AgencySetting
    {
        try {
            if (! Schema::hasTable('agency_settings')) {
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
                'locale' => AgencySettingCatalog::LOCALE_EN,
                'timezone' => 'Europe/Istanbul',
                'display_currency' => AgencySettingCatalog::CURRENCY_TRY,
                'week_starts_on' => AgencySettingCatalog::WEEK_MONDAY,
                'analytical_date_range' => AgencySettingCatalog::RANGE_LAST_28,
            ],
        );

        return $settings;
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

        if (! Schema::hasTable('agency_settings')) {
            throw new InvalidArgumentException('Agency settings are not available.');
        }

        $settings = $this->current();
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

        return $settings->fresh() ?? $settings;
    }

    public function defaultLocale(): string
    {
        $locale = (string) $this->current()->locale;

        return AgencySettingCatalog::isLocale($locale) ? $locale : AgencySettingCatalog::LOCALE_EN;
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
            'locale' => AgencySettingCatalog::LOCALE_EN,
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
