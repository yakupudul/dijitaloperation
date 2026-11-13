<?php

namespace App\Services\Social;

use App\Models\AgencySetting;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Threads (yakup 2026-11-13): short text posts on the agency's own Threads profile through the official Threads API
 * (graph.threads.net). "Threads'i bağla" on Sosyal › Hesaplar signs in with the Threads app of the Meta app (its own
 * app ID and secret) and keeps a 60-day token that is renewed before it ends. A post goes to Threads only when it
 * carries a Threads text: a text post of at most 500 characters, with the link at its end shown as a card.
 */
class ThreadsApi
{
    public const string AUTH_URL = 'https://threads.net/oauth/authorize';

    public const string API_BASE = 'https://graph.threads.net';

    public const array SCOPES = ['threads_basic', 'threads_content_publish'];

    public const int MAX_TEXT = 500;

    private const int REFRESH_BEFORE_DAYS = 7;

    private const string STATE_PREFIX = 'social_threads_oauth:';

    private const string LAST_ATTEMPT_KEY = 'social_threads:last_attempt';

    /** @return array{id: string, secret: string}|null */
    public static function app(): ?array
    {
        try {
            $setting = AgencySetting::query()->first();
        } catch (Throwable) {
            return null;
        }
        $id = trim((string) ($setting?->social_threads_app_id ?? ''));
        $secret = trim((string) ($setting?->social_threads_app_secret ?? ''));

        return $id !== '' && $secret !== '' ? ['id' => $id, 'secret' => $secret] : null;
    }

    public static function redirectUri(): string
    {
        return route('integrations.threads.callback');
    }

    /** @return array{url: string}|array{error: string} */
    public function begin(int $userId): array
    {
        $app = self::app();
        if ($app === null) {
            return ['error' => 'Önce Threads uygulama kimliğini ve gizli anahtarını kaydedin.'];
        }
        $state = Str::random(48);
        Cache::put(self::STATE_PREFIX.hash('sha256', $state), $userId, now()->addMinutes(20));

        return ['url' => self::AUTH_URL.'?'.http_build_query([
            'client_id' => $app['id'], 'redirect_uri' => self::redirectUri(), 'scope' => implode(',', self::SCOPES),
            'response_type' => 'code', 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986)];
    }

    /** @return array{ok: true, name: string}|array{error: string} */
    public function handleCallback(?string $code, ?string $state, ?string $oauthError, ?string $errorText, int $userId): array
    {
        $owner = Cache::pull(self::STATE_PREFIX.hash('sha256', (string) $state));
        if ($owner === null || (int) $owner !== $userId) {
            return ['error' => 'Oturum süresi doldu; “Threads’i bağla” ile yeniden başlayın.'];
        }
        if ($oauthError !== null && $oauthError !== '') {
            return ['error' => 'Threads izin vermedi ('.Str::limit(trim(preg_replace('/[^\pL\pN _.:-]/u', '', (string) ($errorText ?: $oauthError)) ?? ''), 160).').'];
        }
        $app = self::app();
        if ($code === null || $code === '' || $app === null) {
            return ['error' => 'Threads bir kod döndürmedi.'];
        }
        try {
            // Threads appends "#_" to the code in some browsers.
            $short = $this->json($this->send(fn () => Http::timeout(30)->acceptJson()->asForm()->post(self::API_BASE.'/oauth/access_token', [
                'client_id' => $app['id'], 'client_secret' => $app['secret'], 'grant_type' => 'authorization_code',
                'redirect_uri' => self::redirectUri(), 'code' => preg_replace('/#_$/', '', $code),
            ])));
            $long = $this->json($this->send(fn () => Http::timeout(30)->acceptJson()->get(self::API_BASE.'/access_token', [
                'grant_type' => 'th_exchange_token', 'client_secret' => $app['secret'], 'access_token' => (string) ($short['access_token'] ?? ''),
            ])));
            $token = (string) ($long['access_token'] ?? '');
            if ($token === '') {
                return ['error' => 'Threads anahtar vermedi; yeniden deneyin.'];
            }
            $me = $this->get('/v1.0/me', $token, ['fields' => 'id,username,name']);
        } catch (SocialGraphException $e) {
            return ['error' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'Threads’e bağlanılamadı; yeniden deneyin.'];
        }
        $id = (string) ($me['id'] ?? '');
        if ($id === '') {
            return ['error' => 'Threads profili okunamadı.'];
        }
        $values = [
            'external_id' => $id, 'username' => (string) ($me['username'] ?? '') ?: null, 'name' => (string) ($me['name'] ?? '') ?: 'Threads',
            'access_token' => $token, 'token_expires_at' => now()->addSeconds((int) ($long['expires_in'] ?? 5184000)),
            'connected_at' => now(), 'enabled' => true,
        ];
        $existing = SocialAccount::query()->where('platform', SocialAccount::THREADS)->orderBy('id')->first();
        if ($existing !== null) {
            $existing->forceFill($values)->save();
        } else {
            SocialAccount::query()->forceCreate(['platform' => SocialAccount::THREADS] + $values
                + ['brand_id' => SocialAccount::query()->whereNotNull('brand_id')->value('brand_id')]);
        }
        app(SocialPlanner::class)->syncUpcoming();

        return ['ok' => true, 'name' => '@'.($values['username'] ?? $id)];
    }

    /** @return array{status: string, id: string, permalink: ?string} */
    public function publish(SocialPost $post, SocialAccount $account): array
    {
        $text = self::clip((string) $post->threads_text);
        if ($text === '') {
            throw new SocialGraphException('Threads metni yok.');
        }
        $token = $this->freshToken($account);
        $user = (string) $account->external_id;
        $container = (string) ($this->json($this->send(fn () => Http::timeout(60)->acceptJson()->withToken($token)->asForm()
            ->post(self::API_BASE.'/v1.0/'.$user.'/threads', ['media_type' => 'TEXT', 'text' => $text])))['id'] ?? '');
        if ($container === '') {
            throw new SocialGraphException('Threads gönderi kabı açılamadı.');
        }
        $id = (string) ($this->json($this->send(fn () => Http::timeout(60)->acceptJson()->withToken($token)->asForm()
            ->post(self::API_BASE.'/v1.0/'.$user.'/threads_publish', ['creation_id' => $container])))['id'] ?? '');
        if ($id === '') {
            throw new SocialGraphException('Threads gönderiyi yayınlamadı.');
        }
        $permalink = null;
        try {
            $permalink = $this->get('/v1.0/'.$id, $token, ['fields' => 'permalink'])['permalink'] ?? null;
        } catch (SocialGraphException) {
        }

        return ['status' => 'published', 'id' => $id, 'permalink' => is_string($permalink) ? $permalink : null];
    }

    /** At most 500 characters; a link at the very end is kept whole and the text before it is shortened. */
    public static function clip(string $text): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= self::MAX_TEXT) {
            return $text;
        }
        if (preg_match('/^(.*?)\s*(https?:\/\/\S+)$/su', $text, $m) === 1 && mb_strlen($m[2]) < 200) {
            return rtrim(mb_substr(trim($m[1]), 0, self::MAX_TEXT - mb_strlen($m[2]) - 3)).'…'."\n\n".$m[2];
        }

