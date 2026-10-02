<?php

namespace Tests\Unit\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsProviderErrorMapper;
use Illuminate\Http\Client\Factory;
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
}
