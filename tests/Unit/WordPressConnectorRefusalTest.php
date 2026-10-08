<?php

namespace Tests\Unit;

use App\Services\Integrations\WordPress\WordPressConnectorClient;
use PHPUnit\Framework\TestCase;

/**
 * A refused connector request names what to do: the site switch that is off, a lost pairing, or a firewall.
 */
final class WordPressConnectorRefusalTest extends TestCase
{
    public function test_refusals_name_the_switch_the_pairing_or_the_firewall(): void
    {
        $this->assertStringContainsString('"SEO düzeltmeleri" izni kapalı', WordPressConnectorClient::refusal(403, 'moxdop_fixes_disabled'));
        $this->assertStringContainsString('"Eklenti güncellemesi" izni kapalı', WordPressConnectorClient::refusal(403, 'moxdop_self_update_disabled'));
        $this->assertStringContainsString('Eşleştirmeyi döndür', WordPressConnectorClient::refusal(401, 'moxdop_auth_failed'));
        $this->assertStringContainsString('güvenlik duvarı', WordPressConnectorClient::refusal(403, ''));
        $this->assertSame('WordPress Connector returned HTTP 500.', WordPressConnectorClient::refusal(500, ''));
        $this->assertStringContainsString('ModSecurity · sunucu: Apache', WordPressConnectorClient::refusal(403, '', '<html><h1>Forbidden</h1><p>This request was blocked by ModSecurity.</p></html>', 'Apache'));
    }
}
