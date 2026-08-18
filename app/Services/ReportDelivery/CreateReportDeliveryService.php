<?php

namespace App\Services\ReportDelivery;

use App\Enums\ReportDeliveryMode;
use App\Enums\ReportDeliveryStatus;
use App\Jobs\Reports\SendReportDeliveryJob;
use App\Models\ReportDelivery;
use App\Models\ReportSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manual / scheduled delivery orchestration (Prompt 60).
 * Always pins an existing ReportSnapshot — never rebuilds Story.
 */
final class CreateReportDeliveryService
{
    public function __construct(
        private readonly GenerateReportPdfService $pdfs,
        private readonly ReportShareService $shares,
        private readonly ReportMailConfigGuard $mailGuard,
    ) {}

    /**
     * @param  array{
     *     recipient_email: string,
     *     recipient_name?: string|null,
     *     expires_at?: string|null,
     *     locale?: string|null,
     *     mode?: string|null,
     *     idempotency_key?: string|null,
     *     schedule_occurrence_id?: int|null
     * }  $input
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    public function sendFromSnapshot(
        ReportSnapshot $snapshot,
        array $input,
        ?User $actor = null,
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
    ): ReportDelivery {
        $this->assertSnapshotAuthorized($snapshot, $authorizedCustomerIds, $authorizedBrandIds);

        $email = strtolower(trim((string) ($input['recipient_email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['recipient_email' => 'RECIPIENT_INVALID']);
        }

        $idempotencyKey = isset($input['idempotency_key']) && is_string($input['idempotency_key']) && $input['idempotency_key'] !== ''
            ? mb_substr($input['idempotency_key'], 0, 191)
            : null;

        if ($idempotencyKey !== null) {
            $existing = ReportDelivery::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $occurrenceId = isset($input['schedule_occurrence_id']) ? (int) $input['schedule_occurrence_id'] : null;
        if ($occurrenceId) {
            $dup = ReportDelivery::query()
                ->where('schedule_occurrence_id', $occurrenceId)
                ->where('recipient_email_snapshot', $email)
                ->first();
            if ($dup !== null) {
                return $dup;
            }
        }

        $this->mailGuard->assertConfigured();

        $ttlHours = (int) config('report_delivery.share.default_ttl_hours', 72);
        $expiresAt = ! empty($input['expires_at'])
            ? CarbonImmutable::parse((string) $input['expires_at'])
            : CarbonImmutable::now()->addHours($ttlHours);

        $locale = (string) ($input['locale'] ?? $snapshot->locale ?: 'en');
        $locale = in_array($locale, ['en', 'tr'], true) ? $locale : 'en';
        $mode = ReportDeliveryMode::tryFrom((string) ($input['mode'] ?? config('report_delivery.delivery.default_mode')))
            ?? ReportDeliveryMode::AuthenticatedSecureLinkWithPdf;

        return DB::transaction(function () use (
            $snapshot,
            $email,
            $input,
            $expiresAt,
            $locale,
            $mode,
            $actor,
            $idempotencyKey,
            $occurrenceId,
            $authorizedCustomerIds,
            $authorizedBrandIds,
        ): ReportDelivery {
            if ($idempotencyKey !== null) {
                $existing = ReportDelivery::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $artifact = $this->pdfs->generate(
                $snapshot,
                $actor,
                $idempotencyKey !== null ? $idempotencyKey.':pdf' : null,
                $authorizedCustomerIds,
                $authorizedBrandIds,
            );

            $created = $this->shares->createGrant(
                snapshot: $snapshot,
                recipientEmail: $email,
                recipientName: isset($input['recipient_name']) ? (string) $input['recipient_name'] : null,
                expiresAt: $expiresAt,
                actor: $actor,
                permissions: [
                    'html_view' => true,
                    'pdf_download' => $mode === ReportDeliveryMode::AuthenticatedSecureLinkWithPdf,
                ],
                authorizedCustomerIds: $authorizedCustomerIds,
                authorizedBrandIds: $authorizedBrandIds,
            );

            $delivery = ReportDelivery::query()->create([
                'report_snapshot_id' => (int) $snapshot->id,
                'recipient_email_snapshot' => $email,
                'recipient_name_snapshot' => $created['grant']->recipient_name,
                'delivery_mode' => $mode,
                'share_grant_id' => (int) $created['grant']->id,
                'artifact_id' => (int) $artifact->id,
                'locale' => $locale,
                'subject_template_version' => (string) config('report_delivery.delivery.subject_template_version'),
                'email_template_version' => (string) config('report_delivery.delivery.email_template_version'),
                'status' => ReportDeliveryStatus::Queued,
                'schedule_occurrence_id' => $occurrenceId,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor?->id,
                'created_at' => CarbonImmutable::now(),
            ]);

            // Persist locator temporarily in cache for the send job (not in DB plaintext).
            cache()->put(
                $this->locatorCacheKey((int) $delivery->id),
                $created['locator_token'],
                now()->addDays(7),
            );

            SendReportDeliveryJob::dispatch((int) $delivery->id);

            return $delivery;
        });
    }

    public function locatorCacheKey(int $deliveryId): string
    {
        return 'report-delivery-locator:'.$deliveryId;
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
