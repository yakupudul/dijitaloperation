<?php

namespace Tests\Feature\Work;

use App\Livewire\Operator\Work\WorkPage;
use App\Models\AssetAlert;
use App\Models\DigitalAsset;
use App\Models\PushSubscription;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Assistant\PushNotifier;
use App\Services\Push\WebPush;
use App\Services\Work\WorkVerifier;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\CreatesCanonicalPortfolio;
use Tests\TestCase;

/** Genel işler: six tabs over suggestions + alerts, "Yaptım" and the system's own check, phone notifications. */
class WorkDeskTest extends TestCase
{
    use CreatesCanonicalPortfolio;
    use RefreshDatabase;

    private User $admin;

    private DigitalAsset $ads;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['locale' => 'tr']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->seedCanonicalPortfolio();
        $this->ads = $this->createPortfolioAsset('google_ads', 'Northwind Ads', ['module_id' => 'google-ads', 'status' => 'active']);
        $this->site = $this->createPortfolioAsset('website', 'Northwind Website', ['status' => 'active']);
    }

    public function test_each_tab_lists_its_own_work_most_urgent_first(): void
    {
        $this->suggestion('google_ads', 'google_ads', $this->ads->id, 'ads_check', 'Dönüşüm izleme yok', ['check' => 'conversion_tracking'], 1);
        $this->suggestion('search', 'site', $this->site->id, 'content', 'İmplant fiyatları rehberi', ['site_id' => $this->site->id], 20);
        $this->suggestion('search', 'site', $this->site->id, 'title_description', 'Ana sayfa başlığı çok uzun', [], 5);
        AssetAlert::query()->create(['digital_asset_id' => $this->site->id, 'brand_id' => $this->site->brand_id, 'alert_key' => 'k1', 'kind' => 'site_down',
            'severity' => 'critical', 'title' => 'Site erişilemiyor', 'message' => '5xx', 'data' => [], 'first_detected_at' => now(), 'last_detected_at' => now()]);

        $this->get(route('operator.work'))->assertOk()->assertSee('Genel işler')->assertSee('Web site SEO içerikler')
            ->assertSee('İmplant fiyatları rehberi')->assertSee('Başlık onayı bekliyor')->assertDontSee('Ana sayfa başlığı çok uzun')
            ->assertSee('Telefona bildirim aç', false);

        Livewire::test(WorkPage::class)->call('setTab', 'teknik')->assertSee('Ana sayfa başlığı çok uzun')->assertDontSee('İmplant fiyatları rehberi')
            ->call('setTab', 'saglik')->assertSee('Site erişilemiyor')->assertSee('Acil')
            ->call('setTab', 'ads')->assertSee('Dönüşüm izleme yok')->assertSee('Yaptım');
    }

    public function test_done_waits_for_the_system_and_a_passing_check_closes_work_by_itself(): void
    {
        $tracked = $this->suggestion('google_ads', 'google_ads', $this->ads->id, 'ads_check', 'Dönüşüm izleme yok', ['check' => 'conversion_tracking'], 1);
        $budget = $this->suggestion('google_ads', 'google_ads', $this->ads->id, 'ads_check', 'Bütçe yetmiyor', ['check' => 'budget'], 2);
        $policy = $this->suggestion('google_ads', 'google_ads', $this->ads->id, 'ads_check', 'Reklam reddedildi', ['check' => 'policy'], 2);

        Livewire::test(WorkPage::class, ['tab' => 'ads'])->call('done', $tracked->id)->assertSee('sistem bir sonraki veri çekiminde kontrol edecek')
            ->call('done', $policy->id);
        $this->assertSame([Suggestion::APPLIED, Suggestion::VERIFY_PENDING], [$tracked->fresh()->status, $tracked->fresh()->verification]);

        $verifier = app(WorkVerifier::class);
        // Same-day pull: too early to call the change failed.
        $counts = $verifier->checks($this->ads, 'google_ads', 'ads_check', ['conversion_tracking', 'budget'], ['policy']);
        $this->assertSame(['auto' => 1, 'confirmed' => 1, 'still_seen' => 0], $counts);
        $this->assertSame(Suggestion::VERIFY_CONFIRMED, $tracked->fresh()->verification);
        $this->assertSame([Suggestion::APPLIED, Suggestion::VERIFY_AUTO], [$budget->fresh()->status, $budget->fresh()->verification]);
        $this->assertSame(Suggestion::VERIFY_PENDING, $policy->fresh()->verification);

        $this->travel(13)->hours();
        $verifier->checks($this->ads, 'google_ads', 'ads_check', [], ['policy']);
        $this->assertSame(Suggestion::VERIFY_STILL_SEEN, $policy->fresh()->verification);

        // No data for a check changes nothing.
        $this->assertSame(['auto' => 0, 'confirmed' => 0, 'still_seen' => 0], $verifier->checks($this->ads, 'google_ads', 'ads_check', [], []));

        Livewire::test(WorkPage::class, ['tab' => 'ads', 'view' => 'yapildi'])->assertSee('Sistem doğruladı')->assertSee('Sistem kendisi fark etti')
            ->assertSee('Sistem hâlâ görüyor')->call('reopen', $policy->id);
        $this->assertSame(Suggestion::OPEN, $policy->fresh()->status);
    }

    public function test_manual_work_cannot_be_approved_but_site_work_can(): void
    {
        $meta = $this->suggestion('meta', 'meta', $this->ads->id, 'meta_check', 'Pixel bağlı değil', ['check' => 'pixel'], 1);
        $title = $this->suggestion('search', 'site', $this->site->id, 'content', 'Diş beyazlatma rehberi', ['site_id' => $this->site->id], 3);

        Livewire::test(WorkPage::class)->call('approve', $meta->id)->assertSee('Bu iş elle yapılır')
            ->call('approve', $title->id)->assertSee('Başlık onaylandı');
        $this->assertSame(Suggestion::APPROVED, $title->fresh()->status);
        $this->assertSame(Suggestion::OPEN, $meta->fresh()->status);

        Livewire::test(WorkPage::class, ['tab' => 'meta'])->call('snooze', 'suggestion', $meta->id)->assertSee('7 gün ertelendi');
        $this->assertSame(Suggestion::SNOOZED, $meta->fresh()->status);
    }

    public function test_setup_overlap_and_draft_work_use_their_own_buttons(): void
    {
        $gap = $this->suggestion('search', 'brand', $this->portfolioBrand->id, 'brand_gap', '2 hizmet bölgesi bulundu',
            ['gap' => 'areas', 'fix' => 'add_areas', 'params' => ['areas' => []], 'url' => null], 1);
        $manualGap = $this->suggestion('search', 'brand', $this->portfolioBrand->id, 'brand_gap', 'Sektör seçilmemiş',
            ['gap' => 'no_sector', 'fix' => null, 'params' => [], 'url' => 'https://moxdop.test/brands/1/setup'], 2);
        $audit = $this->suggestion('search', 'brand', $this->portfolioBrand->id, 'brand_audit', '3 sayfa yanlış kümede', ['check' => 'cluster_pages', 'items' => [5, 6]], 1);
        $overlap = $this->suggestion('search', 'site', $this->site->id, 'cluster_overlap', 'Çakışma: implant', ['site_id' => $this->site->id, 'recommendation' => 'differentiate'], 3);
        $draft = $this->suggestion('search', 'site', $this->site->id, 'content', 'İmplant bakımı', ['site_id' => $this->site->id, 'article' => ['title' => 'İmplant bakımı']], 3);
        $draft->forceFill(['status' => Suggestion::APPROVED])->save();

        Livewire::test(WorkPage::class)->assertDontSee('2 hizmet bölgesi bulundu')->assertSee('Çakışma: implant')->assertSee('Ayrı kalsın')
            ->assertDontSee('301 ile birleştir')->assertSee('Yazı hazır · okunacak')->assertSee('WordPress\'e taslak gönder')->assertSee('Yazıyı oku')
            ->call('setTab', 'kurulum')->assertSee('2 hizmet bölgesi bulundu')->assertSee('Onayla ve yap')->assertSee('Elle yap')
            ->assertSee('Doğru, bırak')->assertDontSee('wire:click="done('.$gap->id.')"', false)
            ->call('done', $gap->id)->assertSee('sistem kendisi kapatır')
            ->call('approve', $gap->id)->assertSee('kendi düğmesi')
            ->call('run', $manualGap->id, 'gap_fix')->assertSee('bu adım yok')
            ->call('run', $gap->id, 'gap_fix')->assertSee('0 hizmet bölgesi eklendi')
            ->call('run', $audit->id, 'audit_accept')->assertSee('Doğru kabul edildi');
        $this->assertSame(Suggestion::APPLIED, $gap->fresh()->status);
        $this->assertSame(Suggestion::OPEN, $manualGap->fresh()->status);
        $this->assertSame([Suggestion::DISMISSED, [5, 6]], [$audit->fresh()->status, $audit->fresh()->action['accepted']]);

        Livewire::test(WorkPage::class)->call('run', $overlap->id, 'merge')->assertSee('bu adım yok')->call('run', $overlap->id, 'keep');
        $this->assertSame(Suggestion::DISMISSED, $overlap->fresh()->status);
    }

    public function test_a_reopened_item_does_not_show_its_old_done_time(): void
    {
        $gap = $this->suggestion('search', 'brand', $this->portfolioBrand->id, 'brand_gap', '6 hizmet hiçbir sayfayla eşleşmemiş', ['fix' => 'site_setup', 'params' => []], 1);
        $gap->forceFill(['applied_at' => now()->subHour()])->save();

        Livewire::test(WorkPage::class, ['tab' => 'kurulum'])->assertSee('6 hizmet hiçbir sayfayla eşleşmemiş')->assertDontSee('· yapıldı', false);
    }

    public function test_a_phone_subscribes_and_only_important_work_alerts_are_pushed(): void
    {
        $this->postJson(route('push.subscribe'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => 'BPk', 'auth' => 'au']])->assertOk();
        $this->assertSame(1, PushSubscription::query()->count());
        $key = $this->getJson(route('push.key'))->assertOk()->json('key');
        $this->assertSame(65, strlen((string) base64_decode(strtr($key, '-_', '+/'))));

        $sent = [];
        $status = 201;
        Http::fake(function (Request $request) use (&$sent, &$status) {
            $sent[] = $request;

            return Http::response('', $status);
        });
        $notifier = app(PushNotifier::class);
        $this->assertSame(1, $notifier->send('alert:1:site_down', 'Site erişilemiyor', 'northwind.test yanıt vermiyor', 'critical', route('operator.work'), 1));
        $this->assertSame(0, $notifier->send('app-error:x', 'Uygulama hatası', 'TypeError', 'critical'), 'software errors do not go to phones');
        $this->assertSame(0, $notifier->send('alert:1:low', 'Eklenti eski', 'x', 'medium'), 'only high / critical');
        $this->assertCount(1, $sent);
        $this->assertStringStartsWith('vapid t=', $sent[0]->header('Authorization')[0]);
        $this->assertSame('', $sent[0]->body());
        $this->assertJwtVerifies(substr(explode(',', $sent[0]->header('Authorization')[0])[0], 8), $key);

        $this->getJson(route('push.latest'))->assertOk()->assertJson(['title' => 'Site erişilemiyor', 'url' => route('operator.work')]);

        $status = 410;
        $this->assertSame(0, $notifier->browser('alert:2', 'Bütçe bitti', 'x', 'critical', null));
        $this->assertSame(0, PushSubscription::query()->count(), 'an expired subscription is removed');
        $this->assertSame('failed', DB::table('push_notifications')->orderByDesc('id')->value('status'));
    }

    private function assertJwtVerifies(string $jwt, string $publicKey): void
    {
        [$header, $claims, $signature] = explode('.', $jwt);
        $raw = base64_decode(strtr($signature, '-_', '+/'));
        $integer = fn (string $bytes): string => "\x02".chr(strlen($v = (ord($bytes[0]) & 0x80 ? "\0" : '').ltrim($bytes, "\0") ?: "\0")).$v;
        $sequence = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32));
        $der = "\x30".chr(strlen($sequence)).$sequence;
        $point = base64_decode(strtr($publicKey, '-_', '+/'));
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n").'-----END PUBLIC KEY-----';
        $this->assertSame(1, openssl_verify($header.'.'.$claims, $der, $pem, OPENSSL_ALGO_SHA256));
        $this->assertSame('https://fcm.googleapis.com', json_decode((string) base64_decode(strtr($claims, '-_', '+/')), true)['aud']);
        $this->assertSame(64, strlen(WebPush::derToRaw($der)));
    }

    /** @param  array<string, mixed>  $action */
    private function suggestion(string $channel, string $targetType, int $targetId, string $type, string $title, array $action, int $priority): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->portfolioBrand->id, 'channel' => $channel, 'decision_key' => $type.':'.$title, 'fingerprint' => md5($channel.$title),
            'material_hash' => md5($title), 'title' => $title, 'reason' => 'Neden: '.$title, 'priority' => $priority, 'evidence' => [],
            'action_type' => $type, 'action' => $action, 'status' => Suggestion::OPEN, 'target_type' => $targetType, 'target_id' => $targetId,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }
}
