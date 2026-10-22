<?php

namespace Tests\Feature\Mcp;

use App\Models\McpOAuthClient;
use App\Models\McpToken;
use Tests\Feature\Site\SiteTestCase;

/** yakup 2026-10-22: the claude.ai connector could not connect; it needs OAuth, a fixed key cannot be pasted there. */
final class McpOAuthTest extends SiteTestCase
{
    private const array LIST = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []];

    private const string CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    public function test_a_claude_ai_connector_registers_gets_admin_approval_and_reaches_the_server(): void
    {
        config(['moxdop-mcp.token' => '']);

        $this->postJson('/mcp/moxdop', self::LIST)->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp/moxdop').'"');
        $this->getJson('/.well-known/oauth-protected-resource/mcp/moxdop')->assertOk()
            ->assertJsonPath('resource', url('/mcp/moxdop'))->assertJsonPath('authorization_servers.0', url('/'));
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
            ->assertJsonPath('registration_endpoint', route('mcp.oauth.register'))->assertJsonPath('code_challenge_methods_supported.0', 'S256');

        $clientId = $this->postJson('/mcp/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::CALLBACK]])
            ->assertCreated()->json('client_id');

        $verifier = str_repeat('v', 64);
        $query = [
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::CALLBACK, 'state' => 'xyz',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'scope' => 'write',
        ];
        $this->get('/mcp/oauth/authorize?'.http_build_query($query))->assertOk()->assertSee('Claude MoxDOP')->assertSee('Onayla');

        $location = $this->post('/mcp/oauth/authorize', $query + ['decision' => 'approve'])->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(self::CALLBACK.'?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $back);
        $this->assertSame('xyz', $back['state']);

        $form = ['grant_type' => 'authorization_code', 'code' => $back['code'], 'redirect_uri' => self::CALLBACK, 'client_id' => $clientId];
        $this->post('/mcp/oauth/token', $form + ['code_verifier' => 'wrong'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $location = $this->post('/mcp/oauth/authorize', $query + ['decision' => 'approve'])->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $back);
        $form['code'] = $back['code'];
        $key = $this->post('/mcp/oauth/token', $form + ['code_verifier' => $verifier])->assertOk()->assertJsonPath('token_type', 'Bearer')->json('access_token');
        $this->post('/mcp/oauth/token', $form + ['code_verifier' => $verifier])->assertStatus(400);

        $this->assertSame(McpToken::WRITE, McpToken::query()->sole()->scope);
        $this->postJson('/mcp/moxdop', self::LIST, ['Authorization' => 'Bearer '.$key])->assertOk()->assertSee('submit-results');
    }

    public function test_only_claude_callbacks_register_and_a_non_admin_cannot_approve(): void
    {
        $this->postJson('/mcp/oauth/register', ['redirect_uris' => ['https://evil.example/cb']])->assertStatus(400);
        $this->postJson('/mcp/oauth/register', ['redirect_uris' => ['http://localhost:4567/callback']])->assertCreated();

        $client = McpOAuthClient::query()->sole();
        $query = ['response_type' => 'code', 'client_id' => $client->client_id, 'redirect_uri' => 'https://claude.ai/other', 'code_challenge' => 'abc', 'code_challenge_method' => 'S256'];
        $this->get('/mcp/oauth/authorize?'.http_build_query($query))->assertOk()->assertSee('uyuşmuyor');

        auth()->logout();
        $this->get('/mcp/oauth/authorize?'.http_build_query($query))->assertRedirect(route('app.login'));
    }
}
