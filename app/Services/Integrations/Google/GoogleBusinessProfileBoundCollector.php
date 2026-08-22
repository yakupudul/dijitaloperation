<?php

namespace App\Services\Integrations\Google;

use App\Contracts\Integrations\CollectsBoundProviderData;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Run;
use App\Services\Integrations\BoundCollectionGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Google Business Profile read-only bound collector.
 * Pulls provider-owned data only; MOXDOP-derived / external local SEO data is not fabricated here.
 */
final class GoogleBusinessProfileBoundCollector implements CollectsBoundProviderData
{
    private const string CAPABILITY = 'google_business_profile';

    private const string LOCATION_READ_MASK = 'name,languageCode,storeCode,title,phoneNumbers,categories,storefrontAddress,websiteUri,regularHours,specialHours,serviceArea,labels,adWordsLocationExtensions,latlng,openInfo,metadata,profile,relationshipData,moreHours,serviceItems';

    /** @var list<string> */
    private const DAILY_METRICS = [
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
        'BUSINESS_CONVERSATIONS',
        'BUSINESS_DIRECTION_REQUESTS',
        'CALL_CLICKS',
        'WEBSITE_CLICKS',
        'BUSINESS_BOOKINGS',
        'BUSINESS_FOOD_ORDERS',
        'BUSINESS_FOOD_MENU_CLICKS',
    ];

    public function __construct(
        private readonly BoundCollectionGuard $guard,
        private readonly GoogleApiClient $client,
    ) {}

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function moduleId(): string
    {
        return 'google-business-profile';
    }

