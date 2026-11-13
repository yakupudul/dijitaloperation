<?php

namespace App\Services\Measurement;

use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Integrations\Google\GoogleMeasurement;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Ölçüm kurulumu (yakup 2026-11-13: "mevcut tüm siteler için ve sonraki siteler için", Google Ads writes allowed for
 * conversion setup only). One standard for every brand website:
 *
 *  - GA4: a property (an existing bound one is reused), its web stream, three key events (phone_click, whatsapp_click,
 *    generate_lead), 14-month data retention and the Google Ads link.
 *  - Google Ads: three WEBPAGE conversion actions ("MoxDOP · …"). A category that already has a working primary action
 *    gets ours as secondary, so bidding never counts twice.
 *  - Tag Manager: a web container (an existing one is reused), the Google tag, click triggers for tel: and WhatsApp
 *    links, a form listener (Contact Form 7, Elementor, WPForms, Fluent Forms, Gravity Forms) and the GA4 event and
 *    Ads conversion tags, then a published version.
 *  - Search Console: the site is added; verification runs once the container is live on the site (action verify).
 *
 * Every step is a create step: nothing is updated or removed in an account (except GA4 retention), and an item that
 * already exists by name is skipped, so a run can be repeated safely. A site whose GA4 property or container already
 * measures conversions of its own is only reported unless the caller says allow_existing.
 */
final class MeasurementSetup
{
    public const string PREFIX = 'MoxDOP · ';

    /** The standard: our GA4 event, its Ads category and the GTM trigger that fires it. */
    public const array EVENTS = [
        'phone_click' => ['label' => 'Telefon tıklaması', 'ads_category' => 'PHONE_CALL_LEAD'],
        'whatsapp_click' => ['label' => 'WhatsApp tıklaması', 'ads_category' => 'CONTACT'],
        'generate_lead' => ['label' => 'Form gönderimi', 'ads_category' => 'SUBMIT_LEAD_FORM'],
    ];

    private const string GA = 'https://analyticsadmin.googleapis.com/v1beta/';

    private const string GTM = 'https://tagmanager.googleapis.com/tagmanager/v2/';

    private const string ALL_PAGES = '2147479553';

    /** @var array<string, array{label: string, ads_category: string}> the events of this run (all three unless `events` narrows it) */
    private array $events = self::EVENTS;

    public function __construct(private readonly GoogleApiClient $google, private readonly GoogleMeasurement $measurement) {}

    /**
     * What the brand has today: website, bound GA4 / Search Console / Ads, the tags on the home page, the Ads
     * conversion actions and which measurement permissions the connection still lacks.
     *
     * @return array<string, mixed>
     */
    public function status(Brand $brand): array
    {
        $site = $this->website($brand);
        $bound = $this->bindings($brand);
        $url = $site !== null ? $this->siteUrl($site) : null;
        $out = [
            'brand' => ['id' => $brand->id, 'name' => $brand->name],
            'website' => $url,
            'bound' => $bound,
            'missing_permissions' => $this->measurement->missing(),
            'connect_url' => $this->measurement->missing() !== [] ? $this->measurement->connectUrl() : null,
            'site_tags' => $url !== null ? $this->siteTags($url) : null,
        ];
        if (($bound['google_ads'] ?? null) !== null) {
            try {
                $out['ads_conversions'] = $this->adsConversions($bound['google_ads']['customer_id'], $bound['google_ads']['login_customer_id']);
            } catch (Throwable $e) {
                $out['ads_conversions_error'] = $e->getMessage();
            }
        }
        if (($bound['ga4'] ?? null) !== null) {
            try {
                $property = $this->propertyId($bound['ga4']);
                $out['ga4_streams'] = collect($this->list(self::GA.'properties/'.$property.'/dataStreams', 'dataStreams'))
                    ->map(fn (array $s): array => ['name' => $s['displayName'] ?? '', 'uri' => data_get($s, 'webStreamData.defaultUri'), 'measurement_id' => data_get($s, 'webStreamData.measurementId')])->all();
                $out['ga4_key_events'] = collect($this->list(self::GA.'properties/'.$property.'/keyEvents', 'keyEvents'))->pluck('eventName')->values()->all();
            } catch (Throwable $e) {
                $out['ga4_error'] = $e->getMessage();
            }
        }

        return $out;
    }

