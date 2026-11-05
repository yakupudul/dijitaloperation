<?php

namespace App\Http\Controllers\Sales;

use App\Services\Sales\LeadIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Site forms (moximu.com first): the site's server posts each submission here with the token from Satış › Form
 * bağlantısı. The token never reaches a browser; the site keeps it in its own settings. A card opens (or the known
 * card is added to) and the operator is told at once in the day, at 08:30 for the night.
 */
final class LeadFormController
{
    public function __invoke(Request $request, LeadIntake $intake): JsonResponse
    {
        $expected = LeadIntake::token();
        $given = (string) ($request->header('X-Moxdop-Token') ?: $request->input('token', ''));
        if ($expected === null || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['ok' => false, 'error' => 'Yetki gerekli.'], 401);
        }
        if ((string) $request->input('website_url_confirm', '') !== '') {
            // The theme's hidden honeypot field: a bot filled it, nothing is opened.
            return response()->json(['ok' => true], 202);
        }
        try {
            $lead = $intake->fromForm($request->except(['token', 'website_url_confirm']), $request->input('source') === 'meta_form' ? 'meta_form' : 'form');
        } catch (ValidationException $error) {
            return response()->json(['ok' => false, 'errors' => $error->errors()], 422);
        }

        return response()->json(['ok' => true, 'lead' => $lead->id], 201);
    }
}
