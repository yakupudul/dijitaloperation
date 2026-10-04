<?php

namespace App\Services\WhatsApp;

/**
 * Plain Turkish for what Meta reports during the WhatsApp connection: Graph API errors (code / subcode) and the
 * Embedded Signup popup steps. The raw Meta text stays in the technical details; this is what the operator reads.
 */
final class WhatsAppErrorText
{
    /** Embedded Signup popup steps (CANCEL current_step) in the operator's words. */
    public const STEPS = [
        'BUSINESS_ACCOUNT_SELECTION' => 'işletme portföyü seçimi',
        'BUSINESS_SELECTION' => 'işletme portföyü seçimi',
        'WABA_SELECTION' => 'WhatsApp hesabı seçimi',
        'WABA_PHONE_PROFILE_PICKER' => 'WhatsApp hesabı / numara seçimi',
        'WHATSAPP_BUSINESS_PROFILE_SETUP' => 'WhatsApp işletme profili bilgileri',
        'PHONE_NUMBER_SETUP' => 'telefon numarası ekleme',
        'PHONE_NUMBER_VERIFICATION' => 'numara doğrulama (SMS / arama kodu)',
        'WHATSAPP_BUSINESS_APP_ONBOARDING' => 'WhatsApp Business uygulamasıyla eşleştirme',
        'PERMISSIONS' => 'izin onayı',
        'LOGIN' => 'Facebook girişi',
    ];

    /** @param  array<string, mixed>  $error  details stored from WhatsAppGraphException or the signup report */
    public static function explain(array $error): string
    {
        $code = isset($error['code']) ? (int) $error['code'] : null;
        $subcode = isset($error['subcode']) ? (int) $error['subcode'] : null;

        return match (true) {
            $code === 190 && $subcode === 463 => 'Kayıtlı erişim anahtarının süresi dolmuş. Numarayı Meta ile yeniden bağlayınca yenilenir.',
            $code === 190 && $subcode === 460 => 'Facebook şifresi değiştiği için erişim anahtarı geçersiz oldu. Numarayı Meta ile yeniden bağlayın.',
            $code === 190 => 'Kayıtlı erişim anahtarı artık geçerli değil. Numarayı Meta ile yeniden bağlayın.',
            $code === 100 && $subcode === 33 => 'Kayıtlı WhatsApp hesabı veya numarası bulunamadı ya da bu uygulamanın erişimi yok. Numarayı yeniden bağlayın.',
            in_array($code, [10, 200, 294], true) => 'Meta uygulamasının bu işlem için izni yok. Bağlarken WhatsApp izinlerinin hepsini onaylayın.',
            in_array($code, [4, 17, 32, 80007, 130429, 613], true) => 'Meta istek sınırına takıldı. Birkaç dakika sonra tekrar deneyin.',
            $code === 368 => 'Meta bu hesabı geçici olarak engellemiş. WhatsApp Yöneticisi\'ndeki uyarıyı kontrol edin.',
            $code === 131031 => 'WhatsApp hesabı Meta tarafından kilitlenmiş. WhatsApp Yöneticisi\'ndeki uyarıyı kontrol edin.',
            $code === 133010 => 'Numara henüz WhatsApp Cloud API\'ye kayıtlı değil.',
            default => (string) ($error['message'] ?? 'Meta ayrıntı vermedi.'),
        };
    }

    /** True when the stored error means the saved access token no longer works (reconnect needed). */
    public static function tokenExpired(?array $error): bool
    {
        return is_array($error) && (int) ($error['code'] ?? 0) === 190;
    }

    public static function step(?string $step): string
    {
        $step = strtoupper(trim((string) $step));

        return self::STEPS[$step] ?? ($step !== '' ? mb_strtolower(str_replace('_', ' ', $step)) : 'bilinmeyen adım');
    }
}
