<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Fetches peer TLS certificate metadata for a host (no page body, no secrets).
 */
class SslCertificateProbe
{
    public function __construct(
        private readonly SslCertParser $parser = new SslCertParser,
        private readonly int $timeoutSeconds = 10,
    ) {}

    /**
     * @return array{
     *     subject_common_name: string|null,
     *     issuer_common_name: string|null,
     *     valid_from: string|null,
     *     valid_to: string|null,
     *     observed_at: string,
     *     fetch_method: string,
     *     host: string,
     *     present: bool,
     *     error_class?: string
     * }
     */
    public function probe(string $host, DateTimeInterface $observedAt, int $port = 443): array
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return $this->parser->missing($host, $observedAt, SslCertParser::FETCH_METHOD_PHP_STREAM, 'invalid_host');
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'peer_name' => $host,
            ],
        ]);

        $client = @stream_socket_client(
            'ssl://'.$host.':'.$port,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($client === false) {
            return $this->parser->missing(
                $host,
                $observedAt,
                SslCertParser::FETCH_METHOD_PHP_STREAM,
                'certificate_missing',
            );
        }

        try {
            $params = stream_context_get_params($client);
            $certificate = $params['options']['ssl']['peer_certificate'] ?? null;

            if ($certificate === null) {
                return $this->parser->missing(
                    $host,
                    $observedAt,
                    SslCertParser::FETCH_METHOD_PHP_STREAM,
                    'certificate_missing',
                );
            }

            $parsed = openssl_x509_parse($certificate);

            if ($parsed === false) {
                return $this->parser->missing(
                    $host,
                    $observedAt,
                    SslCertParser::FETCH_METHOD_PHP_STREAM,
                    'certificate_unparseable',
                );
            }

            return $this->parser->fromOpenSslParsed(
                $parsed,
                $host,
                $observedAt,
                SslCertParser::FETCH_METHOD_PHP_STREAM,
            );
        } finally {
            fclose($client);
        }
    }
}
