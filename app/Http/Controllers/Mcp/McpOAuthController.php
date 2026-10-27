<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\McpOAuthClient;
use App\Models\McpToken;
use App\Services\AiTasks\AiWorkers;
use App\Services\Mcp\McpKeys;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * OAuth 2.1 for the MoxDOP MCP server, the way claude.ai custom connectors connect (they cannot send a fixed bearer
 * key; yakup 2026-10-22 "bağlanamadı"). Discovery → dynamic client registration → the Admin approves in the panel
 * (PKCE S256) → the token endpoint hands out an ordinary panel MCP key (mcp_tokens), so it shows up and is revoked in
 * Ayarlar › MCP anahtarları like any other key. No refresh tokens: a revoked key means connecting again.
 */
final class McpOAuthController extends Controller
{
    private const string CODE_CACHE = 'mcp-oauth-code:';

    private const int CODE_MINUTES = 10;

    /** Callback hosts a client may register (claude.ai / claude.com connectors; Claude Code on the operator's machine). */
    private const array REDIRECT_HOSTS = ['claude.ai', 'claude.com', ...self::CHATGPT_HOSTS];

    /** ChatGPT connectors (developer mode; yakup 2026-10-27 "ChatGPT'yi yedek bağla"): their key works as ChatGPT MCP. */
    private const array CHATGPT_HOSTS = ['chatgpt.com', 'chat.openai.com'];

    private const array LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    /** RFC 9728: where the MCP server sends a client to get a key. */
    public function protectedResource(): JsonResponse
    {
        return response()->json([
            'resource' => self::resourceUrl(),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => [McpToken::READ, McpToken::WRITE],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'MoxDOP',
        ]);
    }

