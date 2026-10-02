<?php

namespace Tests\Unit\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Services\Collection\Providers\MetaAds\MetaAdsProviderErrorMapper;
use App\Services\Integrations\Meta\MetaException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaAdsProviderErrorMapperTest extends TestCase
{
    #[Test]
    public function graph_code_100_is_terminal_invalid_request_even_when_http_status_is_500(): void
    {
        $result = app(MetaAdsProviderErrorMapper::class)->fromThrowable(new MetaException(
            "(#100) Filtering field 'id' with operation 'in' is not supported",
            kind: MetaException::KIND_HTTP,
            httpStatus: 500,
            providerCode: 100,
        ));

        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame(CollectionErrorCategory::InvalidRequest, $result->errorCategory);
        $this->assertSame('META_INVALID_REQUEST_100', $result->errorCode);
        $this->assertStringContainsString("Filtering field 'id'", (string) $result->errorMessage);
        $this->assertStringContainsString('[meta-error-v2 · http 500 · code 100]', (string) $result->errorMessage);
    }

    #[Test]
    public function genuine_unstructured_http_500_remains_retryable(): void
    {
        $result = app(MetaAdsProviderErrorMapper::class)->fromThrowable(new MetaException(
            'Provider unavailable.',
            kind: MetaException::KIND_HTTP,
            httpStatus: 500,
        ));

        $this->assertSame(DatasetExecutionOutcome::Retry, $result->outcome);
        $this->assertSame(CollectionErrorCategory::Provider5xx, $result->errorCategory);
    }
}
