<?php

namespace Tests\Feature;

use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Services\Sales\WarmPoolWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Satış Düzeni 4.4: a silent prospect comes back only when something happened on its site, with the reason. */
final class WarmPoolWatchTest extends TestCase
{
    use RefreshDatabase;

    private function lead(string $host): SalesLead
    {
        $lead = new SalesLead;
        $lead->forceFill(['name' => 'Aday', 'website_host' => $host, 'stage' => 'new', 'last_contact_at' => now()->subDays(90),
            'next_step' => 'Yeniden ısıt: yeni bulgu ya da vaka ile yaz', 'next_step_at' => now()->addDays(40)])->save();

        return $lead;
    }

    public function test_a_first_look_at_a_working_site_is_not_an_event_and_a_site_that_stops_opening_is(): void
    {
        $lead = $this->lead('ornek-klinik.test');
        Http::fake(['*' => Http::response('<html><title>Örnek Klinik</title></html>', 200)]);

        $this->assertFalse(app(WarmPoolWatch::class)->look($lead));
        $this->assertSame(['state' => 'up', 'title' => 'Örnek Klinik'], $lead->fresh()->site_watch);
        $this->assertSame(0, SalesLeadEvent::query()->count());

        Http::swap(new Factory);
        Http::fake(['*' => Http::response('', 503)]);
        $this->assertTrue(app(WarmPoolWatch::class)->look($lead->fresh()));

        $lead->refresh();
        $this->assertSame('Yaz: sitesi açılmıyor.', $lead->next_step);
        $this->assertTrue($lead->next_step_at->lt(now()->addDays(4)), 'comes back this week, not at the re-warm round');
        $this->assertStringContainsString('Yazmak için iyi an: sitesi açılmıyor.', (string) SalesLeadEvent::query()->sole()->body);

        // Still down a moment later: the same state is not a second event.
        $this->assertFalse(app(WarmPoolWatch::class)->look($lead));
    }

    public function test_the_reasons(): void
    {
        $up = fn (?string $title): array => ['state' => 'up', 'title' => $title];
        $this->assertNull(WarmPoolWatch::reason(null, $up('A')));
        $this->assertNull(WarmPoolWatch::reason($up('A'), $up('A')));
        $this->assertSame('sitesini yeniledi, ana sayfa başlığı değişti.', WarmPoolWatch::reason($up('A'), $up('B')));
        $this->assertSame('sitesi yeniden açıldı.', WarmPoolWatch::reason(['state' => 'down', 'title' => null], $up('A')));
        $this->assertSame('sitesinin güvenlik sertifikası çalışmıyor, ziyaretçi uyarı görüyor.', WarmPoolWatch::reason($up('A'), ['state' => 'insecure', 'title' => 'A']));
        $this->assertNull(WarmPoolWatch::reason($up('A'), $up(null)), 'an unreadable answer (firewall) is not a renewal');
    }
}
