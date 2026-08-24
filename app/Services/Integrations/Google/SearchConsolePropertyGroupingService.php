<?php

namespace App\Services\Integrations\Google;

use Illuminate\Support\Collection;

/**
 * Turns Google's raw Search Console property list into human site groups.
 *
 * A Domain Property is the preferred primary source because it covers protocol,
 * www/non-www and subdomain variants. URL-prefix properties remain visible as
 * secondary/diagnostic sources but are not independently selectable by default.
 */
final class SearchConsolePropertyGroupingService
{
    /**
     * @param list<array<string, mixed>> $resources
     * @return list<array<string, mixed>>
     */
    public function group(array $resources): array
    {
        if ($resources === []) {
            return [];
        }

        $annotated = collect($resources)
            ->map(fn (array $resource): array => $this->annotate($resource))
            ->values();

        $domainProperties = $annotated
            ->filter(fn (array $resource): bool => ($resource['_gsc_type'] ?? null) === 'domain')
            ->pluck('_gsc_domain')
            ->filter(fn ($domain): bool => is_string($domain) && $domain !== '')
            ->unique()
            ->sortByDesc(fn (string $domain): int => strlen($domain))
            ->values();

        $grouped = $annotated->groupBy(function (array $resource) use ($domainProperties): string {
            $domain = (string) ($resource['_gsc_domain'] ?? '');
            $host = (string) ($resource['_gsc_host'] ?? '');

            if (($resource['_gsc_type'] ?? null) === 'domain' && $domain !== '') {
                return $domain;
            }

            foreach ($domainProperties as $domainProperty) {
                if ($host === $domainProperty || str_ends_with($host, '.'.$domainProperty)) {
                    return $domainProperty;
                }
            }

            return $domain !== '' ? $domain : 'resource-'.(string) ($resource['id'] ?? uniqid());
        });

        return $grouped
            ->map(fn (Collection $members, string $groupKey): array => $this->collapseGroup($groupKey, $members))
            ->sortBy(fn (array $group): string => mb_strtolower((string) ($group['name'] ?? '')))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $resource */
    private function annotate(array $resource): array
    {
        $externalId = trim((string) ($resource['external_id'] ?? ''));
        $lower = mb_strtolower($externalId);

        if (str_starts_with($lower, 'sc-domain:')) {
            $domain = $this->normalizeHost(substr($lower, strlen('sc-domain:')), false);

            return $resource + [
                '_gsc_type' => 'domain',
                '_gsc_domain' => $domain,
                '_gsc_host' => $domain,
                '_gsc_scheme' => null,
                '_gsc_path' => '/',
                '_gsc_www' => false,
            ];
        }

        $host = parse_url($externalId, PHP_URL_HOST);
        $scheme = parse_url($externalId, PHP_URL_SCHEME);
        $path = parse_url($externalId, PHP_URL_PATH);
        $host = is_string($host) ? $this->normalizeHost($host, false) : '';
        $withoutWww = $this->normalizeHost($host, true);

        return $resource + [
            '_gsc_type' => 'url_prefix',
            '_gsc_domain' => $withoutWww,
            '_gsc_host' => $host,
            '_gsc_scheme' => is_string($scheme) ? mb_strtolower($scheme) : null,
            '_gsc_path' => is_string($path) && $path !== '' ? $path : '/',
            '_gsc_www' => str_starts_with($host, 'www.'),
        ];
    }

    private function normalizeHost(string $host, bool $stripWww): string
    {
        $host = mb_strtolower(trim($host));
        $host = rtrim($host, '.');

        if ($stripWww && str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * @param Collection<int, array<string, mixed>> $members
     * @return array<string, mixed>
     */
    private function collapseGroup(string $groupKey, Collection $members): array
    {
        $sorted = $members
            ->sortBy(fn (array $member): string => sprintf(
                '%02d|%s',
                $this->primaryRank($member),
                mb_strtolower((string) ($member['external_id'] ?? '')),
            ))
            ->values();

        /** @var array<string, mixed> $primary */
        $primary = $sorted->first();
        $memberCount = $sorted->count();
        $secondaryCount = max(0, $memberCount - 1);
        $primaryExternalId = (string) ($primary['external_id'] ?? '');
        $hasDomainProperty = ($primary['_gsc_type'] ?? null) === 'domain';

        $boundMember = $sorted->first(fn (array $member): bool => ($member['state'] ?? null) === 'bound');
        if (is_array($boundMember)) {
            if (empty($primary['asset_name']) && ! empty($boundMember['asset_name'])) {
                $primary['asset_name'] = $boundMember['asset_name'];
                $primary['asset_url'] = $boundMember['asset_url'] ?? null;
            }
            if (($primary['state'] ?? null) !== 'bound') {
                $primary['state'] = 'bound';
                $primary['state_label'] = 'Bound';
            }
        }

        $displayDomain = $groupKey !== '' ? $groupKey : $this->normalizeHost((string) ($primary['_gsc_host'] ?? ''), true);
        $primary['name'] = $displayDomain !== '' ? $displayDomain : (string) ($primary['name'] ?? $primaryExternalId);
        $primary['provider_external_id'] = $primaryExternalId;
        $primary['property_id'] = $primaryExternalId;
        $primary['is_property_group'] = $memberCount > 1;
        $primary['group_member_count'] = $memberCount;
        $primary['secondary_count'] = $secondaryCount;
        $primary['primary_source_type'] = $hasDomainProperty ? 'domain' : 'url_prefix';
        $primary['primary_source_label'] = $hasDomainProperty ? 'Domain Property' : 'URL-prefix Property';
        $primary['primary_source_reason'] = $hasDomainProperty
            ? 'Tüm protokol, www/non-www ve alt alan adı varyasyonlarını kapsayan önerilen ana kaynak.'
            : 'Domain Property bulunmadığı için en uygun URL-prefix ana kaynak seçildi.';
        $primary['group_members'] = $sorted->map(fn (array $member): array => [
            'id' => (string) ($member['id'] ?? ''),
            'external_id' => (string) ($member['external_id'] ?? ''),
            'type' => (string) ($member['_gsc_type'] ?? 'url_prefix'),
            'is_primary' => (string) ($member['id'] ?? '') === (string) ($primary['id'] ?? ''),
        ])->all();

        $primary['external_id'] = $memberCount > 1
            ? sprintf(
                'Ana kaynak · %s%s',
                $primaryExternalId,
                $secondaryCount > 0 ? ' · '.$secondaryCount.' örtüşen alt mülk' : '',
            )
            : $primaryExternalId;

        foreach (array_keys($primary) as $key) {
            if (str_starts_with((string) $key, '_gsc_')) {
                unset($primary[$key]);
            }
        }

        return $primary;
    }

    /** @param array<string, mixed> $resource */
    private function primaryRank(array $resource): int
    {
        if (($resource['_gsc_type'] ?? null) === 'domain') {
            return 0;
        }

        $scheme = (string) ($resource['_gsc_scheme'] ?? '');
        $path = (string) ($resource['_gsc_path'] ?? '/');
        $www = (bool) ($resource['_gsc_www'] ?? false);
        $root = $path === '' || $path === '/';

        if ($scheme === 'https' && ! $www && $root) {
            return 10;
        }
        if ($scheme === 'https' && $www && $root) {
            return 20;
        }
        if ($scheme === 'http' && ! $www && $root) {
            return 30;
        }
        if ($scheme === 'http' && $www && $root) {
            return 40;
        }

        return 50 + min(40, strlen($path));
    }
}
