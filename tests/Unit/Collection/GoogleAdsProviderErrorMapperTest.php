<?php

namespace Tests\Unit\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCustomerNotEnabledException;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsProviderErrorMapper;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsQuotaCooldownException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleAdsProviderErrorMapperTest extends TestCase
{
    #[Test]
    public function http_400_includes_google_ads_failure_query_error_details(): void
    {
        Http::swap(new Factory);
        Http::fake([
            'https://googleads.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'Request contains an invalid argument.',
                    'status' => 'INVALID_ARGUMENT',
                    'details' => [[
                        '@type' => 'type.googleapis.com/google.ads.googleads.v25.errors.GoogleAdsFailure',
                        'errors' => [[
                            'errorCode' => [
                                'queryError' => 'UNRECOGNIZED_FIELD',
                            ],
                            'message' => "Unrecognized field in the query: 'campaign.start_date'.",
                        ]],
                        'requestId' => 'req-ads-failure-1',
                    ]],
                ],
            ], 400),
        ]);

        $response = Http::post('https://googleads.googleapis.com/v25/customers/1/googleAds:search');
        $result = (new GoogleAdsProviderErrorMapper)->fromHttpResponse($response);

        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame(CollectionErrorCategory::ContractMismatch, $result->errorCategory);
        $this->assertSame('CONTRACT_MISMATCH', $result->errorCode);
        $this->assertStringContainsString('UNRECOGNIZED_FIELD', (string) $result->errorMessage);
        $this->assertStringContainsString('campaign.start_date', (string) $result->errorMessage);
        $this->assertStringContainsString('Request contains an invalid argument.', (string) $result->errorMessage);
    }

    #[Test]
    public function http_403_customer_not_enabled_keeps_its_own_code(): void
    {
        $result = (new GoogleAdsProviderErrorMapper)->fromHttpResponse($this->forbidden('CUSTOMER_NOT_ENABLED',
            "The customer account can't be accessed because it is not yet enabled or has been deactivated."));

        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame(CollectionErrorCategory::Authorization, $result->errorCategory);
        $this->assertSame('CUSTOMER_NOT_ENABLED', $result->errorCode, 'the history probe parks a closed account on this code');
        $this->assertStringStartsWith('Google Ads authorization failed: The caller does not have permission', (string) $result->errorMessage);
        $this->assertStringContainsString('authorizationError:CUSTOMER_NOT_ENABLED', (string) $result->errorMessage);
    }

    #[Test]
    public function http_403_other_permission_errors_stay_authorization(): void
    {
        $result = (new GoogleAdsProviderErrorMapper)->fromHttpResponse($this->forbidden('USER_PERMISSION_DENIED',
            "User doesn't have permission to access customer."));

        $this->assertSame(CollectionErrorCategory::Authorization, $result->errorCategory);
        $this->assertSame('AUTHORIZATION', $result->errorCode);
    }

    #[Test]
    public function parked_closed_account_and_customer_lock_wait_keep_their_meaning_when_rethrown(): void
    {
        $mapper = new GoogleAdsProviderErrorMapper;

        $closed = $mapper->fromThrowable(new GoogleAdsCustomerNotEnabledException);
        $this->assertSame([DatasetExecutionOutcome::Failed, CollectionErrorCategory::Authorization, 'CUSTOMER_NOT_ENABLED'],
            [$closed->outcome, $closed->errorCategory, $closed->errorCode], 'a closed account is not an "unexpected error"');

        $busy = $mapper->fromThrowable(new GoogleAdsQuotaCooldownException(15, 'customer_concurrency'));
        $this->assertSame([DatasetExecutionOutcome::Retry, CollectionErrorCategory::Quota, 'GOOGLE_ADS_CONCURRENCY_WAIT', 15],
            [$busy->outcome, $busy->errorCategory, $busy->errorCode, $busy->backoffSeconds]);
    }

    private function forbidden(string $authorizationError, string $message): Response
    {
        Http::swap(new Factory);
        Http::fake([
            'https://googleads.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'The caller does not have permission',
                    'status' => 'PERMISSION_DENIED',
                    'details' => [[
                        '@type' => 'type.googleapis.com/google.ads.googleads.v25.errors.GoogleAdsFailure',
                        'errors' => [[
                            'errorCode' => ['authorizationError' => $authorizationError],
                            'message' => $message,
                        ]],
                        'requestId' => 'req-ads-403',
                    ]],
                ],
            ], 403),
        ]);

        return Http::post('https://googleads.googleapis.com/v25/customers/1/googleAds:searchStream');
    }
}
