<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\Meta\MetaPermissionRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Validates Meta access tokens via official GET /debug_token.
 * Never called on page render — results are persisted/cached on Integration config.
 */
final class MetaCredentialValidator
{
    public const string STATUS_VALID = 'valid';

    public const string STATUS_EXPIRED = 'expired';

    public const string STATUS_REVOKED = 'revoked';

    public const string STATUS_WRONG_APP = 'wrong_app';

    public const string STATUS_INVALID = 'invalid';

    public const string STATUS_TRANSIENT_FAILURE = 'transient_failure';

    /**
     * @return array{
     *   ok: bool,
     *   status: string,
     *   app_id: ?string,
     *   user_id: ?string,
     *   expires_at: ?string,
     *   data_access_expires_at: ?string,
     *   granted_permissions: list<string>,
     *   granular_scopes: list<array<string, mixed>>,
     *   is_valid: bool,
     *   safe_error: ?string,
     *   validated_at: string
     * }
     */
    public function validate(CoreIntegration $integration, ?string $accessToken = null): array
    {
        $token = $accessToken ?? app(MetaCredentialResolver::class)->accessToken($integration);
        $validatedAt = now()->toIso8601String();

        if ($token === null || $token === '') {
            return $this->result(
                ok: false,
                status: self::STATUS_INVALID,
                safeError: 'No Meta access token is stored.',
                validatedAt: $validatedAt,
            );
        }

        $resolver = app(MetaCredentialResolver::class);
        $appToken = $resolver->appAccessToken($integration);
        if ($appToken === null) {
            return $this->result(
                ok: false,
                status: self::STATUS_TRANSIENT_FAILURE,
                safeError: 'Meta App ID/Secret are required to validate tokens.',
                validatedAt: $validatedAt,
            );
        }

        try {
            $response = Http::timeout(MetaApiConfig::timeoutSeconds())
                ->connectTimeout(5)
                ->acceptJson()
                ->get(MetaApiConfig::graphBaseUrl().'/debug_token', [
                    'input_token' => $token,
                    'access_token' => $appToken,
                ]);
        } catch (Throwable $exception) {
            Log::warning('meta.token_validation.transport_failure', [
                'integration_id' => $integration->id,
                'exception_class' => $exception::class,
            ]);

            return $this->result(
                ok: false,
                status: self::STATUS_TRANSIENT_FAILURE,
                safeError: 'Token validation temporarily unavailable.',
                validatedAt: $validatedAt,
            );
        }

        if ($response->serverError()) {
            return $this->result(
                ok: false,
                status: self::STATUS_TRANSIENT_FAILURE,
                safeError: 'Meta token validation provider error.',
                validatedAt: $validatedAt,
            );
        }

        if (! $response->successful()) {
            return $this->result(
                ok: false,
                status: self::STATUS_INVALID,
                safeError: 'Meta rejected token validation.',
                validatedAt: $validatedAt,
            );
        }

        $data = data_get($response->json(), 'data');
        if (! is_array($data)) {
            return $this->result(
                ok: false,
                status: self::STATUS_INVALID,
                safeError: 'Unexpected debug_token response.',
                validatedAt: $validatedAt,
            );
        }

        $isValid = (bool) ($data['is_valid'] ?? false);
        $appId = isset($data['app_id']) ? (string) $data['app_id'] : null;
        $expectedApp = $resolver->appId($integration);
        if ($expectedApp !== null && $appId !== null && $appId !== $expectedApp) {
            return $this->result(
                ok: false,
                status: self::STATUS_WRONG_APP,
                appId: $appId,
                safeError: 'Token was issued for a different Meta App.',
                validatedAt: $validatedAt,
                userId: isset($data['user_id']) ? (string) $data['user_id'] : null,
            );
        }

        $scopes = MetaPermissionRegistry::normalize(
            isset($data['scopes']) && is_array($data['scopes']) ? $data['scopes'] : [],
        );
        $granular = [];
        if (isset($data['granular_scopes']) && is_array($data['granular_scopes'])) {
            foreach ($data['granular_scopes'] as $row) {
                if (is_array($row)) {
                    $granular[] = [
                        'scope' => $row['scope'] ?? null,
                        'target_ids' => $row['target_ids'] ?? [],
                    ];
                }
            }
        }

        $expiresAt = null;
        if (isset($data['expires_at']) && is_numeric($data['expires_at']) && (int) $data['expires_at'] > 0) {
            $expiresAt = now()->setTimestamp((int) $data['expires_at'])->toIso8601String();
        }

        $dataAccessExpiresAt = null;
        if (isset($data['data_access_expires_at']) && is_numeric($data['data_access_expires_at']) && (int) $data['data_access_expires_at'] > 0) {
            $dataAccessExpiresAt = now()->setTimestamp((int) $data['data_access_expires_at'])->toIso8601String();
        }

        if (! $isValid) {
            $errorSubcode = data_get($data, 'error.subcode') ?? data_get($data, 'error_subcode');
            $status = self::STATUS_INVALID;
            $message = 'Meta token is not valid.';
            if ((int) $errorSubcode === 463 || str_contains(strtolower((string) data_get($data, 'error.message', '')), 'expired')) {
                $status = self::STATUS_EXPIRED;
                $message = 'Meta token has expired.';
            } elseif ((int) $errorSubcode === 458 || str_contains(strtolower((string) data_get($data, 'error.message', '')), 'revok')) {
                $status = self::STATUS_REVOKED;
                $message = 'Meta token was revoked.';
            }

            return $this->result(
                ok: false,
                status: $status,
                appId: $appId,
                userId: isset($data['user_id']) ? (string) $data['user_id'] : null,
                expiresAt: $expiresAt,
                dataAccessExpiresAt: $dataAccessExpiresAt,
                granted: $scopes,
                granular: $granular,
                isValid: false,
                safeError: $message,
                validatedAt: $validatedAt,
            );
        }

        return $this->result(
            ok: true,
            status: self::STATUS_VALID,
            appId: $appId,
            userId: isset($data['user_id']) ? (string) $data['user_id'] : null,
            expiresAt: $expiresAt,
            dataAccessExpiresAt: $dataAccessExpiresAt,
            granted: $scopes,
            granular: $granular,
            isValid: true,
            safeError: null,
            validatedAt: $validatedAt,
        );
    }

