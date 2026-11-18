<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Kişisel LinkedIn'i bağla" (Sosyal › Ayarlar › Hesaplar; yakup 2026-11-17): the owner signs in with a second
 * LinkedIn app that carries "Share on LinkedIn" and "Sign In with LinkedIn using OpenID Connect". The member token
 * (60 days, no refresh token) is stored on its own account row; posts that carry a personal text are then published
 * on his profile at their time. LinkedIn gives no statistics for personal posts through this permission.
 */
class LinkedInPersonalConnector
{
    public const array SCOPES = ['openid', 'profile', 'w_member_social'];

    private const string STATE_PREFIX = 'social_linkedin_me_oauth:';

    private const string LAST_ATTEMPT_KEY = 'social_linkedin_me:last_attempt';

    public function __construct(private readonly LinkedInApi $api) {}

    /** @return array{url: string}|array{error: string} */
    public function begin(int $userId): array
    {
        $app = LinkedInApi::app(true);
        if ($app === null) {
            return ['error' => 'Önce kişisel LinkedIn uygulamasının Client ID ve Client Secret bilgisini kaydedin.'];
        }
        $state = Str::random(48);
        Cache::put(self::STATE_PREFIX.hash('sha256', $state), $userId, now()->addMinutes(20));

        return ['url' => LinkedInApi::AUTH_BASE.'/authorization?'.http_build_query([
            'response_type' => 'code', 'client_id' => $app['id'], 'redirect_uri' => LinkedInConnector::redirectUri(),
            'state' => $state, 'scope' => implode(' ', self::SCOPES),
        ], '', '&', PHP_QUERY_RFC3986)];
    }

    /** @return array{ok: true, name: string}|array{error: string} */
    public function handleCallback(?string $code, ?string $state, ?string $oauthError, ?string $errorText, int $userId): array
    {
        $owner = Cache::pull(self::STATE_PREFIX.hash('sha256', (string) $state));
        if ($owner === null || (int) $owner !== $userId) {
            return ['error' => 'Oturum süresi doldu; “Kişisel LinkedIn’i bağla” ile yeniden başlayın.'];
        }
        if ($oauthError !== null && $oauthError !== '') {
            return ['error' => 'LinkedIn izin vermedi ('.Str::limit(trim(preg_replace('/[^\pL\pN _.:-]/u', '', (string) ($errorText ?: $oauthError)) ?? ''), 160).').'];
        }
        if ($code === null || $code === '') {
            return ['error' => 'LinkedIn bir kod döndürmedi.'];
        }
        try {
            $data = $this->api->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => LinkedInConnector::redirectUri()], true);
            $token = (string) ($data['access_token'] ?? '');
            if ($token === '') {
                return ['error' => 'LinkedIn anahtar vermedi; yeniden deneyin.'];
            }
            // OpenID userinfo: "sub" is the member id the posts are written as.
            $response = Http::timeout(30)->acceptJson()->withToken($token)->get('https://api.linkedin.com/v2/userinfo');
            $me = $response->successful() && is_array($response->json()) ? $response->json() : [];
        } catch (SocialGraphException $e) {
            return ['error' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'LinkedIn’e bağlanılamadı; yeniden deneyin.'];
        }
        $id = (string) ($me['sub'] ?? '');
        if ($id === '') {
            return ['error' => 'LinkedIn profili okunamadı; uygulamada “Sign In with LinkedIn using OpenID Connect” ürünü açık mı bakın.'];
        }
        $existing = SocialAccount::query()->where('platform', SocialAccount::LINKEDIN_PERSONAL)->orderBy('id')->first();
        $values = [
            'external_id' => 'person/'.$id,
            'name' => trim((string) ($me['name'] ?? '')) ?: 'Kişisel profil',
            'access_token' => $token,
            'refresh_token' => is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== '' ? $data['refresh_token'] : null,
            'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 5184000)),
            'meta' => array_merge((array) ($existing->meta ?? []), ['person_urn' => 'urn:li:person:'.$id, 'scopes' => (string) ($data['scope'] ?? '')]),
            'connected_at' => now(),
            'enabled' => true,
        ];
        if ($existing !== null) {
            $existing->forceFill($values)->save();
        } else {
            SocialAccount::query()->forceCreate(['platform' => SocialAccount::LINKEDIN_PERSONAL] + $values
                + ['brand_id' => SocialAccount::query()->whereNotNull('brand_id')->value('brand_id')]);
        }
        app(SocialPlanner::class)->syncUpcoming();

        return ['ok' => true, 'name' => $values['name']];
    }

    public static function ownsState(?string $state): bool
    {
        return is_string($state) && $state !== '' && Cache::has(self::STATE_PREFIX.hash('sha256', $state));
    }

    public static function rememberAttempt(bool $ok, string $text): void
    {
        Cache::forever(self::LAST_ATTEMPT_KEY, ['ok' => $ok, 'text' => Str::limit($text, 600), 'at' => now()->toIso8601String()]);
    }

    /** @return array{ok: bool, text: string, at: string}|null */
    public static function lastAttempt(): ?array
    {
        try {
            $value = Cache::get(self::LAST_ATTEMPT_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_array($value) && isset($value['ok'], $value['text'], $value['at']) ? $value : null;
    }
}