    /** RFC 8414: the authorization server's endpoints. */
    public function authorizationServer(): JsonResponse
    {
        return response()->json([
            'issuer' => self::issuer(),
            'authorization_endpoint' => route('mcp.oauth.authorize'),
            'token_endpoint' => route('mcp.oauth.token'),
            'registration_endpoint' => route('mcp.oauth.register'),
            'scopes_supported' => [McpToken::READ, McpToken::WRITE],
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);
    }

    /** RFC 7591 dynamic client registration; only claude.ai / claude.com or local callbacks are accepted. */
    public function register(Request $request): JsonResponse
    {
        $uris = $request->input('redirect_uris');
        if (! is_array($uris) || $uris === [] || count($uris) > 5) {
            return $this->oauthError('invalid_redirect_uri', 'redirect_uris is required.', 400);
        }
        foreach ($uris as $uri) {
            if (! is_string($uri) || ! self::allowedRedirect($uri)) {
                return $this->oauthError('invalid_redirect_uri', 'Only claude.ai, claude.com, chatgpt.com or local callbacks may connect.', 400);
            }
        }

        $client = McpOAuthClient::query()->create([
            'client_id' => 'moxdop-'.Str::lower(Str::random(32)),
            'name' => Str::limit(trim((string) $request->input('client_name')) ?: 'Claude', 120, ''),
            'redirect_uris' => array_values($uris),
        ]);

        return response()->json([
            'client_id' => $client->client_id,
            'client_id_issued_at' => $client->created_at?->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    /** The Admin sees which app asks and picks read or read+write (login first; the login comes back here). */
    public function showAuthorize(Request $request): View|RedirectResponse
    {
        $this->authorizeAdmin();
        $client = $this->validClient($request);
        if ($client instanceof View) {
            return $client;
        }
        $requested = explode(' ', (string) $request->query('scope'));

        return view('public.mcp-authorize', [
            'client' => $client,
            'params' => $request->only(['client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'response_type', 'scope', 'resource']),
            'scope' => in_array(McpToken::WRITE, $requested, true) || ! in_array(McpToken::READ, $requested, true) ? McpToken::WRITE : McpToken::READ,
        ]);
    }

    public function approve(Request $request): View|RedirectResponse
    {
        $this->authorizeAdmin();
        $client = $this->validClient($request);
        if ($client instanceof View) {
            return $client;
        }
        $redirect = (string) $request->input('redirect_uri');
        $state = $request->input('state');

        if ($request->input('decision') !== 'approve') {
            return redirect()->away($this->withQuery($redirect, array_filter(['error' => 'access_denied', 'state' => $state], fn ($value): bool => $value !== null)));
        }

        $code = Str::random(48);
        Cache::put(self::CODE_CACHE.hash('sha256', $code), [
            'client_id' => $client->client_id,
            'redirect_uri' => $redirect,
            'challenge' => (string) $request->input('code_challenge'),
            'scope' => $request->input('scope') === McpToken::READ ? McpToken::READ : McpToken::WRITE,
            'user_id' => auth()->id(),
            'name' => $client->name,
            'provider' => self::workerFor($redirect),
        ], now()->addMinutes(self::CODE_MINUTES));

        return redirect()->away($this->withQuery($redirect, array_filter(['code' => $code, 'state' => $state], fn ($value): bool => $value !== null)));
    }

    /** Code (+ PKCE verifier) → a panel MCP key. The code works once. */
    public function token(Request $request): JsonResponse
    {
        if ($request->input('grant_type') !== 'authorization_code') {
            return $this->oauthError('unsupported_grant_type', 'Only authorization_code is supported; connect again to get a new key.', 400);
        }
        $grant = Cache::pull(self::CODE_CACHE.hash('sha256', (string) $request->input('code')));
        $verifier = (string) $request->input('code_verifier');
        $valid = is_array($grant)
            && hash_equals($grant['client_id'], (string) $request->input('client_id', $grant['client_id']))
            && hash_equals($grant['redirect_uri'], (string) $request->input('redirect_uri', $grant['redirect_uri']))
            && $verifier !== ''
            && hash_equals($grant['challenge'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
        if (! $valid) {
            return $this->oauthError('invalid_grant', 'The code is invalid, used or expired.', 400);
        }

        $key = McpKeys::issue($grant['name'].' (bağlantı)', $grant['scope'], $grant['user_id'], (string) ($grant['provider'] ?? AiWorkers::CLAUDE_MCP));

        return response()->json(['access_token' => $key, 'token_type' => 'Bearer', 'scope' => $grant['scope']])
            ->header('Cache-Control', 'no-store');
    }

    public static function resourceUrl(): string
    {
        return url((string) config('moxdop-mcp.path', 'mcp/moxdop'));
    }

    private static function issuer(): string
    {
        return rtrim(url('/'), '/');
    }

    /** Which AI worker a connection is: ChatGPT when it comes back to chatgpt.com, Claude otherwise. */
    public static function workerFor(string $redirect): string
    {
        return in_array(strtolower((string) parse_url($redirect, PHP_URL_HOST)), self::CHATGPT_HOSTS, true) ? AiWorkers::CHATGPT_MCP : AiWorkers::CLAUDE_MCP;
    }

    private static function allowedRedirect(string $uri): bool
    {
        $parts = parse_url($uri);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (isset($parts['fragment']) || $host === '') {
            return false;
        }

        return ($scheme === 'https' && in_array($host, self::REDIRECT_HOSTS, true))
            || ($scheme === 'http' && in_array($host, self::LOCAL_HOSTS, true));
    }

    /** The registered client, or an error page (never redirect to a callback that was not registered). */
    private function validClient(Request $request): McpOAuthClient|View
    {
        $client = McpOAuthClient::query()->where('client_id', (string) $request->input('client_id'))->first();
        $redirect = (string) $request->input('redirect_uri');
        $error = match (true) {
            $client === null => 'Bu uygulama MoxDOP\'a kayıtlı değil. Claude ya da ChatGPT\'de bağlayıcıyı silip yeniden ekle.',
            ! in_array($redirect, $client->redirect_uris ?? [], true) => 'Dönüş adresi kayıtlı adresle uyuşmuyor.',
            $request->input('response_type') !== 'code' => 'Desteklenmeyen istek türü.',
            $request->input('code_challenge_method') !== 'S256' || trim((string) $request->input('code_challenge')) === '' => 'Güvenli bağlantı (PKCE) bilgisi eksik.',
            default => null,
        };

        return $error === null ? $client : view('public.mcp-authorize', ['error' => $error]);
    }

    /** @param  array<string, mixed>  $query */
    private function withQuery(string $url, array $query): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    private function oauthError(string $error, string $description, int $status): JsonResponse
    {
        return response()->json(['error' => $error, 'error_description' => $description], $status);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
