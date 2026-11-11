<?php

namespace Tests\Feature;

use App\Livewire\Operator\Calendar\CalendarPage;
use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\Promise;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Services\Calendar\PromiseBook;
use App\Services\Operator\Agenda;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Söz defteri (Günlük Düzen 4.2): a promise in the operator's own message is asked once, then it is a task on its day. */
class PromiseBookTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private WhatsAppConversation $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        // Wednesday 2026-11-11 12:00 in Istanbul.
        CarbonImmutable::setTestNow('2026-11-11 09:00:00');
        $this->chat = WhatsAppConversation::query()->create(['integration_id' => CoreIntegration::factory()->create()->id, 'phone_number_id' => 'PN1', 'contact_id' => '905321112233', 'contact_name' => 'Ali Akbulut']);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function say(string $body, string $direction = 'outgoing', bool $history = false): void
    {
        static $n = 0;
        $this->chat->messages()->create(['message_id' => 'm'.(++$n), 'direction' => $direction, 'message_type' => 'text', 'body' => $body, 'sent_at' => now(), 'is_history' => $history]);
    }

    public function test_a_promise_in_an_own_message_is_asked_and_becomes_a_task_on_its_day(): void
    {
        $this->say('Tamamdır. Perşembe raporu gönderirim.');
        $this->say('Yarın bakarım', 'incoming');
        $this->say('Yarın bakarım', history: true);
        $this->say('Teşekkür ederim, iyi günler');
        $this->say('Yarın müsait misiniz?');

        $promise = Promise::query()->sole();
        $this->assertSame('Perşembe raporu gönderirim.', $promise->text);
        $this->assertSame('2026-11-12', $promise->due_on->toDateString());
        $this->assertSame(0, Reminder::query()->count(), 'asked first, nothing is added by itself');

        $row = collect(app(Agenda::class)->today($this->admin->id)['rows'])->firstWhere('kind', 'promise');
        $this->assertSame('1 söz yakalandı', $row['title']);

        Livewire::test(CalendarPage::class)->assertSeeHtml('data-calendar-promises')->assertSee('Perşembe raporu gönderirim.')
            ->call('promise', $promise->id, true)->assertDontSeeHtml('data-calendar-promises');
        $task = Reminder::query()->sole();
        $this->assertSame('task', $task->kind);
        $this->assertStringContainsString('Söz: Perşembe raporu gönderirim.', $task->title);
        $this->assertSame('2026-11-12', $task->remind_at->timezone('Europe/Istanbul')->toDateString());
        $this->assertSame('added', $promise->fresh()->status);
    }

    public function test_soz_degil_dismisses_and_the_rule_stops_asking_once_it_proves_right(): void
    {
        $this->say('Bugün ararım sizi');
        $first = Promise::query()->sole();
        Livewire::test(CalendarPage::class)->call('promise', $first->id, false);
        $this->assertSame('rejected', $first->fresh()->status);
        $this->assertSame(0, Reminder::query()->count());
        $this->assertFalse(app(PromiseBook::class)->learned());

        Promise::query()->delete();
        foreach (range(1, PromiseBook::LEARN) as $i) {
            Promise::query()->create(['user_id' => $this->admin->id, 'message_id' => 9000 + $i, 'text' => 'x', 'due_on' => '2026-11-12', 'status' => $i === 1 ? 'rejected' : 'added']);
        }
        $this->assertTrue(app(PromiseBook::class)->learned());
        $this->say('Haftaya teklifi hazırlayıp ileteceğim.');
        $this->assertSame('added', Promise::query()->latest('id')->first()->status, 'no question any more');
        $this->assertSame('2026-11-18', Reminder::query()->sole()->remind_at->timezone('Europe/Istanbul')->toDateString());
    }
}
