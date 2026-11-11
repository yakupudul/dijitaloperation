<?php

namespace App\Services\Access;

use App\Models\AccessLink;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\User;
use App\Services\Assistant\PushNotifier;
use App\Services\DataStatus\ConnectionMap;
use App\Support\SchemaState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Erişim bağlantısı (Bağlantı Düzeni 4.1). A missing access is no longer chased by message: the operator picks which
 * accesses a brand owes, gets ONE link and sends it himself. The customer opens it without logging in, sees for each
 * access the few steps in his own account and the agency identity to add, and marks "Yetkiyi verdim"; the operator is
 * told at once. The system sends nothing to the customer and nothing here touches an account: the grant itself is
 * made by the customer inside Google or Meta.
 */
final class AccessLinks
{
    /**
     * What can be asked, the identity the customer adds and the steps (in the customer's words).
     *
     * @var array<string, array{name: string, identity: string, role: string, where: string, steps: list<string>}>
     */
    public const array CHANNELS = [
        'search_console' => ['name' => 'Google Search Console', 'identity' => 'google', 'role' => 'Tam', 'where' => 'https://search.google.com/search-console',
            'steps' => ['Search Console\'u açın ve sitenizi seçin.', 'Sol alttan Ayarlar, sonra Kullanıcılar ve izinler bölümüne girin.', 'Kullanıcı ekle düğmesine basın, aşağıdaki e-posta adresini yazın ve izni "Tam" seçin.']],
        'ga4' => ['name' => 'Google Analytics', 'identity' => 'google', 'role' => 'Düzenleyici', 'where' => 'https://analytics.google.com',
            'steps' => ['Analytics\'i açın, sol alttaki Yönetici (dişli) simgesine basın.', 'Hesap erişim yönetimi bölümüne girin.', 'Sağ üstteki artı işaretinden Kullanıcı ekle deyin, aşağıdaki e-posta adresini yazın ve rolü "Düzenleyici" seçin.']],
        'google_ads' => ['name' => 'Google Ads', 'identity' => 'google', 'role' => 'Yönetici', 'where' => 'https://ads.google.com',
            'steps' => ['Google Ads hesabınızı açın, soldan Yönetici bölümüne girin.', 'Erişim ve güvenlik sayfasını açın.', 'Artı işaretine basın, aşağıdaki e-posta adresini yazın, erişim düzeyini "Yönetici" seçip daveti gönderin.']],
        'google_business_profile' => ['name' => 'Google İşletme Profili', 'identity' => 'google', 'role' => 'Yönetici', 'where' => 'https://business.google.com',
            'steps' => ['Profilin sahibi olan Google hesabıyla Google\'da işletmenizin adını arayın.', 'Çıkan yönetim panelinde üç noktaya basıp İşletme Profili ayarları, sonra Kişiler ve erişim bölümüne girin.', 'Ekle düğmesine basın, aşağıdaki e-posta adresini yazın, erişimi "Yönetici" seçip davet edin.']],
        'meta_ads' => ['name' => 'Meta (Facebook ve Instagram reklamları)', 'identity' => 'meta', 'role' => 'Tam denetim', 'where' => 'https://business.facebook.com/settings',
            'steps' => ['Meta Business ayarlarını açın.', 'Kullanıcılar altındaki İş ortakları bölümüne girin, Ekle deyip "Varlıklarınıza erişim verin" seçeneğini seçin.', 'Aşağıdaki işletme kimliğini yazın; reklam hesabını, sayfayı, Instagram hesabını ve pikseli seçip tam denetim verin.']],
    ];

    public const array IDENTITIES = ['google' => 'Google hesabı e-postası', 'meta' => 'Meta e-postası ya da işletme kimliği (Business ID)'];

    public function __construct(private readonly ConnectionMap $map, private readonly PushNotifier $push) {}

    /** @return array{google: string, meta: string} the agency identities a customer adds to his accounts */
    public function identities(): array
    {
        $stored = SchemaState::hasColumn('agency_settings', 'access_identities') ? json_decode((string) (DB::table('agency_settings')->value('access_identities') ?? '{}'), true) : [];

        return ['google' => trim((string) ($stored['google'] ?? '')), 'meta' => trim((string) ($stored['meta'] ?? ''))];
    }

