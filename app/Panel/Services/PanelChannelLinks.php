<?php

namespace App\Panel\Services;

use App\Panel\Models\PanelChannel;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppGraph;
use App\Services\WhatsApp\WhatsAppGraphException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Bağla" on the brand's own panel: the brand's manager connects the brand's WhatsApp number (Embedded Signup, the
 * phone's WhatsApp Business app keeps working) or its Facebook page, Instagram account and Meta lead forms (Facebook
 * login) without asking the agency. Meta's answer is checked here, the app is subscribed to the account, and the
 * channel is bound with PanelChannels::connect like the agency's own connect.
 */
final class PanelChannelLinks
{
    /** What each Facebook-login channel asks for; only these, so a permission the app lacks for another channel never breaks this one. */
    public const array SCOPES = [
        'instagram' => ['pages_show_list', 'pages_manage_metadata', 'business_management', 'instagram_basic', 'instagram_manage_messages'],
        'facebook' => ['pages_show_list', 'pages_manage_metadata', 'business_management', 'pages_messaging'],
        'meta_form' => ['pages_show_list', 'pages_manage_metadata', 'business_management', 'pages_read_engagement', 'pages_manage_ads', 'leads_retrieval'],
    ];

    /** The page events each channel listens to. */
    private const array FIELDS = ['instagram' => 'messages', 'facebook' => 'messages', 'meta_form' => 'leadgen'];

    /** A choice (several numbers or pages) waits this long for the manager's pick. */
    private const int CHOICE_MINUTES = 10;

    public function __construct(
        private readonly WhatsAppGraph $graph,
        private readonly WhatsAppConnection $connection,
        private readonly PanelChannels $channels,
    ) {}

    /**
     * What the panel page needs to open Meta's window; null when the agency's Meta app is not set up.
     *
     * @return array{appId: string, whatsappConfig: string, version: string}|null
     */
    public function settings(): ?array
    {
        $integration = $this->connection->integration();
        $appId = (string) data_get($integration?->config, 'app_id', '');
        if ($appId === '') {
            return null;
        }

        return ['appId' => $appId, 'whatsappConfig' => (string) data_get($integration->config, 'signup_config_id', ''), 'version' => (string) config('whatsapp.graph_version', 'v23.0')];
    }

    /**
     * The code Meta's WhatsApp window gave. Returns the bound channel, or the numbers to choose from.
     *
     * @return array{channel: PanelChannel}|array{choose: list<array{id: string, label: string}>}
     */
    public function whatsapp(int $brandId, int $panelUserId, string $code, ?string $wabaId, ?string $phoneId): array
    {
        [$appId, $secret] = $this->app();
        $token = $this->exchange($appId, $secret, $code);
        $debug = $this->debug($token, $appId, $secret);
        foreach (['whatsapp_business_management', 'whatsapp_business_messaging'] as $scope) {
            if (! in_array($scope, (array) data_get($debug, 'data.scopes', []), true)) {
                throw $this->fail('Meta, WhatsApp izinlerini vermedi. Penceredeki bütün adımları onaylayıp yeniden deneyin.');
            }
        }
        $wabas = $this->valid($wabaId) ? [$wabaId] : $this->sharedAccounts($debug);
        $phones = [];
        foreach (array_slice($wabas, 0, 20) as $waba) {
            $list = $this->call('GET', $waba.'/phone_numbers', ['fields' => 'id,display_phone_number,verified_name', 'limit' => 100], $token, $secret);
            foreach ((array) ($list['data'] ?? []) as $phone) {
                if (is_array($phone) && $this->valid((string) ($phone['id'] ?? ''))) {
                    $phones[] = ['id' => (string) $phone['id'], 'waba' => $waba, 'number' => (string) ($phone['display_phone_number'] ?? ''), 'name' => (string) ($phone['verified_name'] ?? '')];
                }
            }
        }
        if ($phones === []) {
            throw $this->fail('Meta penceresinde bir numara seçilmedi. Yeniden "Bağla"ya basıp numaranızı seçin ve son ekranda "Bitti" deyin.');
        }
        if (! $this->valid($phoneId) && count($phones) > 1) {
            $this->remember($panelUserId, 'whatsapp', ['token' => $token, 'phones' => $phones]);

            return ['choose' => array_map(fn (array $phone): array => ['id' => $phone['id'], 'label' => trim($phone['number'].' · '.$phone['name'], ' ·')], $phones)];
        }

        return ['channel' => $this->bindWhatsApp($brandId, $panelUserId, $token, $secret, collect($phones)->firstWhere('id', $this->valid($phoneId) ? $phoneId : $phones[0]['id']))];
    }

