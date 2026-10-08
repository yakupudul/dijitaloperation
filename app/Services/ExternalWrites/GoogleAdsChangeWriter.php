<?php

namespace App\Services\ExternalWrites;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\ExternalWriteAction;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\Integrations\Google\GoogleApiClient;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * ADR-081: one Admin-approved Google Ads setting change — a campaign's Search Partners / Display network or location
 * option, a campaign budget, a keyword's status or the account's auto-tagging. The live value is read first and the
 * write stops when it is no longer the value the change was prepared from; Google checks the request (validateOnly)
 * before it is made. Undo writes the previous value back when the field still holds what MoxDOP wrote.
 */
final class GoogleAdsChangeWriter
{
    /** change field => [service, update mask, GAQL resource, GAQL field] */
    public const array FIELDS = [
        'search_partners' => ['campaigns', 'network_settings.target_search_network', 'campaign', 'campaign.network_settings.target_search_network'],
        'display_network' => ['campaigns', 'network_settings.target_content_network', 'campaign', 'campaign.network_settings.target_content_network'],
        'location_option' => ['campaigns', 'geo_target_type_setting.positive_geo_target_type', 'campaign', 'campaign.geo_target_type_setting.positive_geo_target_type'],
        'budget' => ['campaignBudgets', 'amount_micros', 'campaign_budget', 'campaign_budget.amount_micros'],
        'keyword_status' => ['adGroupCriteria', 'status', 'ad_group_criterion', 'ad_group_criterion.status'],
        'auto_tagging' => ['customers', 'auto_tagging_enabled', 'customer', 'customer.auto_tagging_enabled'],
    ];

    public function __construct(
        private readonly GoogleApiClient $google,
        private readonly GoogleAdsSpecialistBindingResolver $bindings,
    ) {}

    /** @return array<string, mixed> */
    public function apply(ExternalWriteAction $action): array
    {
        [$integration, $customerId, $login] = $this->account((int) $action->digital_asset_id);
        $payload = (array) $action->request_payload;
        $field = (string) ($payload['field'] ?? '');
        $resource = (string) ($payload['resource'] ?? '');
        $live = $this->read($integration, $customerId, $login, $field, $resource);
        if (! self::same($live, $payload['before'] ?? null)) {
            throw new RuntimeException(sprintf('Hesapta değer değişmiş (şimdi %s, hazırlanırken %s); değişiklik yapılmadı.', self::text($live), self::text($payload['before'] ?? null)));
        }
        $this->write($integration, $customerId, $login, $field, $resource, $payload['after'] ?? null);

        return ['status' => 'succeeded', 'field' => $field, 'resource' => $resource, 'before' => $live, 'after' => $payload['after'] ?? null];
    }

    /** @return array<string, mixed> */
    public function undo(ExternalWriteAction $action): array
    {
        [$integration, $customerId, $login] = $this->account((int) $action->digital_asset_id);
        $result = (array) $action->result;
        $field = (string) ($result['field'] ?? '');
        $resource = (string) ($result['resource'] ?? '');
        $live = $this->read($integration, $customerId, $login, $field, $resource);
        if (! self::same($live, $result['after'] ?? null)) {
            return ['restored' => false, 'changed_since' => self::text($live)];
        }
        $this->write($integration, $customerId, $login, $field, $resource, $result['before'] ?? null);

        return ['restored' => true];
    }

    /** The live value of one field (null when the resource is gone). */
    public function read(CoreIntegration $integration, string $customerId, string $login, string $field, string $resource): mixed
    {
        [, , $from, $select] = self::FIELDS[$field] ?? throw new RuntimeException('Bilinmeyen Google Ads değişikliği.');
        if (preg_match('#^customers/'.$customerId.'(/[A-Za-z]+/[\d~]+)?$#', $resource) !== 1) {
            throw new RuntimeException('Değişiklik bu hesaba ait değil.');
        }
        $where = $from === 'customer' ? '' : sprintf(" WHERE %s.resource_name = '%s'", $from, $resource);
        $response = $this->checked($this->google->searchAds($integration, $customerId, sprintf('SELECT %s FROM %s%s', $select, $from, $where), $login));
        $row = ((array) ($response['results'] ?? []))[0] ?? null;
        if ($row === null) {
            return null;
        }
        $value = data_get($row, self::camel($select));

        return $field === 'budget' && $value !== null ? (int) $value : $value;
    }

    private function write(CoreIntegration $integration, string $customerId, string $login, string $field, string $resource, mixed $value): void
    {
        [$service, $mask] = self::FIELDS[$field];
        $update = ['resourceName' => $resource];
        data_set($update, self::camel($mask), $field === 'budget' ? (string) $value : $value);
        $operation = ['update' => $update, 'updateMask' => $mask];
        foreach ([true, false] as $validateOnly) {
            $body = $service === 'customers' ? ['operation' => $operation] : ['operations' => [$operation]];
            $this->checked($this->google->mutateAdsSettings($integration, $customerId, $service, $body + ($validateOnly ? ['validateOnly' => true] : []), $login));
        }
    }

    public static function same(mixed $a, mixed $b): bool
    {
        return is_numeric($a) && is_numeric($b) ? (int) $a === (int) $b : $a === $b;
    }

    public static function text(mixed $value): string
    {
        return match (true) {
            $value === null => 'yok',
            is_bool($value) => $value ? 'açık' : 'kapalı',
            default => (string) $value,
        };
    }

    /** "network_settings.target_search_network" → "networkSettings.targetSearchNetwork" */
    private static function camel(string $path): string
    {
        return implode('.', array_map(fn (string $part): string => lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $part)))), explode('.', $path)));
    }

    /** @return array{0: CoreIntegration, 1: string, 2: string} */
    public function account(int $assetId): array
    {
        $binding = $this->bindings->resolve((string) $assetId);
        if (! $binding->isReal()) {
            throw new RuntimeException('Google Ads hesabı bağlı değil.');
        }
        $resource = CoreExternalResource::query()->with('integration')->findOrFail((int) $binding->externalResourceId);
        if (! $resource->integration instanceof CoreIntegration) {
            throw new RuntimeException('Google bağlantısı bulunamadı.');
        }
        $metadata = is_array($resource->metadata) ? $resource->metadata : [];
        $customerId = preg_replace('/\D+/', '', (string) $binding->customerId) ?: '';
        $login = preg_replace('/\D+/', '', (string) ($metadata['login_customer_id'] ?? $metadata['manager_customer_id'] ?? $customerId)) ?: $customerId;

        return [$resource->integration, $customerId, $login];
    }

    /** @return array<string, mixed> */
    private function checked(Response $response): array
    {
        if (! $response->successful()) {
            $message = (string) (data_get($response->json(), 'error.details.0.errors.0.message') ?? data_get($response->json(), 'error.message') ?? ('HTTP '.$response->status()));
            throw new RuntimeException('Google Ads: '.mb_substr($message, 0, 300));
        }

        return (array) $response->json();
    }
}