        return rtrim(mb_substr($text, 0, self::MAX_TEXT - 1)).'…';
    }

    /** A token that ends within a week is renewed (Threads renews a live long-lived token for another 60 days). */
    public function freshToken(SocialAccount $account): string
    {
        $token = (string) $account->access_token;
        $ends = $account->token_expires_at;
        if ($ends === null || $ends->isAfter(now()->addDays(self::REFRESH_BEFORE_DAYS))) {
            return $token;
        }
        if ($ends->isPast()) {
            throw new SocialGraphException('Threads bağlantısının süresi doldu; Sosyal › Hesaplar’dan yeniden bağlayın.', 'auth');
        }
        try {
            $data = $this->get('/refresh_access_token', $token, ['grant_type' => 'th_refresh_token', 'access_token' => $token]);
            $new = (string) ($data['access_token'] ?? '');
            if ($new !== '') {
                $account->forceFill(['access_token' => $new, 'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 5184000))])->save();

                return $new;
            }
        } catch (SocialGraphException) {
            // The current token still works until its end; the next post tries the renewal again.
        }

        return $token;
    }

    /** @param  array<string, string>  $query  @return array<string, mixed> */
    public function get(string $path, string $token, array $query = []): array
    {
        return $this->json($this->send(fn () => Http::timeout(30)->acceptJson()->withToken($token)->get(self::API_BASE.$path, $query)));
    }

    /** @param  \Closure(): Response  $call */
    private function send(\Closure $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new SocialGraphException('Threads’e ulaşılamadı.', 'transport', 0, $e);
        }
        if ($response->failed() || is_array($response->json('error'))) {
            $message = (string) ($response->json('error.message') ?? $response->json('error_message') ?? 'HTTP '.$response->status());
            $kind = match (true) {
                $response->status() === 429 => 'rate',
                $response->status() >= 500 => 'transport',
                $response->status() === 401 || (int) $response->json('error.code') === 190 => 'auth',
                default => 'provider',
            };

            throw new SocialGraphException('Threads: '.Str::limit($message, 240), $kind, (int) $response->json('error.code'));
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
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