    /**
     * Persist validation outcome on Integration config + credential metadata (no secrets).
     *
     * @param  array<string, mixed>  $result
     */
    public function persist(CoreIntegration $integration, array $result): void
    {
        $previousConfig = is_array($integration->config) ? $integration->config : [];
        $config = $previousConfig;
        $config['last_validated_at'] = $result['validated_at'];
        if ($result['granted_permissions'] !== []) {
            $config['granted_permissions'] = $result['granted_permissions'];
        }
        $config['granular_scopes'] = $result['granular_scopes'];

        if ($result['status'] === self::STATUS_TRANSIENT_FAILURE) {
            // Temporary provider/network failure ≠ revoked token.
            $config['last_validation_failure_at'] = $result['validated_at'];
            $integration->forceFill(['config' => $config])->save();

            return;
        }

        $config['credential_status'] = $result['status'];

        if ($result['ok']) {
            $config['auth_status'] = 'connected';
            $config['connection_status'] = 'connected';
            $config['last_validation_failure_at'] = null;
            unset($config['last_error_safe']);
            $integration->forceFill([
                'config' => $config,
                'last_error' => null,
                'last_success_at' => now(),
            ])->save();
        } else {
            $config['auth_status'] = 'reauth_required';
            $config['connection_status'] = 'issue';
            $config['last_validation_failure_at'] = $result['validated_at'];
            $config['last_error_safe'] = $result['safe_error'];
            $integration->forceFill([
                'config' => $config,
                'last_error' => $result['safe_error'],
            ])->save();
        }

        $credential = $integration->providerCredential()->first();
        if ($credential === null) {
            return;
        }

        $payload = is_array($credential->encrypted_payload) ? $credential->encrypted_payload : [];
        if ($result['granted_permissions'] !== []) {
            $payload['granted_permissions'] = $result['granted_permissions'];
        }
        $payload['token_type'] = $payload['token_type'] ?? 'user_access_token';
        if (is_string($result['expires_at'] ?? null)) {
            $payload['expires_at'] = $result['expires_at'];
            $credential->expires_at = $result['expires_at'];
        }
        if (is_string($result['data_access_expires_at'] ?? null)) {
            $payload['data_access_expires_at'] = $result['data_access_expires_at'];
        }
        if (is_string($result['user_id'] ?? null)) {
            $payload['provider_user_id'] = $result['user_id'];
        }
        $credential->encrypted_payload = $payload;
        $credential->save();
    }

    /**
     * @param  list<string>  $granted
     * @param  list<array<string, mixed>>  $granular
     * @return array<string, mixed>
     */
    private function result(
        bool $ok,
        string $status,
        ?string $appId = null,
        ?string $userId = null,
        ?string $expiresAt = null,
        ?string $dataAccessExpiresAt = null,
        array $granted = [],
        array $granular = [],
        bool $isValid = false,
        ?string $safeError = null,
        string $validatedAt = '',
    ): array {
        return [
            'ok' => $ok,
            'status' => $status,
            'app_id' => $appId,
            'user_id' => $userId,
            'expires_at' => $expiresAt,
            'data_access_expires_at' => $dataAccessExpiresAt,
            'granted_permissions' => $granted,
            'granular_scopes' => $granular,
            'is_valid' => $isValid,
            'safe_error' => $safeError,
            'validated_at' => $validatedAt !== '' ? $validatedAt : now()->toIso8601String(),
        ];
    }
}
