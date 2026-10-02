<?php

namespace Tests\Feature;

use Tests\TestCase;

/** W7: the system map is generated from the code (menu, command center sources, writes, schedule). */
final class SystemMapCommandTest extends TestCase
{
    public function test_system_map_lists_menu_sources_writes_and_schedule(): void
    {
        $path = sys_get_temp_dir().'/system-map-'.uniqid().'.md';
        $this->artisan('moxdop:system-map', ['--path' => $path])->assertSuccessful();
        $map = (string) file_get_contents($path);
        @unlink($path);

        $this->assertStringContainsString('Entegrasyonlar (`operator.integrations`) — sekmeler: Keşfedilen varlıklar', $map);
        $this->assertStringContainsString('review_reply', $map);
        $this->assertStringContainsString('`moxdop:integrations:discover`', $map);
        $this->assertStringNotContainsString('campaign_budget', $map);
    }
}