    /** The number the manager picked when Meta shared several. */
    public function chooseWhatsApp(int $brandId, int $panelUserId, string $phoneId): PanelChannel
    {
        $pending = $this->recall($panelUserId, 'whatsapp');
        $phone = collect($pending['phones'] ?? [])->firstWhere('id', $phoneId);
        if ($phone === null) {
            throw $this->fail('Seçim süresi doldu. Yeniden "Bağla"ya basın.');
        }
        [, $secret] = $this->app();

        return $this->bindWhatsApp($brandId, $panelUserId, (string) $pending['token'], $secret, $phone);
    }

    /**
     * The token Meta's Facebook window gave for Instagram, Facebook or Meta formu. Returns the bound channel, or the
     * pages (accounts) to choose from.
     *
     * @return array{channel: PanelChannel}|array{choose: list<array{id: string, label: string}>}
     */
    public function meta(int $brandId, int $panelUserId, string $channel, string $userToken): array
    {
        if (! isset(self::SCOPES[$channel])) {
            throw $this->fail('Bilinmeyen kanal.');
        }
        [$appId, $secret] = $this->app();
        $this->debug($userToken, $appId, $secret);
        $userToken = $this->longLived($appId, $secret, $userToken);
        $granted = [];
        foreach ((array) ($this->call('GET', 'me/permissions', [], $userToken, $secret)['data'] ?? []) as $row) {
            if (is_array($row) && ($row['status'] ?? '') === 'granted') {
                $granted[] = (string) ($row['permission'] ?? '');
            }
        }
        $missing = array_values(array_diff(self::SCOPES[$channel], $granted));
        if ($missing !== []) {
            throw $this->fail('Meta şu izinleri vermedi: '.implode(', ', $missing).'. Yeniden "Bağla"ya basın ve penceredeki izinlerin hepsini açık bırakın.');
        }
        $pages = $this->call('GET', 'me/accounts', ['fields' => 'id,name,access_token,instagram_business_account{id,username,name}', 'limit' => 100], $userToken, $secret);
        $options = [];
        foreach ((array) ($pages['data'] ?? []) as $page) {
            if (! is_array($page) || ! $this->valid((string) ($page['id'] ?? '')) || ! is_string($page['access_token'] ?? null)) {
                continue;
            }
            $ig = is_array($page['instagram_business_account'] ?? null) && $this->valid((string) ($page['instagram_business_account']['id'] ?? '')) ? $page['instagram_business_account'] : null;
            if ($channel === 'instagram' && $ig === null) {
                continue;
            }
            $options[] = [
                'id' => (string) $page['id'], 'token' => $page['access_token'], 'name' => (string) ($page['name'] ?? ''),
                'ig' => $ig ? ['id' => (string) $ig['id'], 'username' => (string) ($ig['username'] ?? '')] : null,
            ];
        }
        if ($options === []) {
            throw $this->fail($channel === 'instagram'
                ? 'Seçtiğiniz sayfalarda bağlı bir Instagram işletme hesabı yok. Instagram hesabınızın Facebook sayfanıza bağlı olduğundan emin olup yeniden deneyin.'
                : 'Meta penceresinde bir sayfa seçilmedi. Yeniden "Bağla"ya basıp sayfanızı seçin.');
        }
        if (count($options) > 1) {
            $this->remember($panelUserId, $channel, ['pages' => $options]);

            return ['choose' => array_map(fn (array $page): array => ['id' => $page['id'], 'label' => $channel === 'instagram' ? '@'.$page['ig']['username'].' · '.$page['name'] : $page['name']], $options)];
        }

        return ['channel' => $this->bindPage($brandId, $panelUserId, $channel, $options[0], $secret)];
    }

