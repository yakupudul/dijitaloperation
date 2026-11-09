<?php

namespace App\Services\Social;

use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Small Graph API client for the agency's own Instagram and Facebook accounts (ADR-083). It signs every call with the
 * Page token it is given (plus appsecret_proof when the app secret is known) and never logs a token.
 */
class SocialGraph
{
    public function __construct(private readonly MetaCredentialResolver $credentials) {}

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, string $token, array $query = []): array
    {
        return $this->send('GET', $path, $token, $query);
    }

    /**
     * @param  array<string, scalar|null>  $form
     * @return array<string, mixed>
     */
    public function post(string $path, string $token, array $form = []): array
    {
        return $this->send('POST', $path, $token, $form);
    }

    /** @return array<string, mixed> */
    public function delete(string $path, string $token): array
    {
        return $this->send('DELETE', $path, $token, []);
    }

    public function metaIntegration(): ?CoreIntegration
    {
        return CoreIntegration::query()->where('provider', ProviderRegistry::META)->orderBy('id')->first();
    }

    /**
     * Sosyal medya's own Meta app (saved on /sosyal › Hesaplar) when both id and secret are set; otherwise the Meta Ads
     * app of Entegrasyonlar › Meta. Id and secret always come from the same source so appsecret_proof matches.
     */
    public function appId(): ?string
    {
        $own = self::ownApp();

        return $own !== null ? $own['id'] : $this->credentials->appId($this->metaIntegration());
    }

    public function appSecret(): ?string
    {
        $own = self::ownApp();

        return $own !== null ? $own['secret'] : $this->credentials->appSecret($this->metaIntegration());
    }

    /**
     * The app used for the social login: the one saved on /sosyal › Hesaplar, else the WhatsApp app of Entegrasyonlar
     * (the reviewed, live app) when its App ID and App Secret are stored there. Null → the Meta Ads app.
     *
     * @return array{id: string, secret: string}|null
     */
    public static function ownApp(): ?array
    {
        try {
            $setting = AgencySetting::query()->first();
            $id = trim((string) ($setting?->social_meta_app_id ?? ''));
            $secret = trim((string) ($setting?->social_meta_app_secret ?? ''));
            if ($id !== '' && $secret !== '') {
                return ['id' => $id, 'secret' => $secret];
            }
            $whatsapp = CoreIntegration::query()->where('provider', WhatsAppConnection::PROVIDER)->orderBy('id')->first();
            if ($whatsapp !== null) {
                $id = trim((string) data_get($whatsapp->config, 'app_id'));
                $secret = trim((string) (app(WhatsAppConnection::class)->secrets($whatsapp)['app_secret'] ?? ''));
                if ($id !== '' && $secret !== '') {
                    return ['id' => $id, 'secret' => $secret];
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, string $token, array $params): array
    {
        if ($token === '') {
            throw new SocialGraphException('Hesabın erişim anahtarı yok; Meta bağlantısını yenileyin.', 'auth');
        }
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, '://')) {
            throw new RuntimeException('Invalid Graph path.');
        }
        $secret = $this->appSecret();
        if ($secret !== null && $secret !== '') {
            $params['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
        }
        $params = array_filter($params, static fn (mixed $v): bool => $v !== null && $v !== '');
        $url = MetaApiConfig::graphBaseUrl().'/'.$path;

        try {
            $request = Http::timeout(60)->connectTimeout(10)->withToken($token)->acceptJson();
            $response = match (strtoupper($method)) {
                'POST' => $request->asForm()->post($url, $params),
                'DELETE' => $request->delete($url.'?'.http_build_query($params)),
                default => $request->get($url, $params),
            };
        } catch (ConnectionException $e) {
            throw new SocialGraphException('Meta\'ya bağlanılamadı; birazdan yeniden denenecek.', 'transport', previous: $e);
        }

        $json = $response->json();
        if (! is_array($json)) {
            $json = [];
        }
        if ($response->failed() || isset($json['error'])) {
            $error = is_array($json['error'] ?? null) ? $json['error'] : [];
            $code = (int) ($error['code'] ?? 0);
            $kind = match (true) {
                $code === 190 || in_array($code, [10, 200], true) || ($code >= 200 && $code < 300) => 'auth',
                in_array($code, [4, 17, 32, 613], true) || $response->status() === 429 => 'rate',
                $response->serverError() => 'transport',
                default => 'provider',
            };
            $message = trim((string) ($error['error_user_msg'] ?? $error['message'] ?? 'Meta isteği başarısız.'));

            throw new SocialGraphException(mb_substr($message, 0, 300), $kind, $code);
        }

        return $json;
    }
}