    /**
     * The GA4 accounts (with their properties) and Tag Manager accounts (with their containers) the connection sees.
     *
     * @return array<string, mixed>
     */
    public function accounts(): array
    {
        $ga4 = collect($this->list(self::GA.'accountSummaries', 'accountSummaries', ['pageSize' => 200]))
            ->map(fn (array $a): array => [
                'account' => $a['account'] ?? '', 'name' => $a['displayName'] ?? '',
                'properties' => collect($a['propertySummaries'] ?? [])->map(fn (array $p): string => ($p['property'] ?? '').' '.($p['displayName'] ?? ''))->values()->all(),
            ])->values()->all();
        $gtm = [];
        try {
            foreach ($this->list(self::GTM.'accounts', 'account') as $account) {
                $containers = $this->list(self::GTM.'accounts/'.$account['accountId'].'/containers', 'container');
                $gtm[] = [
                    'account_id' => $account['accountId'], 'name' => $account['name'] ?? '',
                    'containers' => collect($containers)->map(fn (array $c): array => ['container_id' => $c['containerId'], 'public_id' => $c['publicId'] ?? '', 'name' => $c['name'] ?? '', 'domains' => $c['domainName'] ?? []])->values()->all(),
                ];
            }
        } catch (Throwable $e) {
            $gtm = ['error' => $e->getMessage()];
        }

        return ['ga4' => $ga4, 'gtm' => $gtm, 'missing_permissions' => $this->measurement->missing()];
    }

