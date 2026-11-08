<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppBackupLine;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppSignupAttempt;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Baştan sona kontrol" (yakup, 2026-11-07): every link of the live WhatsApp chain checked with read-only Meta calls,
 * from the app's own credentials to the last message that reached the inbox, each row with what is wrong in plain
 * Turkish and who fixes it. Stored on the integration so the screen and Claude (system-health) read the same result.
 * Never writes to Meta and never prints a token or secret.
 */
final class WhatsAppDiagnosis
{
    public const CACHE_KEY = 'whatsapp:diagnosis';

    public function __construct(private WhatsAppConnection $connection, private WhatsAppGraph $graph) {}

    /** @return array{at: string, ok: bool, first_problem: ?string, rows: list<array{step: string, ok: bool|null, detail: string, fix?: string}>} */
    public function run(): array
    {
        $rows = [];
        $add = function (string $step, ?bool $ok, string $detail, ?string $fix = null) use (&$rows): void {
            $rows[] = ['step' => $step, 'ok' => $ok, 'detail' => mb_substr($detail, 0, 400)] + ($fix !== null ? ['fix' => $fix] : []);
        };

        $row = $this->connection->integration();
        $config = $row?->config ?? [];
        $secrets = $row ? $this->connection->secrets($row) : [];
        $appId = trim((string) ($config['app_id'] ?? ''));
        $secret = trim((string) ($secrets['app_secret'] ?? ''));
        $token = trim((string) ($secrets['access_token'] ?? ''));
        $wabaId = trim((string) ($config['waba_id'] ?? ''));
        $phoneId = trim((string) ($config['phone_number_id'] ?? ''));

        // 1. MoxDOP settings.
        $missing = array_keys(array_filter([
            'App ID' => $appId === '', 'Embedded Signup yapılandırma ID' => trim((string) ($config['signup_config_id'] ?? '')) === '',
            'App Secret' => $secret === '', 'Verify Token' => trim((string) ($secrets['verify_token'] ?? '')) === '',
        ]));
        $add('1. MoxDOP ayarları', $missing === [] && ($row?->isActive() ?? false),
            $missing === [] ? 'App ID '.$appId.', yapılandırma '.($config['signup_config_id'] ?? '').', mesaj alımı '.(($row?->isActive() ?? false) ? 'açık' : 'kapalı').'.' : 'Eksik: '.implode(', ', $missing).'.',
            $missing === [] ? (($row?->isActive() ?? false) ? null : 'WhatsApp › Ayarlar › "Mesaj alımı açık" kutusunu işaretleyip kaydet.') : 'WhatsApp › Canlı bağlantı › 1. adımda eksik alanları gir.');

        // 2. The app answers with its own credentials.
        if ($appId !== '' && $secret !== '') {
            try {
                $app = $this->graph->request('GET', $appId, ['fields' => 'id,name'], $appId.'|'.$secret);
                $add('2. Meta uygulaması', true, 'Uygulama yanıt veriyor: '.($app['name'] ?? $appId).'. App Secret doğru.');
            } catch (WhatsAppGraphException $e) {
                $add('2. Meta uygulaması', false, $this->error($e), 'App Secret yanlış ya da uygulama silinmiş olabilir: Meta › Uygulama ayarları › Temel\'deki App Secret\'ı WhatsApp ayarlarına yeniden gir.');
            }
        } else {
            $add('2. Meta uygulaması', null, 'App ID ve App Secret olmadan denenemedi.');
        }

        // 3. The number's access token.
        $tokenOk = false;
        if ($token === '') {
            $add('3. Erişim anahtarı', false, 'Kayıtlı anahtar yok: numara henüz Meta ile bağlanmamış.', 'WhatsApp › "Bağlamaya başla" ile numarayı bağla.');
        } elseif ($appId !== '' && $secret !== '') {
            try {
                $debug = (array) ($this->graph->request('GET', 'debug_token', ['input_token' => $token], $appId.'|'.$secret, $secret)['data'] ?? []);
                $scopes = array_values(array_intersect((array) ($debug['scopes'] ?? []), ['whatsapp_business_management', 'whatsapp_business_messaging', 'business_management']));
                $tokenOk = ($debug['is_valid'] ?? false) === true && in_array('whatsapp_business_messaging', $scopes, true);
                $expires = (int) ($debug['expires_at'] ?? 0);
                $add('3. Erişim anahtarı', $tokenOk, ($debug['is_valid'] ?? false) ? 'Geçerli; izinler: '.implode(', ', $scopes ?: ['yok']).'; '.($expires > 0 ? 'bitiş '.date('d.m.Y', $expires) : 'süresiz').'.' : 'Geçersiz: '.(string) data_get($debug, 'error.message', 'Meta anahtarı reddetti.'),
                    $tokenOk ? null : 'Numarayı "Bağlamaya başla" ile yeniden bağla; yeni anahtar alınır.');
            } catch (WhatsAppGraphException $e) {
                $add('3. Erişim anahtarı', false, $this->error($e), 'Numarayı "Bağlamaya başla" ile yeniden bağla.');
            }
        }

        // 4. WhatsApp Business account, number, coexistence.
        if ($wabaId === '' || ! $tokenOk) {
            $add('4. WhatsApp hesabı ve numara', null, $wabaId === '' ? 'Bağlı WhatsApp Business hesabı yok.' : 'Anahtar geçerli olmadığı için denenemedi.');
        } else {
            try {
                $numbers = (array) ($this->graph->request('GET', $wabaId.'/phone_numbers', ['fields' => 'id,display_phone_number,verified_name,status,platform_type,code_verification_status,is_on_biz_app'], $token, $secret)['data'] ?? []);
                $mine = collect($numbers)->first(fn ($n): bool => (string) ($n['id'] ?? '') === $phoneId);
                if ($mine === null) {
                    $add('4. WhatsApp hesabı ve numara', false, 'Hesapta '.count($numbers).' numara var, kayıtlı numara ('.$phoneId.') aralarında yok.', 'Numarayı "Bağlamaya başla" ile yeniden bağla ve doğru numarayı seç.');
                } else {
                    $platform = (string) ($mine['platform_type'] ?? '');
                    $good = $platform === 'CLOUD_API';
                    $add('4. WhatsApp hesabı ve numara', $good, ($mine['display_phone_number'] ?? '').' · '.($mine['verified_name'] ?? '').' · durum '.($mine['status'] ?? '-').' · platform '.($platform ?: '-').(($mine['is_on_biz_app'] ?? false) ? ' · WhatsApp Business uygulamasıyla birlikte (coexistence)' : '').'.',
                        $good ? null : 'Numara Cloud API\'ye kayıtlı değil: bağlantı yarım kalmış. "Bağlamaya başla" ile baştan bağla, son ekranda "Bitti" de.');
                }
            } catch (WhatsAppGraphException $e) {
                $add('4. WhatsApp hesabı ve numara', false, $this->error($e), 'Anahtarın bu hesaba erişimi yok; numarayı yeniden bağla.');
            }
        }

        // 5. App subscribed to the account (otherwise no message ever arrives).
        if ($wabaId !== '' && $tokenOk) {
            try {
                $subscribed = $this->graph->subscribed($this->graph->request('GET', $wabaId.'/subscribed_apps', [], $token, $secret), $appId);
                $add('5. Uygulama aboneliği', $subscribed, $subscribed ? 'Uygulama bu WhatsApp hesabına abone.' : 'Uygulama bu WhatsApp hesabına abone değil; mesajlar gelmez.', $subscribed ? null : 'WhatsApp › Ayarlar › "Mesaj aboneliğini kur" düğmesine bas.');
            } catch (WhatsAppGraphException $e) {
                $add('5. Uygulama aboneliği', false, $this->error($e));
            }
        } else {
            $add('5. Uygulama aboneliği', null, 'Numara bağlanmadan denenemedi.');
        }

        // 6. Webhook: Meta checked our address, and events arrive.
        $verified = filled($config['webhook_verified_at'] ?? null);
        $lastReceipt = $config['last_receipt_at'] ?? null;
        $add('6. Webhook', $verified, $verified ? 'Meta adresi doğruladı ('.$this->when($config['webhook_verified_at']).'). Son olay: '.($lastReceipt ? $this->when($lastReceipt) : 'henüz yok').'.' : 'Meta webhook adresini hiç doğrulamadı.',
            $verified ? null : 'Meta › uygulama › WhatsApp › Configuration › Webhook: adres '.route('api.whatsapp.webhook').', Verify Token MoxDOP\'ta kayıtlı olan; messages, history, smb_message_echoes, smb_app_state_sync alanlarına abone ol.');

        // 7. Messages in the inbox.
        $live = $row ? WhatsAppConversation::query()->where('integration_id', $row->id)->where('phone_number_id', '!=', WhatsAppBackupImporter::LINE)->count() : 0;
        $backupShown = WhatsAppBackupLine::shown();
        $lastIn = $config['last_message_received_at'] ?? null;
        $add('7. Gelen kutusu', $lastIn !== null, 'Canlı görüşme: '.$live.'. Son gelen canlı mesaj: '.($lastIn ? $this->when($lastIn) : 'yok').'. Gelen kutusunda gösterilen: '.($backupShown ? '"'.$backupShown->name.'" yedeği' : 'canlı numara').'.',
            $lastIn === null ? 'Başka bir telefondan numaraya bir mesaj at; 1 dakika içinde burada görünmeli.' : ($backupShown ? 'Canlı görüşmeleri görmek için yedekler listesindeki "Canlı numara (Meta)" satırında "Görüşmelerde kullan"a bas.' : null));

        // 8. The last connection attempts, as Meta answered them.
        foreach (WhatsAppSignupAttempt::query()->where('integration_id', $row?->id)->orderByDesc('created_at')->limit(2)->get() as $i => $attempt) {
            $trace = collect((array) ($attempt->trace ?? []))->map(fn ($t): string => ($t['event'] ?? '').(filled($t['note'] ?? null) ? ' ('.$t['note'].')' : ''))->implode(' → ');
            $add('8. Bağlantı denemesi '.($i + 1), $attempt->status === 'completed' ? true : ($attempt->status === 'failed' ? false : null),
                $attempt->created_at?->timezone('Europe/Istanbul')->format('d.m H:i').' · '.$attempt->status.(filled(data_get($attempt->details, 'message')) ? ' · '.data_get($attempt->details, 'message') : '').($trace !== '' ? ' · '.$trace : ''));
        }

        $first = collect($rows)->first(fn (array $r): bool => ($r['ok'] ?? null) === false);
        $result = ['at' => now()->toIso8601String(), 'ok' => $first === null && $lastIn !== null, 'first_problem' => $first['step'] ?? null, 'rows' => $rows];
        try {
            Cache::put(self::CACHE_KEY, $result, now()->addDays(7));
        } catch (Throwable) {
        }

        return $result;
    }

    /** The last stored result (system-health and the screen), without calling Meta. */
    public static function last(): ?array
    {
        try {
            return Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }
    }

    private function error(WhatsAppGraphException $e): string
    {
        $d = $e->details;

        return 'Meta: '.($d['message'] ?? 'hata').(isset($d['code']) ? ' (kod '.$d['code'].(isset($d['subcode']) ? '/'.$d['subcode'] : '').')' : '');
    }

    private function when(string $iso): string
    {
        try {
            return CarbonImmutable::parse($iso)->timezone('Europe/Istanbul')->format('d.m.Y H:i');
        } catch (Throwable) {
            return $iso;
        }
    }
}
