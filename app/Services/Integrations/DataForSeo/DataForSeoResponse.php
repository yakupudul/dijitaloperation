<?php

namespace App\Services\Integrations\DataForSeo;

/**
 * Normalized DataForSEO API v3 response envelope.
 *
 * Official docs: HTTP 200 alone does not prove success — top-level
 * status_code must be 20000 for a successful operation.
 *
 * @phpstan-type TaskRow array{
 *     id?: string|null,
 *     status_code?: int|null,
 *     status_message?: string|null,
 *     cost?: float|null,
 *     result?: list<array<string, mixed>>|null,
 *     path?: list<string>|null,
 *     data?: array<string, mixed>|null
 * }
 */
final class DataForSeoResponse
{
    public const int SUCCESS_STATUS = 20000;

    /**
     * @param  list<TaskRow>  $tasks
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $raw
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly ?int $statusCode,
        public readonly ?string $statusMessage,
        public readonly ?float $cost,
        public readonly ?int $tasksCount,
        public readonly ?int $tasksError,
        public readonly array $tasks,
        public readonly array $headers,
        public readonly ?array $raw,
        public readonly bool $jsonParsed,
    ) {}

    public function isHttpOk(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    public function isProviderOk(): bool
    {
        return $this->statusCode === self::SUCCESS_STATUS;
    }

    public function isSuccessful(): bool
    {
        return $this->isHttpOk() && $this->jsonParsed && $this->isProviderOk();
    }

    /**
     * @return TaskRow|null
     */
    public function firstTask(): ?array
    {
        $task = $this->tasks[0] ?? null;

        return is_array($task) ? $task : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function firstResult(): ?array
    {
        $task = $this->firstTask();
        if ($task === null) {
            return null;
        }

        $results = $task['result'] ?? null;
        if (! is_array($results) || $results === []) {
            return null;
        }

        $first = $results[0] ?? null;

        return is_array($first) ? $first : null;
    }

    /**
     * Safe metadata for Run / Evidence — never includes credentials.
     *
     * @return array{
     *     provider: string,
     *     provider_status_code: int|null,
     *     provider_status_message: string|null,
     *     reported_cost_usd: float|null,
     *     tasks_count: int|null,
     *     tasks_error: int|null,
     *     http_status: int
     * }
     */
    public function costProvenanceMetadata(): array
    {
        return [
            'provider' => 'dataforseo',
            'provider_status_code' => $this->statusCode,
            'provider_status_message' => $this->statusMessage,
            'reported_cost_usd' => $this->cost,
            'tasks_count' => $this->tasksCount,
            'tasks_error' => $this->tasksError,
            'http_status' => $this->httpStatus,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @param  array<string, string>  $headers
     */
    public static function fromHttp(int $httpStatus, ?array $json, array $headers = []): self
    {
        if ($json === null) {
            return new self(
                httpStatus: $httpStatus,
                statusCode: null,
                statusMessage: null,
                cost: null,
                tasksCount: null,
                tasksError: null,
                tasks: [],
                headers: $headers,
                raw: null,
                jsonParsed: false,
            );
        }

        $statusCode = isset($json['status_code']) && is_numeric($json['status_code'])
            ? (int) $json['status_code']
            : null;
        $statusMessage = isset($json['status_message']) && is_string($json['status_message'])
            ? $json['status_message']
            : null;
        $cost = isset($json['cost']) && is_numeric($json['cost'])
            ? (float) $json['cost']
            : null;
        $tasksCount = isset($json['tasks_count']) && is_numeric($json['tasks_count'])
            ? (int) $json['tasks_count']
            : null;
        $tasksError = isset($json['tasks_error']) && is_numeric($json['tasks_error'])
            ? (int) $json['tasks_error']
            : null;

        $tasks = [];
        if (isset($json['tasks']) && is_array($json['tasks'])) {
            foreach ($json['tasks'] as $task) {
                if (is_array($task)) {
                    $tasks[] = $task;
                }
            }
        }

        return new self(
            httpStatus: $httpStatus,
            statusCode: $statusCode,
            statusMessage: $statusMessage,
            cost: $cost,
            tasksCount: $tasksCount,
            tasksError: $tasksError,
            tasks: $tasks,
            headers: $headers,
            raw: $json,
            jsonParsed: true,
        );
    }
}
