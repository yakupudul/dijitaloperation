<?php

namespace App\Services\ReportDelivery;

use App\Enums\ReportArtifactType;
use App\Models\ReportArtifact;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Support\ReportDelivery\ReportPdfRendererVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Idempotent private PDF artifact generation from ReportSnapshot only.
 */
final class GenerateReportPdfService
{
    public function __construct(
        private readonly ReportPdfRenderer $renderer,
    ) {}

    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    public function generate(
        ReportSnapshot $snapshot,
        ?User $actor = null,
        ?string $idempotencyKey = null,
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
    ): ReportArtifact {
        $this->assertAuthorized($snapshot, $authorizedCustomerIds, $authorizedBrandIds);

        $rendererVersion = ReportPdfRendererVersion::current();

        $existing = ReportArtifact::query()
            ->where('report_snapshot_id', $snapshot->id)
            ->where('renderer_version', $rendererVersion)
            ->first();
        if ($existing !== null && $this->artifactFileExists($existing)) {
            return $existing;
        }

        if ($idempotencyKey !== null) {
            $byKey = ReportArtifact::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($byKey !== null && $this->artifactFileExists($byKey)) {
                return $byKey;
            }
        }

        return DB::transaction(function () use ($snapshot, $actor, $idempotencyKey, $rendererVersion, $existing): ReportArtifact {
            $locked = ReportArtifact::query()
                ->where('report_snapshot_id', $snapshot->id)
                ->where('renderer_version', $rendererVersion)
                ->lockForUpdate()
                ->first();
            if ($locked !== null && $this->artifactFileExists($locked)) {
                return $locked;
            }

            if ($idempotencyKey !== null) {
                $byKey = ReportArtifact::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($byKey !== null && $this->artifactFileExists($byKey)) {
                    return $byKey;
                }
            }

            $rendered = $this->renderer->render($snapshot);
            $disk = (string) config('report_delivery.pdf.disk', 'local');
            $directory = trim((string) config('report_delivery.pdf.directory', 'report-artifacts'), '/');
            $path = $directory.'/'.$snapshot->id.'/'.$rendererVersion.'/'.hash('sha256', (string) $snapshot->id.'|'.$rendererVersion).'.pdf';

            Storage::disk($disk)->put($path, $rendered['bytes']);

            $now = CarbonImmutable::now();
            $payload = [
                'report_snapshot_id' => (int) $snapshot->id,
                'artifact_type' => ReportArtifactType::Pdf,
                'snapshot_schema_version' => $snapshot->snapshot_schema_version?->value
                    ?? (string) $snapshot->snapshot_schema_version,
                'renderer_version' => $rendererVersion,
                'content_checksum' => (string) $snapshot->content_checksum,
                'file_checksum' => hash('sha256', $rendered['bytes']),
                'storage_disk' => $disk,
                'storage_path' => $path,
                'mime_type' => (string) config('report_delivery.pdf.mime', 'application/pdf'),
                'byte_size' => strlen($rendered['bytes']),
                'generated_by' => $actor?->id,
                'generated_at' => $now,
                'idempotency_key' => $idempotencyKey,
                'created_at' => $now,
            ];

            if ($locked !== null) {
                // Broken prior row: replace storage metadata via delete+create (content immutable policy).
                $locked->delete();
            }
            if ($existing !== null && $existing->id !== ($locked?->id)) {
                $existing->delete();
            }

            return ReportArtifact::query()->create($payload);
        });
    }

    public function streamBytes(ReportArtifact $artifact): string
    {
        if (! $this->artifactFileExists($artifact)) {
            throw ValidationException::withMessages(['artifact' => 'ARTIFACT_MISSING_OR_CORRUPT']);
        }

        $bytes = Storage::disk((string) $artifact->storage_disk)->get((string) $artifact->storage_path);
        if (! is_string($bytes) || $bytes === '') {
            throw ValidationException::withMessages(['artifact' => 'ARTIFACT_MISSING_OR_CORRUPT']);
        }
        if (! hash_equals((string) $artifact->file_checksum, hash('sha256', $bytes))) {
            throw ValidationException::withMessages(['artifact' => 'ARTIFACT_CHECKSUM_MISMATCH']);
        }

        return $bytes;
    }

    private function artifactFileExists(ReportArtifact $artifact): bool
    {
        return Storage::disk((string) $artifact->storage_disk)->exists((string) $artifact->storage_path);
    }

    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    private function assertAuthorized(
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
