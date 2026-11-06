<?php

namespace App\Services\Social;

use App\Models\CoreIntegration;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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

    public function metaIntegration(): ?CoreIntegration
    {
        return CoreIntegration::query()->where('provider', ProviderRegistry::META)->orderBy('id')->first();
    }

    public function appId(): ?string
    {
        return $this->credentials->appId($this->metaIntegration());
    }

    public function appSecret(): ?string
    {
        return $this->credentials->appSecret($this->metaIntegration());
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
            $response = strtoupper($method) === 'POST' ? $request->asForm()->post($url, $params) : $request->get($url, $params);
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
