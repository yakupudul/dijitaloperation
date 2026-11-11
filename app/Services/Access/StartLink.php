<?php

namespace App\Services\Access;

use App\Models\AccessLink;
use App\Models\AgencySetting;
use App\Models\Agreement;
use App\Services\Assistant\PushNotifier;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tek bağlantıyla başlangıç (Satış Düzeni 3.2). The brand's access link also carries the start of the work when the
 * brand has a running agreement: the customer writes his billing details, reads and accepts the service contract,
 * sees where to pay (havale to the agency's account) and marks "Ödemeyi yaptım", then goes on with the accesses. The
 * operator sends the link himself; nothing here sends anything or moves money. An acceptance writes the agreement's
 * contract day; a payment mark only tells the operator, who ticks the payment after seeing it in his account.
 */
final class StartLink
{
    public const int VAT = 20;

    public const array PARTY = ['title' => 'Unvan ya da ad soyad', 'tax_office' => 'Vergi dairesi', 'tax_no' => 'Vergi no ya da T.C. kimlik no', 'address' => 'Fatura adresi', 'email' => 'Fatura e-postası', 'person' => 'Yetkili ad soyad'];

    public function __construct(private readonly PushNotifier $push) {}

    /** @return array{holder: string, iban: string, bank: string} */
    public function bank(): array
    {
        $stored = SchemaState::hasColumn('agency_settings', 'bank') ? json_decode((string) (DB::table('agency_settings')->value('bank') ?? '{}'), true) : [];

        return ['holder' => trim((string) ($stored['holder'] ?? '')), 'iban' => trim((string) ($stored['iban'] ?? '')), 'bank' => trim((string) ($stored['bank'] ?? ''))];
    }

    public function contractText(): string
    {
        $stored = SchemaState::hasColumn('agency_settings', 'contract_text') ? trim((string) DB::table('agency_settings')->value('contract_text')) : '';

        return $stored !== '' ? $stored : self::defaultContract();
    }

    public function save(string $holder, string $iban, string $bank, string $contract): void
    {
        $iban = mb_strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
        if ($iban !== '' && preg_match('/^TR\d{24}$/', $iban) !== 1) {
            throw new \InvalidArgumentException('IBAN TR ile başlar ve 26 hanedir.');
        }
        $settings = AgencySetting::query()->first() ?? new AgencySetting;
        $contract = trim($contract);
        $settings->forceFill(['bank' => json_encode(['holder' => trim($holder), 'iban' => $iban, 'bank' => trim($bank)]),
            'contract_text' => $contract === '' || $contract === self::defaultContract() ? null : $contract])->save();
    }

    public function agreement(AccessLink $link): ?Agreement
    {
        return SchemaState::hasTable('agreements') ? Agreement::query()->where('brand_id', $link->brand_id)->where('status', 'active')->orderByDesc('id')->first() : null;
    }

    /**
     * What the start part of the page shows; null when the brand has no running agreement (then the link is accesses only).
     *
     * @return array{party: array<string, string>, party_done: bool, contract: string, accepted_at: ?string, bank: array{holder: string, iban: string, bank: string}, amount: ?string, paid_at: ?string}|null
     */
    public function of(AccessLink $link): ?array
    {
        $agreement = $this->agreement($link);
        if ($agreement === null || ! SchemaState::hasColumn('access_links', 'start')) {
            return null;
        }
        $start = (array) $link->start;
        $party = array_map('strval', (array) ($start['party'] ?? []));
        $fee = (int) $agreement->fee;

        return [
            'party' => $party, 'party_done' => filled($party['title'] ?? null) && filled($party['tax_no'] ?? null),
            'contract' => $this->render($link, $agreement, $party), 'accepted_at' => $start['accepted_at'] ?? null,
            'bank' => $this->bank(), 'paid_at' => $start['paid_at'] ?? null,
            'amount' => $fee > 0 ? number_format($fee * (100 + self::VAT) / 100, 0, ',', '.').' TL ('.number_format($fee, 0, ',', '.').' TL + %'.self::VAT.' KDV)' : null,
        ];
    }

    /** @param  array<string, mixed>  $input */
    public function saveParty(AccessLink $link, array $input): void
    {
        if (($link->start['accepted_at'] ?? null) !== null) {
            return;
        }
        $party = [];
        foreach (array_keys(self::PARTY) as $key) {
            $party[$key] = mb_substr(trim((string) ($input[$key] ?? '')), 0, $key === 'address' ? 400 : 160);
        }
        $link->forceFill(['start' => ['party' => $party] + (array) $link->start])->save();
    }

    /** "Okudum, kabul ediyorum": the accepted text, the time and the address it came from are kept; the agreement gets its contract day. */
    public function accept(AccessLink $link, ?string $ip): void
    {
        $state = $this->of($link);
        if ($state === null || ! $state['party_done'] || $state['accepted_at'] !== null) {
            return;
        }
        $link->forceFill(['start' => ['accepted_at' => now()->toIso8601String(), 'accepted_ip' => $ip, 'accepted_text' => $state['contract']] + (array) $link->start])->save();
        $agreement = $this->agreement($link);
        if ($agreement !== null && $agreement->contract_on === null) {
            $agreement->forceFill(['contract_on' => CarbonImmutable::now('Europe/Istanbul')->toDateString()])->save();
        }
        $this->push->send('start-link:'.$link->id.':contract', ($link->brand?->name ?? 'Marka').': sözleşme onaylandı',
            ($state['party']['title'] ?? 'Müşteri').' sözleşmeyi bağlantıdan onayladı.', 'high', route('operator.brand', ['brand' => $link->brand_id]));
    }

    /** "Ödemeyi yaptım": told to the operator; the payment itself is ticked by him after he sees it. */
    public function paid(AccessLink $link): void
    {
        $state = $this->of($link);
        if ($state === null || $state['accepted_at'] === null || $state['paid_at'] !== null) {
            return;
        }
        $link->forceFill(['start' => ['paid_at' => now()->toIso8601String()] + (array) $link->start])->save();
        $this->push->send('start-link:'.$link->id.':paid', ($link->brand?->name ?? 'Marka').': ödemeyi yaptım diyor',
            'Havaleyi hesabında gör, sonra ilk ödemeyi işaretle.', 'high', route('operator.brand', ['brand' => $link->brand_id]));
    }

    /** @param  array<string, string>  $party */
    private function render(AccessLink $link, Agreement $agreement, array $party): string
    {
        $bank = $this->bank();
        $services = implode(', ', array_filter(array_map('strval', (array) $agreement->services)));
        $fee = (int) $agreement->fee;

        return strtr($this->contractText(), [
            '{hizmet_veren}' => $bank['holder'] !== '' ? $bank['holder'] : 'Moximu',
            '{musteri}' => filled($party['title'] ?? null) ? $party['title'] : (string) ($link->brand?->name ?? 'Müşteri'),
            '{marka}' => (string) ($link->brand?->name ?? ''),
            '{hizmetler}' => $services !== '' ? $services : 'tarafların yazılı olarak kararlaştırdığı dijital pazarlama hizmetleri',
            '{ucret}' => $fee > 0 ? number_format($fee, 0, ',', '.').' TL + KDV' : 'tarafların yazılı olarak kararlaştırdığı ücret',
            '{odeme_gunu}' => (string) ((int) $agreement->due_day ?: 1),
            '{baslangic}' => $agreement->starts_on?->locale('tr')->isoFormat('D MMMM YYYY') ?? CarbonImmutable::now('Europe/Istanbul')->locale('tr')->isoFormat('D MMMM YYYY'),
            '{iban}' => $bank['iban'] !== '' ? $bank['iban'] : 'ayrıca bildirilecek hesap',
        ]);
    }

    public static function defaultContract(): string
    {
        return <<<'TXT'
HİZMET SÖZLEŞMESİ

1. Taraflar
Hizmet veren: {hizmet_veren} ("Moximu"). Müşteri: {musteri}. Sözleşme {marka} markası için yapılmıştır.

2. Konu
Moximu, müşteriye şu hizmetleri verir: {hizmetler}. Kapsam dışında kalan işler ayrıca konuşulur ve yazılı olarak kararlaştırılır.

3. Süre
Sözleşme {baslangic} tarihinde başlar ve aylık olarak yenilenir. Asgari süre yoktur.

4. Ücret ve ödeme
Aylık hizmet bedeli {ucret} tutarındadır. Ödeme her ayın {odeme_gunu}. günü, o aya ait hizmet için peşin olarak havale ya da EFT ile yapılır. Hesap: {hizmet_veren}, IBAN {iban}. Reklam platformlarına ödenen reklam bütçesi bu bedelin dışındadır ve doğrudan müşterinin kendi hesabından ödenir.

5. Erişimler ve hesapların sahipliği
Müşteri, işin yapılabilmesi için gereken hesaplarda Moximu'ya yetki verir. Web sitesi, alan adı, Google ve Meta hesapları ile bu hesaplardaki bütün veriler müşteriye aittir. Sözleşme bittiğinde Moximu bu hesaplardaki yetkilerini bırakır.

6. Müşterinin sorumlulukları
Müşteri, işin gerektirdiği bilgi, görsel ve onayları makul sürede verir. Yayınlanan içeriklerde müşterinin verdiği bilgilerin doğruluğundan müşteri sorumludur. Sağlık alanındaki müşteriler için içerikler ilgili tanıtım ve bilgilendirme mevzuatına uygun hazırlanır; müşterinin mevzuata aykırı bir talebi yerine getirilmez.

7. Sonuçlar hakkında
Moximu işi özenle ve düzenli raporlayarak yapar. Arama sıralaması, başvuru sayısı ya da ciro gibi sonuçlar üçüncü taraf platformlara ve pazar koşullarına bağlı olduğundan belirli bir sonuç taahhüt edilmez.

8. Gizlilik ve kişisel veriler
Taraflar, bu iş nedeniyle öğrendikleri ticari bilgileri gizli tutar. Moximu, müşterinin hesaplarında gördüğü kişisel verileri yalnız bu sözleşmedeki hizmeti vermek için ve 6698 sayılı Kanun'a uygun biçimde işler, üçüncü kişilerle paylaşmaz.

9. Sona erme
Taraflardan her biri, bir sonraki ödeme gününden en az 15 gün önce yazılı olarak (e-posta ya da WhatsApp yeterlidir) bildirerek sözleşmeyi sona erdirebilir. Ödenmiş ayın hizmeti ay sonuna kadar verilir, o aya ait bedel iade edilmez. Ödemenin 15 günden fazla gecikmesi halinde Moximu hizmeti durdurabilir.

10. Diğer
Bu sözleşme elektronik ortamda onaylanmıştır; onay zamanı ve onaylanan metin kayıt altındadır. Uyuşmazlıklarda Manisa mahkemeleri ve icra daireleri yetkilidir.
TXT;
    }
}
