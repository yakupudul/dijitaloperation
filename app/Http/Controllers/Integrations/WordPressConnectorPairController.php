<?php

namespace App\Http\Controllers\Integrations;

use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class WordPressConnectorPairController
{
    public function __invoke(Request $request, WordPressConnectorPairingService $pairing): JsonResponse
    {
        $validated = $request->validate([
            'pairing_code' => ['required', 'string', 'max:64'],
            'site_url' => ['required', 'url:https', 'max:2048'],
            'home_url' => ['required', 'url:https', 'max:2048'],
            'status_url' => ['required', 'url:https', 'max:2048'],
            'snapshot_url' => ['required', 'url:https', 'max:2048'],
            'installation_id' => ['required', 'uuid'],
            'plugin_version' => ['required', 'string', 'max:32'],
        ]);

        try {
            $result = $pairing->complete($validated);
        } catch (InvalidArgumentException) {
            return response()->json([
                'message' => 'Pairing code is invalid, expired, or does not match this Website.',
            ], 422);
        }

        return response()->json([
            'data' => [
                ...$result,
                'schema_version' => 1,
                'signature_algorithm' => 'hmac-sha256',
            ],
        ], 201);
    }
}
