<?php

namespace App\Support\Operator;

/**
 * Turns a collection error category (CollectionErrorCategory values, the ResourceAutomation stop reasons and
 * integration auth states) into what went wrong and the matching fix, in plain Turkish.
 *
 * `kind` tells the caller which button fits: reconnect (provider consent screen), grant_access (someone must give the
 * connected user access to the account, or enable the API it is read with), wait (it resumes by itself), retry
 * ("Şimdi güncelle"), developer (a software fix; retrying does not help), bind (connect the account to an asset),
 * customer (the customer is passive).
 */
final class CollectionErrorExplainer
{
    /**
     * @return array{problem: string, fix: string, kind: string}
     */
    public static function explain(?string $category, ?string $provider = null, ?string $accountEmail = null): array
    {
        $company = self::company($provider);
        $user = $accountEmail !== null && $accountEmail !== '' ? $accountEmail.' kullanıcısına' : 'bağlantıyı yapan '.$company.' kullanıcısına';

        return match (self::normalize($category)) {
            'auth' => [
                'problem' => $company.' bağlantısının izni sona ermiş veya geri alınmış',
                'fix' => $company.' bağlantısını yenileyin: "Yeniden bağlan" ile izin ekranını açıp aynı kullanıcıyla onaylayın. Bağlantı yenilenince durmuş çekimler kendiliğinden devam eder.',
                'kind' => 'reconnect',
            ],
            'permission' => [
                'problem' => 'Hesaba erişim yetkisi yok',
                'fix' => 'Hesabın sahibinden (müşteri ya da hesap yöneticisi) '.$user.' bu hesapta en az okuma yetkisi vermesini isteyin; yetki verilince "Şimdi güncelle" ile tekrar deneyin.',
                'kind' => 'grant_access',
            ],
            'api_disabled' => [
                'problem' => 'Google Cloud projesinde bu veriyi okuyan API kapalı',
                'fix' => 'Google bağlantısının kurulduğu Google Cloud projesinde API Kitaplığı\'ndan ilgili API\'yi etkinleştirin; etkinleşince "Şimdi güncelle" ile tekrar deneyin.',
                'kind' => 'grant_access',
            ],
            'quota' => [
                'problem' => $company.' günlük istek kotası doldu',
                'fix' => 'Bir şey yapmanıza gerek yok: kota gece sıfırlanınca çekim yarın kendiliğinden devam eder.',
                'kind' => 'wait',
            ],
            'rate_limit' => [
                'problem' => $company.' çok sık istek yapıldığı için çekimi geçici olarak yavaşlattı',
                'fix' => 'Bir şey yapmanıza gerek yok: sistem birkaç dakika bekleyip kendiliğinden yeniden dener. Birkaç saat içinde düzelmezse "Şimdi güncelle" ile tekrar deneyin.',
                'kind' => 'wait',
            ],
            'not_found' => [
                'problem' => 'Hesap artık bulunamıyor (silinmiş, taşınmış ya da bağlı kullanıcının erişimi kaldırılmış)',
                'fix' => 'Hesap hâlâ kullanılıyorsa erişimi yeniden isteyin ve Entegrasyonlar\'dan hesapları yeniden keşfedin; kullanılmıyorsa varlığın Veri kaynakları sayfasından bağlantıyı kaldırın.',
                'kind' => 'grant_access',
            ],
            'timeout' => [
                'problem' => $company.' zamanında yanıt vermedi (zaman aşımı / ağ hatası)',
                'fix' => '"Şimdi güncelle" ile tekrar deneyin. Sorun sürerse '.$company.' tarafında geçici bir kesinti olabilir; birkaç saat sonra kendiliğinden düzelir.',
                'kind' => 'retry',
            ],
            'provider' => [
                'problem' => $company.' tarafında geçici bir hata oluştu',
                'fix' => '"Şimdi güncelle" ile tekrar deneyin. Genellikle kendiliğinden düzelir; bir gün boyunca sürerse yazılım ekibine haber verin.',
                'kind' => 'retry',
            ],
            'software' => [
                'problem' => 'MoxDOP\'un veri isteği veya kaydı hatalı (yazılım sorunu)',
                'fix' => 'Tekrar denemek işe yaramaz; yazılım ekibine bildirin. Düzeltme yayınlanınca "Şimdi güncelle" ile devam edin.',
                'kind' => 'developer',
            ],
            'cancelled' => [
                'problem' => 'Son çekim yarıda durduruldu',
                'fix' => '"Şimdi güncelle" ile çekimi yeniden başlatın.',
                'kind' => 'retry',
            ],
            'bind' => [
                'problem' => 'Hesap bir dijital varlığa bağlı değil',
                'fix' => 'Hesabı Entegrasyonlar › Hesaplar ekranından doğru markanın varlığına bağlayın; bağlanınca veriler otomatik gelir.',
                'kind' => 'bind',
            ],
            'customer_passive' => [
                'problem' => 'Hesabın bağlı olduğu müşteri veya varlık pasif',
                'fix' => 'Müşteriyle çalışmaya devam ediyorsanız müşteriyi aktif yapın; çekim kendiliğinden sürer. Çalışmıyorsanız bir şey yapmanıza gerek yok.',
                'kind' => 'customer',
            ],
            default => [
                'problem' => 'Çekim beklenmeyen bir hatayla durdu',
                'fix' => '"Şimdi güncelle" ile tekrar deneyin; yine başarısız olursa yazılım ekibine haber verin.',
                'kind' => 'retry',
            ],
        };
    }

    /** Groups the many raw categories / reasons into the handful of fixes above. */
    public static function normalize(?string $category): string
    {
        $value = strtolower(trim((string) $category));

        return match (true) {
            in_array($value, ['authentication', 'reconnect', 'refresh_required', 'reauth_required', 'revoked', 'expired', 'invalid', 'wrong_app', 'reconnect_required', 'unauthenticated', 'invalid_grant'], true) => 'auth',
            in_array($value, ['authorization', 'permission', 'permission_required', 'permission_denied', 'forbidden', 'resource_unavailable'], true) => 'permission',
            in_array($value, ['not_found', 'notfound', '404'], true) => 'not_found',
            in_array($value, ['service_disabled', 'api_disabled'], true) => 'api_disabled',
            $value === 'quota' => 'quota',
            in_array($value, ['rate_limit', 'rate_limited', 'throttled'], true) => 'rate_limit',
            in_array($value, ['timeout', 'network'], true) => 'timeout',
            in_array($value, ['provider_5xx', 'provider_error', 'server_error'], true) => 'provider',
            in_array($value, ['invalid_request', 'contract_mismatch', 'unimplemented_capability', 'normalization', 'persistence', 'request_requires_fix'], true) => 'software',
            $value === 'cancelled' => 'cancelled',
            in_array($value, ['binding', 'unbound'], true) => 'bind',
            $value === 'customer_passive' => 'customer_passive',
            default => 'unknown',
        };
    }

    public static function company(?string $provider): string
    {
        return match (strtolower((string) $provider)) {
            'meta', 'meta_ads', 'facebook' => 'Meta',
            'dataforseo' => 'DataForSEO',
            'openai' => 'OpenAI',
            default => 'Google',
        };
    }

    /** Provider (google / meta) of an account type. */
    public static function providerOf(?string $resourceType): string
    {
        return in_array(strtolower((string) $resourceType), ['meta_ads', 'meta', 'instagram'], true) ? 'meta' : 'google';
    }
}
