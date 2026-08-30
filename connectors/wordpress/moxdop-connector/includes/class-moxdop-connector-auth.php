<?php

defined('ABSPATH') || exit;

final class MoxDOP_Connector_Auth
{
    const CLOCK_SKEW = 300;

    private $secrets;

    public function __construct()
    {
        $this->secrets = new MoxDOP_Connector_Secrets();
    }

    public function authorize(WP_REST_Request $request)
    {
        $credentials = $this->secrets->read();
        if (! is_array($credentials)) {
            return new WP_Error('moxdop_not_paired', 'Connector is not paired.', ['status' => 401]);
        }

        $client = trim((string) $request->get_header('x-moxdop-client'));
        $timestamp = (int) $request->get_header('x-moxdop-timestamp');
        $nonce = trim((string) $request->get_header('x-moxdop-nonce'));
        $provided = strtolower(trim((string) $request->get_header('x-moxdop-signature')));
        if (! hash_equals((string) ($credentials['client_id'] ?? ''), $client)
            || $timestamp < 1 || abs(time() - $timestamp) > self::CLOCK_SKEW
            || ! preg_match('/^[a-f0-9-]{32,64}$/i', $nonce)
            || ! preg_match('/^[a-f0-9]{64}$/', $provided)) {
            return new WP_Error('moxdop_auth_failed', 'Connector request authentication failed.', ['status' => 401]);
        }

        $query = [];
        foreach ($request->get_query_params() as $key => $value) {
            if (is_scalar($value)) {
                $query[(string) $key] = (string) $value;
            }
        }
        ksort($query, SORT_STRING);
        $canonical = implode("\n", [
            strtoupper($request->get_method()),
            $request->get_route(),
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            (string) $timestamp,
            $nonce,
            hash('sha256', (string) $request->get_body()),
        ]);
        $expected = hash_hmac('sha256', $canonical, (string) ($credentials['shared_secret'] ?? ''));
        if (! hash_equals($expected, $provided)) {
            return new WP_Error('moxdop_auth_failed', 'Connector request authentication failed.', ['status' => 401]);
        }

        $replay_key = 'moxdop_nonce_'.hash('sha256', $client.'|'.$nonce);
        if (get_transient($replay_key)) {
            return new WP_Error('moxdop_replay', 'Connector request was already used.', ['status' => 409]);
        }
        set_transient($replay_key, 1, self::CLOCK_SKEW * 2);

        return true;
    }

    public function envelope(array $data, WP_REST_Request $request)
    {
        $credentials = $this->secrets->read();
        if (! is_array($credentials)) {
            return new WP_Error('moxdop_not_paired', 'Connector is not paired.', ['status' => 401]);
        }
        $server_time = time();
        $request_nonce = trim((string) $request->get_header('x-moxdop-nonce'));
        $signature = hash_hmac('sha256', implode("\n", [
            (string) $server_time,
            $request_nonce,
            hash('sha256', MoxDOP_Connector_Canonical_JSON::encode($data)),
        ]), (string) ($credentials['shared_secret'] ?? ''));

        return rest_ensure_response([
            'data' => $data,
            'meta' => [
                'server_time' => $server_time,
                'request_nonce' => $request_nonce,
                'signature' => $signature,
            ],
        ]);
    }
}

