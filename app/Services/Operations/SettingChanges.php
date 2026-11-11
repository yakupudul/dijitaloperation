<?php

namespace App\Services\Operations;

use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\SettingChange;
use App\Services\Sales\StandingRules;
use App\Support\SchemaState;
use App\Support\WriteLevels;
use InvalidArgumentException;
use Throwable;

/**
 * Ayar değişiklik kaydı (Bağlantı Düzeni 2.5): every change of a setting is written down with the value before, so
 * "Geri al" puts the old value back in one tap. Secrets (keys, passwords) are noted as changed without their values
 * and cannot be undone from here. Recording never gets in the way of the change itself.
 */
final class SettingChanges
{
    /** agency_settings columns that are the system's own state, not a setting somebody chose. */
    private const array NOT_SETTINGS = ['id', 'created_at', 'updated_at', 'writes_stopped_at', 'writes_stopped_by', 'jobs_off'];

    /** agency_settings columns with a readable name; the rest is shown by its column name. */
    private const array AGENCY_LABELS = [
        'agency_name' => 'Ajans adı', 'portal_name' => 'Panel adı', 'locale' => 'Dil', 'timezone' => 'Saat dilimi', 'display_currency' => 'Para birimi',
        'week_starts_on' => 'Haftanın ilk günü', 'analytical_date_range' => 'Varsayılan tarih aralığı', 'mail_enabled' => 'E-posta gönderimi',
        'mail_from_name' => 'Gönderen adı', 'mail_from_address' => 'Gönderen adresi', 'mail_host' => 'E-posta sunucusu', 'mail_port' => 'E-posta kapısı',
        'mail_username' => 'E-posta kullanıcısı', 'mail_encryption' => 'E-posta şifrelemesi', 'mail_password' => 'E-posta şifresi',
        'push_min_severity' => 'Bildirim eşiği', 'theme_accent' => 'Vurgu rengi', 'theme_second' => 'İkinci renk', 'mcp_env_token_off' => 'Eski sunucu anahtarı kapalı',
        'backup_remote_url' => 'Sunucu dışı yedek adresi', 'backup_remote_username' => 'Sunucu dışı yedek kullanıcısı', 'backup_remote_password' => 'Sunucu dışı yedek şifresi',
    ];

    private static bool $undoing = false;

    public static function record(string $target, string $label, mixed $before, mixed $after, bool $secret = false): void
    {
        if (self::$undoing || $before === $after) {
            return;
        }
        try {
            if (! SchemaState::hasTable('setting_changes')) {
                return;
            }
            SettingChange::query()->create([
                'user_id' => auth()->id(), 'target' => mb_substr($target, 0, 191), 'label' => mb_substr($label, 0, 191), 'secret' => $secret,
                'before' => $secret ? null : ['v' => $before], 'after' => $secret ? null : ['v' => $after],
            ]);
        } catch (Throwable $error) {
            report($error);
        }
    }

    /** AgencySetting::updating: one row per changed column. */
    public static function agencySaving(AgencySetting $settings): void
    {
        $hidden = $settings->getHidden();
        foreach ($settings->getDirty() as $column => $value) {
            if (in_array($column, self::NOT_SETTINGS, true)) {
                continue;
            }
            $secret = in_array($column, $hidden, true) || preg_match('/(token|secret|password|key)$/', $column) === 1;
            self::record('agency:'.$column, self::AGENCY_LABELS[$column] ?? $column, $settings->getRawOriginal($column), $settings->getAttributes()[$column] ?? null, $secret);
        }
    }

    public static function undoable(SettingChange $change): bool
    {
        return ! $change->secret && $change->undone_at === null && preg_match('/^(agency|level|job|sales):/', (string) $change->target) === 1;
    }

    /** Puts the value before the change back (a later change of the same setting is overwritten: the operator asked for the old value). */
    public function undo(SettingChange $change): void
    {
        if (! self::undoable($change)) {
            throw new InvalidArgumentException('Bu değişiklik buradan geri alınamaz.');
        }
        $before = $change->before['v'] ?? null;
        [$area, $rest] = explode(':', (string) $change->target, 2);
        self::$undoing = true;
        try {
            match ($area) {
                'agency' => $this->agency($rest, $before),
                'level' => $this->level($rest, $before),
                'job' => app(ScheduledJobs::class)->set($rest, (bool) $before),
                'sales' => $rest === 'prices' ? StandingRules::savePrices((string) $before) : StandingRules::save((string) $before),
            };
        } finally {
            self::$undoing = false;
        }
        $change->forceFill(['undone_at' => now()])->save();
        // The undo itself is a change somebody made: it is on the record too.
        self::record((string) $change->target, $change->label.' (geri alındı)', $change->after['v'] ?? null, $before);
    }

    private function agency(string $column, mixed $before): void
    {
        $settings = AgencySetting::query()->firstOrFail();
        if (! array_key_exists($column, $settings->getAttributes()) || in_array($column, self::NOT_SETTINGS, true)) {
            throw new InvalidArgumentException('Bu ayar artık yok.');
        }
        $settings->setRawAttributes(array_merge($settings->getAttributes(), [$column => $before]));
        $settings->save();
    }

    private function level(string $rest, mixed $before): void
    {
        [$brandId, $channel] = explode(':', $rest, 2);
        $brand = Brand::query()->findOrFail((int) $brandId);
        if (is_string($before) && isset(WriteLevels::LABELS[$before])) {
            WriteLevels::set($brand, $channel, $before);

            return;
        }
        $levels = (array) $brand->write_levels;
        unset($levels[$channel]);
        $brand->write_levels = $levels === [] ? null : $levels;
        $brand->save();
    }
}
