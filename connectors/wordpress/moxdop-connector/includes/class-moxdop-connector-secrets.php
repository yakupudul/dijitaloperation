<?php

defined('ABSPATH') || exit;

final class MoxDOP_Connector_Secrets
{
    const OPTION = 'moxdop_connector_credentials';

    public function store(array $credentials)
    {
        $plaintext = wp_json_encode($credentials);
        if (! is_string($plaintext)) {
            return new WP_Error('moxdop_secret_encode', 'Connector credentials could not be encoded.');
        }

        $key = hash('sha256', wp_salt('auth'), true);
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $payload = [
                'mode' => 'sodium',
                'nonce' => base64_encode($nonce),
                'ciphertext' => base64_encode(sodium_crypto_secretbox($plaintext, $nonce, $key)),
            ];
        } elseif (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if (! is_string($ciphertext)) {
                return new WP_Error('moxdop_secret_encrypt', 'Connector credentials could not be encrypted.');
            }
            $payload = [
                'mode' => 'openssl',
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'ciphertext' => base64_encode($ciphertext),
            ];
        } else {
            return new WP_Error('moxdop_crypto_missing', 'Sodium or OpenSSL is required for connector credentials.');
        }

        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, $payload, '', 'no');
        } else {
            update_option(self::OPTION, $payload, false);
        }

        return true;
    }

    public function read()
    {
        $payload = get_option(self::OPTION);
        if (! is_array($payload)) {
            return null;
        }

        $key = hash('sha256', wp_salt('auth'), true);
        $ciphertext = base64_decode((string) ($payload['ciphertext'] ?? ''), true);
        if (! is_string($ciphertext)) {
            return null;
        }

        if (($payload['mode'] ?? '') === 'sodium' && function_exists('sodium_crypto_secretbox_open')) {
            $nonce = base64_decode((string) ($payload['nonce'] ?? ''), true);
            $plaintext = is_string($nonce) ? sodium_crypto_secretbox_open($ciphertext, $nonce, $key) : false;
        } elseif (($payload['mode'] ?? '') === 'openssl' && function_exists('openssl_decrypt')) {
            $iv = base64_decode((string) ($payload['iv'] ?? ''), true);
            $tag = base64_decode((string) ($payload['tag'] ?? ''), true);
            $plaintext = is_string($iv) && is_string($tag)
                ? openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag)
                : false;
        } else {
            return null;
        }

        $decoded = is_string($plaintext) ? json_decode($plaintext, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}

