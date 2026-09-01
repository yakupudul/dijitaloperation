<?php

namespace App\Services\Collection\Website;

use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\User;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use InvalidArgumentException;
use MoxDop\Website\Discovery\PublicUrlNormalizer;

final class WebsiteIssueVerificationService
{
    public const int MAX_URLS = 100;

    public function __construct(
        private readonly WebsiteCollectionOrchestrator $collections,
        private readonly PublicUrlNormalizer $urls = new PublicUrlNormalizer,
    ) {}

    /**
     * @return array{
     *     collection_run_id:int,
     *     planned_url_count:int,
     *     related_url_count:int,
     *     candidate_url_count:int,
     *     truncated:bool,
     *     relation_key:string
     * }
     */
    public function start(
        DigitalAsset $asset,
        int $profileId,
        string $issueCode,
        ?User $requestedBy = null,
    ): array {
        if ((string) $asset->type !== 'website') {
            throw new InvalidArgumentException('Issue verification requires a Website Digital Asset.');
        }

        $issueCode = strtoupper(trim($issueCode));
        if ($issueCode === '' || preg_match('/^[A-Z0-9_]+$/', $issueCode) !== 1) {
            throw new InvalidArgumentException('Issue verification requires a valid issue code.');
        }

        $profiles = WebsitePageProfile::query()
            ->where('website_asset_id', $asset->getKey())
            ->get(['id', 'preferred_url', 'source_states']);
        $selected = $profiles->firstWhere('id', $profileId);
        if (! $selected instanceof WebsitePageProfile) {
            throw new InvalidArgumentException('The selected page is outside this Website.');
        }

        $selectedIssue = $this->issue($selected, $issueCode);
        if ($selectedIssue === null) {
            throw new InvalidArgumentException('The selected observation is no longer current.');
        }

        $selectedUrl = $this->profileUrl($selected);
        if ($selectedUrl === null) {
            throw new InvalidArgumentException('The selected page does not have a public Website URL.');
        }

        $relationBase = $this->relationBase($selectedUrl);
        $candidates = $profiles
            ->filter(fn (WebsitePageProfile $profile): bool => $this->issue($profile, $issueCode) !== null)
            ->map(fn (WebsitePageProfile $profile): ?string => $this->profileUrl($profile))
            ->filter(fn (?string $url): bool => $url !== null && $this->relationBase($url) === $relationBase)
            ->map(fn (string $url): string => $this->urls->normalizeAbsolute($url) ?? $url)
            ->unique()
            ->sort()
            ->values();

        $selectedNormalized = $this->urls->normalizeAbsolute($selectedUrl) ?? $selectedUrl;
        $candidateUrls = collect([$selectedNormalized])
            ->merge($candidates->reject(static fn (string $url): bool => $url === $selectedNormalized))
            ->unique()
            ->values();
        $candidateCount = $candidateUrls->count();
        $plannedUrls = $candidateUrls->take(self::MAX_URLS)->all();
        $observationKey = (string) ($selectedIssue['observed_at'] ?? data_get($selected->source_states, 'website.http.observed_at', 'unknown'));
        $relationKey = hash('sha256', $relationBase.'|'.$issueCode);
        $requestWindow = now()->utc()->format('YmdHi');
        $idempotencyKey = 'website-verify:'.hash('sha256', implode('|', [
            (string) $asset->getKey(),
            (string) $profileId,
            $issueCode,
            $observationKey,
            $relationKey,
            $requestWindow,
        ]));

        $run = $this->collections->start(
            asset: $asset,
            requestedBy: $requestedBy,
            requestFamilyIds: [WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL],
            context: [
                'idempotency_key' => $idempotencyKey,
                'force_refresh' => true,
                'collection_intent' => 'website_issue_verification',
                'collection_intent_label' => 'Website fix verification',
                'targeted_verification' => [
                    'version' => 1,
                    'selected_profile_id' => $profileId,
                    'selected_url' => $selectedNormalized,
                    'issue_code' => $issueCode,
                    'observation_key' => $observationKey,
                    'relation_key' => $relationKey,
                    'relation_base' => $relationBase,
                    'candidate_url_count' => $candidateCount,
                    'truncated' => $candidateCount > self::MAX_URLS,
                    'urls' => $plannedUrls,
                ],
            ],
        );

        return [
            'collection_run_id' => (int) $run->getKey(),
            'planned_url_count' => count($plannedUrls),
            'related_url_count' => max(0, count($plannedUrls) - 1),
            'candidate_url_count' => $candidateCount,
            'truncated' => $candidateCount > self::MAX_URLS,
            'relation_key' => $relationKey,
        ];
    }

    /** @return array<string, mixed>|null */
    private function issue(WebsitePageProfile $profile, string $issueCode): ?array
    {
        $issues = data_get($profile->source_states, 'website.crawl_issues');
        if (! is_array($issues)) {
            return null;
        }

        foreach ($issues as $issue) {
            if (is_array($issue) && strtoupper((string) ($issue['code'] ?? '')) === $issueCode) {
                return $issue;
            }
        }

        return null;
    }

    private function profileUrl(WebsitePageProfile $profile): ?string
    {
        $url = data_get($profile->source_states, 'website.url');
        if (! is_string($url) || trim($url) === '') {
            $url = $profile->preferred_url;
        }

        $normalized = $this->urls->normalizeAbsolute(is_string($url) ? $url : null);

        return $normalized !== null ? $normalized : null;
    }

    private function relationBase(string $url): string
    {
        $normalized = $this->urls->normalizeAbsolute($url) ?? $url;
        $parts = parse_url($normalized);
        if (! is_array($parts) || empty($parts['host'])) {
            return $normalized;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        $path = preg_replace('#/page/\d+/?$#i', '/', $path) ?? $path;
        if ($path === '') {
            $path = '/';
        } elseif ($path !== '/') {
            $path = rtrim($path, '/').'/';
        }

        $query = [];
        if (is_string($parts['query'] ?? null) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
            foreach (array_keys($query) as $key) {
                if ($this->isPaginationParameter((string) $key, $query[$key])) {
                    unset($query[$key]);
                }
            }
            ksort($query);
        }

        $queryString = $query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $scheme.'://'.$host.$port.$path.$queryString;
    }

    private function isPaginationParameter(string $key, mixed $value): bool
    {
        if (! is_scalar($value) || preg_match('/^\d+$/', (string) $value) !== 1) {
            return false;
        }

        return preg_match('/^e-page-/i', $key) === 1
            || preg_match('/^(?:paged|page|page_num|page_no|pageno|product-page|sf_paged)$/i', $key) === 1
            || preg_match('/(?:^|[-_])page(?:d|num|no)?(?:$|[-_])/i', $key) === 1;
    }
}
