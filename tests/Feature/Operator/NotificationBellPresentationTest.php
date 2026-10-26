<?php

namespace Tests\Feature\Operator;

use App\Livewire\Demo\NotificationBell;
use App\Support\Demo\DemoMenu;
use App\Support\OperatorMenu;
use Tests\TestCase;

/** The bell shows system alerts in Turkish, never the title twice, and each menu item has its own icon. */
final class NotificationBellPresentationTest extends TestCase
{
    public function test_old_english_alerts_read_in_turkish_without_repeating_the_title(): void
    {
        app()->setLocale('tr');
        $item = NotificationBell::present([
            'title' => 'Automatic account updates · Adadent', 'subject_label' => 'Automatic account updates · Adadent',
            'subject_kind' => 'operational_alert', 'created_at' => '2026-09-24T10:00:00+00:00', 'presentation' => [],
        ]);

        $this->assertSame('Hesap güncellemesi durdu · Adadent', $item['title']);
        $this->assertSame('', $item['detail'], 'the subject line equal to the title is not repeated');
        $this->assertNotSame('', $item['when']);
        $this->assertSame(route('operator.settings.system-health'), $item['url']);

        $withSummary = NotificationBell::present(['title' => 'Datasets reported STALE / BLOCKED by Prompt27', 'presentation' => ['summary' => '3 hesap × veri seti güncellenemiyor.']]);
        $this->assertSame('Bazı hesapların verisi güncel değil', $withSummary['title']);
        $this->assertSame('3 hesap × veri seti güncellenemiyor.', $withSummary['detail']);
    }

    public function test_the_chief_plan_opens_on_bugun_and_rows_call_a_method_alpine_does_not_shadow(): void
    {
        foreach (['/', null] as $old) {
            $item = NotificationBell::present(['title' => 'Şef: bu haftanın planı hazır (10 iş)', 'presentation' => ['url' => $old]]);
            $this->assertSame(route('operator.today', [], false).'#sef-plani', $item['url']);
        }
        $this->assertSame('/onarim', NotificationBell::present(['title' => 'Başka', 'presentation' => ['url' => '/onarim']])['url']);

        // Livewire leaves names in Alpine scope as they are: a row calling open(..) under x-data="{ open: .. }" did nothing.
        $blade = (string) file_get_contents(resource_path('views/livewire/demo/notification-bell.blade.php'));
        preg_match_all('/x-data="\{\s*(\w+)\s*:/', $blade, $scope);
        preg_match_all('/wire:click(?:\.\w+)*="(\w+)\(/', $blade, $calls);
        $this->assertNotEmpty($calls[1]);
        $this->assertSame([], array_values(array_intersect($scope[1], $calls[1])));
        $this->assertTrue(method_exists(NotificationBell::class, 'openNotification'));
    }

    public function test_every_menu_item_has_a_distinct_icon(): void
    {
        app()->setLocale('tr');
        $icons = collect(DemoMenu::groups())->flatMap(fn (array $group): array => array_column($group['items'], 'icon'));

        $this->assertSame($icons->count(), $icons->unique()->count(), 'duplicates: '.$icons->duplicates()->implode(', '));
        $icons->each(fn (string $icon) => $this->assertNotSame(OperatorMenu::icon('__missing__'), OperatorMenu::icon($icon), $icon.' has no icon'));
    }
}
