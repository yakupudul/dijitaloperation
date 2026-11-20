<?php

namespace App\Panel\Http\Controllers;

use App\Panel\Models\PanelBrandSetting;
use App\Panel\Services\PanelLeads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where a brand's site form sends a request. The address carries the brand's secret token; the brand is read from
 * the token alone, never from the posted fields.
 */
final class PanelIntakeController
{
    public function __invoke(Request $request, string $token, PanelLeads $leads): JsonResponse
    {
        $setting = PanelBrandSetting::query()->where('form_token', $token)->where('is_enabled', true)->first();
        abort_if($setting === null, 404);
        // A filled trap field is a robot: answer as if it worked, write nothing.
        if (trim((string) $request->input('website', '')) !== '') {
            return $this->ok();
        }
        $pick = static function (array $names) use ($request): string {
            foreach ($names as $name) {
                $value = $request->input($name);
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return mb_substr(trim((string) $value), 0, 2000);
                }
            }

            return '';
        };
        $name = $pick(['name', 'ad', 'adsoyad', 'ad_soyad', 'isim', 'fullname', 'your-name']);
        $phone = $pick(['phone', 'telefon', 'tel', 'gsm', 'your-tel', 'your-phone']);
        $email = $pick(['email', 'eposta', 'e-posta', 'your-email']);
        if ($phone === '' && $email === '') {
            return response()->json(['ok' => false, 'message' => 'Telefon ya da e-posta gerekli.'], 422)->withHeaders($this->cors());
        }
        $leads->record((int) $setting->brand_id, [
            'name' => mb_substr($name, 0, 190), 'phone' => mb_substr($phone, 0, 40), 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 190) : null,
            'interest' => mb_substr($pick(['interest', 'service', 'hizmet', 'konu', 'subject', 'your-subject']), 0, 190) ?: null,
            'message' => $pick(['message', 'mesaj', 'not', 'your-message']) ?: null,
            'city' => mb_substr($pick(['city', 'sehir', 'il']), 0, 100) ?: null,
            'meta' => array_filter([
                'page' => mb_substr($pick(['page_url', 'page', 'sayfa']) ?: (string) $request->headers->get('referer', ''), 0, 500),
                'utm_source' => mb_substr($pick(['utm_source']), 0, 100), 'utm_medium' => mb_substr($pick(['utm_medium']), 0, 100),
                'utm_campaign' => mb_substr($pick(['utm_campaign']), 0, 150), 'gclid' => mb_substr($pick(['gclid']), 0, 200), 'fbclid' => mb_substr($pick(['fbclid']), 0, 200),
            ]),
        ], 'web_form');

        return $this->ok();
    }

    public function options(): JsonResponse
    {
        return response()->json(null, 204)->withHeaders($this->cors());
    }

    private function ok(): JsonResponse
    {
        return response()->json(['ok' => true])->withHeaders($this->cors());
    }

    /** @return array<string, string> */
    private function cors(): array
    {
        return ['Access-Control-Allow-Origin' => '*', 'Access-Control-Allow-Methods' => 'POST, OPTIONS', 'Access-Control-Allow-Headers' => 'Content-Type, Accept'];
    }
}
