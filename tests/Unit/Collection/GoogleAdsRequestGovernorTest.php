<?php

namespace Tests\Unit\Collection;

use App\Models\CoreIntegration;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsQuotaCooldownException;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsRequestGovernor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleAdsRequestGovernorTest extends TestCase
{
    use RefreshDatabase;

    private CoreIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'moxdop-google-ads-collector.request_lock_wait_seconds' => 1,
            'moxdop-google-ads-collector.request_lock_contention_retry_seconds' => 15,
            'moxdop-google-ads-collector.minimum_request_interval_ms' => 0,
        ]);
        $this->integration = CoreIntegration::factory()->google()->create();
    }

    #[Test]
    public function a_busy_customer_lock_becomes_a_concurrency_wait_without_calling_the_provider(): void
    {
        $held = Cache::lock($this->lockKey('1112223333'), 60);
        $this->assertTrue($held->get());
        $called = false;

        try {
            app(GoogleAdsRequestGovernor::class)->run($this->integration, '111-222-3333', function () use (&$called): Response {
                $called = true;

                return $this->okResponse();
            });
            $this->fail('A held customer lock must turn into a concurrency wait.');
        } catch (GoogleAdsQuotaCooldownException $wait) {
            $this->assertSame('customer_concurrency', $wait->scope);
            $this->assertSame(15, $wait->retryAfterSeconds);
        } finally {
            $held->release();
        }

        $this->assertFalse($called, 'no provider call while another request holds the customer');
    }

    #[Test]
    public function a_free_customer_lock_runs_the_request_and_releases_the_lock(): void
    {
        $response = app(GoogleAdsRequestGovernor::class)->run($this->integration, '1112223333', fn (): Response => $this->okResponse());

        $this->assertTrue($response->successful());
        $this->assertTrue(Cache::lock($this->lockKey('1112223333'), 60)->get(), 'the customer lock is released after the request');
    }

    #[Test]
    public function another_customer_is_not_blocked_by_a_busy_one(): void
    {
        $held = Cache::lock($this->lockKey('1112223333'), 60);
        $this->assertTrue($held->get());

        try {
            $response = app(GoogleAdsRequestGovernor::class)->run($this->integration, '4445556666', fn (): Response => $this->okResponse());
            $this->assertTrue($response->successful());
        } finally {
            $held->release();
        }
    }

    private function lockKey(string $customerId): string
    {
        return 'moxdop:gads:request:customer:'.hash('sha256', $this->integration->getKey().'|'.$customerId);
    }

    private function okResponse(): Response
    {
        Http::swap(new Factory);
        Http::fake(['https://googleads.googleapis.com/*' => Http::response(['results' => []])]);

        return Http::post('https://googleads.googleapis.com/v25/customers/1/googleAds:searchStream');
    }
}
