<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * "LinkedIn'i bağla" (Sosyal › Hesaplar): a LinkedIn sign-in of a super admin of the company page with the Community
 * Management API permissions. The token is stored on the LinkedIn account row together with the page (organization)
 * it publishes as; the Moximu page is picked by its vanity name, otherwise the only page the member administers.
 */
class LinkedInConnector
{
    public const array SCOPES = ['w_organization_social', 'r_organization_social', 'rw_organization_admin'];

    private const string STATE_PREFIX = 'social_linkedin_oauth:';

    private const string LAST_ATTEMPT_KEY = 'social_linkedin:last_attempt';

    public function __construct(private readonly LinkedInApi $api) {}

    public static function redirectUri(): string
    {
        return route('integrations.linkedin.callback');
    }

    /** @return array{url: string}|array{error: string} */
    public function begin(int $userId): array
    {
        $app = LinkedInApi::app();
        if ($app === null) {
            return ['error' => 'Önce LinkedIn uygulamasının Client ID ve Client Secret bilgisini kaydedin.'];
        }
        $state = Str::random(48);
        Cache::put(self::STATE_PREFIX.hash('sha256', $state), $userId, now()->addMinutes(20));

        return ['url' => LinkedInApi::AUTH_BASE.'/authorization?'.http_build_query([
            'response_type' => 'code', 'client_id' => $app['id'], 'redirect_uri' => self::redirectUri(),
            'state' => $state, 'scope' => implode(' ', self::SCOPES),
        ], '', '&', PHP_QUERY_RFC3986)];
    }

    /** @return array{ok: true, page: string}|array{error: string} */
    public function handleCallback(?string $code, ?string $state, ?string $oauthError, ?string $errorText, int $userId): array
    {
        $owner = Cache::pull(self::STATE_PREFIX.hash('sha256', (string) $state));
        if ($owner === null || (int) $owner !== $userId) {
            return ['error' => 'Oturum süresi doldu; “LinkedIn’i bağla” ile yeniden başlayın.'];
        }
        if ($oauthError !== null && $oauthError !== '') {
            return ['error' => 'LinkedIn izin vermedi ('.Str::limit(trim(preg_replace('/[^\pL\pN _.:-]/u', '', (string) ($errorText ?: $oauthError)) ?? ''), 160).').'];
        }
        if ($code === null || $code === '') {
            return ['error' => 'LinkedIn bir kod döndürmedi.'];
        }

        try {
            $data = $this->api->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri()]);
            $token = (string) ($data['access_token'] ?? '');
            if ($token === '') {
                return ['error' => 'LinkedIn anahtar vermedi; yeniden deneyin.'];
            }
            $pages = $this->adminPages($token);
        } catch (SocialGraphException $e) {
            return ['error' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'LinkedIn’e bağlanılamadı; yeniden deneyin.'];
        }
        if ($pages === []) {
            return ['error' => 'Bu LinkedIn hesabı hiçbir şirket sayfasında yönetici değil. Moximu sayfasında süper yönetici olan hesapla girin.'];
        }

        $existing = SocialAccount::query()->where('platform', SocialAccount::LINKEDIN)->orderBy('id')->first();
        $wanted = $existing !== null ? strtolower(basename(trim((string) ($existing->meta['url'] ?? $existing->external_id), '/'))) : 'moximu';
        $page = collect($pages)->first(fn (array $p): bool => strtolower($p['vanity']) === $wanted)
            ?? collect($pages)->first(fn (array $p): bool => str_contains(strtolower($p['vanity'].' '.$p['name']), 'moximu'))
            ?? (count($pages) === 1 ? $pages[0] : null);
        if ($page === null) {
            return ['error' => 'Yönetici olduğunuz sayfalar arasında Moximu bulunamadı ('.implode(', ', array_column($pages, 'name')).').'];
        }

        $url = $page['vanity'] !== '' ? 'https://www.linkedin.com/company/'.$page['vanity'].'/' : ($existing?->meta['url'] ?? null);
        $values = [
            'name' => $page['name'] !== '' ? $page['name'] : ($existing->name ?? 'Moximu'),
            'access_token' => $token,
            'refresh_token' => is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== '' ? $data['refresh_token'] : null,
            'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 5184000)),
            'meta' => array_merge((array) ($existing->meta ?? []), ['url' => $url, 'org_urn' => $page['urn'], 'vanity' => $page['vanity'], 'scopes' => (string) ($data['scope'] ?? '')]),
            'connected_at' => now(),
            'enabled' => true,
        ];
        if ($existing !== null) {
            $existing->forceFill($values)->save();
        } else {
            SocialAccount::query()->forceCreate(['platform' => SocialAccount::LINKEDIN, 'external_id' => 'company/'.($page['vanity'] ?: $page['id'])]
                + $values + ['brand_id' => SocialAccount::query()->whereNotNull('brand_id')->value('brand_id')]);
        }
        app(SocialPlanner::class)->syncUpcoming();

        return ['ok' => true, 'page' => $values['name']];
    }

    /** @return list<array{urn: string, id: string, name: string, vanity: string}> */
    private function adminPages(string $token): array
    {
        $acls = $this->api->get('/organizationAcls', $token, ['q' => 'roleAssignee', 'role' => 'ADMINISTRATOR', 'state' => 'APPROVED']);
        $pages = [];
        foreach ((array) ($acls['elements'] ?? []) as $acl) {
            $urn = (string) ($acl['organization'] ?? $acl['organizationTarget'] ?? '');
            if (! preg_match('/^urn:li:organization:(\d+)$/', $urn, $m)) {
                continue;
            }
            $name = '';
            $vanity = '';
            try {
                $org = $this->api->get('/organizations/'.$m[1], $token);
                $name = (string) ($org['localizedName'] ?? '');
                $vanity = (string) ($org['vanityName'] ?? '');
            } catch (SocialGraphException) {
            }
            $pages[] = ['urn' => $urn, 'id' => $m[1], 'name' => $name, 'vanity' => $vanity];
        }

        return $pages;
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
