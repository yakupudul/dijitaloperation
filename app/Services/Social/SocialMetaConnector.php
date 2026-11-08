<?php

namespace App\Services\Social;

use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\SocialAccount;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\Meta\MetaOAuthRedirectUriResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Sosyal medya" Meta login (ADR-083). Separate from the read-only Meta Ads connection: it asks for publishing,
 * messaging, comment and insight permissions of the agency's own Pages and Instagram accounts, then stores one row per
 * Facebook Page and per linked Instagram professional account with the (non-expiring) Page token. It reuses the
 * existing Meta callback URL, so no new redirect URI has to be added in the Meta app: the callback hands states it
 * started back to this class.
 */
class SocialMetaConnector
{
    /** Everything the module will use, asked once so later stages need no new login. */
    public const array SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        // Reading visitors' comments on Page posts. Meta also adds it on its own from the "Manage everything on your
        // Page" use case and refuses the login ("Invalid Scopes") until it is added there (2026-11-08).
        'pages_read_user_content',
        'pages_manage_posts',
        'pages_manage_metadata',
        'pages_manage_engagement',
        // pages_messaging (Facebook Page DMs) needs the Messenger use case on the app; added back once it is there.
        'instagram_basic',
        'instagram_content_publish',
        'instagram_manage_comments',
        'instagram_manage_messages',
        'instagram_manage_insights',
        'read_insights',
        'business_management',
    ];

    private const string STATE_PREFIX = 'social_meta_oauth:';

    /** The last "Meta'yı bağla" result, shown on Hesaplar so a failed attempt never disappears with the redirect. */
    private const string LAST_ATTEMPT_KEY = 'social_meta:last_attempt';

    public function __construct(
        private readonly SocialGraph $graph,
        private readonly MetaOAuthRedirectUriResolver $redirect,
    ) {}

    public static function configId(): ?string
    {
        try {
            $value = trim((string) (AgencySetting::query()->value('social_meta_config_id') ?? ''));
        } catch (Throwable) {
            $value = '';
        }

        return $value !== '' ? $value : null;
    }

    /** @return array{url: string}|array{error: string} */
    public function begin(int $userId): array
    {
        $appId = $this->graph->appId();
        if ($appId === null || $appId === '') {
            return ['error' => 'Meta uygulaması tanımlı değil (Entegrasyonlar › Meta).'];
        }
        $configId = self::configId();
        if ($configId !== null && in_array($configId, self::otherConfigIds(), true)) {
            return ['error' => 'Kayıtlı yapılandırma kimliği WhatsApp ya da reklam bağlantısına ait. Hesaplar › “Meta uygulama ayarı” adımlarıyla “Moximu sosyal” yapılandırmasını açıp onun kimliğini kaydedin.'];
        }
        $state = Str::random(64);
        Cache::put(self::STATE_PREFIX.hash('sha256', $state), $userId, now()->addMinutes(20));

        $query = ['client_id' => $appId, 'redirect_uri' => $this->redirect->uri(), 'state' => $state, 'response_type' => 'code'];
        if ($configId !== null) {
            // Login for Business: config_id replaces scope; the code grant must be forced so a configuration whose
            // token type is "system user" still returns a code instead of failing in the dialog.
            $query['config_id'] = $configId;
            $query['override_default_response_type'] = 'true';
        } else {
            $query['scope'] = implode(',', self::SCOPES);
        }

        return ['url' => MetaApiConfig::dialogBaseUrl().'?'.http_build_query($query)];
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

    public static function ownsState(?string $state): bool
    {
        return is_string($state) && $state !== '' && Cache::has(self::STATE_PREFIX.hash('sha256', $state));
    }

    /** @return array{ok: true, accounts: list<string>}|array{error: string} */
    public function handleCallback(?string $code, ?string $state, ?string $oauthError, int $userId): array
    {
        $key = self::STATE_PREFIX.hash('sha256', (string) $state);
        $owner = Cache::pull($key);
        if ($owner === null || (int) $owner !== $userId) {
            return ['error' => 'Oturum süresi doldu; "Meta\'yı bağla" ile yeniden başlayın.'];
        }
        if ($oauthError !== null && $oauthError !== '') {
            return ['error' => 'Meta izin vermedi ('.Str::limit(preg_replace('/[^a-z_]/i', '', $oauthError) ?: 'iptal', 40).').'];
        }
        if ($code === null || $code === '') {
            return ['error' => 'Meta bir kod döndürmedi.'];
        }

        $appId = (string) $this->graph->appId();
        $secret = (string) $this->graph->appSecret();
        try {
            $short = Http::timeout(30)->acceptJson()->get(MetaApiConfig::graphBaseUrl().'/oauth/access_token', [
                'client_id' => $appId, 'client_secret' => $secret, 'redirect_uri' => $this->redirect->uri(), 'code' => $code,
            ])->json();
            $shortToken = is_array($short) ? (string) ($short['access_token'] ?? '') : '';
            if ($shortToken === '') {
                return ['error' => 'Meta anahtarı verilemedi; yeniden deneyin.'];
            }
            $long = Http::timeout(30)->acceptJson()->get(MetaApiConfig::graphBaseUrl().'/oauth/access_token', [
                'grant_type' => 'fb_exchange_token', 'client_id' => $appId, 'client_secret' => $secret, 'fb_exchange_token' => $shortToken,
            ])->json();
            $userToken = is_array($long) && is_string($long['access_token'] ?? null) ? $long['access_token'] : $shortToken;
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'Meta\'ya bağlanılamadı; yeniden deneyin.'];
        }

        $missing = $this->missingPermissions($userToken);
        if ($missing !== []) {
            return ['error' => 'Meta girişi bu izinleri vermedi: '.implode(', ', $missing).'. Yapılandırmada (Facebook Login for Business › Configurations) bu izinler seçili değil ya da uygulamaya eklenmemiş; Hesaplar › “Meta uygulama ayarı” adımlarını izleyin.'];
        }

        return $this->storePages($userToken);
    }

    /**
     * Configuration ids that belong to the other Meta logins (WhatsApp signup, Meta Ads). Pasting one of them here is
     * the usual setup mistake: the dialog then asks for WhatsApp/ads permissions and no Page token comes back.
     *
     * @return list<string>
     */
    private static function otherConfigIds(): array
    {
        $ids = [(string) MetaApiConfig::loginConfigurationId()];
        try {
            foreach (CoreIntegration::query()->where('provider', 'whatsapp')->get() as $row) {
                $ids[] = (string) data_get($row->config, 'signup_config_id');
            }
        } catch (Throwable) {
        }

        return array_values(array_filter(array_map('trim', $ids), fn (string $id): bool => $id !== ''));
    }

    /**
     * The two permissions without which nothing works; the rest are reported by the calls that need them.
     *
     * @return list<string>
     */
    private function missingPermissions(string $userToken): array
    {
        try {
            $rows = (array) ($this->graph->get('me/permissions', $userToken)['data'] ?? []);
        } catch (Throwable) {
            return [];
        }
        $granted = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($row['status'] ?? '') === 'granted' && is_string($row['permission'] ?? null)) {
                $granted[] = $row['permission'];
            }
        }
        if ($granted === []) {
            return [];
        }

        return array_values(array_diff(['pages_show_list', 'instagram_basic'], $granted));
    }

    /** The agency's own Page or Instagram account, by name (Facebook) or handle (Instagram). */
    public static function isOwn(string $pageName, ?string $username = null): bool
    {
        return str_contains(mb_strtolower($pageName), 'moximu') || in_array(mb_strtolower((string) $username), ['moximucom', 'moximu'], true);
    }

    /**
     * A new account starts switched on only when it is the agency's own; an existing one keeps the operator's choice.
     *
     * @param  array<string, mixed>  $values
     */
    private function keep(string $platform, string $externalId, array $values, bool $own, ?int $brandId): void
    {
        $account = SocialAccount::query()->firstOrNew(['platform' => $platform, 'external_id' => $externalId]);
        if (! $account->exists) {
            $account->enabled = $own;
        }
        $account->fill($values + ['brand_id' => $own ? $brandId : null])->save();
    }

    /** @return array{ok: true, accounts: list<string>}|array{error: string} */
    public function storePages(string $userToken): array
    {
        try {
            $pages = $this->graph->get('me/accounts', $userToken, [
                'fields' => 'id,name,access_token,picture{url},instagram_business_account{id,username,name,profile_picture_url}',
                'limit' => 100,
            ]);
        } catch (SocialGraphException $e) {
            return ['error' => 'Sayfalar okunamadı: '.$e->getMessage()];
        }

        $brandId = Brand::query()->where('name', 'Moximu')->value('id');
        $names = [];
        $found = 0;
        foreach ((array) ($pages['data'] ?? []) as $page) {
            if (! is_array($page) || ! is_string($page['id'] ?? null) || ! is_string($page['access_token'] ?? null)) {
                continue;
            }
            $ig = $page['instagram_business_account'] ?? null;
            $ig = is_array($ig) && is_string($ig['id'] ?? null) ? $ig : null;
            // The login returns every Page the operator manages, customers' too (2026-11-08: 56 accounts). Only the
            // agency's own Page and its Instagram are switched on; the rest are kept switched off, so a Moximu post
            // can never go out on a customer's account.
            $own = self::isOwn((string) ($page['name'] ?? ''), $ig !== null ? (string) ($ig['username'] ?? '') : null);
            $this->keep(SocialAccount::FACEBOOK, $page['id'], ['name' => (string) ($page['name'] ?? ''), 'access_token' => $page['access_token'],
                'page_id' => $page['id'], 'picture_url' => $page['picture']['data']['url'] ?? null, 'connected_at' => now()], $own, $brandId);
            $found++;
            if ($own) {
                $names[] = 'Facebook: '.($page['name'] ?? $page['id']);
            }
            if ($ig !== null) {
                $this->keep(SocialAccount::INSTAGRAM, $ig['id'], ['name' => (string) ($ig['name'] ?? ''), 'username' => $ig['username'] ?? null,
                    'access_token' => $page['access_token'], 'page_id' => $page['id'], 'picture_url' => $ig['profile_picture_url'] ?? null,
                    'connected_at' => now()], $own, $brandId);
                if ($own) {
                    $names[] = 'Instagram: @'.($ig['username'] ?? $ig['id']);
                }
            }
        }
        if ($found === 0) {
            return ['error' => 'Bu Meta hesabında yönetilen sayfa bulunamadı. Girişte Moximu sayfasını ve Instagram hesabını seçtiğinizden emin olun.'];
        }
        if ($names === []) {
            app(SocialPlanner::class)->syncUpcoming();

            return ['error' => $found.' sayfa geldi ama aralarında Moximu sayfası yok. Girişte Moximu sayfasını da seçin.'];
        }
        if ($found > 1) {
            $names[] = '(diğer '.($found - 1).' sayfa kapalı olarak listede)';
        }

        app(SocialPlanner::class)->syncUpcoming();

        return ['ok' => true, 'accounts' => $names];
    }
}
