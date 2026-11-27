<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fatura bilgileri from the old Moxportal client list (moximu.com/moxportal, read 2026-11-27 at yakup's word "burada
 * bazı markaların bilgileri var"). Only what that list holds: unvan, vergi no, adres. Vergi dairesi and fatura
 * e-postası were not there (its e-mails are placeholders), so they stay empty for yakup to fill. Each row is written
 * only when the brand id still carries the expected name, and only into fields that are still empty.
 */
return new class extends Migration
{
    private const array ROWS = [
        // brand id, a word the brand name must contain, unvan, vergi no, adres, ilçe / il
        [5, 'panorama', 'Panorama Ankara Ağız ve Diş Sağlığı Hizmetleri Limited Şirketi', '9971719846', null, 'Ankara'],
        [7, 'avrupadent', 'Den-Ay Diş ve Protez Özel Sağlık Tic. Ltd. Şti.', '2910808037', 'Yiğitler Mahallesi Menderes Caddesi No: 220', 'Buca / İzmir'],
        [11, 'dentocare', 'Dentocare Sağlık Hizmetleri San. ve Tic. Ltd. Şti.', '2921119072', 'Şehit Abdullah Çavuş Mah. Mustafa Or. Cad. Güliz Hanım Apt. No: 12C', 'Onikişubat / Kahramanmaraş'],
        [12, 'öncül', 'Dent Çayyolu Genel Sağlık Hizmetleri Ticaret Limited Şirketi', '2921328587', null, 'Çankaya / Ankara'],
        [13, 'suada', 'Suada Gasimova', '3891050803', 'Ataköy 7-8-9-10. Kısım Mah. Çobançeşme E-5 Yan Yol Cad. Ataköy Towers A Blok No: 20/1 İç Kapı No: 181', 'Bakırköy / İstanbul'],
        [14, 'opc', 'OPC Clinic Ezay Ağız ve Diş Sağlığı Hizmetleri Ticaret Limited Şirketi', '3840790952', null, 'Erenköy / İstanbul'],
        [16, 'burcu', 'Burcu Kısa', '5580442139', 'Mustafa Kemal Mah. 2118 Cad. No: 4 C İç Kapı No: 115', 'Çankaya / Ankara'],
        [17, 'eslem', 'Eslem Şahan Divanoğlu', '7890096827', 'Doğu Mah. Tazegül Sk. No: 9 A', 'Pendik / İstanbul'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'tax_no') || ! Schema::hasTable('brands')) {
            return;
        }
        foreach (self::ROWS as [$brandId, $word, $title, $taxNo, $address, $city]) {
            $brand = DB::table('brands')->where('id', $brandId)->first(['id', 'name', 'customer_id']);
            if ($brand === null || $brand->customer_id === null || ! str_contains(mb_strtolower((string) $brand->name), $word)) {
                continue;
            }
            $customer = DB::table('customers')->where('id', $brand->customer_id)->first();
            if ($customer === null) {
                continue;
            }
            $fill = array_filter([
                'legal_name' => blank($customer->legal_name) ? $title : null,
                'tax_no' => blank($customer->tax_no) ? $taxNo : null,
                'billing_address' => blank($customer->billing_address) ? $address : null,
                'billing_city' => blank($customer->billing_city) ? $city : null,
            ]);
            if ($fill === []) {
                continue;
            }
            DB::table('customers')->where('id', $customer->id)->update($fill + ['billing_source' => 'moxportal', 'billing_updated_at' => now()]);
            if (isset($fill['tax_no']) && Schema::hasTable('agreements')) {
                DB::table('agreements')->where('brand_id', $brandId)->whereNull('tax_no')->update(['tax_no' => $taxNo]);
            }
        }
    }

    public function down(): void {}
};
