<?php

namespace App\Services\BusinessOutcomes;

use App\Enums\BusinessOutcomeCompleteness;
use App\Enums\BusinessOutcomeImportBatchStatus;
use App\Enums\BusinessOutcomeSourceKind;
use App\Enums\BusinessOutcomeUnit;
use App\Models\Brand;
use App\Models\BusinessOutcomeImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Strict aggregate-only CSV import for Business Outcomes.
 * Unknown columns rejected. No person-level CRM fields. Atomic V1 commit.
 */
final class BusinessOutcomeCsvImportService
{
    public const int MAX_ROWS = 500;

    public const int MAX_BYTES = 512_000;

    /** @var list<string> */
    public const array ALLOWED_HEADERS = [
        'outcome_code',
        'period_start',
        'period_end',
        'value',
        'currency',
        'completeness',
    ];

    /** @var list<string> */
    public const array FORBIDDEN_HEADER_FRAGMENTS = [
        'name', 'email', 'phone', 'patient', 'lead', 'contact', 'deal',
        'appointment', 'invoice', 'address', 'medical', 'treatment',
    ];

    public function __construct(
        private readonly BusinessOutcomeReadService $reads,
        private readonly BusinessOutcomeObservationService $observations,
    ) {}

    /**
     * Validate CSV text without writing observations.
     *
     * @return array{
     *     ok: bool,
     *     checksum: string,
     *     rows: list<array<string, mixed>>,
     *     errors: list<array{row: int, code: string, message: string}>,
     *     valid_count: int,
     *     error_count: int
     * }
     */
    public function validate(Brand $brand, string $csvContents): array
    {
        if (strlen($csvContents) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'FILE_TOO_LARGE']);
        }

        $checksum = hash('sha256', $csvContents);
        $parsed = $this->parse($csvContents);
        $errors = $parsed['errors'];
        $rows = [];

        foreach ($parsed['rows'] as $index => $row) {
            $rowNumber = $index + 2; // header is row 1
            $rowErrors = $this->validateRow($brand, $row, $rowNumber);
            if ($rowErrors !== []) {
                foreach ($rowErrors as $err) {
                    $errors[] = $err;
                }

                continue;
            }
            $rows[] = array_merge($row, ['_row_number' => $rowNumber]);
        }

        // Duplicate semantic rows inside file
        $seen = [];
        foreach ($rows as $row) {
            $key = $row['outcome_code'].'|'.$row['period_start'].'|'.$row['period_end'];
            if (isset($seen[$key])) {
                $errors[] = [
                    'row' => (int) $row['_row_number'],
                    'code' => 'DUPLICATE_ROW',
                    'message' => 'Duplicate semantic row in CSV.',
                ];
            }
            $seen[$key] = true;
        }

        // Overlaps within file for same outcome_code
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[$row['outcome_code']][] = $row;
        }
        foreach ($byCode as $codeRows) {
            usort($codeRows, static fn ($a, $b) => strcmp($a['period_start'], $b['period_start']));
            for ($i = 1; $i < count($codeRows); $i++) {
                if ($codeRows[$i]['period_start'] <= $codeRows[$i - 1]['period_end']) {
                    $errors[] = [
                        'row' => (int) $codeRows[$i]['_row_number'],
                        'code' => 'OVERLAPPING_PERIOD',
                        'message' => 'Overlapping periods in CSV for same outcome_code.',
                    ];
                }
            }
        }

        $errorCount = count($errors);
        $validCount = $errorCount === 0 ? count($rows) : 0;

        return [
            'ok' => $errorCount === 0,
            'checksum' => $checksum,
            'rows' => $errorCount === 0 ? $rows : [],
            'errors' => $errors,
            'valid_count' => $validCount,
            'error_count' => $errorCount,
            'row_count' => count($parsed['rows']),
        ];
    }

    /**
     * Preview = validate only; persists a validation batch record, no observations.
     *
     * @return array{batch: BusinessOutcomeImportBatch, preview: array<string, mixed>}
     */
    public function preview(Brand $brand, string $csvContents, ?User $actor = null, ?string $filename = null): array
    {
        $result = $this->validate($brand, $csvContents);

        $batch = BusinessOutcomeImportBatch::query()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'imported_by' => $actor?->id,
            'status' => $result['ok']
                ? BusinessOutcomeImportBatchStatus::Validated
                : BusinessOutcomeImportBatchStatus::Rejected,
            'file_checksum' => $result['checksum'],
            'original_filename' => $filename,
            'row_count' => $result['row_count'],
            'valid_count' => $result['valid_count'],
            'error_count' => $result['error_count'],
            'committed_count' => 0,
            'validation_errors' => $result['errors'],
            'validated_at' => now(),
        ]);

        return [
            'batch' => $batch,
            'preview' => [
                'ok' => $result['ok'],
                'rows' => $result['rows'],
                'errors' => $result['errors'],
                'writes' => 0,
            ],
        ];
    }

    /**
     * Atomic commit of a previously validated batch using the same CSV contents.
     *
     * @return array{batch: BusinessOutcomeImportBatch, committed: int, idempotent: bool}
     */
    public function commit(
        Brand $brand,
        BusinessOutcomeImportBatch $batch,
        string $csvContents,
        ?User $actor = null,
    ): array {
        if ((int) $batch->brand_id !== (int) $brand->id) {
            throw ValidationException::withMessages(['batch' => 'BATCH_BRAND_MISMATCH']);
        }

        $checksum = hash('sha256', $csvContents);
        if ($checksum !== $batch->file_checksum) {
            throw ValidationException::withMessages(['file' => 'CHECKSUM_MISMATCH']);
        }

        // Idempotent re-commit of same batch
        if ($batch->status === BusinessOutcomeImportBatchStatus::Committed) {
            return ['batch' => $batch, 'committed' => (int) $batch->committed_count, 'idempotent' => true];
        }

        $result = $this->validate($brand, $csvContents);
        if (! $result['ok']) {
            $batch->forceFill([
                'status' => BusinessOutcomeImportBatchStatus::Rejected,
                'validation_errors' => $result['errors'],
                'error_count' => $result['error_count'],
                'valid_count' => 0,
            ])->save();
            throw ValidationException::withMessages(['csv' => 'VALIDATION_FAILED']);
        }

        return DB::transaction(function () use ($brand, $batch, $result, $actor): array {
            $committed = 0;
            foreach ($result['rows'] as $row) {
                $definition = $this->reads->findActiveDefinitionByCode($brand, $row['outcome_code']);
                if ($definition === null) {
                    throw ValidationException::withMessages(['outcome_code' => 'UNKNOWN_OUTCOME_CODE']);
                }

                $payload = [
                    'period_start' => $row['period_start'],
                    'period_end' => $row['period_end'],
                    'value' => $row['value'],
                    'completeness' => $row['completeness'],
                    'import_batch_id' => $batch->id,
                    'import_row_number' => $row['_row_number'],
                ];
                if ($definition->unit === BusinessOutcomeUnit::Money) {
                    $payload['currency'] = $row['currency'];
                }

                try {
                    $this->observations->record(
                        brand: $brand,
                        definition: $definition,
                        input: $payload,
                        actor: $actor,
                        source: BusinessOutcomeSourceKind::CsvImport,
                        allowCorrection: false,
                    );
                } catch (ValidationException $e) {
                    $messages = $e->errors();
                    if (isset($messages['value']) && in_array(BusinessOutcomeObservationService::ERROR_CORRECTION_REQUIRED, $messages['value'], true)) {
                        throw ValidationException::withMessages([
                            'row_'.$row['_row_number'] => 'CORRECTION_REQUIRED',
                        ]);
                    }
                    throw $e;
                }
                $committed++;
            }

            $batch->forceFill([
                'status' => BusinessOutcomeImportBatchStatus::Committed,
                'committed_count' => $committed,
                'committed_at' => now(),
                'valid_count' => $committed,
                'error_count' => 0,
                'validation_errors' => [],
            ])->save();

            return ['batch' => $batch->refresh(), 'committed' => $committed, 'idempotent' => false];
        });
    }

    /**
     * @return array{rows: list<array<string, string>>, errors: list<array{row: int, code: string, message: string}>}
     */
    private function parse(string $csvContents): array
    {
        $errors = [];
        $lines = preg_split("/\r\n|\n|\r/", trim($csvContents)) ?: [];
        if ($lines === [] || trim($lines[0]) === '') {
            throw ValidationException::withMessages(['file' => 'EMPTY_CSV']);
        }

        $headerLine = str_getcsv($lines[0]);
        $headers = array_map(static fn ($h) => strtolower(trim((string) $h)), $headerLine);

        foreach ($headers as $header) {
            if ($header === '') {
                $errors[] = ['row' => 1, 'code' => 'UNKNOWN_COLUMN', 'message' => 'Empty header.'];

                continue;
            }
            if (! in_array($header, self::ALLOWED_HEADERS, true)) {
                $errors[] = ['row' => 1, 'code' => 'UNKNOWN_COLUMN', 'message' => 'Unknown column: '.$header];
            }
            foreach (self::FORBIDDEN_HEADER_FRAGMENTS as $fragment) {
                if ($header === $fragment || str_contains($header, $fragment.'_') || str_ends_with($header, '_'.$fragment)) {
                    $errors[] = ['row' => 1, 'code' => 'UNKNOWN_COLUMN', 'message' => 'Forbidden column: '.$header];
                }
            }
        }

        foreach (['outcome_code', 'period_start', 'period_end', 'value', 'completeness'] as $required) {
            if (! in_array($required, $headers, true)) {
                $errors[] = ['row' => 1, 'code' => 'UNKNOWN_COLUMN', 'message' => 'Missing required column: '.$required];
            }
        }

        if ($errors !== []) {
            return ['rows' => [], 'errors' => $errors];
        }

        $dataLines = array_slice($lines, 1);
        if (count($dataLines) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'TOO_MANY_ROWS']);
        }

        $rows = [];
        foreach ($dataLines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            // Neutralize CSV formula injection in cells
            $cells = array_map(static function (string $cell): string {
                $cell = trim($cell);
                if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true)) {
                    return "'".$cell;
                }

                return $cell;
            }, str_getcsv($line));

            if (count($cells) !== count($headers)) {
                $errors[] = [
                    'row' => $i + 2,
                    'code' => 'INVALID_PERIOD',
                    'message' => 'Column count mismatch.',
                ];

                continue;
            }
            $rows[] = array_combine($headers, $cells) ?: [];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<array{row: int, code: string, message: string}>
     */
    private function validateRow(Brand $brand, array $row, int $rowNumber): array
    {
        $errors = [];
        $definition = $this->reads->findActiveDefinitionByCode($brand, (string) ($row['outcome_code'] ?? ''));
        if ($definition === null) {
            $errors[] = ['row' => $rowNumber, 'code' => 'UNKNOWN_OUTCOME_CODE', 'message' => 'Unknown outcome_code.'];

            return $errors;
        }

        foreach (['period_start', 'period_end'] as $field) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row[$field] ?? ''))) {
                $errors[] = ['row' => $rowNumber, 'code' => 'INVALID_PERIOD', 'message' => $field.' invalid.'];
            }
        }
        if ($errors === [] && ($row['period_end'] < $row['period_start'])) {
            $errors[] = ['row' => $rowNumber, 'code' => 'INVALID_PERIOD', 'message' => 'end before start'];
        }

        $completeness = BusinessOutcomeCompleteness::tryFrom(strtolower((string) ($row['completeness'] ?? '')));
        if ($completeness === null) {
            $errors[] = ['row' => $rowNumber, 'code' => 'INVALID_COMPLETENESS', 'message' => 'completeness required'];
        }

        $value = (string) ($row['value'] ?? '');
        if ($definition->unit === BusinessOutcomeUnit::Count) {
            if (! preg_match('/^\d+$/', $value)) {
                $errors[] = ['row' => $rowNumber, 'code' => 'COUNT_MUST_BE_INTEGER', 'message' => 'Count must be integer.'];
            }
        } else {
            if (! preg_match('/^\d+(\.\d{1,4})?$/', $value)) {
                $errors[] = ['row' => $rowNumber, 'code' => 'INVALID_COUNT', 'message' => 'Invalid revenue value.'];
            }
            $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
            if ($currency === '') {
                $errors[] = ['row' => $rowNumber, 'code' => 'REVENUE_CURRENCY_REQUIRED', 'message' => 'Currency required.'];
            } elseif ($definition->currency_code !== null && strtoupper($definition->currency_code) !== $currency) {
                $errors[] = ['row' => $rowNumber, 'code' => 'CURRENCY_MISMATCH', 'message' => 'Currency mismatch.'];
            }
        }

        if ($definition->unit === BusinessOutcomeUnit::Count && trim((string) ($row['currency'] ?? '')) !== '') {
            $errors[] = ['row' => $rowNumber, 'code' => 'CURRENCY_MISMATCH', 'message' => 'Count rows must not set currency.'];
        }

        return $errors;
    }
}
