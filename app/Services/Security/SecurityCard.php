<?php

namespace App\Services\Security;

use App\Models\McpToken;
use App\Models\User;
use App\Services\BrandSetup\BrandAutofill;
use App\Services\Mcp\McpKeys;
use App\Services\Operations\SystemBackup;
use App\Support\EmergencyStop;
use App\Support\Roles;
use App\Support\SchemaState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Güvenlik karnesi (Bağlantı Düzeni 3.5): who can get in and what happens if the server is lost, on one page. Each
 * row is one question with a plain answer (ok / warn / bad), what it means and where it is fixed. Read only, built
 * from what the panel already knows; nothing secret (a key, a password, an address of a secret) is ever shown.
 */
final class SecurityCard
{
    /** A key nobody used for this long is asked about. */
    public const int KEY_UNUSED_DAYS = 30;

    /** An active admin who has not signed in for this long is asked about. */
    public const int LOGIN_QUIET_DAYS = 90;

    /**
     * @return list<array{key: string, tone: string, title: string, answer: string, detail: string, url: ?string, action: ?string, lines: list<string>}>
     */
    public function rows(): array
    {
        return array_values(array_filter([
            $this->twoFactor(), $this->loginAddress(), $this->logins(), $this->keys(), $this->oldKey(),
            $this->backup(), $this->offServer(), $this->drill(), $this->debug(), $this->emergencyStop(),
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{ok: int, warn: int, bad: int}
     */
    public static function totals(array $rows): array
    {
        $totals = ['ok' => 0, 'warn' => 0, 'bad' => 0];
        foreach ($rows as $row) {
            $totals[$row['tone']] = ($totals[$row['tone']] ?? 0) + 1;
        }

        return $totals;
    }

    /** @return Collection<int, User> */
    private function admins(): Collection
    {
        return User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', Roles::ADMIN))->orderBy('id')->get()
            ->reject(fn (User $user): bool => $user->email === BrandAutofill::SYSTEM_EMAIL)->values();
    }

    /** Claude's own panel user: signs in from cloud sessions without a phone, exempt from two factors by rule. */
    private function isRobot(User $user): bool
    {
        return $user->email === BrandAutofill::SYSTEM_EMAIL;
    }

    /** @return array<string, mixed> */
    private function twoFactor(): array
    {
        $without = $this->admins()->reject(fn (User $user): bool => $user->hasTwoFactorEnabled())
            ->map(fn (User $user): string => (string) ($user->name ?: 'Adsız kullanıcı'))->all();

        return $this->row('two_factor', $without === [] ? 'ok' : 'bad', 'İki adımlı doğrulama', $without === [] ? 'Her yöneticide açık' : count($without).' yöneticide kapalı',
            $without === [] ? 'Şifre ele geçse bile telefondaki kod olmadan girilemez.' : 'Şifresi ele geçen bu hesaplara tek adımda girilir: '.implode(', ', $without).'.',
            route('operator.settings.users'), $without === [] ? null : 'Kullanıcılar');
    }

    /** @return array<string, mixed> */
    private function loginAddress(): array
    {
        $unreachable = $this->admins()->filter(fn (User $user): bool => preg_match('/(\.local|\.test|\.invalid|@example\.)/i', (string) $user->email) === 1)
            ->map(fn (User $user): string => (string) ($user->name ?: 'Adsız kullanıcı'))->all();
        $mail = ! in_array((string) config('mail.default'), ['log', 'array', ''], true);
        $tone = $unreachable !== [] ? 'bad' : ($mail ? 'ok' : 'warn');

        return $this->row('login_address', $tone, 'Şifre sıfırlama sana ulaşır mı', match (true) {
            $unreachable !== [] => 'Hayır, adres gerçek değil',
            ! $mail => 'Adres gerçek, e-posta gönderimi kurulu değil',
            default => 'Evet',
        }, match (true) {
            $unreachable !== [] => 'Bu hesapların giriş adresi gerçek bir posta kutusu değil: '.implode(', ', $unreachable).'. Şifre unutulursa panele dönüş yolu kalmaz.',
            ! $mail => 'Giriş adresi gerçek ama sunucu e-posta gönderemiyor (SMTP ayarı yok). Şifre unutulursa sıfırlama e-postası çıkmaz.',
            default => 'Giriş adresi gerçek ve sunucu e-posta gönderebiliyor.',
        }, route('operator.settings.users'), $tone === 'ok' ? null : 'Kullanıcılar');
    }

    /** @return array<string, mixed> */
    private function logins(): array
    {
        $users = User::query()->where('is_active', true)->orderByDesc('last_login_at')->get();
        $quiet = $users->filter(fn (User $user): bool => ! $this->isRobot($user) && ($user->last_login_at === null || $user->last_login_at->lt(now()->subDays(self::LOGIN_QUIET_DAYS))));
        $lines = $users->take(6)->map(fn (User $user): string => ($user->name ?: 'Adsız kullanıcı').($this->isRobot($user) ? ' (Claude\'un panel hesabı)' : '').': '
            .($user->last_login_at !== null ? 'son giriş '.$user->last_login_at->timezone('Europe/Istanbul')->locale('tr')->translatedFormat('j F H:i') : 'hiç girmedi'))->all();

        return $this->row('logins', $quiet->isEmpty() ? 'ok' : 'warn', 'Kimler girebiliyor', $users->count().' açık hesap'.($quiet->isEmpty() ? '' : ', '.$quiet->count().' tanesi uzun süredir girmedi'),
            $quiet->isEmpty() ? 'Açık hesapların hepsi yakın zamanda kullanıldı.' : self::LOGIN_QUIET_DAYS.' gündür girilmeyen hesap kullanılmıyorsa kapatılmalı: '.$quiet->map(fn (User $u): string => (string) ($u->name ?: 'Adsız kullanıcı'))->implode(', ').'.',
            route('operator.settings.users'), $quiet->isEmpty() ? null : 'Kullanıcılar', $lines);
    }

    /** @return ?array<string, mixed> */
    private function keys(): ?array
    {
        if (! SchemaState::hasTable('mcp_tokens')) {
            return null;
        }
        $open = McpToken::query()->whereNull('revoked_at')->orderBy('id')->get();
        $unused = $open->filter(fn (McpToken $key): bool => $key->last_used_at === null ? $key->created_at?->lt(now()->subDays(7)) ?? true : $key->last_used_at->lt(now()->subDays(self::KEY_UNUSED_DAYS)));
        $write = $open->where('scope', McpToken::WRITE)->count();
        $lines = $open->map(fn (McpToken $key): string => $key->name.' ('.($key->scope === McpToken::WRITE ? 'okur ve yazar' : 'yalnız okur').'): '
            .($key->last_used_at !== null ? 'son kullanım '.$key->last_used_at->locale('tr')->diffForHumans() : 'hiç kullanılmadı'))->all();

        return $this->row('keys', $unused->isEmpty() ? 'ok' : 'warn', 'Açık anahtarlar', $open->count().' anahtar, '.$write.' tanesi yazabiliyor'.($unused->isEmpty() ? '' : ', '.$unused->count().' tanesi kullanılmıyor'),
            $unused->isEmpty() ? 'Açık anahtarların hepsi kullanılıyor.' : 'Kullanılmayan anahtar boşuna açık bir kapıdır: '.$unused->pluck('name')->implode(', ').'. Kapatmak senin kararın.',
            route('operator.settings.mcp'), $unused->isEmpty() ? null : 'Anahtarlar', $lines);
    }

    /** @return ?array<string, mixed> */
    private function oldKey(): ?array
    {
        if (! McpKeys::envConfigured()) {
            return null;
        }
        $on = McpKeys::envKeyOn();

        return $this->row('old_key', $on ? 'warn' : 'ok', 'Eski sunucu anahtarı', $on ? 'Açık' : 'Kapalı',
            $on ? 'Sunucu dosyasında duran eski anahtar hâlâ geçerli: okur ve yazar, kimin kullandığı görünmez. Panelden açılan anahtarlar yeterli; kapatmak senin kararın.' : 'Yalnız panelden açılan, adı ve son kullanımı görünen anahtarlar geçerli.',
            route('operator.settings.mcp'), $on ? 'Anahtarlar' : null);
    }

    /** @return ?array<string, mixed> */
    private function backup(): ?array
    {
        $status = $this->backupStatus();
        if ($status === null) {
            return null;
        }
        $when = $status['last_success_at'] !== null ? Carbon::parse($status['last_success_at'])->timezone('Europe/Istanbul')->locale('tr')->translatedFormat('j F H:i') : null;

        return $this->row('backup', $status['ok'] ? 'ok' : 'bad', 'Günlük yedek', $when !== null ? 'Son yedek '.$when : 'Hiç alınmamış',
            $status['ok'] ? 'Veritabanı her gün yedekleniyor.' : 'Son '.(int) config('moxdop-backup.stale_hours', 26).' saatte başarılı yedek yok'.(filled($status['last_error']) ? ': '.mb_substr((string) $status['last_error'], 0, 160) : '.').' Yedek yokken yeni sürüm canlıya alınmaz.',
            route('operator.settings.system-health'), $status['ok'] ? null : 'Sistem');
    }

    /** @return ?array<string, mixed> */
    private function offServer(): ?array
    {
        $status = $this->backupStatus();
        if ($status === null) {
            return null;
        }
        $target = blank(config('moxdop-backup.remote_disk')) ? null : $status['remote_target'];
        $tone = $target === null ? 'bad' : ($status['remote'] ? 'ok' : 'warn');

        return $this->row('off_server', $tone, 'Sunucu dışı yedek', match ($tone) {
            'bad' => 'Yok',
            'warn' => 'Hedef var, son kopya gitmedi',
            default => 'Son yedek sunucu dışında da duruyor',
        }, match ($tone) {
            'bad' => 'Yedekler yalnız aynı sunucuda. Sunucu kaybolursa yedek de gider: bütün markaların verisi, konuşmalar ve ayarlar geri gelmez. Bir dış hedef (S3 ya da SFTP) söylemen yeter, kurulumu ben yaparım.',
            'warn' => 'Dış hedef tanımlı ama son yedeğin kopyası oraya ulaşmadı'.(filled($status['remote_error']) ? ': '.mb_substr((string) $status['remote_error'], 0, 160) : '.'),
            default => 'Sunucu kaybolsa bile veri geri yüklenebilir.',
        }, route('operator.settings.system-health'), null);
    }

    /** @return ?array<string, mixed> */
    private function drill(): ?array
    {
        $status = $this->backupStatus();
        if ($status === null) {
            return null;
        }
        $drill = $status['drill'];
        $at = isset($drill['checked_at']) ? Carbon::parse($drill['checked_at']) : null;
        $tone = match (true) {
            $drill === null || $at === null => 'warn',
            $drill['state'] === 'failed' => 'bad',
            $drill['state'] === 'ok' && $at->gt(now()->subDays(45)) => 'ok',
            default => 'warn',
        };

        return $this->row('drill', $tone, 'Yedekten geri dönüş denendi mi', match (true) {
            $drill === null || $at === null => 'Hiç denenmedi',
            $drill['state'] === 'failed' => 'Son deneme başarısız',
            $drill['state'] === 'ok' => 'Evet, '.$at->timezone('Europe/Istanbul')->locale('tr')->translatedFormat('j F'),
            default => 'Son deneme atlandı',
        }, match ($tone) {
            'ok' => 'Yedek ayrı bir veritabanına geri yüklendi ve açıldı ('.(int) ($drill['seconds'] ?? 0).' sn). Sistem bunu ayda bir kendisi dener.',
            'bad' => 'Yedek geri yüklenemedi: '.mb_substr((string) ($drill['message'] ?? ''), 0, 160),
            default => 'Geri yüklenmesi denenmemiş bir yedeğe güvenilmez. Sistem bunu ayda bir kendisi dener; ilk deneme henüz çalışmadı ya da eski.',
        }, route('operator.settings.system-health'), null);
    }

    /** @return array<string, mixed> */
    private function debug(): array
    {
        $on = (bool) config('app.debug');

        return $this->row('debug', $on ? 'bad' : 'ok', 'Hata ayrıntıları dışarı sızıyor mu', $on ? 'Evet' : 'Hayır',
            $on ? 'Hata ayıklama modu açık: bir hata sayfası sunucu ayarlarını ziyaretçiye gösterebilir. Kapatmayı ben yaparım.' : 'Hata olduğunda ziyaretçi yalnız sade bir hata sayfası görür.', null, null);
    }

    /** @return array<string, mixed> */
    private function emergencyStop(): array
    {
        $since = EmergencyStop::since();

        return $this->row('emergency_stop', $since === null ? 'ok' : 'warn', 'Acil durdurma', $since === null ? 'Hazır, basılı değil' : 'Basılı',
            $since === null ? 'Tek düğme siteye, İşletme Profili\'ne ve sosyal medyaya giden bütün yazmaları durdurur; üst şeritteki düğmeden, telefondan da basılır.'
                : $since->timezone('Europe/Istanbul')->locale('tr')->translatedFormat('j F H:i').'\'den beri dışarıya hiçbir şey gönderilmiyor. "Devam ettir" ile açılır.', null, null);
    }

    /** @return ?array<string, mixed> */
    private function backupStatus(): ?array
    {
        return once(function (): ?array {
            try {
                return SchemaState::hasTable('system_backups') ? app(SystemBackup::class)->status() : null;
            } catch (Throwable $error) {
                report($error);

                return null;
            }
        });
    }

    /**
     * @param  list<string>  $lines
     * @return array{key: string, tone: string, title: string, answer: string, detail: string, url: ?string, action: ?string, lines: list<string>}
     */
    private function row(string $key, string $tone, string $title, string $answer, string $detail, ?string $url, ?string $action, array $lines = []): array
    {
        return ['key' => $key, 'tone' => $tone, 'title' => $title, 'answer' => $answer, 'detail' => $detail, 'url' => $url, 'action' => $action, 'lines' => array_values($lines)];
    }
}
