<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Normalizes OpenSSL / cURL certificate metadata into diagnosis evidence fields.
 * Does not perform network I/O and never stores private keys or raw dumps.
 */
class SslCertParser
{
    public const FETCH_METHOD_PHP_STREAM = 'php_stream';

    public const FETCH_METHOD_CURL = 'curl';

    /**
     * @param  array<string, mixed>  $parsed  Result of openssl_x509_parse()
     * @return array{
     *     subject_common_name: string|null,
     *     issuer_common_name: string|null,
     *     valid_from: string|null,
     *     valid_to: string|null,
     *     observed_at: string,
     *     fetch_method: string,
     *     host: string,
     *     present: bool
     * }
     */
    public function fromOpenSslParsed(
        array $parsed,
        string $host,
        DateTimeInterface $observedAt,
        string $fetchMethod = self::FETCH_METHOD_PHP_STREAM,
    ): array {
        $validFrom = $this->timeFieldToIso8601($parsed['validFrom_time_t'] ?? $parsed['validFrom'] ?? null);
        $validTo = $this->timeFieldToIso8601($parsed['validTo_time_t'] ?? $parsed['validTo'] ?? null);

        return [
            'subject_common_name' => $this->commonName($parsed['subject'] ?? null),
            'issuer_common_name' => $this->commonName($parsed['issuer'] ?? null),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'observed_at' => $this->toIso8601($observedAt),
            'fetch_method' => $fetchMethod,
            'host' => strtolower($host),
            'present' => true,
        ];
    }

    /**
     * @param  list<array{0?: string, 1?: string}|array{string, string}>  $certInfoEntries  CURLOPT_CERTINFO-style pairs
     * @return array{
     *     subject_common_name: string|null,
     *     issuer_common_name: string|null,
     *     valid_from: string|null,
     *     valid_to: string|null,
     *     observed_at: string,
     *     fetch_method: string,
     *     host: string,
     *     present: bool
     * }
     */
    public function fromCurlCertInfo(
        array $certInfoEntries,
        string $host,
        DateTimeInterface $observedAt,
    ): array {
        $fields = [];

        foreach ($certInfoEntries as $entry) {
            if (! is_array($entry) || count($entry) < 2) {
                continue;
            }

            $key = strtolower(trim((string) $entry[0]));
            $fields[$key] = trim((string) $entry[1]);
        }

        $subject = $fields['subject'] ?? null;
        $issuer = $fields['issuer'] ?? null;

        return [
            'subject_common_name' => $this->commonNameFromDn($subject),
            'issuer_common_name' => $this->commonNameFromDn($issuer),
            'valid_from' => $this->timeFieldToIso8601($fields['start date'] ?? $fields['start_date'] ?? null),
            'valid_to' => $this->timeFieldToIso8601($fields['expire date'] ?? $fields['expire_date'] ?? null),
            'observed_at' => $this->toIso8601($observedAt),
            'fetch_method' => self::FETCH_METHOD_CURL,
            'host' => strtolower($host),
            'present' => true,
        ];
    }

    /**
     * @return array{
     *     subject_common_name: null,
     *     issuer_common_name: null,
     *     valid_from: null,
     *     valid_to: null,
     *     observed_at: string,
     *     fetch_method: string,
     *     host: string,
     *     present: bool,
     *     error_class: string
     * }
     */
    public function missing(
        string $host,
        DateTimeInterface $observedAt,
        string $fetchMethod = self::FETCH_METHOD_PHP_STREAM,
        string $errorClass = 'certificate_missing',
    ): array {
        return [
            'subject_common_name' => null,
            'issuer_common_name' => null,
            'valid_from' => null,
            'valid_to' => null,
            'observed_at' => $this->toIso8601($observedAt),
            'fetch_method' => $fetchMethod,
            'host' => strtolower($host),
            'present' => false,
            'error_class' => $errorClass,
        ];
    }

    public function fingerprint(string $host, ?string $validTo, ?string $issuerCommonName): string
    {
        return sha1(strtolower($host).'ssl'.($validTo ?? '').($issuerCommonName ?? ''));
    }

    private function commonName(mixed $nameAttributes): ?string
    {
        if (! is_array($nameAttributes)) {
            return null;
        }

        if (isset($nameAttributes['CN']) && is_string($nameAttributes['CN']) && $nameAttributes['CN'] !== '') {
            return $nameAttributes['CN'];
        }

        return null;
    }

    private function commonNameFromDn(?string $dn): ?string
    {
        if ($dn === null || $dn === '') {
            return null;
        }

        if (preg_match('/(?:^|,)\s*CN\s*=\s*([^,]+)/i', $dn, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function timeFieldToIso8601(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return $this->toIso8601((new DateTimeImmutable)->setTimestamp((int) $value));
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Unsupported certificate time field type.');
        }

        // OpenSSL ASN.1 UTCTIME / GENERALIZEDTIME without separators, e.g. 20240101120000Z
        if (preg_match('/^\d{14}Z$/', $value) === 1) {
            $parsed = DateTimeImmutable::createFromFormat('YmdHis\Z', $value);

            return $parsed instanceof DateTimeImmutable ? $this->toIso8601($parsed) : null;
        }

        if (preg_match('/^\d{12}Z$/', $value) === 1) {
            $parsed = DateTimeImmutable::createFromFormat('ymdHis\Z', $value);

            return $parsed instanceof DateTimeImmutable ? $this->toIso8601($parsed) : null;
        }

        $parsed = date_create_immutable($value);

        return $parsed instanceof DateTimeImmutable ? $this->toIso8601($parsed) : null;
    }

    private function toIso8601(DateTimeInterface $dateTime): string
    {
        return DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
