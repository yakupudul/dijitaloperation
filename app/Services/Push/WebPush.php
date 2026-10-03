<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Web Push (RFC 8030 + VAPID RFC 8292) without a payload: MoxDOP sends an empty, VAPID-signed push and the service
 * worker (public/sw.js) fetches the notification text from /push/latest with the user's session. No payload means no
 * message encryption, so no extra library. The VAPID key pair (P-256) is created once and kept in `web_push_keys`,
 * the private key encrypted with the app key. A push service answering 404 / 410 removes the subscription.
 */
final class WebPush
{
    public const int TTL_SECONDS = 86400;

    /** @var array{public: string, private: string}|null */
    private ?array $keys = null;

    /** The public VAPID key (uncompressed P-256 point, base64url) the browser subscribes with. */
    public function publicKey(): string
    {
        return $this->keys()['public'];
    }

    /** @return bool whether the push service accepted the push */
    public function send(PushSubscription $subscription, string $urgency = 'high'): bool
    {
        try {
            $response = Http::timeout(10)->withHeaders([
                'TTL' => (string) self::TTL_SECONDS,
                'Urgency' => in_array($urgency, ['very-low', 'low', 'normal', 'high'], true) ? $urgency : 'high',
                'Authorization' => 'vapid t='.$this->jwt($subscription->endpoint).', k='.$this->publicKey(),
            ])->withBody('', 'application/octet-stream')->post($subscription->endpoint);
        } catch (Throwable) {
            $subscription->forceFill(['failures' => $subscription->failures + 1])->save();

            return false;
        }
        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();

            return false;
        }
        if (! $response->successful()) {
            $subscription->forceFill(['failures' => $subscription->failures + 1])->save();

            return false;
        }
        $subscription->forceFill(['failures' => 0, 'last_sent_at' => now()])->save();

        return true;
    }

    /** VAPID JWT (ES256) for the endpoint's push service origin, valid 12 hours. */
    public function jwt(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Push endpoint is not a URL.');
        }
        $audience = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $subject = (string) (config('mail.from.address') ? 'mailto:'.config('mail.from.address') : config('app.url'));
        $input = self::base64Url((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            .'.'.self::base64Url((string) json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
        $key = openssl_pkey_get_private($this->keys()['private']);
        if ($key === false || ! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID signing failed.');
        }

        return $input.'.'.self::base64Url(self::derToRaw($der));
    }

    /** @return array{public: string, private: string} */
    private function keys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }
        $row = DB::table('web_push_keys')->orderBy('id')->first();
        if ($row === null) {
            $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if ($key === false || ! openssl_pkey_export($key, $pem)) {
                throw new RuntimeException('VAPID key could not be created.');
            }
            $ec = (array) (openssl_pkey_get_details($key)['ec'] ?? []);
            $public = self::base64Url("\x04".str_pad((string) $ec['x'], 32, "\0", STR_PAD_LEFT).str_pad((string) $ec['y'], 32, "\0", STR_PAD_LEFT));
            DB::table('web_push_keys')->insert(['public_key' => $public, 'private_key' => Crypt::encryptString($pem), 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('web_push_keys')->orderBy('id')->first();
        }

        return $this->keys = ['public' => (string) $row->public_key, 'private' => Crypt::decryptString((string) $row->private_key)];
    }

    /** ECDSA signature: DER SEQUENCE { INTEGER r, INTEGER s } → raw 64 bytes r ‖ s (JWS ES256). */
    public static function derToRaw(string $der): string
    {
        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7F;
        }
        $integers = [];
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$offset]) !== 0x02) {
                throw new RuntimeException('Unexpected ECDSA signature.');
            }
            $length = ord($der[$offset + 1]);
            $value = ltrim(substr($der, $offset + 2, $length), "\0");
            $integers[] = str_pad($value, 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $integers[0].$integers[1];
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
