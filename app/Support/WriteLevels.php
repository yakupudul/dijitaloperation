<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\ExternalWriteAction;
use App\Services\Operations\SettingChanges;
use App\Services\Work\WorkBatches;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Yetki kademeleri (Bağlantı Düzeni 3.2): what MoxDOP may do on each channel of each brand, as a lock and not a habit.
 *  - oku  ("Yalnız oku"): nothing is written there, approved or not.
 *  - oner ("Öner"): the system prepares and proposes; a write goes only when the operator started it by hand.
 *  - yaz  ("Yaz"): the system's own automatic writes go too.
 * Advertising channels are locked at "Yalnız oku" for every brand. A brand added after the levels came starts at
 * "Öner"; the brands that were already running keep working as they did ("Yaz") until the operator lowers them.
 */
final class WriteLevels
{
    public const string READ = 'oku';

    public const string SUGGEST = 'oner';

    public const string WRITE = 'yaz';

    /** @var array<string, string> */
    public const array LABELS = [self::READ => 'Yalnız oku', self::SUGGEST => 'Öner', self::WRITE => 'Yaz'];

    /** @var array<string, string> write channel => name on screen */
    public const array CHANNELS = [
        ExternalWriteAction::CHANNEL_WORDPRESS => 'Site',
        ExternalWriteAction::CHANNEL_GBP => 'İşletme Profili',
        ExternalWriteAction::CHANNEL_GOOGLE_ADS => 'Google Ads',
        'meta_ads' => 'Meta reklam',
    ];

    /** Channels nobody can open from the panel. */
    public const array LOCKED = [ExternalWriteAction::CHANNEL_GOOGLE_ADS, 'meta_ads'];

    /** Brands created from this day on start at "Öner". */
    private const string INTRODUCED = '2026-11-11';

    public const string HAND = 'hand';

    public const string AUTO = 'auto';

    public static function level(?Brand $brand, string $channel): string
    {
        if (in_array($channel, self::LOCKED, true)) {
            return self::READ;
        }
        if ($brand === null) {
            return self::WRITE;
        }
        $stored = data_get($brand->write_levels, $channel);
        if (is_string($stored) && isset(self::LABELS[$stored])) {
            return $stored;
        }

        return $brand->created_at !== null && $brand->created_at->gte(CarbonImmutable::parse(self::INTRODUCED, 'Europe/Istanbul')) ? self::SUGGEST : self::WRITE;
    }

    public static function set(Brand $brand, string $channel, string $level): void
    {
        if (! isset(self::CHANNELS[$channel], self::LABELS[$level]) || in_array($channel, self::LOCKED, true)) {
            throw new InvalidArgumentException('Bu kanalın kademesi değiştirilemez.');
        }
        $before = data_get($brand->write_levels, $channel);
        $brand->write_levels = array_merge((array) $brand->write_levels, [$channel => $level]);
        $brand->save();
        SettingChanges::record('level:'.$brand->id.':'.$channel, $brand->name.', '.self::CHANNELS[$channel].' yetkisi',
            is_string($before) ? $before : null, $level);
    }

    private static bool $byHand = false;

    /**
     * Runs $work as the operator's own: a text they approved earlier that the system only delivers at its hour (a
     * planned Business Profile post) is still their write.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function byHand(callable $work): mixed
    {
        $before = self::$byHand;
        self::$byHand = true;
        try {
            return $work();
        } finally {
            self::$byHand = $before;
        }
    }

    /** Who starts the write being created now: the operator (a panel click, an approval link, a batch they started) or the system. */
    public static function origin(): string
    {
        if (self::$byHand || WorkBatches::current() !== null) {
            return self::HAND;
        }
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return self::AUTO;
        }
        try {
            $request = request();
            if ($request->is(trim((string) config('moxdop-mcp.path', 'mcp/moxdop'), '/').'*')) {
                return self::AUTO;
            }

            // The approval links (/onay/…) are opened by the operator from the phone without a panel session.
            return auth()->check() || $request->is('onay/*') || $request->hasValidSignature() ? self::HAND : self::AUTO;
        } catch (Throwable) {
            return self::AUTO;
        }
    }

    /** @return ?string why this write may not leave (Turkish, for the operator); null = the level allows it */
    public static function refusal(ExternalWriteAction $action): ?string
    {
        if ($action->brand_id === null || in_array($action->channel, self::LOCKED, true)) {
            // Advertising writes have their own switch (ExternalWriteService::channelOn); a brandless write its own rule.
            return null;
        }
        $brand = Brand::query()->find($action->brand_id);
        $level = self::level($brand, (string) $action->channel);
        $name = self::CHANNELS[$action->channel] ?? (string) $action->channel;
        if ($level === self::READ) {
            return sprintf('%s markasında %s "Yalnız oku" kademesinde: buraya hiçbir şey yazılmaz. Kademe Bağlantı sağlığı › Yetkiler\'den değişir.', $brand?->name ?? 'Bu', $name);
        }
        if ($level === self::SUGGEST && $action->origin === self::AUTO) {
            return sprintf('%s markasında %s "Öner" kademesinde: sistem kendiliğinden göndermez, senin onayınla gider.', $brand?->name ?? 'Bu', $name);
        }

        return null;
    }
}
