<?php

namespace App\Services\ReportDelivery;

use App\Enums\ReportDeliveryAttemptResult;
use App\Enums\ReportDeliveryFailureCategory;
use App\Enums\ReportDeliveryStatus;
use App\Mail\ReportDeliveryMail;
use App\Models\ReportDelivery;
use App\Models\ReportDeliveryAttempt;
use App\Models\ReportShareGrant;
use App\Models\ReportSnapshot;
use App\Services\Operator\OperatorMailConfigService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Transport send for one logical Delivery — idempotent retries.
 */
final class SendReportDeliveryService
{
    public function __construct(
        private readonly CreateReportDeliveryService $create,
        private readonly ReportMailConfigGuard $mailGuard,
        private readonly OperatorMailConfigService $operatorMail,
    ) {}

    public function send(int $deliveryId): ReportDelivery
    {
        $this->operatorMail->reloadForQueuedSend();

        $delivery = ReportDelivery::query()->find($deliveryId);
        if ($delivery === null) {
            throw ValidationException::withMessages(['delivery' => 'DELIVERY_NOT_FOUND']);
        }

        if ($delivery->status === ReportDeliveryStatus::Sent) {
            return $delivery;
        }
        if ($delivery->status === ReportDeliveryStatus::Cancelled) {
            return $delivery;
        }

        $this->mailGuard->assertConfigured();

        $snapshot = ReportSnapshot::query()->find($delivery->report_snapshot_id);
        $grant = ReportShareGrant::query()->find($delivery->share_grant_id);
        if ($snapshot === null || $grant === null) {
            return $this->fail($delivery, ReportDeliveryFailureCategory::AuthorizationInvalidated, 'Missing snapshot or grant');
        }
        if (! $grant->isActive()) {
            return $this->fail($delivery, ReportDeliveryFailureCategory::ShareExpiredBeforeSend, 'Share grant inactive');
        }

        $locator = cache()->get($this->create->locatorCacheKey((int) $delivery->id));
        if (! is_string($locator) || $locator === '') {
            return $this->fail($delivery, ReportDeliveryFailureCategory::ShareCreationFailed, 'Locator unavailable for send');
        }

        $attemptNumber = (int) ReportDeliveryAttempt::query()->where('delivery_id', $delivery->id)->count() + 1;
        $max = (int) config('report_delivery.delivery.max_attempts', 5);
        if ($attemptNumber > $max) {
            return $this->fail($delivery, ReportDeliveryFailureCategory::EmailTransportPermanent, 'Max attempts exceeded');
        }

        $delivery->status = ReportDeliveryStatus::Sending;
        $delivery->save();

        $started = CarbonImmutable::now();
        try {
            $url = url('/reports/share/'.$locator);
            Mail::to((string) $delivery->recipient_email_snapshot)
                ->send(new ReportDeliveryMail($delivery, $snapshot, $url));

            ReportDeliveryAttempt::query()->create([
                'delivery_id' => (int) $delivery->id,
                'attempt_number' => $attemptNumber,
                'started_at' => $started,
                'finished_at' => CarbonImmutable::now(),
                'result' => ReportDeliveryAttemptResult::Sent,
                'created_at' => CarbonImmutable::now(),
            ]);

            $delivery->status = ReportDeliveryStatus::Sent;
            $delivery->sent_at = CarbonImmutable::now();
            $delivery->failed_at = null;
            $delivery->failure_category = null;
            $delivery->failure_message = null;
            $delivery->save();

            return $delivery;
        } catch (\Throwable $e) {
            ReportDeliveryAttempt::query()->create([
                'delivery_id' => (int) $delivery->id,
                'attempt_number' => $attemptNumber,
                'started_at' => $started,
                'finished_at' => CarbonImmutable::now(),
                'result' => ReportDeliveryAttemptResult::FailedTransient,
                'error_class' => ReportDeliveryFailureCategory::EmailTransportTransient->value,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'created_at' => CarbonImmutable::now(),
            ]);

            if ($attemptNumber >= $max) {
                return $this->fail($delivery, ReportDeliveryFailureCategory::EmailTransportPermanent, $e->getMessage());
            }

            $delivery->status = ReportDeliveryStatus::Queued;
            $delivery->save();
            throw $e;
        }
    }

    private function fail(
        ReportDelivery $delivery,
        ReportDeliveryFailureCategory $category,
        string $message,
    ): ReportDelivery {
        $delivery->status = ReportDeliveryStatus::Failed;
        $delivery->failed_at = CarbonImmutable::now();
        $delivery->failure_category = $category->value;
        $delivery->failure_message = mb_substr($message, 0, 500);
        $delivery->save();

        return $delivery;
    }
}