    /**
     * Runs the standard for one brand. Options: ga4_account (accounts/N, for a new property), property_id (reuse),
     * gtm_account (N), container_id (reuse), skip_ads, allow_existing, publish (default true).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function apply(Brand $brand, array $options = []): array
    {
        if (($missing = $this->measurement->missing()) !== []) {
            throw new RuntimeException('Google bağlantısında ölçüm yetkileri eksik; önce "Ölçüm kurulumunu bağla": '.$this->measurement->connectUrl());
        }
        $site = $this->website($brand) ?? throw new RuntimeException('Markanın web sitesi kaydı yok.');
        $url = $this->siteUrl($site);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $bound = $this->bindings($brand);
        $allowExisting = (bool) ($options['allow_existing'] ?? false);
        // `events` narrows the run to some of the standard (a site that already counts WhatsApp gets only the rest).
        $only = array_values(array_intersect((array) ($options['events'] ?? []), array_keys(self::EVENTS)));
        $this->events = $only === [] ? self::EVENTS : array_intersect_key(self::EVENTS, array_flip($only));
        $log = ['created' => [], 'skipped' => [], 'warnings' => []];

        // 1. GA4 property + web stream.
        $property = isset($options['property_id']) ? preg_replace('/\D+/', '', (string) $options['property_id'])
            : ($bound['ga4'] !== null ? $this->propertyId($bound['ga4']) : null);
        if ($property === null || $property === '') {
            $account = (string) ($options['ga4_account'] ?? '');
            if (preg_match('#^accounts/\d+$#', $account) !== 1) {
                throw new RuntimeException('Bu markaya bağlı GA4 mülkü yok: yeni mülk için ga4_account (accounts/N) ver; hesaplar için action=accounts.');
            }
            $created = $this->ok($this->write('post', self::GA.'properties', [
                'parent' => $account, 'displayName' => $host, 'timeZone' => 'Europe/Istanbul', 'currencyCode' => 'TRY',
            ]), 'GA4 mülkü');
            $property = str_replace('properties/', '', (string) $created['name']);
            $log['created'][] = 'GA4 mülkü properties/'.$property;
        }
        $stream = collect($this->list(self::GA.'properties/'.$property.'/dataStreams', 'dataStreams'))
            ->first(fn (array $s): bool => ($s['type'] ?? '') === 'WEB_DATA_STREAM' && $this->sameHost((string) data_get($s, 'webStreamData.defaultUri'), $host));
        if ($stream === null) {
            $stream = $this->ok($this->write('post', self::GA.'properties/'.$property.'/dataStreams', [
                'type' => 'WEB_DATA_STREAM', 'displayName' => $host, 'webStreamData' => ['defaultUri' => 'https://'.$host],
            ]), 'GA4 web akışı');
            $log['created'][] = 'GA4 web akışı '.data_get($stream, 'webStreamData.measurementId');
        }
        $measurementId = (string) data_get($stream, 'webStreamData.measurementId');

        $keyEvents = collect($this->list(self::GA.'properties/'.$property.'/keyEvents', 'keyEvents'))->pluck('eventName')->all();
        $foreign = array_values(array_diff($keyEvents, array_keys(self::EVENTS), ['purchase']));
        if ($foreign !== [] && ! $allowExisting) {
            return $log + ['stopped' => true, 'reason' => 'GA4 mülkünde zaten dönüşüm sayan olaylar var ('.implode(', ', $foreign).'). Çift sayım olmasın diye durdum; bilerek eklemek için allow_existing=true.', 'property' => 'properties/'.$property, 'measurement_id' => $measurementId];
        }
        // key_events=false: the property already counts these as its own key events; ours are sent as plain events.
        foreach (($options['key_events'] ?? true) ? array_keys($this->events) : [] as $event) {
            if (in_array($event, $keyEvents, true)) {
                $log['skipped'][] = 'GA4 anahtar etkinlik '.$event;

                continue;
            }
            $this->ok($this->write('post', self::GA.'properties/'.$property.'/keyEvents', ['eventName' => $event, 'countingMethod' => 'ONCE_PER_EVENT']), 'GA4 anahtar etkinlik');
            $log['created'][] = 'GA4 anahtar etkinlik '.$event;
        }
        try {
            $this->ok($this->write('patch', self::GA.'properties/'.$property.'/dataRetentionSettings?updateMask=eventDataRetention', ['eventDataRetention' => 'FOURTEEN_MONTHS']), 'GA4 saklama süresi');
        } catch (Throwable $e) {
            $log['warnings'][] = $e->getMessage();
        }

        // 2. Tag Manager container and its workspace (checked before anything is created in Ads).
        $container = $this->container($options, $host, $url, $log);
        $base = self::GTM.'accounts/'.$container['accountId'].'/containers/'.$container['containerId'];
        $workspaces = $this->list($base.'/workspaces', 'workspace');
        $workspace = collect($workspaces)->firstWhere('name', 'Default Workspace') ?? ($workspaces[0] ?? throw new RuntimeException('GTM çalışma alanı bulunamadı.'));
        $ws = $base.'/workspaces/'.$workspace['workspaceId'];
        $tags = $this->list($ws.'/tags', 'tag');
        $foreignTags = collect($tags)->reject(fn (array $t): bool => str_starts_with((string) ($t['name'] ?? ''), self::PREFIX))
            ->filter(fn (array $t): bool => in_array($t['type'] ?? '', ['gaawe', 'awct', 'html', 'ua'], true))->pluck('name')->values()->all();
        if ($foreignTags !== [] && ! $allowExisting) {
            return $log + ['stopped' => true, 'reason' => 'GTM kapsayıcısında zaten olay / dönüşüm etiketleri var ('.implode(', ', array_slice($foreignTags, 0, 8)).'). Çift sayım olmasın diye durdum; bilerek eklemek için allow_existing=true.', 'container' => $container['publicId'], 'measurement_id' => $measurementId];
        }
        // 3. Google Ads conversion actions (+ the GA4 link), then the GTM standard and its publication.
        $ads = ! ($options['skip_ads'] ?? false) ? $bound['google_ads'] : null;
        $conversions = [];
        if ($ads !== null) {
            try {
                $conversions = $this->adsSetup($ads['customer_id'], $ads['login_customer_id'], $log);
            } catch (Throwable $e) {
                $log['warnings'][] = $e->getMessage();
            }
            try {
                $links = collect($this->list(self::GA.'properties/'.$property.'/googleAdsLinks', 'googleAdsLinks'))->pluck('customerId')->all();
                if (! in_array($ads['customer_id'], $links, true)) {
                    $this->ok($this->write('post', self::GA.'properties/'.$property.'/googleAdsLinks', ['customerId' => $ads['customer_id']]), 'GA4 - Google Ads bağlantısı');
                    $log['created'][] = 'GA4 - Google Ads bağlantısı '.$ads['customer_id'];
                }
            } catch (Throwable $e) {
                $log['warnings'][] = $e->getMessage();
            }
        }

        // A Google tag already hard-coded on the site with the same id must not be loaded twice (double page views).
        $hardCoded = in_array($measurementId, $this->siteTags($url)['ga4'] ?? [], true);
        if ($hardCoded) {
            $log['skipped'][] = 'GTM Google etiketi ('.$measurementId.' sitede zaten kodla yüklü)';
        }
        $changed = $this->gtmStandard($ws, $tags, $measurementId, $conversions, $log, $hardCoded);

        $published = null;
        if ($changed && ($options['publish'] ?? true)) {
            $version = $this->ok($this->write('post', $ws.':create_version', ['name' => 'MoxDOP ölçüm standardı '.now('Europe/Istanbul')->format('Y-m-d H:i'), 'notes' => 'GA4 + Google Ads dönüşümleri: telefon, WhatsApp, form.']), 'GTM sürümü');
            $versionId = (string) data_get($version, 'containerVersion.containerVersionId');
            if ($versionId === '') {
                throw new RuntimeException('GTM sürümü oluşmadı: '.json_encode(data_get($version, 'compilerError') ?? $version));
            }
            $this->ok($this->write('post', $base.'/versions/'.$versionId.':publish'), 'GTM yayını');
            $published = $versionId;
            $log['created'][] = 'GTM sürüm '.$versionId.' yayında';
        }

        // 4. Search Console site (verification comes after the container is on the site).
        if ($bound['search_console'] === null) {
            try {
                $this->ok($this->write('put', 'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode('https://'.$host.'/')), 'Search Console sitesi');
                $log['created'][] = 'Search Console sitesi https://'.$host.'/';
            } catch (Throwable $e) {
                $log['warnings'][] = $e->getMessage();
            }
        }

        $onSite = $this->siteTags($url);

        return $log + [
            'stopped' => false,
            'website' => $url,
            'property' => 'properties/'.$property,
            'measurement_id' => $measurementId,
            'container' => $container['publicId'],
            'published_version' => $published,
            'ads_conversions' => $conversions,
            'site_has_container' => in_array($container['publicId'], $onSite['gtm'] ?? [], true),
            'site_tags' => $onSite,
            'next' => in_array($container['publicId'], $onSite['gtm'] ?? [], true)
                ? 'Kapsayıcı sitede. Search Console doğrulaması için action=verify.'
                : 'Kapsayıcıyı siteye koy (WordPress: site-tool ile GTM4WP eklentisi, ya da temaya kod), sonra action=verify.',
        ];
    }

    /**
     * Search Console verification by the Tag Manager method (the container must be on the site), Analytics as fallback.
     *
     * @return array<string, mixed>
     */
    public function verify(Brand $brand): array
    {
        $site = $this->website($brand) ?? throw new RuntimeException('Markanın web sitesi kaydı yok.');
        $host = (string) parse_url($this->siteUrl($site), PHP_URL_HOST);
        $errors = [];
        foreach (['TAG_MANAGER', 'ANALYTICS'] as $method) {
            $response = $this->write('post', 'https://www.googleapis.com/siteVerification/v1/webResource?verificationMethod='.$method, ['site' => ['type' => 'SITE', 'identifier' => 'https://'.$host.'/']]);
            if ($response->successful()) {
                return ['verified' => true, 'method' => $method, 'site' => 'https://'.$host.'/', 'owners' => $response->json('owners')];
            }
            $errors[$method] = $this->error($response);
        }

        return ['verified' => false, 'site' => 'https://'.$host.'/', 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $log
     * @return array<string, array{action: string, send_to: string, primary: bool}>
     */
    private function adsSetup(string $customerId, string $login, array &$log): array
    {
        $existing = $this->adsConversions($customerId, $login);
        $ours = collect($existing)->keyBy('name');
        $create = [];
        foreach ($this->events as $event => $meta) {
            $name = self::PREFIX.$meta['label'];
            if ($ours->has($name)) {
                $log['skipped'][] = 'Ads dönüşümü '.$name;

                continue;
            }
            // A category that already has a working primary action (not ours) gets ours as secondary: never count twice.
            $taken = collect($existing)->contains(fn (array $c): bool => $c['category'] === $meta['ads_category'] && $c['primary'] && $c['status'] === 'ENABLED' && ! str_starts_with($c['name'], self::PREFIX));
            $create[] = [
                'name' => $name, 'type' => 'WEBPAGE', 'category' => $meta['ads_category'], 'status' => 'ENABLED',
                'countingType' => 'ONE_PER_CLICK', 'primaryForGoal' => ! $taken,
                'valueSettings' => ['defaultValue' => 1, 'alwaysUseDefaultValue' => false],
            ];
            $log['created'][] = 'Ads dönüşümü '.$name.($taken ? ' (ikincil: bu kategoride çalışan birincil dönüşüm var)' : '');
        }
        if ($create !== []) {
            $integration = $this->integration();
            $this->ok($this->google->createAdsConversionActions($integration, $customerId, $create, $login), 'Google Ads dönüşümü');
        }

        $rows = $this->adsSearch($customerId, $login, "SELECT conversion_action.name, conversion_action.primary_for_goal, conversion_action.tag_snippets FROM conversion_action WHERE conversion_action.name LIKE 'MoxDOP%' AND conversion_action.status = 'ENABLED'");
        $out = [];
        foreach ($this->events as $event => $meta) {
            $row = collect($rows)->first(fn (array $r): bool => data_get($r, 'conversionAction.name') === self::PREFIX.$meta['label']);
            $snippet = json_encode(data_get($row, 'conversionAction.tagSnippets', []));
            if ($row !== null && preg_match('#AW-(\d+)\\\\?/([\w-]+)#', (string) $snippet, $m) === 1) {
                $out[$event] = ['action' => self::PREFIX.$meta['label'], 'send_to' => 'AW-'.$m[1].'/'.$m[2], 'primary' => (bool) data_get($row, 'conversionAction.primaryForGoal')];
            }
        }

        return $out;
    }

    /** @return list<array{name: string, category: string, status: string, primary: bool, type: string}> */
    public function adsConversions(string $customerId, string $login): array
    {
        $rows = $this->adsSearch($customerId, $login, "SELECT conversion_action.name, conversion_action.category, conversion_action.status, conversion_action.primary_for_goal, conversion_action.type FROM conversion_action WHERE conversion_action.status != 'REMOVED'");

        return array_map(fn (array $r): array => [
            'name' => (string) data_get($r, 'conversionAction.name'),
            'category' => (string) data_get($r, 'conversionAction.category'),
            'status' => (string) data_get($r, 'conversionAction.status'),
            'primary' => (bool) data_get($r, 'conversionAction.primaryForGoal'),
            'type' => (string) data_get($r, 'conversionAction.type'),
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function adsSearch(string $customerId, string $login, string $query): array
    {
        $response = $this->google->searchAds($this->integration(), $customerId, $query, $login);

        return array_values((array) ($this->ok($response, 'Google Ads okuma')['results'] ?? []));
    }

    /**
     * @param  list<array<string, mixed>>  $tags
     * @param  array<string, array{action: string, send_to: string, primary: bool}>  $conversions
     * @param  array<string, mixed>  $log
     */
    private function gtmStandard(string $ws, array $tags, string $measurementId, array $conversions, array &$log, bool $hardCoded = false): bool
    {
        $changed = false;
        $enabled = collect($this->list($ws.'/built_in_variables', 'builtInVariable'))->pluck('type')->all();
        $need = array_values(array_diff(['clickUrl', 'clickText', 'pageUrl'], $enabled));
        if ($need !== []) {
            $this->ok($this->write('post', $ws.'/built_in_variables?'.implode('&', array_map(fn (string $t): string => 'type='.$t, $need))), 'GTM yerleşik değişkenler');
            $changed = true;
        }

        $triggers = collect($this->list($ws.'/triggers', 'trigger'))->keyBy('name');
        $triggerIds = [];
        foreach (array_intersect_key($this->triggerDefinitions(), $this->events) as $event => $definition) {
            if ($triggers->has($definition['name'])) {
                $triggerIds[$event] = (string) $triggers[$definition['name']]['triggerId'];

                continue;
            }
            $created = $this->ok($this->write('post', $ws.'/triggers', $definition), 'GTM tetikleyici');
            $triggerIds[$event] = (string) $created['triggerId'];
            $log['created'][] = 'GTM tetikleyici '.$definition['name'];
            $changed = true;
        }

        $names = collect($tags)->pluck('name')->all();
        $hasGoogleTag = collect($tags)->contains(fn (array $t): bool => ($t['type'] ?? '') === 'googtag' && collect($t['parameter'] ?? [])->contains(fn (array $p): bool => ($p['key'] ?? '') === 'tagId' && ($p['value'] ?? '') === $measurementId));
        $wanted = [];
        if (! $hasGoogleTag && ! $hardCoded) {
            $wanted[] = ['name' => self::PREFIX.'Google etiketi (GA4)', 'type' => 'googtag', 'parameter' => [$this->tpl('tagId', $measurementId)], 'firingTriggerId' => [self::ALL_PAGES]];
        }
        $wanted[] = ! isset($this->events['generate_lead']) ? null : ['name' => self::PREFIX.'Form dinleyici', 'type' => 'html', 'parameter' => [$this->tpl('html', $this->formListener()), ['type' => 'boolean', 'key' => 'supportDocumentWrite', 'value' => 'false']], 'firingTriggerId' => [self::ALL_PAGES]];
        if ($conversions !== []) {
            $wanted[] = ['name' => self::PREFIX.'Dönüşüm bağlayıcı', 'type' => 'gclidw', 'parameter' => [['type' => 'boolean', 'key' => 'enableCrossDomain', 'value' => 'false'], ['type' => 'boolean', 'key' => 'enableUrlPassthrough', 'value' => 'false']], 'firingTriggerId' => [self::ALL_PAGES]];
        }
        foreach ($this->events as $event => $meta) {
            $wanted[] = [
                'name' => self::PREFIX.'GA4 · '.$meta['label'], 'type' => 'gaawe', 'firingTriggerId' => [$triggerIds[$event]],
                'parameter' => [
                    $this->tpl('eventName', $event), $this->tpl('measurementIdOverride', $measurementId),
                    ['type' => 'boolean', 'key' => 'sendEcommerceData', 'value' => 'false'],
                    ['type' => 'list', 'key' => 'eventSettingsTable', 'list' => [['type' => 'map', 'map' => [$this->tpl('parameter', 'link_url'), $this->tpl('parameterValue', '{{Click URL}}')]]]],
                ],
            ];
            if (isset($conversions[$event]) && preg_match('#^AW-(\d+)/(.+)$#', $conversions[$event]['send_to'], $m) === 1) {
                $wanted[] = [
                    'name' => self::PREFIX.'Ads · '.$meta['label'], 'type' => 'awct', 'firingTriggerId' => [$triggerIds[$event]],
                    'parameter' => [$this->tpl('conversionId', $m[1]), $this->tpl('conversionLabel', $m[2]), ['type' => 'boolean', 'key' => 'enableConversionLinker', 'value' => 'true']],
                ];
            }
        }
        foreach (array_filter($wanted) as $tag) {
            if (in_array($tag['name'], $names, true)) {
                $log['skipped'][] = 'GTM etiket '.$tag['name'];

                continue;
            }
            $this->ok($this->write('post', $ws.'/tags', $tag), 'GTM etiket');
            $log['created'][] = 'GTM etiket '.$tag['name'];
            $changed = true;
        }

        return $changed;
    }

    /** @return array<string, array<string, mixed>> */
    private function triggerDefinitions(): array
    {
        $click = fn (string $name, array $filter): array => [
            'name' => $name, 'type' => 'linkClick', 'filter' => [$filter],
            'waitForTags' => ['type' => 'boolean', 'value' => 'false'], 'checkValidation' => ['type' => 'boolean', 'value' => 'false'],
            'autoEventFilter' => [['type' => 'matchRegex', 'parameter' => [$this->tpl('arg0', '{{Page URL}}'), $this->tpl('arg1', '.*')]]],
        ];

        return [
            'phone_click' => $click(self::PREFIX.'Telefon tıklaması', ['type' => 'startsWith', 'parameter' => [$this->tpl('arg0', '{{Click URL}}'), $this->tpl('arg1', 'tel:')]]),
            'whatsapp_click' => $click(self::PREFIX.'WhatsApp tıklaması', ['type' => 'matchRegex', 'parameter' => [
                $this->tpl('arg0', '{{Click URL}}'), $this->tpl('arg1', '(wa\.me/|api\.whatsapp\.com/|web\.whatsapp\.com/|^whatsapp:)'), ['type' => 'boolean', 'key' => 'ignore_case', 'value' => 'true'],
            ]]),
            'generate_lead' => [
                'name' => self::PREFIX.'Form gönderimi', 'type' => 'customEvent',
                'customEventFilter' => [['type' => 'equals', 'parameter' => [$this->tpl('arg0', '{{_event}}'), $this->tpl('arg1', 'moxdop_form_gonderildi')]]],
            ],
        ];
    }

    /** One dataLayer event for a successful form, whatever form plugin the site uses. */
    private function formListener(): string
    {
        return <<<'HTML'
<script>
(function () {
  if (window.__moxdopForms) return; window.__moxdopForms = true;
  var sent = function (kind) { window.dataLayer = window.dataLayer || []; window.dataLayer.push({event: 'moxdop_form_gonderildi', form_type: kind}); };
  document.addEventListener('wpcf7mailsent', function () { sent('cf7'); });
  document.addEventListener('wpformsAjaxSubmitSuccess', function () { sent('wpforms'); });
  document.addEventListener('fluentform_submission_success', function () { sent('fluentform'); });
  if (window.jQuery) {
    jQuery(document).on('submit_success', function () { sent('elementor'); });
    jQuery(document).on('wpformsAjaxSubmitSuccess', function () { sent('wpforms'); });
    jQuery(document).on('fluentform_submission_success', function () { sent('fluentform'); });
    jQuery(document).on('gform_confirmation_loaded', function () { sent('gravity'); });
  }
})();
</script>
HTML;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $log
     * @return array<string, string>
     */
    private function container(array $options, string $host, string $url, array &$log): array
    {
        $account = preg_replace('/\D+/', '', (string) ($options['gtm_account'] ?? '')) ?: null;
        $containerId = preg_replace('/\D+/', '', (string) ($options['container_id'] ?? '')) ?: null;
        $onSite = $this->siteTags($url)['gtm'] ?? [];
        $accounts = $account !== null ? [['accountId' => $account]] : $this->list(self::GTM.'accounts', 'account');
        foreach ($accounts as $a) {
            foreach ($this->list(self::GTM.'accounts/'.$a['accountId'].'/containers', 'container') as $c) {
                $match = $containerId !== null ? $c['containerId'] === $containerId
                    : (in_array($c['publicId'] ?? '', $onSite, true) || ($c['name'] ?? '') === $host || in_array($host, (array) ($c['domainName'] ?? []), true));
                if ($match) {
                    $log['skipped'][] = 'GTM kapsayıcı '.$c['publicId'].' (var olan kullanıldı)';

                    return ['accountId' => (string) $c['accountId'], 'containerId' => (string) $c['containerId'], 'publicId' => (string) $c['publicId']];
                }
            }
        }
        if ($containerId !== null) {
            throw new RuntimeException('GTM kapsayıcısı '.$containerId.' bulunamadı.');
        }
        if ($onSite !== []) {
            throw new RuntimeException('Sitede bu bağlantının görmediği bir GTM kapsayıcısı var ('.implode(', ', $onSite).'). O hesaba erişim gerekir ya da container_id ver.');
        }
        if ($account === null) {
            throw new RuntimeException('Yeni kapsayıcı için gtm_account ver (action=accounts).');
        }
        $created = $this->ok($this->write('post', self::GTM.'accounts/'.$account.'/containers', ['name' => $host, 'usageContext' => ['web'], 'domainName' => [$host]]), 'GTM kapsayıcı');
        $log['created'][] = 'GTM kapsayıcı '.$created['publicId'];

        return ['accountId' => (string) $created['accountId'], 'containerId' => (string) $created['containerId'], 'publicId' => (string) $created['publicId']];
    }

    /**
     * GTM, GA4 and Ads ids found in the site's home page HTML.
     *
     * @return array{gtm: list<string>, ga4: list<string>, ads: list<string>, error?: string}
     */
    public function siteTags(string $url): array
    {
        try {
            $html = (string) Http::timeout(20)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MoxDOP olcum kontrolu)'])->get($url)->body();
        } catch (Throwable $e) {
            return ['gtm' => [], 'ga4' => [], 'ads' => [], 'error' => 'Site açılmadı: '.$e->getMessage()];
        }
        $find = fn (string $pattern): array => preg_match_all($pattern, $html, $m) ? array_values(array_unique($m[0])) : [];

        return ['gtm' => $find('/GTM-[A-Z0-9]{4,10}/'), 'ga4' => $find('/\bG-[A-Z0-9]{6,12}\b/'), 'ads' => $find('/\bAW-\d{6,12}\b/')];
    }

    /**
     * The brand's bound GA4 property, Search Console site and Google Ads account (null each when none).
     *
     * @return array{ga4: ?string, search_console: ?string, google_ads: ?array{customer_id: string, login_customer_id: string}}
     */
    public function bindings(Brand $brand): array
    {
        $rows = DB::table('core_asset_bindings as b')
            ->join('digital_assets as a', 'a.id', '=', 'b.digital_asset_id')
            ->join('core_external_resources as r', 'r.id', '=', 'b.external_resource_id')
            ->where('a.brand_id', $brand->id)->where('b.status', 'active')
            ->whereIn('b.capability', ['ga4', 'search_console', 'google_ads'])
            ->orderBy('b.id')->get(['b.capability', 'r.external_id', 'r.metadata']);
        $out = ['ga4' => null, 'search_console' => null, 'google_ads' => null];
        foreach ($rows as $row) {
            if ($out[$row->capability] !== null) {
                continue;
            }
            if ($row->capability === 'google_ads') {
                $meta = is_string($row->metadata) ? (array) json_decode($row->metadata, true) : (array) $row->metadata;
                $customer = preg_replace('/\D+/', '', (string) $row->external_id) ?: '';
                $login = preg_replace('/\D+/', '', (string) ($meta['login_customer_id'] ?? $meta['manager_customer_id'] ?? $customer)) ?: $customer;
                $out['google_ads'] = $customer !== '' ? ['customer_id' => $customer, 'login_customer_id' => $login] : null;
            } else {
                $out[$row->capability] = (string) $row->external_id;
            }
        }

        return $out;
    }

    public function website(Brand $brand): ?DigitalAsset
    {
        return DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')
            ->where(fn ($q) => $q->whereNotNull('primary_url')->orWhereNotNull('domain'))->orderBy('id')->first();
    }

    public function siteUrl(DigitalAsset $site): string
    {
        $base = trim((string) ($site->primary_url ?: $site->domain));
        if (! preg_match('~^https?://~i', $base)) {
            $base = 'https://'.$base;
        }
        $host = (string) parse_url($base, PHP_URL_HOST);

        return 'https://'.$host.'/';
    }

    private function sameHost(string $uri, string $host): bool
    {
        $other = strtolower((string) parse_url(preg_match('~^https?://~i', $uri) ? $uri : 'https://'.$uri, PHP_URL_HOST));
        $strip = fn (string $h): string => preg_replace('/^www\./', '', strtolower($h)) ?? $h;

        return $other !== '' && $strip($other) === $strip($host);
    }

    private function propertyId(string $property): string
    {
        return preg_replace('/\D+/', '', $property) ?? '';
    }

    /** @return array{type: string, key: string, value: string} */
    private function tpl(string $key, string $value): array
    {
        return ['type' => 'template', 'key' => $key, 'value' => $value];
    }

    private function integration(): CoreIntegration
    {
        return $this->measurement->integration() ?? throw new RuntimeException('Google bağlı değil.');
    }

    /** @param array<string, mixed> $body */
    private function write(string $method, string $url, array $body = []): Response
    {
        return $this->google->writeMeasurement($this->integration(), $method, $url, $body);
    }

    /**
     * Every page of a Google list call.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function list(string $url, string $key, array $query = []): array
    {
        $items = [];
        $token = null;
        for ($page = 0; $page < 20; $page++) {
            $response = $this->google->get($this->integration(), $url, $query + ($token !== null ? ['pageToken' => $token] : []));
            $json = $this->ok($response, 'Google okuma');
            foreach ((array) ($json[$key] ?? []) as $item) {
                $items[] = (array) $item;
            }
            $token = $json['nextPageToken'] ?? null;
            if (! is_string($token) || $token === '') {
                break;
            }
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function ok(Response $response, string $what): array
    {
        if (! $response->successful()) {
            throw new RuntimeException($what.': '.$this->error($response));
        }

        return (array) $response->json();
    }

    private function error(Response $response): string
    {
        $json = (array) $response->json();
        $message = (string) (data_get($json, 'error.details.0.errors.0.message') ?? data_get($json, 'error.message') ?? ('HTTP '.$response->status()));
        // A Google API not switched on in the Cloud project answers SERVICE_DISABLED with the page that switches it on.
        $activation = collect((array) data_get($json, 'error.details', []))->pluck('metadata.activationUrl')->filter()->first();

        return mb_substr($message, 0, 300).(is_string($activation) ? ' · API açma sayfası: '.$activation : '');
    }
}
