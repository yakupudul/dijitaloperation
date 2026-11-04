<?php

use App\Models\SalesLead;
use App\Services\Sales\SalesLeads;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Satış (2026-11-04): the warm prospects of the last weeks (from the WhatsApp reading of 31 October) get their cards on
 * deploy, and every inbox chat without a card gets one, so the first morning of Satış is not empty. Runs once; a card
 * that already exists (same site or phone) is only updated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests() || ! Schema::hasTable('sales_leads')) {
            return;
        }
        $leads = app(SalesLeads::class);
        $rows = [
            ['name' => 'Şaban Bey', 'clinic' => 'Klinik Ereğli', 'city' => 'Ereğli', 'website' => 'klinikeregli.com', 'source' => 'afc', 'note' => 'All for Clinics (Eyüp Bey) referansıyla 24 Eylül\'de yazdı; otomatik mesajın arkasında kaldı, cevap verilmedi. Notları: SEO için tasarlanmamış site, girişteki büyük video, sitemap ve llms.txt.'],
            ['name' => 'Nuri Hocam', 'clinic' => 'Dentafix', 'city' => 'İzmir', 'branch' => 'Diş', 'source' => 'referral', 'note' => '18 Eylül\'de Bayraklı şubede yüz yüze görüşüldü, sonra sessizlik.'],
            ['name' => 'Doğan Yılmaz', 'clinic' => 'Klinik Psikolog Doğan Yılmaz', 'branch' => 'Klinik psikoloji', 'source' => 'whatsapp', 'note' => 'Sıcak aday (WhatsApp).'],
            ['name' => 'Dr. Yaşar Özdemir', 'city' => 'Antalya', 'source' => 'whatsapp', 'note' => 'Sıcak aday (WhatsApp).'],
            ['name' => 'Dt. Gözde Zeyrek', 'city' => 'Ankara', 'branch' => 'Diş', 'source' => 'whatsapp', 'note' => 'Sıcak aday (WhatsApp).'],
            ['name' => 'Dr. Ercan Akpınar', 'city' => 'Bodrum', 'phone' => '+90 552 818 71 48', 'website' => 'drercanakpinar.com', 'source' => 'whatsapp', 'note' => '3 Kasım: Google İşletme Profili açılmasını ve fiyat istiyor.'],
            ['name' => 'Aslı Hocam', 'website' => 'aslialay.com.tr', 'source' => 'whatsapp', 'note' => '27 Ekim\'de inceleme gönderildi: iletişim sayfasında \"üroloji\" şema hatası, mobil başlıklar.'],
            ['name' => 'Fatih Hocam', 'source' => 'whatsapp', 'note' => '16 Eylül: hosting sorunu çözüldü; \"fiyat tahminimden yüksek, nasıl belirleyebiliriz\" dedi, fiyat savunuldu, sustu.'],
        ];
        foreach ($rows as $row) {
            try {
                $lead = $leads->quickAdd($row);
                if (isset($row['note']) && str_starts_with($row['note'], '16 Eylül') && $lead->stage === 'new') {
                    $leads->setStage($lead, 'priced', null, null, 'Fiyat konuşuldu (16 Eylül).');
                    $leads->setNextStep($lead, 'Fiyat sonrası takip', now('Europe/Istanbul')->toDateString());
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        try {
            $leads->scanConversations();
        } catch (Throwable $exception) {
            report($exception);
        }
        foreach (SalesLead::query()->whereNull('conversation_id')->whereNotNull('phone_key')->get() as $lead) {
            try {
                $leads->linkConversation($lead);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    public function down(): void
    {
        // The cards stay: they hold what was said to whom.
    }
};