    public function collect(CoreAssetBinding $binding): Run
    {
        $scope = $this->guard->assertCollectable($binding, self::CAPABILITY);
        $asset = $scope['asset'];
        $resource = $scope['resource'];
        $integration = $scope['integration'];
        $locationName = $this->locationName((string) $resource->external_id);

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'core_asset_binding_id' => $binding->id,
            'module_id' => $this->moduleId(),
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'metadata' => [
                'provider' => 'google',
                'capability' => self::CAPABILITY,
                'external_resource_id' => $resource->id,
                'location_name' => $locationName,
                'datasets' => [],
            ],
        ]);

        try {
            $location = $this->request(
                $integration,
                'https://mybusinessbusinessinformation.googleapis.com/v1/'.$locationName,
                ['readMask' => self::LOCATION_READ_MASK],
                'gbp_location'
            );

            $googleUpdated = $this->captureOptional(fn (): array => $this->request(
                $integration,
                'https://mybusinessbusinessinformation.googleapis.com/v1/'.$locationName.':getGoogleUpdated',
                ['readMask' => self::LOCATION_READ_MASK],
                'gbp_google_updated'
            ));

            $this->persistLocation($run, $resource, $locationName, $location, $googleUpdated['payload']);
            $datasets = [
                'gbp_location' => [
                    'status' => $googleUpdated['error'] === null ? 'available' : 'partial',
                    'rows' => 1,
                    'google_updated' => $googleUpdated['error'] === null,
                    'limitation' => $googleUpdated['error'],
                ],
            ];

            $datasets['gbp_performance_daily'] = $this->captureDataset(
                'gbp_performance_daily',
                fn (): array => $this->collectPerformance($run, $resource, $integration, $locationName)
            );

            $datasets['gbp_search_keywords_monthly'] = $this->captureDataset(
                'gbp_search_keywords_monthly',
                fn (): array => $this->collectSearchKeywords($run, $resource, $integration, $locationName)
            );

            $accountName = $this->resolveAccountName($resource, $integration, $locationName);

            $reviewResult = $this->captureDataset(
                'gbp_reviews',
                fn (): array => $this->collectReviews($run, $resource, $integration, $locationName, $accountName)
            );
            $datasets['gbp_reviews'] = $reviewResult;
            if (isset($reviewResult['average_rating']) || isset($reviewResult['total_review_count'])) {
                DB::table('gbp_location_snapshots')->where('run_id', $run->id)->update([
                    'average_rating' => $reviewResult['average_rating'] ?? null,
                    'total_review_count' => $reviewResult['total_review_count'] ?? null,
                    'updated_at' => now(),
                ]);
            }

            $datasets['gbp_media'] = $this->captureDataset(
                'gbp_media',
                fn (): array => $this->collectMedia($run, $resource, $integration, $locationName, $accountName)
            );
            $datasets['gbp_posts'] = $this->captureDataset(
                'gbp_posts',
                fn (): array => $this->collectPosts($run, $resource, $integration, $locationName, $accountName)
            );
            $datasets['gbp_attributes'] = $this->captureDataset(
                'gbp_attributes',
                fn (): array => $this->collectAttributes($run, $resource, $integration, $locationName)
            );
            $datasets['gbp_services'] = $this->captureDataset(
                'gbp_services',
                fn (): array => $this->collectServices($run, $resource, $locationName, $location)
            );
            $datasets['gbp_place_actions'] = $this->captureDataset(
                'gbp_place_actions',
                fn (): array => $this->collectPlaceActions($run, $resource, $integration, $locationName)
            );
            $datasets['gbp_verification'] = $this->captureDataset(
                'gbp_verification',
                fn (): array => $this->collectVerification($run, $resource, $integration, $locationName)
            );

            $hasGaps = collect($datasets)->contains(
                fn (array $dataset): bool => ($dataset['status'] ?? 'unavailable') !== 'available'
            );

            $run->forceFill([
                'status' => $hasGaps ? 'partial' : 'completed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'datasets' => $datasets,
                    'account_name' => $accountName,
                    'provider_direct_only' => true,
                    'verification_options_policy' => 'not_background_collected',
                ]),
            ])->save();

            return $run->fresh() ?? $run;
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'failure' => $this->safeMessage($e),
                ]),
            ])->save();

            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function collectPerformance(
        Run $run,
        CoreExternalResource $resource,
        CoreIntegration $integration,
        string $locationName,
    ): array {
        $days = max(1, min(540, (int) config('moxdop-gbp-collector.performance_days', 180)));
        $end = CarbonImmutable::now('UTC')->subDay()->startOfDay();
        $start = $end->subDays($days - 1);
        $rows = 0;
        $errors = [];
        $successfulMetrics = [];

        foreach (self::DAILY_METRICS as $metric) {
            try {
                $payload = $this->request(
                    $integration,
                    'https://businessprofileperformance.googleapis.com/v1/'.$locationName.':getDailyMetricsTimeSeries',
                    [
                        'dailyMetric' => $metric,
                        'dailyRange.start_date.year' => $start->year,
                        'dailyRange.start_date.month' => $start->month,
                        'dailyRange.start_date.day' => $start->day,
                        'dailyRange.end_date.year' => $end->year,
                        'dailyRange.end_date.month' => $end->month,
                        'dailyRange.end_date.day' => $end->day,
                    ],
                    'gbp_performance_daily'
                );

                $datedValues = data_get($payload, 'timeSeries.datedValues', []);
                if (! is_array($datedValues)) {
                    $datedValues = [];
                }

                foreach ($datedValues as $point) {
                    if (! is_array($point)) {
                        continue;
                    }
                    $date = $this->googleDate($point['date'] ?? null);
                    if ($date === null) {
                        continue;
                    }

                    DB::table('gbp_performance_daily')->updateOrInsert(
                        [
                            'external_resource_id' => $resource->id,
                            'reporting_date' => $date,
                            'metric' => $metric,
                        ],
                        [
                            'digital_asset_id' => $run->digital_asset_id,
                            'run_id' => $run->id,
                            'location_name' => $locationName,
                            'value' => (int) ($point['value'] ?? 0),
                            'collected_at' => now(),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                    $rows++;
                }
                $successfulMetrics[] = $metric;
            } catch (Throwable $e) {
                $errors[$metric] = $this->safeMessage($e);
            }
        }

        if ($successfulMetrics === []) {
            throw new RuntimeException('No GBP Performance metric could be collected.');
        }

        return [
            'rows' => $rows,
            'metrics' => $successfulMetrics,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'partial' => $errors !== [],
            'metric_errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    private function collectSearchKeywords(
        Run $run,
        CoreExternalResource $resource,
        CoreIntegration $integration,
        string $locationName,
    ): array {
        $months = max(1, min(36, (int) config('moxdop-gbp-collector.search_keyword_months', 12)));
        $latest = CarbonImmutable::now('UTC')->startOfMonth()->subMonth();
        $rows = 0;
        $errors = [];

        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $month = $latest->subMonths($offset);
            $pageToken = null;
            $page = 0;

            try {
                do {
                    $query = [
                        'monthlyRange.start_month.year' => $month->year,
                        'monthlyRange.start_month.month' => $month->month,
                        'monthlyRange.end_month.year' => $month->year,
                        'monthlyRange.end_month.month' => $month->month,
                        'pageSize' => 100,
                    ];
                    if ($pageToken !== null) {
                        $query['pageToken'] = $pageToken;
                    }

                    $payload = $this->request(
                        $integration,
                        'https://businessprofileperformance.googleapis.com/v1/'.$locationName.'/searchkeywords/impressions/monthly',
                        $query,
                        'gbp_search_keywords_monthly'
                    );
                    $page++;
                    $items = $payload['searchKeywordsCounts'] ?? [];
                    if (! is_array($items)) {
                        $items = [];
                    }

                    foreach ($items as $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        $keyword = trim((string) ($item['searchKeyword'] ?? ''));
                        if ($keyword === '') {
                            continue;
                        }
                        $insights = is_array($item['insightsValue'] ?? null) ? $item['insightsValue'] : [];

                        DB::table('gbp_search_keywords_monthly')->updateOrInsert(
                            [
                                'external_resource_id' => $resource->id,
                                'month_start' => $month->toDateString(),
                                'search_keyword_hash' => hash('sha256', $keyword),
                            ],
                            [
                                'digital_asset_id' => $run->digital_asset_id,
                                'run_id' => $run->id,
                                'location_name' => $locationName,
                                'search_keyword' => $keyword,
                                'impressions' => array_key_exists('value', $insights) ? (int) $insights['value'] : null,
                                'threshold' => array_key_exists('threshold', $insights) ? (int) $insights['threshold'] : null,
                                'collected_at' => now(),
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                        $rows++;
                    }

                    $next = $payload['nextPageToken'] ?? null;
                    $pageToken = is_string($next) && $next !== '' ? $next : null;
                } while ($pageToken !== null && $page < 100);
            } catch (Throwable $e) {
                $errors[$month->format('Y-m')] = $this->safeMessage($e);
            }
        }

        if ($rows === 0 && $errors !== []) {
            throw new RuntimeException('GBP Search Keywords could not be collected for any requested month.');
        }

        return [
            'rows' => $rows,
            'months' => $months,
            'partial' => $errors !== [],
            'month_errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    private function collectReviews(
        Run $run,
        CoreExternalResource $resource,
        CoreIntegration $integration,
        string $locationName,
        ?string $accountName,
    ): array {
        $parent = $this->v4Parent($accountName, $locationName);
        $pageToken = null;
        $page = 0;
        $rows = 0;
        $averageRating = null;
        $totalReviewCount = null;

        do {
            $query = ['pageSize' => 50];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $payload = $this->request(
                $integration,
                'https://mybusiness.googleapis.com/v4/'.$parent.'/reviews',
                $query,
                'gbp_reviews'
            );
            $page++;

            if ($page === 1) {
                $averageRating = is_numeric($payload['averageRating'] ?? null) ? (string) $payload['averageRating'] : null;
                $totalReviewCount = is_numeric($payload['totalReviewCount'] ?? null) ? (int) $payload['totalReviewCount'] : null;
            }

            $items = $payload['reviews'] ?? [];
            if (! is_array($items)) {
                $items = [];
            }
            foreach ($items as $review) {
                if (! is_array($review)) {
                    continue;
                }
                $reviewId = trim((string) ($review['reviewId'] ?? ''));
                if ($reviewId === '') {
                    continue;
                }
                DB::table('gbp_reviews')->updateOrInsert(
                    ['external_resource_id' => $resource->id, 'review_id' => $reviewId],
                    [
                        'digital_asset_id' => $run->digital_asset_id,
                        'run_id' => $run->id,
                        'location_name' => $locationName,
                        'reviewer' => $this->json($review['reviewer'] ?? null),
                        'star_rating' => isset($review['starRating']) ? (string) $review['starRating'] : null,
                        'comment' => isset($review['comment']) ? (string) $review['comment'] : null,
                        'create_time' => $this->timestamp($review['createTime'] ?? null),
                        'update_time' => $this->timestamp($review['updateTime'] ?? null),
                        'review_reply' => $this->json($review['reviewReply'] ?? null),
                        'review_media_items' => $this->json($review['reviewMediaItems'] ?? null),
                        'review_reply_url' => isset($review['reviewReplyUrl']) ? (string) $review['reviewReplyUrl'] : null,
                        'raw_payload' => $this->json($review),
                        'collected_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $rows++;
            }

            $next = $payload['nextPageToken'] ?? null;
            $pageToken = is_string($next) && $next !== '' ? $next : null;
        } while ($pageToken !== null && $page < 200);

        return [
            'rows' => $rows,
            'average_rating' => $averageRating,
            'total_review_count' => $totalReviewCount,
        ];
    }

    /** @return array<string, mixed> */
    private function collectMedia(
        Run $run,
        CoreExternalResource $resource,
        CoreIntegration $integration,
        string $locationName,
        ?string $accountName,
    ): array {
        $parent = $this->v4Parent($accountName, $locationName);
        $pageToken = null;
        $page = 0;
        $rows = 0;

        do {
            $query = ['pageSize' => 2500];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $payload = $this->request(
                $integration,
                'https://mybusiness.googleapis.com/v4/'.$parent.'/media',
                $query,
                'gbp_media'
            );
            $page++;
            $items = $payload['mediaItems'] ?? [];
            if (! is_array($items)) {
                $items = [];
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $association = is_array($item['locationAssociation'] ?? null) ? $item['locationAssociation'] : [];
                DB::table('gbp_media')->updateOrInsert(
                    ['external_resource_id' => $resource->id, 'media_name' => $name],
                    [
                        'digital_asset_id' => $run->digital_asset_id,
                        'run_id' => $run->id,
                        'location_name' => $locationName,
                        'media_format' => isset($item['mediaFormat']) ? (string) $item['mediaFormat'] : null,
                        'category' => isset($association['category']) ? (string) $association['category'] : null,
                        'google_url' => isset($item['googleUrl']) ? (string) $item['googleUrl'] : null,
                        'thumbnail_url' => isset($item['thumbnailUrl']) ? (string) $item['thumbnailUrl'] : null,
                        'create_time' => $this->timestamp($item['createTime'] ?? null),
                        'dimensions' => $this->json($item['dimensions'] ?? null),
                        'location_association' => $this->json($association),
                        'raw_payload' => $this->json($item),
                        'collected_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $rows++;
            }

            $next = $payload['nextPageToken'] ?? null;
            $pageToken = is_string($next) && $next !== '' ? $next : null;
        } while ($pageToken !== null && $page < 100);

        return ['rows' => $rows];
    }

    /** @return array<string, mixed> */
    private function collectPosts(
        Run $run,
        CoreExternalResource $resource,
        CoreIntegration $integration,
        string $locationName,
        ?string $accountName,
    ): array {
        $parent = $this->v4Parent($accountName, $locationName);
        $pageToken = null;
        $page = 0;
        $rows = 0;

        do {
            $query = ['pageSize' => 100];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $payload = $this->request(
                $integration,
                'https://mybusiness.googleapis.com/v4/'.$parent.'/localPosts',
                $query,
                'gbp_posts'
            );
            $page++;
            $items = $payload['localPosts'] ?? [];
            if (! is_array($items)) {
                $items = [];
            }

            foreach ($items as $post) {
                if (! is_array($post)) {
                    continue;
                }
                $name = trim((string) ($post['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                DB::table('gbp_posts')->updateOrInsert(
                    ['external_resource_id' => $resource->id, 'post_name' => $name],
                    [
                        'digital_asset_id' => $run->digital_asset_id,
                        'run_id' => $run->id,
                        'location_name' => $locationName,
                        'language_code' => isset($post['languageCode']) ? (string) $post['languageCode'] : null,
                        'summary' => isset($post['summary']) ? (string) $post['summary'] : null,
                        'topic_type' => isset($post['topicType']) ? (string) $post['topicType'] : null,
                        'state' => isset($post['state']) ? (string) $post['state'] : null,
                        'create_time' => $this->timestamp($post['createTime'] ?? null),
                        'update_time' => $this->timestamp($post['updateTime'] ?? null),
                        'call_to_action' => $this->json($post['callToAction'] ?? null),
                        'media' => $this->json($post['media'] ?? null),
                        'event' => $this->json($post['event'] ?? null),
                        'offer' => $this->json($post['offer'] ?? null),
                        'recurrence' => $this->json([
                            'schedule' => $post['schedule'] ?? null,
                            'recurrence' => $post['recurrence'] ?? null,
                        ]),
                        'raw_payload' => $this->json($post),
                        'collected_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $rows++;
            }

            $next = $payload['nextPageToken'] ?? null;
            $pageToken = is_string($next) && $next !== '' ? $next : null;
        } while ($pageToken !== null && $page < 100);

        return ['rows' => $rows];
    }

    /** @return array<string, mixed> */
    private function collectAttributes(
        Run $run,
        CoreExternalResource $resource,
        CoreIntegration $integration,
        string $locationName,
    ): array {
        $current = $this->request(
            $integration,
            'https://mybusinessbusinessinformation.googleapis.com/v1/'.$locationName.'/attributes',
            [],
            'gbp_attributes'
        );
        $googleUpdated = $this->captureOptional(fn (): array => $this->request(
            $integration,
            'https://mybusinessbusinessinformation.googleapis.com/v1/'.$locationName.'/attributes:getGoogleUpdated',
            [],
            'gbp_attributes_google_updated'
        ));

        $available = [];
        $pageToken = null;
        $page = 0;
        do {
            $query = ['parent' => $locationName, 'pageSize' => 200];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $payload = $this->request(
                $integration,
                'https://mybusinessbusinessinformation.googleapis.com/v1/attributes',
                $query,
                'gbp_available_attributes'
            );
            $page++;
            $items = $payload['attributeMetadata'] ?? [];
            if (is_array($items)) {
                $available = array_merge($available, array_values($items));
            }
            $next = $payload['nextPageToken'] ?? null;
            $pageToken = is_string($next) && $next !== '' ? $next : null;
        } while ($pageToken !== null && $page < 100);

        DB::table('gbp_attribute_snapshots')->updateOrInsert(
            ['run_id' => $run->id],
            [
                'digital_asset_id' => $run->digital_asset_id,
                'external_resource_id' => $resource->id,
                'location_name' => $locationName,
                'attributes' => $this->json($current),
                'google_updated_attributes' => $this->json($googleUpdated['payload']),
                'available_attributes' => $this->json($available),
                'captured_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return [
            'rows' => count(is_array($current['attributes'] ?? null) ? $current['attributes'] : []),
            'available_attribute_count' => count($available),
            'partial' => $googleUpdated['error'] !== null,
            'google_updated_limitation' => $googleUpdated['error'],
        ];
    }

    /** @param array<string, mixed> $location @return array<string, mixed> */
    private function collectServices(
        Run $run,
        CoreExternalResource $resource,
        string $locationName,
        array $location,
    ): array {
        $services = $location['serviceItems'] ?? [];
        if (! is_array($services)) {
            $services = [];
        }
        DB::table('gbp_service_snapshots')->updateOrInsert(
            ['run_id' => $run->id],
            [
                'digital_asset_id' => $run->digital_asset_id,
                'external_resource_id' => $resource->id,
                'location_name' => $locationName,
                'service_items' => $this->json($services),
                'captured_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return ['rows' => count($services)];
    }

    /** @return array<string, mixed> */
    private function collectPlaceActions(
        Run $run,
        CoreExternalResource $resource,
        CoreIntegration $integration,
        string $locationName,
    ): array {
        $pageToken = null;
        $page = 0;
        $rows = 0;

        do {
            $query = ['pageSize' => 100];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $payload = $this->request(
                $integration,
                'https://mybusinessplaceactions.googleapis.com/v1/'.$locationName.'/placeActionLinks',
                $query,
                'gbp_place_actions'
            );
            $page++;
            $items = $payload['placeActionLinks'] ?? [];
            if (! is_array($items)) {
                $items = [];
            }

            foreach ($items as $link) {
                if (! is_array($link)) {
                    continue;
                }
                $name = trim((string) ($link['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                DB::table('gbp_place_action_links')->updateOrInsert(
                    ['external_resource_id' => $resource->id, 'link_name' => $name],
                    [
                        'digital_asset_id' => $run->digital_asset_id,
                        'run_id' => $run->id,
                        'location_name' => $locationName,
                        'provider_type' => isset($link['providerType']) ? (string) $link['providerType'] : null,
                        'is_editable' => array_key_exists('isEditable', $link) ? (bool) $link['isEditable'] : null,
                        'uri' => isset($link['uri']) ? (string) $link['uri'] : null,
                        'place_action_type' => isset($link['placeActionType']) ? (string) $link['placeActionType'] : null,
                        'is_preferred' => array_key_exists('isPreferred', $link) ? (bool) $link['isPreferred'] : null,
                        'create_time' => $this->timestamp($link['createTime'] ?? null),
                        'update_time' => $this->timestamp($link['updateTime'] ?? null),
                        'raw_payload' => $this->json($link),
                        'collected_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $rows++;
            }

            $next = $payload['nextPageToken'] ?? null;
            $pageToken = is_string($next) && $next !== '' ? $next : null;
        } while ($pageToken !== null && $page < 100);

        return ['rows' => $rows];
    }

    /** @return array<string, mixed> */
    private function collectVerification(
        Run $run,
        CoreExternalResource $resource,
        CoreIntegration $integration,
        string $locationName,
    ): array {
        $voice = $this->request(
            $integration,
            'https://mybusinessverifications.googleapis.com/v1/'.$locationName.'/VoiceOfMerchantState',
            [],
            'gbp_verification_voice'
        );

        $verifications = [];
        $pageToken = null;
        $page = 0;
        do {
            $query = ['pageSize' => 100];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $payload = $this->request(
                $integration,
                'https://mybusinessverifications.googleapis.com/v1/'.$locationName.'/verifications',
                $query,
                'gbp_verifications'
            );
            $page++;
            $items = $payload['verifications'] ?? [];
            if (is_array($items)) {
                $verifications = array_merge($verifications, array_values($items));
            }
            $next = $payload['nextPageToken'] ?? null;
            $pageToken = is_string($next) && $next !== '' ? $next : null;
        } while ($pageToken !== null && $page < 100);

        // Eligible verification methods are not background-fetched: Google restricts
        // fetchVerificationOptions to a direct merchant-owner request.
        DB::table('gbp_verification_snapshots')->updateOrInsert(
            ['run_id' => $run->id],
            [
                'digital_asset_id' => $run->digital_asset_id,
                'external_resource_id' => $resource->id,
                'location_name' => $locationName,
                'has_voice_of_merchant' => array_key_exists('hasVoiceOfMerchant', $voice) ? (bool) $voice['hasVoiceOfMerchant'] : null,
                'has_business_authority' => array_key_exists('hasBusinessAuthority', $voice) ? (bool) $voice['hasBusinessAuthority'] : null,
                'voice_state' => $this->json($voice),
                'verifications' => $this->json($verifications),
                'verification_options' => null,
                'verification_options_state' => 'on_demand_only',
                'captured_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return [
            'rows' => count($verifications),
            'has_voice_of_merchant' => $voice['hasVoiceOfMerchant'] ?? null,
            'verification_options_state' => 'on_demand_only',
        ];
    }

    /** @param array<string, mixed> $location @param array<string, mixed>|null $googleUpdated */
    private function persistLocation(
        Run $run,
        CoreExternalResource $resource,
        string $locationName,
        array $location,
        ?array $googleUpdated,
    ): void {
        $categories = is_array($location['categories'] ?? null) ? $location['categories'] : [];
        $primary = is_array($categories['primaryCategory'] ?? null) ? $categories['primaryCategory'] : [];
        $metadata = is_array($location['metadata'] ?? null) ? $location['metadata'] : [];

        DB::table('gbp_location_snapshots')->updateOrInsert(
            ['run_id' => $run->id],
            [
                'digital_asset_id' => $run->digital_asset_id,
                'external_resource_id' => $resource->id,
                'location_name' => $locationName,
                'title' => isset($location['title']) ? (string) $location['title'] : null,
                'language_code' => isset($location['languageCode']) ? (string) $location['languageCode'] : null,
                'store_code' => isset($location['storeCode']) ? (string) $location['storeCode'] : null,
                'place_id' => isset($metadata['placeId']) ? (string) $metadata['placeId'] : null,
                'maps_uri' => isset($metadata['mapsUri']) ? (string) $metadata['mapsUri'] : null,
                'primary_category' => isset($primary['displayName']) ? (string) $primary['displayName'] : (isset($primary['name']) ? (string) $primary['name'] : null),
                'additional_categories' => $this->json($categories),
                'storefront_address' => $this->json($location['storefrontAddress'] ?? null),
                'phone_numbers' => $this->json($location['phoneNumbers'] ?? null),
                'website_uri' => isset($location['websiteUri']) ? (string) $location['websiteUri'] : null,
                'open_info' => $this->json($location['openInfo'] ?? null),
                'latlng' => $this->json($location['latlng'] ?? null),
                'service_area' => $this->json($location['serviceArea'] ?? null),
                'regular_hours' => $this->json($location['regularHours'] ?? null),
                'special_hours' => $this->json($location['specialHours'] ?? null),
                'more_hours' => $this->json($location['moreHours'] ?? null),
                'profile' => $this->json($location['profile'] ?? null),
                'provider_metadata' => $this->json($metadata),
                'google_updated' => $this->json($googleUpdated),
                'captured_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function resolveAccountName(
        CoreExternalResource $resource,
        CoreIntegration $integration,
        string $locationName,
    ): ?string {
        if (is_string($resource->parent_external_id) && str_starts_with($resource->parent_external_id, 'accounts/')) {
            return $resource->parent_external_id;
        }
        if (preg_match('#^(accounts/[^/]+)/locations/[^/]+$#', (string) $resource->external_id, $match) === 1) {
            return $match[1];
        }

        $accountsPayload = $this->request(
            $integration,
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts',
            [],
            'gbp_account_resolution'
        );
        $accounts = $accountsPayload['accounts'] ?? [];
        if (! is_array($accounts)) {
            return null;
        }

        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }
            $accountName = trim((string) ($account['name'] ?? ''));
            if ($accountName === '') {
                continue;
            }
            $pageToken = null;
            $page = 0;
            do {
                $query = ['readMask' => 'name', 'pageSize' => 100];
                if ($pageToken !== null) {
                    $query['pageToken'] = $pageToken;
                }
                try {
                    $payload = $this->request(
                        $integration,
                        'https://mybusinessbusinessinformation.googleapis.com/v1/'.$accountName.'/locations',
                        $query,
                        'gbp_account_resolution'
                    );
                } catch (Throwable) {
                    break;
                }
                $page++;
                $locations = $payload['locations'] ?? [];
                if (is_array($locations)) {
                    foreach ($locations as $candidate) {
                        if (is_array($candidate) && ($candidate['name'] ?? null) === $locationName) {
                            return $accountName;
                        }
                    }
                }
                $next = $payload['nextPageToken'] ?? null;
                $pageToken = is_string($next) && $next !== '' ? $next : null;
            } while ($pageToken !== null && $page < 100);
        }

        return null;
    }

    private function v4Parent(?string $accountName, string $locationName): string
    {
        if ($accountName === null || ! str_starts_with($accountName, 'accounts/')) {
            throw new RuntimeException('GBP account context could not be resolved for v4 reviews/media/posts.');
        }
        $accountId = trim(substr($accountName, strlen('accounts/')));
        $locationId = $this->locationId($locationName);
        if ($accountId === '' || $locationId === '') {
            throw new RuntimeException('GBP account/location provider identity is incomplete.');
        }

        return 'accounts/'.$accountId.'/locations/'.$locationId;
    }

    private function locationName(string $externalId): string
    {
        if (preg_match('#(?:^|/)locations/([^/]+)$#', trim($externalId), $match) === 1) {
            return 'locations/'.$match[1];
        }
        throw new RuntimeException('GBP External Resource does not contain a valid locations/{locationId} identity.');
    }

    private function locationId(string $locationName): string
    {
        return str_starts_with($locationName, 'locations/') ? substr($locationName, strlen('locations/')) : '';
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function request(
        CoreIntegration $integration,
        string $url,
        array $query,
        string $dataset,
    ): array {
        $response = $this->client->get($integration, $url, $query, GoogleScopeRegistry::CAPABILITY_GBP);
        if (! $response->successful()) {
            throw new RuntimeException(sprintf('%s provider request failed with HTTP %d.', $dataset, $response->status()));
        }
        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /** @param callable(): array<string, mixed> $callback @return array<string, mixed> */
    private function captureDataset(string $dataset, callable $callback): array
    {
        try {
            $result = $callback();
            $partial = (bool) ($result['partial'] ?? false);

            return array_merge(['status' => $partial ? 'partial' : 'available'], $result);
        } catch (Throwable $e) {
            Log::warning('GBP dataset collection unavailable', [
                'dataset' => $dataset,
                'exception' => $e::class,
                'message' => $this->safeMessage($e),
            ]);

            return ['status' => 'unavailable', 'rows' => 0, 'reason' => $this->safeMessage($e)];
        }
    }

    /** @param callable(): array<string, mixed> $callback @return array{payload: ?array<string, mixed>, error: ?string} */
    private function captureOptional(callable $callback): array
    {
        try {
            return ['payload' => $callback(), 'error' => null];
        } catch (Throwable $e) {
            return ['payload' => null, 'error' => $this->safeMessage($e)];
        }
    }

    private function googleDate(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }
        $year = (int) ($value['year'] ?? 0);
        $month = (int) ($value['month'] ?? 0);
        $day = (int) ($value['day'] ?? 0);
        if ($year < 2000 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }
        try {
            return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC')->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function json(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function safeMessage(Throwable $e): string
    {
        return mb_substr(trim($e->getMessage()) ?: $e::class, 0, 500);
    }
}
