<?php

namespace App\Support\Security;

/**
 * Canonical structured redaction for logs, exceptions, and safe exports (Prompt 64).
 * Does not attempt AI-based secret discovery.
 */
final class SecurityRedactor
{
    public const string REDACTED = '[REDACTED]';

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function redactContext(array $context): array
    {
        $fields = array_map('strtolower', config('moxdop-security.sensitive_field_names', []));
        $headers = array_map('strtolower', config('moxdop-security.sensitive_headers', []));

        return $this->walk($context, $fields, $headers);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public function redactHeaders(array $headers): array
    {
        $sensitive = array_map('strtolower', config('moxdop-security.sensitive_headers', []));
        $out = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string) $name);
            if (in_array($lower, $sensitive, true) || $this->keyLooksSensitive($lower, config('moxdop-security.sensitive_field_names', []))) {
                $out[$name] = self::REDACTED;
            } else {
                $out[$name] = is_array($value) ? $this->redactHeaders($value) : $value;
            }
        }

        return $out;
    }

    public function redactString(string $value, string $knownSecret = ''): string
    {
        if ($knownSecret !== '' && str_contains($value, $knownSecret)) {
            return str_replace($knownSecret, self::REDACTED, $value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $fields
     * @param  list<string>  $headers
     * @return array<string, mixed>
     */
    private function walk(array $node, array $fields, array $headers): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            $lower = strtolower((string) $key);
            if ($this->keyLooksSensitive($lower, $fields) || in_array($lower, $headers, true)) {
                $out[$key] = self::REDACTED;

                continue;
            }
            if ($value instanceof EphemeralSecret) {
                $out[$key] = $value->toArray();

                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->walk($value, $fields, $headers);

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param  list<string>  $fields
     */
    private function keyLooksSensitive(string $lowerKey, array $fields): bool
    {
        // Avoid treating token_count / authorization_code_challenge metadata as secrets by exact match preference.
        foreach ($fields as $field) {
            if ($lowerKey === $field) {
                return true;
            }
            if (str_ends_with($lowerKey, '_'.$field) || str_starts_with($lowerKey, $field.'_')) {
                // token_count is not a credential.
                if ($field === 'token' && str_contains($lowerKey, 'token_count')) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }
}
