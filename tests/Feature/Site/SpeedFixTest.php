<?php

namespace Tests\Feature\Site;

use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/**
 * "Hız turu" (yakup 2026-11-05): the burcinoncul speed recipe as a site fix `speed` (connector ≥ 1.37.0). An older
 * connector gets its self-update queued instead of the fix.
 */
final class SpeedFixTest extends SiteTestCase
{
    public function test_the_speed_fix_is_sent_once_the_connector_knows_it(): void
    {
        Queue::fake();
        $connection = CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'plugin_version' => '1.36.0']]);
        $writes = app(ExternalWriteService::class);
        $change = ['type' => 'speed', 'object_id' => 0, 'reference' => 'hiz-turu', 'value' => ['all']];

        try {
            $writes->requestSiteFixes($this->admin, $this->site, [$change]);
            $this->fail('an older connector does not know the speed fix');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('1.37.0', $exception->getMessage());
        }
        $this->assertSame([ExternalWriteAction::ACTION_CONNECTOR_UPDATE], ExternalWriteAction::query()->pluck('action')->all());

        ExternalWriteAction::query()->delete();
        $connection->update(['config' => ['pairing_state' => 'paired', 'plugin_version' => '1.37.0']]);
        $writes->requestSiteFixes($this->admin, $this->site, [$change, ['type' => 'speed', 'object_id' => 0, 'reference' => 'kapat', 'value' => '']]);
        $sent = ExternalWriteAction::query()->sole()->request_payload['changes'];
        $this->assertSame(['type' => 'speed', 'object_id' => 0, 'reference' => 'hiz-turu', 'value' => 'all'], $sent[0]);
        $this->assertSame('', $sent[1]['value'], 'an empty value switches the recipe off');

        $plugin = (string) file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-fixes.php'));
        $this->assertStringContainsString("case 'speed':", $plugin);
    }
}