    public function saveIdentities(string $google, string $meta): void
    {
        $google = trim($google);
        // Meta: either the agency's business id (the customer adds a partner) or a person's e-mail (the customer invites a person).
        $meta = str_contains($meta, '@') ? trim($meta) : (preg_replace('/\D+/', '', $meta) ?? '');
        if (str_contains($meta, '@') && filter_var($meta, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Meta için e-posta adresi ya da işletme kimliği yazılmalı.');
        }
        if ($google !== '' && filter_var($google, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Google hesabı bir e-posta adresi olmalı.');
        }
        $settings = AgencySetting::query()->first() ?? new AgencySetting;
        $settings->forceFill(['access_identities' => json_encode(['google' => $google, 'meta' => $meta])])->save();
    }

    public function of(Brand $brand): ?AccessLink
    {
        return SchemaState::hasTable('access_links') ? AccessLink::query()->where('brand_id', $brand->id)->first() : null;
    }

    /**
     * The channels the map shows as not working for this brand: the default ticks of a new link.
     *
     * @return list<string>
     */
    public function suggested(Brand $brand): array
    {
        $row = collect($this->map->rows())->firstWhere('brand_id', (int) $brand->id);

        return array_values(array_filter(array_keys(self::CHANNELS), fn (string $channel): bool => ($row['cells'][$channel]['tone'] ?? 'muted') !== 'ok'));
    }

    /**
     * Creates the brand's link or changes what it asks (the address stays the same once sent).
     *
     * @param  list<string>  $channels
     */
    public function ask(Brand $brand, array $channels, ?User $by = null): AccessLink
    {
        $channels = array_values(array_intersect(array_keys(self::CHANNELS), $channels));
        if ($channels === []) {
            throw new InvalidArgumentException('En az bir erişim seç.');
        }
        $identities = $this->identities();
        foreach ($channels as $channel) {
            if ($identities[self::CHANNELS[$channel]['identity']] === '') {
                throw new InvalidArgumentException(self::CHANNELS[$channel]['name'].' için önce "'.self::IDENTITIES[self::CHANNELS[$channel]['identity']].'" yazılmalı: müşteri kimi ekleyeceğini bu sayfadan okur.');
            }
        }
        $link = $this->of($brand) ?? new AccessLink(['brand_id' => $brand->id, 'token' => Str::random(48), 'created_by' => $by?->id]);
        $link->forceFill(['asked' => $channels, 'done' => array_intersect_key((array) $link->done, array_flip($channels))])->save();

        return $link;
    }

    /**
     * The public page's items: asked channel, its steps, the identity to add and where it stands.
     *
     * @return list<array{channel: string, name: string, role: string, where: string, steps: list<string>, identity: string, identity_label: string, state: string, marked_at: ?string}>
     */
    public function items(AccessLink $link): array
    {
        $identities = $this->identities();
        $row = collect($this->map->rows())->firstWhere('brand_id', (int) $link->brand_id);
        $items = [];
        foreach ((array) $link->asked as $channel) {
            $guide = self::CHANNELS[$channel] ?? null;
            if ($guide === null) {
                continue;
            }
            $marked = $link->done[$channel]['at'] ?? null;
            $byMail = $guide['identity'] === 'meta' && str_contains($identities['meta'], '@');
            if ($byMail) {
                $guide['steps'] = ['Meta Business ayarlarını açın.', 'Kullanıcılar altındaki Kişiler bölümüne girin ve Kişi davet et düğmesine basın.',
                    'Aşağıdaki e-posta adresini yazın, tam denetim verin; reklam hesabını, sayfayı, Instagram hesabını ve pikseli seçip daveti gönderin.'];
            }
            $items[] = [
                'channel' => $channel, 'name' => $guide['name'], 'role' => $guide['role'], 'where' => $guide['where'], 'steps' => $guide['steps'],
                'identity' => $identities[$guide['identity']], 'identity_label' => $guide['identity'] === 'google' || $byMail ? 'E-posta adresi' : 'İşletme kimliği',
                // connected = the system reads the channel now; marked = the customer says it is given, not seen yet.
                'state' => ($row['cells'][$channel]['tone'] ?? 'muted') === 'ok' ? 'connected' : ($marked !== null ? 'marked' : 'open'),
                'marked_at' => $marked,
            ];
        }

        return $items;
    }

    /** The customer's "Yetkiyi verdim": written down and told to the operator once per channel. */
    public function mark(AccessLink $link, string $channel): void
    {
        if (! in_array($channel, (array) $link->asked, true) || isset($link->done[$channel])) {
            return;
        }
        $link->forceFill(['done' => array_merge((array) $link->done, [$channel => ['at' => now()->toIso8601String()]])])->save();
        $this->push->send('access-link:'.$link->id.':'.$channel, ($link->brand?->name ?? 'Marka').': erişim verildi diyor',
            self::CHANNELS[$channel]['name'].' için "Yetkiyi verdim" işaretlendi. Daveti kabul edip hesabı markaya bağla.', 'high',
            route('operator.integrations.connection-health', ['gorunum' => 'harita']));
    }

    public function opened(AccessLink $link): void
    {
        if ($link->opened_at === null) {
            $link->forceFill(['opened_at' => now()])->save();
        }
    }

    /** The ready message the operator sends with the link (he sends it himself). */
    public function message(AccessLink $link): string
    {
        if (app(StartLink::class)->of($link) !== null) {
            return 'Merhaba, başlangıç için tek bir sayfa hazırladım: fatura bilgileriniz, sözleşme onayı, ilk ödeme ve hesap erişimleri aynı yerde, toplam on dakika sürer: '
                .route('access-link', ['token' => $link->token]);
        }
        $names = collect((array) $link->asked)->map(fn (string $c): string => self::CHANNELS[$c]['name'] ?? $c)->join(', ', ' ve ');

        return 'Merhaba, çalışmaya devam edebilmemiz için '.$names.' erişimine ihtiyacımız var. Aşağıdaki sayfada her biri için yapılacak üç adım yazıyor, toplam birkaç dakika sürer: '
            .route('access-link', ['token' => $link->token]);
    }
}
