<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\MetaLeadgenIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Faz 14 — Meta Lead Ads webhook for the agency's own Facebook page. GET answers Meta's subscription challenge
 * (verify token); POST is signed with the app secret (X-Hub-Signature-256) and each lead is read from the Graph
 * API into the agency lead inbox. Not configured → 404.
 */
final class MetaLeadgenWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('moxdop-leads.meta.verify_token', '');
        abort_if($token === '', 404);
        abort_unless($request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, MetaLeadgenIntake $intake): JsonResponse
    {
        $secret = (string) config('moxdop-leads.meta.app_secret', '');
        abort_if($secret === '', 404);
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        abort_unless(hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature), 403);

        return response()->json(['received' => $intake->handle((array) $request->json()->all())]);
    }
}
