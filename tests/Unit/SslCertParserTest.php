<?php

namespace Tests\Unit;

use App\Support\SslCertParser;
use DateTimeImmutable;
use Tests\TestCase;

class SslCertParserTest extends TestCase
{
    public function test_from_openssl_parsed_normalizes_certificate_fields(): void
    {
        $parser = new SslCertParser;
        $observedAt = new DateTimeImmutable('2026-08-07T12:00:00Z');

        $payload = $parser->fromOpenSslParsed([
            'subject' => ['CN' => 'www.acme.example'],
            'issuer' => ['CN' => 'Test Intermediate CA'],
            'validFrom_time_t' => (new DateTimeImmutable('2025-01-01T00:00:00Z'))->getTimestamp(),
            'validTo_time_t' => (new DateTimeImmutable('2026-01-01T00:00:00Z'))->getTimestamp(),
        ], 'acme.example', $observedAt);

        $this->assertSame('www.acme.example', $payload['subject_common_name']);
        $this->assertSame('Test Intermediate CA', $payload['issuer_common_name']);
        $this->assertSame('2025-01-01T00:00:00Z', $payload['valid_from']);
        $this->assertSame('2026-01-01T00:00:00Z', $payload['valid_to']);
        $this->assertSame('2026-08-07T12:00:00Z', $payload['observed_at']);
        $this->assertSame(SslCertParser::FETCH_METHOD_PHP_STREAM, $payload['fetch_method']);
        $this->assertSame('acme.example', $payload['host']);
        $this->assertTrue($payload['present']);
    }

    public function test_from_curl_cert_info_normalizes_certificate_fields(): void
    {
        $parser = new SslCertParser;
        $observedAt = new DateTimeImmutable('2026-08-07T12:00:00Z');

        $payload = $parser->fromCurlCertInfo([
            ['Subject', 'CN=api.acme.example, O=Acme'],
            ['Issuer', 'CN=Public CA, O=Public'],
            ['Start date', 'Jan  1 00:00:00 2025 GMT'],
            ['Expire date', 'Jan  1 00:00:00 2027 GMT'],
        ], 'API.ACME.EXAMPLE', $observedAt);

        $this->assertSame('api.acme.example', $payload['subject_common_name']);
        $this->assertSame('Public CA', $payload['issuer_common_name']);
        $this->assertSame('2025-01-01T00:00:00Z', $payload['valid_from']);
        $this->assertSame('2027-01-01T00:00:00Z', $payload['valid_to']);
        $this->assertSame(SslCertParser::FETCH_METHOD_CURL, $payload['fetch_method']);
        $this->assertSame('api.acme.example', $payload['host']);
        $this->assertTrue($payload['present']);
    }

    public function test_missing_certificate_payload_has_null_dates_and_error_class(): void
    {
        $parser = new SslCertParser;
        $observedAt = new DateTimeImmutable('2026-08-07T12:00:00Z');

        $payload = $parser->missing('gone.example', $observedAt);

        $this->assertFalse($payload['present']);
        $this->assertNull($payload['subject_common_name']);
        $this->assertNull($payload['issuer_common_name']);
        $this->assertNull($payload['valid_from']);
        $this->assertNull($payload['valid_to']);
        $this->assertSame('certificate_missing', $payload['error_class']);
        $this->assertSame('gone.example', $payload['host']);
    }
}