    /** The page (or Instagram account) the manager picked when the login shared several. */
    public function choosePage(int $brandId, int $panelUserId, string $channel, string $pageId): PanelChannel
    {
        $page = collect($this->recall($panelUserId, $channel)['pages'] ?? [])->firstWhere('id', $pageId);
        if ($page === null) {
            throw $this->fail('Seçim süresi doldu. Yeniden "Bağla"ya basın.');
        }
        [, $secret] = $this->app();

        return $this->bindPage($brandId, $panelUserId, $channel, $page, $secret);
    }

    /** @param array{id: string, waba: string, number: string, name: string} $phone */
    private function bindWhatsApp(int $brandId, int $panelUserId, string $token, string $secret, array $phone): PanelChannel
    {
        $agency = (string) data_get($this->connection->integration()?->config, 'phone_number_id', '');
        if ($agency !== '' && $agency === $phone['id']) {
            throw $this->fail('Bu numara Moximu\'nun kendi numarası; başka bir numara seçin.');
        }
        $result = $this->call('POST', $phone['waba'].'/subscribed_apps', [], $token, $secret);
        if (($result['success'] ?? false) !== true) {
            throw $this->fail('Meta, numaranın mesajlarını panele açmayı onaylamadı. Biraz sonra yeniden deneyin.');
        }
        $label = trim($phone['number'].($phone['name'] !== '' ? ' · '.$phone['name'] : ''));

        return $this->bind($brandId, $panelUserId, 'whatsapp', $phone['id'], $phone['waba'], $label, $token);
    }

    /** @param array{id: string, token: string, name: string, ig: ?array{id: string, username: string}} $page */
    private function bindPage(int $brandId, int $panelUserId, string $channel, array $page, string $secret): PanelChannel
    {
        $result = $this->call('POST', $page['id'].'/subscribed_apps', ['subscribed_fields' => self::FIELDS[$channel]], $page['token'], $secret);
        if (($result['success'] ?? false) !== true) {
            throw $this->fail('Meta, sayfanın '.($channel === 'meta_form' ? 'formlarını' : 'mesajlarını').' panele açmayı onaylamadı. Biraz sonra yeniden deneyin.');
        }
        if ($channel === 'instagram') {
            return $this->bind($brandId, $panelUserId, 'instagram', $page['ig']['id'], $page['id'], '@'.$page['ig']['username'], $page['token']);
        }

        return $this->bind($brandId, $panelUserId, $channel, $page['id'], null, $page['name'], $page['token']);
    }

    private function bind(int $brandId, int $panelUserId, string $channel, string $externalId, ?string $accountId, string $label, string $token): PanelChannel
    {
        try {
            $row = $this->channels->connect($brandId, $channel, $externalId, $accountId, mb_substr($label, 0, 120), $token, null);
        } catch (ValidationException $exception) {
            throw str_contains((string) collect($exception->errors())->flatten()->first(), 'başka bir markaya')
                ? $this->fail('Bu hesap başka bir markanın paneline bağlı. Doğru hesabı seçtiğinizden emin olun; sorun sürerse Destek\'ten yazın.')
                : $exception;
        }
        $row->forceFill(['meta' => array_merge((array) $row->meta, ['via' => 'panel', 'panel_user_id' => $panelUserId])])->save();
        Cache::forget($this->key($panelUserId, $channel));

        return $row;
    }

    /** @return array{0: string, 1: string} app id, app secret */
    private function app(): array
    {
        $integration = $this->connection->integration();
        $appId = (string) data_get($integration?->config, 'app_id', '');
        $secret = $integration ? (string) ($this->connection->secrets($integration)['app_secret'] ?? '') : '';
        if ($appId === '' || $secret === '') {
            throw $this->fail('Bağlantı şu an kurulamıyor. Moximu\'ya haber verildi; Destek\'ten de yazabilirsiniz.');
        }

        return [$appId, $secret];
    }

