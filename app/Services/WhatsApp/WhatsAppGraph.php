<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class WhatsAppGraph
{
    public function request(string $method, string $path, array $parameters = [], string $token = '', string $secret = ''): array
    {
        $version = (string) config('whatsapp.graph_version', 'v23.0');
        if (! preg_match('/^v[0-9]+\.[0-9]+$/', $version)) {
            throw new WhatsAppGraphException(['message' => 'WHATSAPP_GRAPH_VERSION geçerli değil.', 'stage' => $path]);
        }
        if ($token !== '' && $secret !== '' && $path !== 'oauth/access_token') {
            $parameters['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
        }
        try {
            $request = Http::acceptJson()->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false]);
            if ($token !== '') {
                $request = $request->withToken($token);
            }
            $response = $method === 'POST'
                ? $request->asForm()->post('https://graph.facebook.com/'.$version.'/'.$path, $parameters)
                : $request->get('https://graph.facebook.com/'.$version.'/'.$path, $parameters);
        } catch (ConnectionException $exception) {
            // Never persist the HTTP exception: it can contain a credential-bearing URL.
            throw new WhatsAppGraphException(['message' => 'Meta bağlantısı zaman aşımına uğradı veya ağ bağlantısı kurulamadı.', 'stage' => $path]);
        }
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || isset($body['error'])) {
            $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'Meta beklenen JSON yanıtını döndürmedi.';
            foreach (array_filter([$token, $secret, ...array_values($parameters)], 'is_string') as $value) {
                if ($value !== '') {
                    $message = str_replace([$value, rawurlencode($value), urlencode($value)], '[gizlendi]', $message);
                }
            }
            $message = preg_replace('/\b[A-Za-z0-9_\-|]{80,}\b/', '[gizlendi]', strip_tags($message));
            throw new WhatsAppGraphException(array_filter([
                'http_status' => $response->status(),
                'code' => is_numeric($error['code'] ?? null) ? (int) $error['code'] : null,
                'subcode' => is_numeric($error['error_subcode'] ?? null) ? (int) $error['error_subcode'] : null,
                'trace_id' => is_string($error['fbtrace_id'] ?? null) ? preg_replace('/[^a-zA-Z0-9_-]/', '', substr($error['fbtrace_id'], 0, 100)) : null,
                'message' => mb_substr($message, 0, 700),
                'stage' => $path,
            ], fn ($value) => $value !== null));
        }

        return $body;
    }

    public function subscribed(array $body, string $appId): bool
    {
        foreach (($body['data'] ?? []) as $app) {
            if ((string) data_get($app, 'whatsapp_business_api_data.id', data_get($app, 'id', '')) === $appId) {
                return true;
            }
        }

        return false;
    }
}
