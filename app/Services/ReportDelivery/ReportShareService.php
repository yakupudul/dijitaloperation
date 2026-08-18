<?php

namespace App\Services\ReportDelivery;

use App\Enums\ReportShareAccessEventType;
use App\Models\ReportShareAccessEvent;
use App\Models\ReportShareGrant;
use App\Models\ReportShareSession;
use App\Models\ReportShareVerificationChallenge;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Support\ReportDelivery\SecretHasher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Recipient-specific authenticated share (Prompt 60).
 * Locator token ≠ authorization.
 */
final class ReportShareService
{
    /**
     * @param  array{html_view?: bool, pdf_download?: bool}  $permissions
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     * @return array{grant: ReportShareGrant, locator_token: string}
     */
    public function createGrant(
        ReportSnapshot $snapshot,
        string $recipientEmail,
        ?string $recipientName,
        CarbonImmutable $expiresAt,
        ?User $actor = null,
        array $permissions = ['html_view' => true, 'pdf_download' => true],
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
    ): array {
        $this->assertSnapshotAuthorized($snapshot, $authorizedCustomerIds, $authorizedBrandIds);

        $email = strtolower(trim($recipientEmail));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['recipient_email' => 'RECIPIENT_INVALID']);
        }
        if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['expires_at' => 'INVALID_SHARE_EXPIRY']);
        }

        $maxHours = (int) config('report_delivery.share.max_ttl_hours', 720);
        if ($expiresAt->greaterThan(CarbonImmutable::now()->addHours($maxHours))) {
            throw ValidationException::withMessages(['expires_at' => 'SHARE_TTL_EXCEEDED']);
        }

        $rawToken = SecretHasher::randomToken(32);
        $grant = ReportShareGrant::query()->create([
            'report_snapshot_id' => (int) $snapshot->id,
            'recipient_email' => $email,
            'recipient_name' => $recipientName !== null ? mb_substr(trim(strip_tags($recipientName)), 0, 255) : null,
            'permissions' => [
                'html_view' => (bool) ($permissions['html_view'] ?? true),
                'pdf_download' => (bool) ($permissions['pdf_download'] ?? true),
            ],
            'expires_at' => $expiresAt,
            'created_by' => $actor?->id,
            'locator_token_hash' => SecretHasher::hash($rawToken),
            'created_at' => CarbonImmutable::now(),
        ]);

        return ['grant' => $grant, 'locator_token' => $rawToken];
    }

    public function resolveGrantByLocator(string $rawToken): ReportShareGrant
    {
        $grant = ReportShareGrant::query()
            ->where('locator_token_hash', SecretHasher::hash($rawToken))
            ->first();
        if ($grant === null || ! $grant->isActive()) {
            throw ValidationException::withMessages(['share' => 'SHARE_NOT_FOUND']);
        }

        return $grant;
    }

    /**
     * @return array{challenge_id: int, masked_email: string}
     */
    public function requestVerification(ReportShareGrant $grant, ?string $ip = null, ?string $ua = null): array
    {
        if (! $grant->isActive()) {
            $this->audit($grant, ReportShareAccessEventType::AccessDenied, null, $ip, $ua);
            throw ValidationException::withMessages(['share' => 'SHARE_NOT_FOUND']);
        }

        $key = 'report-share-otp:'.$grant->id;
        $max = (int) config('report_delivery.share.otp_request_max_per_hour', 10);
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages(['verification' => 'OTP_RATE_LIMITED']);
        }
        RateLimiter::hit($key, 3600);

        $cooldown = (int) config('report_delivery.share.otp_resend_cooldown_seconds', 60);
        $recent = ReportShareVerificationChallenge::query()
            ->where('share_grant_id', $grant->id)
            ->where('created_at', '>=', CarbonImmutable::now()->subSeconds($cooldown))
            ->exists();
        if ($recent) {
            throw ValidationException::withMessages(['verification' => 'OTP_RESEND_COOLDOWN']);
        }

        $code = SecretHasher::otpCode();
        $ttl = (int) config('report_delivery.share.otp_ttl_minutes', 15);
        $challenge = ReportShareVerificationChallenge::query()->create([
            'share_grant_id' => (int) $grant->id,
            'code_hash' => SecretHasher::hash($code),
            'expires_at' => CarbonImmutable::now()->addMinutes($ttl),
            'created_at' => CarbonImmutable::now(),
        ]);

        Mail::raw(
            'Your MoxDOP report verification code is: '.$code."\nThis code expires in {$ttl} minutes.\nNo report content is included.",
            function ($message) use ($grant): void {
                $message->to($grant->recipient_email)
                    ->subject('MoxDOP report verification code');
            },
        );

        $this->audit($grant, ReportShareAccessEventType::VerificationRequested, null, $ip, $ua);

        return [
            'challenge_id' => (int) $challenge->id,
            'masked_email' => $this->maskEmail((string) $grant->recipient_email),
        ];
    }

    /**
     * @return array{session: ReportShareSession, session_token: string}
     */
    public function verifyCode(
        ReportShareGrant $grant,
        string $code,
        ?string $ip = null,
        ?string $ua = null,
    ): array {
        if (! $grant->isActive()) {
            $this->audit($grant, ReportShareAccessEventType::AccessDenied, null, $ip, $ua);
            throw ValidationException::withMessages(['share' => 'SHARE_NOT_FOUND']);
        }

        $challenge = ReportShareVerificationChallenge::query()
            ->where('share_grant_id', $grant->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', CarbonImmutable::now())
            ->orderByDesc('id')
            ->first();

        if ($challenge === null) {
            $this->audit($grant, ReportShareAccessEventType::VerificationFailed, null, $ip, $ua);
            throw ValidationException::withMessages(['verification' => 'OTP_INVALID']);
        }

        $maxAttempts = (int) config('report_delivery.share.otp_max_attempts', 5);
        if ((int) $challenge->attempts >= $maxAttempts) {
            $this->audit($grant, ReportShareAccessEventType::VerificationFailed, null, $ip, $ua);
            throw ValidationException::withMessages(['verification' => 'OTP_LOCKED']);
        }

        if (! SecretHasher::equals(trim($code), (string) $challenge->code_hash)) {
            $challenge->attempts = (int) $challenge->attempts + 1;
            $challenge->save();
            $this->audit($grant, ReportShareAccessEventType::VerificationFailed, null, $ip, $ua);
            throw ValidationException::withMessages(['verification' => 'OTP_INVALID']);
        }

        $sessionTtl = (int) config('report_delivery.share.session_ttl_minutes', 60);
        $sessionExpiry = CarbonImmutable::now()->addMinutes($sessionTtl);
        if ($sessionExpiry->greaterThan($grant->expires_at)) {
            $sessionExpiry = CarbonImmutable::parse($grant->expires_at);
        }

        $rawSession = SecretHasher::randomToken(32);

        return DB::transaction(function () use ($grant, $challenge, $rawSession, $sessionExpiry, $ip, $ua): array {
            $challenge->consumed_at = CarbonImmutable::now();
            $challenge->save();

            $session = ReportShareSession::query()->create([
                'share_grant_id' => (int) $grant->id,
                'session_token_hash' => SecretHasher::hash($rawSession),
                'expires_at' => $sessionExpiry,
                'created_at' => CarbonImmutable::now(),
                'last_seen_at' => CarbonImmutable::now(),
            ]);

            $grant->last_successful_access_at = CarbonImmutable::now();
            $grant->save();

            $this->audit($grant, ReportShareAccessEventType::VerificationSucceeded, (int) $session->id, $ip, $ua);

            return ['session' => $session, 'session_token' => $rawSession];
        });
    }

    public function resolveSession(string $rawSessionToken): ReportShareSession
    {
        $session = ReportShareSession::query()
            ->where('session_token_hash', SecretHasher::hash($rawSessionToken))
            ->first();
        if ($session === null || ! $session->isActive()) {
            throw ValidationException::withMessages(['session' => 'SHARE_SESSION_INVALID']);
        }

        $grant = ReportShareGrant::query()->find($session->share_grant_id);
        if ($grant === null || ! $grant->isActive()) {
            throw ValidationException::withMessages(['session' => 'SHARE_SESSION_INVALID']);
        }

        $session->last_seen_at = CarbonImmutable::now();
        $session->save();

        return $session;
    }

    public function revokeGrant(ReportShareGrant $grant, ?User $actor = null): void
    {
        $grant->revoked_at = CarbonImmutable::now();
        $grant->revoked_by = $actor?->id;
        $grant->save();

        ReportShareSession::query()
            ->where('share_grant_id', $grant->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => CarbonImmutable::now()]);

        $this->audit($grant, ReportShareAccessEventType::GrantRevoked);
    }

    public function audit(
        ReportShareGrant $grant,
        ReportShareAccessEventType $type,
        ?int $sessionId = null,
        ?string $ip = null,
        ?string $ua = null,
    ): void {
        ReportShareAccessEvent::query()->create([
            'share_grant_id' => (int) $grant->id,
            'event_type' => $type->value,
            'share_session_id' => $sessionId,
            'ip_hash' => $ip !== null ? hash('sha256', $ip) : null,
            'user_agent_hash' => $ua !== null ? hash('sha256', $ua) : null,
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    public function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $localMask = mb_substr($local, 0, 1).'***';

        return $localMask.'@'.$domain;
    }

    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    private function assertSnapshotAuthorized(
        ReportSnapshot $snapshot,
        array $authorizedCustomerIds,
        array $authorizedBrandIds,
    ): void {
        if ($authorizedBrandIds !== [] && ! in_array((int) $snapshot->brand_id, array_map('intval', $authorizedBrandIds), true)) {
            throw ValidationException::withMessages(['brand' => 'UNAUTHORIZED_BRAND']);
        }
        if ($authorizedCustomerIds !== [] && ! in_array((int) $snapshot->customer_id, array_map('intval', $authorizedCustomerIds), true)) {
            throw ValidationException::withMessages(['customer' => 'UNAUTHORIZED_CUSTOMER']);
        }
    }
}