    /** A code from the JavaScript window is bound to an empty redirect address; Meta takes it with or without the field. */
    private function exchange(string $appId, string $secret, string $code): string
    {
        $last = null;
        foreach ([null, ''] as $redirect) {
            try {
                $base = ['client_id' => $appId, 'client_secret' => $secret, 'code' => $code];
                $body = $this->graph->request('GET', 'oauth/access_token', $redirect === null ? $base : $base + ['redirect_uri' => $redirect]);
                if (is_string($body['access_token'] ?? null) && $body['access_token'] !== '') {
                    return $body['access_token'];
                }
            } catch (WhatsAppGraphException $exception) {
                $last = $exception;
                if ((int) ($exception->details['subcode'] ?? 0) !== 36008) {
                    break;
                }
            }
        }
        if ($last !== null) {
            report($last);
        }

        throw $this->fail('Meta onayı alınamadı (süresi dolmuş olabilir). Yeniden "Bağla"ya basın.');
    }

    private function longLived(string $appId, string $secret, string $token): string
    {
        try {
            $body = $this->graph->request('GET', 'oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $appId, 'client_secret' => $secret, 'fb_exchange_token' => $token]);

            return is_string($body['access_token'] ?? null) && $body['access_token'] !== '' ? $body['access_token'] : $token;
        } catch (Throwable) {
            return $token;
        }
    }

    /** @return array<string, mixed> */
    private function debug(string $token, string $appId, string $secret): array
    {
        $debug = $this->call('GET', 'debug_token', ['input_token' => $token], $appId.'|'.$secret, $secret);
        if (data_get($debug, 'data.is_valid') !== true || (string) data_get($debug, 'data.app_id') !== $appId) {
            throw $this->fail('Meta\'dan gelen onay geçerli değil. Yeniden "Bağla"ya basın.');
        }

        return $debug;
    }

    /**
     * The WhatsApp accounts the token was given for, when the window did not say which one was chosen.
     *
     * @return list<string>
     */
    private function sharedAccounts(array $debug): array
    {
        $ids = [];
        foreach ((array) data_get($debug, 'data.granular_scopes', []) as $scope) {
            if (is_array($scope) && in_array($scope['scope'] ?? '', ['whatsapp_business_management', 'whatsapp_business_messaging'], true)) {
                foreach ((array) ($scope['target_ids'] ?? []) as $id) {
                    if ($this->valid((string) $id)) {
                        $ids[] = (string) $id;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path, array $parameters, string $token, string $secret): array
    {
        try {
            return $this->graph->request($method, $path, $parameters, $token, $secret);
        } catch (WhatsAppGraphException $exception) {
            report($exception);
            $detail = trim((string) ($exception->details['message'] ?? ''));

            throw $this->fail('Meta isteği reddetti'.($detail !== '' ? ': '.mb_substr($detail, 0, 200) : '').'. Yeniden deneyin; sürerse Destek\'ten yazın.');
        } catch (Throwable $exception) {
            report($exception);

            throw $this->fail('Meta\'ya ulaşılamadı. Biraz sonra yeniden deneyin.');
        }
    }

    private function valid(?string $id): bool
    {
        return is_string($id) && preg_match('/^[0-9]{5,40}$/', $id) === 1;
    }

    private function key(int $panelUserId, string $channel): string
    {
        return 'panel:channel-choice:'.$panelUserId.':'.$channel;
    }

    private function remember(int $panelUserId, string $channel, array $data): void
    {
        Cache::put($this->key($panelUserId, $channel), Crypt::encrypt($data), now()->addMinutes(self::CHOICE_MINUTES));
    }

    /** @return array<string, mixed> */
    private function recall(int $panelUserId, string $channel): array
    {
        try {
            $value = Cache::get($this->key($panelUserId, $channel));

            return is_string($value) ? (array) Crypt::decrypt($value) : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['channel' => $message]);
    }
}
