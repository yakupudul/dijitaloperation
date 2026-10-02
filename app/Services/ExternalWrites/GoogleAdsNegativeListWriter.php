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
 * ADR-064 (1): adds approved negative keywords to the account's "MoxDOP negatifleri" shared negative
 * keyword list and attaches that list to enabled Search campaigns. It never touches campaigns, budgets,
 * bids, ads or targeting. Undo removes exactly the criteria this action added.
 */
final class GoogleAdsNegativeListWriter
{
    public function __construct(
        private readonly GoogleApiClient $google,
        private readonly GoogleAdsSpecialistBindingResolver $bindings,
    ) {}

    /**
     * Parse the paste format: [term] = exact, "term" = phrase, bare text = exact. Invalid lines are returned
     * separately so the operator sees what was not sent.
     *
     * @return array{keywords: list<array{text: string, match_type: string}>, rejected: list<string>}
     */
    public static function parse(string $lines): array
    {
        $max = (int) config('moxdop-external-writes.google_ads.max_terms', 200);
        $maxLength = (int) config('moxdop-external-writes.google_ads.max_term_length', 80);
        $keywords = [];
        $rejected = [];
        foreach (preg_split('/\R/u', $lines) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $match = 'EXACT';
            if (preg_match('/^\[(.+)\]$/u', $line, $m) === 1) {
                $text = $m[1];
            } elseif (preg_match('/^"(.+)"$/u', $line, $m) === 1) {
                $text = $m[1];
                $match = 'PHRASE';
            } else {
                $text = $line;
            }
            $text = trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)) ?? '');
            $words = $text === '' ? 0 : count(explode(' ', $text));
            if ($text === '' || mb_strlen($text) > $maxLength || $words > 10 || preg_match('/[!@%^*()=\{\};~`<>?\\\\|,\[\]"]/u', $text) === 1) {
                $rejected[] = $line;

                continue;
            }
            $keywords[$match.'|'.$text] = ['text' => $text, 'match_type' => $match];
        }

        return ['keywords' => array_slice(array_values($keywords), 0, $max), 'rejected' => $rejected];
    }

    /** @return array<string, mixed> result stored on the action (resource names for undo) */
    public function apply(ExternalWriteAction $action): array
    {
        [$integration, $customerId, $login] = $this->account((int) $action->digital_asset_id);
        $keywords = (array) ($action->request_payload['keywords'] ?? []);
        if ($keywords === []) {
            throw new RuntimeException('Gönderilecek geçerli negatif anahtar kelime yok.');
        }
        $name = (string) config('moxdop-external-writes.google_ads.shared_set_name', 'MoxDOP negatifleri');

        $setRows = $this->search($integration, $customerId, $login, sprintf(
            "SELECT shared_set.resource_name, shared_set.name FROM shared_set WHERE shared_set.type = 'NEGATIVE_KEYWORDS' AND shared_set.status = 'ENABLED' AND shared_set.name = '%s'",
            str_replace("'", "\\'", $name),
        ));
        $createdSet = false;
        $setResource = (string) data_get($setRows, '0.sharedSet.resourceName', '');
        if ($setResource === '') {
            $created = $this->mutate($integration, $customerId, $login, 'sharedSets', ['operations' => [['create' => ['name' => $name, 'type' => 'NEGATIVE_KEYWORDS']]]]);
            $setResource = (string) data_get($created, 'results.0.resourceName', '');
            if ($setResource === '') {
                throw new RuntimeException('Google Ads paylaşılan liste oluşturulamadı.');
            }
            $createdSet = true;
        }

        $existing = [];
        foreach ($this->search($integration, $customerId, $login, "SELECT shared_criterion.keyword.text, shared_criterion.keyword.match_type FROM shared_criterion WHERE shared_criterion.shared_set = '{$setResource}'") as $row) {
            $existing[strtoupper((string) data_get($row, 'sharedCriterion.keyword.matchType')).'|'.mb_strtolower((string) data_get($row, 'sharedCriterion.keyword.text'))] = true;
        }
        $toAdd = array_values(array_filter($keywords, static fn (array $k): bool => ! isset($existing[$k['match_type'].'|'.$k['text']])));
        $skipped = count($keywords) - count($toAdd);

        $added = [];
        $failed = [];
        if ($toAdd !== []) {
            $response = $this->mutate($integration, $customerId, $login, 'sharedCriteria', [
                'partialFailure' => true,
                'operations' => array_map(static fn (array $k): array => ['create' => ['sharedSet' => $setResource, 'keyword' => ['text' => $k['text'], 'matchType' => $k['match_type']]]], $toAdd),
            ]);
            foreach ($toAdd as $index => $keyword) {
                $resource = (string) data_get($response, 'results.'.$index.'.resourceName', '');
                if ($resource !== '') {
                    $added[] = $keyword + ['resource_name' => $resource];
                } else {
                    $failed[] = $keyword;
                }
            }
        }

        $campaigns = array_map(static fn (array $row): string => (string) data_get($row, 'campaign.resourceName'), $this->search($integration, $customerId, $login,
            "SELECT campaign.resource_name FROM campaign WHERE campaign.status = 'ENABLED' AND campaign.advertising_channel_type = 'SEARCH'"));
        $attached = array_map(static fn (array $row): string => (string) data_get($row, 'campaignSharedSet.campaign'), $this->search($integration, $customerId, $login,
            "SELECT campaign_shared_set.campaign FROM campaign_shared_set WHERE campaign_shared_set.shared_set = '{$setResource}' AND campaign_shared_set.status = 'ENABLED'"));
        $toAttach = array_values(array_diff(array_filter($campaigns), $attached));
        $newlyAttached = [];
        if ($toAttach !== []) {
            $response = $this->mutate($integration, $customerId, $login, 'campaignSharedSets', [
                'partialFailure' => true,
                'operations' => array_map(static fn (string $campaign): array => ['create' => ['campaign' => $campaign, 'sharedSet' => $setResource]], $toAttach),
            ]);
            foreach ($toAttach as $index => $campaign) {
                if ((string) data_get($response, 'results.'.$index.'.resourceName', '') !== '') {
                    $newlyAttached[] = $campaign;
                }
            }
        }

        return [
            'shared_set' => $setResource,
            'shared_set_created' => $createdSet,
            'added' => $added,
            'failed' => $failed,
            'skipped_existing' => $skipped,
            'campaigns_attached' => $newlyAttached,
            'campaigns_total' => count(array_filter($campaigns)),
            'status' => $failed === [] ? 'succeeded' : ($added === [] && $skipped === 0 ? 'failed' : 'partial'),
        ];
    }

    /** Remove exactly the criteria this action added. @return array<string, mixed> */
    public function undo(ExternalWriteAction $action): array
    {
        [$integration, $customerId, $login] = $this->account((int) $action->digital_asset_id);
        $resources = array_values(array_filter(array_map(static fn ($k): string => (string) ($k['resource_name'] ?? ''), (array) ($action->result['added'] ?? []))));
        if ($resources === []) {
            return ['removed' => 0];
        }
        $response = $this->mutate($integration, $customerId, $login, 'sharedCriteria', [
            'partialFailure' => true,
            'operations' => array_map(static fn (string $resource): array => ['remove' => $resource], $resources),
        ]);
        $removed = count(array_filter((array) ($response['results'] ?? []), static fn ($row): bool => (string) ($row['resourceName'] ?? '') !== ''));

        return ['removed' => $removed, 'requested' => count($resources)];
    }

    /** @return array{0: CoreIntegration, 1: string, 2: string} */
    private function account(int $assetId): array
    {
        $binding = $this->bindings->resolve((string) $assetId);
        if (! $binding->isReal()) {
            throw new RuntimeException('Google Ads hesabı bağlı değil.');
        }
        $resource = CoreExternalResource::query()->with('integration')->findOrFail((int) $binding->externalResourceId);
        $integration = $resource->integration;
        if (! $integration instanceof CoreIntegration) {
            throw new RuntimeException('Google bağlantısı bulunamadı.');
        }
        $metadata = is_array($resource->metadata) ? $resource->metadata : [];
        $customerId = (string) $binding->customerId;
        $login = preg_replace('/\D+/', '', (string) ($metadata['login_customer_id'] ?? $metadata['manager_customer_id'] ?? $customerId)) ?: $customerId;

        return [$integration, $customerId, $login];
    }

    /** @return list<array<string, mixed>> */
    private function search(CoreIntegration $integration, string $customerId, string $login, string $query): array
    {
        return (array) ($this->checked($this->google->searchAds($integration, $customerId, $query, $login))['results'] ?? []);
    }

    /** @return array<string, mixed> */
    private function mutate(CoreIntegration $integration, string $customerId, string $login, string $service, array $body): array
    {
        return $this->checked($this->google->mutateAds($integration, $customerId, $service, $body, $login));
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
