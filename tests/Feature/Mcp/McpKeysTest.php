<?php

namespace Tests\Feature\Mcp;

use App\Livewire\Operator\Settings\McpKeysPage;
use App\Models\AgencySetting;
use App\Models\McpToken;
use App\Services\Mcp\McpKeys;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/** Panel audit T1 (yakup 2026-10-21): MCP keys are made in the panel, shown once, read or write, revocable. */
final class McpKeysTest extends SiteTestCase
{
    private const array LIST = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []];

    public function test_a_panel_key_is_shown_once_stored_as_a_hash_and_opens_the_server(): void
    {
        config(['moxdop-mcp.token' => '']);
        $this->postJson('/mcp/moxdop', self::LIST)->assertUnauthorized();

        $page = Livewire::test(McpKeysPage::class)->set('name', 'claude.ai')->set('scope', 'write')->call('create');
        $plain = (string) $page->get('newKey');
        $this->assertStringStartsWith(McpKeys::PREFIX, $plain);
        $page->assertSee($plain);

        $token = McpToken::query()->sole();
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertSame(substr($plain, -4), $token->last_four);

        $this->postJson('/mcp/moxdop', self::LIST, ['Authorization' => 'Bearer '.$plain])->assertOk()->assertSee('submit-results');
        $this->assertNotNull($token->fresh()->last_used_at);

        Livewire::test(McpKeysPage::class)->assertDontSee($plain)->call('revoke', $token->id);
        $this->postJson('/mcp/moxdop', self::LIST, ['Authorization' => 'Bearer '.$plain])->assertUnauthorized();
    }

    public function test_a_read_key_reads_but_cannot_call_a_tool_that_changes_something(): void
    {
        config(['moxdop-mcp.token' => '']);
        $plain = McpKeys::issue('okuma', McpToken::READ, null);
        $headers = ['Authorization' => 'Bearer '.$plain];

        $call = fn (string $tool): array => ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []]];
        $this->postJson('/mcp/moxdop', $call('submit-results'), $headers)->assertOk()->assertJsonPath('error.code', -32601)->assertJsonPath('id', 7);
        $this->postJson('/mcp/moxdop', $call('save-note'), $headers)->assertJsonPath('error.code', -32601);
        $this->postJson('/mcp/moxdop', $call('system-health'), $headers)->assertOk()->assertJsonMissingPath('error');

        $this->assertContains('get-brand', McpKeys::readTools());
        $this->assertNotContains('build-site', McpKeys::readTools());
        $this->assertNotContains('get-tasks', McpKeys::readTools());
    }

    public function test_the_old_server_key_can_be_turned_off_in_the_panel(): void
    {
        config(['moxdop-mcp.token' => 'test-mcp-token']);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        $headers = ['Authorization' => 'Bearer test-mcp-token'];
        $this->postJson('/mcp/moxdop', self::LIST, $headers)->assertOk();

        $plain = McpKeys::issue('yeni', McpToken::WRITE, null);
        Livewire::test(McpKeysPage::class)->assertSee('Eski sunucu anahtarı')->call('setEnvKey', false);

        $this->assertFalse(McpKeys::envKeyOn());
        $this->postJson('/mcp/moxdop', self::LIST, $headers)->assertUnauthorized();
        $this->postJson('/mcp/moxdop', self::LIST, ['Authorization' => 'Bearer '.$plain])->assertOk();
    }
}
