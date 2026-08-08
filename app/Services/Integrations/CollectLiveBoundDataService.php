<?php

namespace App\Services\Integrations;

use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Run;
use Illuminate\Support\Facades\Log;

/**
 * One-operator entry: discover active bindings on a Digital Asset and run
 * registered module collectors. No credentials / external IDs / OAuth here.
 */
final class CollectLiveBoundDataService
{
    public function __construct(
        private readonly BoundCollectorRegistry $registry,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     runs: list<Run>,
     *     skipped: list<array{capability: string, reason: string}>
     * }
     */
    public function collect(DigitalAsset $asset): array
    {
        $bindings = CoreAssetBinding::query()
            ->with(['externalResource.integration', 'digitalAsset'])
            ->where('digital_asset_id', $asset->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        if ($bindings->isEmpty()) {
            return [
                'ok' => false,
                'message' => 'No active provider bindings. Bind External Resources under Provider resources (Settings → Integrations → Google first).',
                'runs' => [],
                'skipped' => [],
            ];
        }

        $runs = [];
        $skipped = [];
        $failures = 0;

        foreach ($bindings as $binding) {
            $collector = $this->registry->forCapability((string) $binding->capability);
            if ($collector === null) {
                $skipped[] = [
                    'capability' => (string) $binding->capability,
                    'reason' => 'No collector registered for this capability yet.',
                ];

                continue;
            }

            try {
                $runs[] = $collector->collect($binding);
            } catch (\Throwable $e) {
                $failures++;
                Log::warning('Bound collector failed', [
                    'binding_id' => $binding->id,
                    'capability' => $binding->capability,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                $skipped[] = [
                    'capability' => (string) $binding->capability,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        if ($runs === [] && $skipped !== []) {
            return [
                'ok' => false,
                'message' => 'No collectors ran. '.$this->summarizeSkipped($skipped),
                'runs' => [],
                'skipped' => $skipped,
            ];
        }

        $ok = $failures === 0;
        $message = count($runs).' collector run(s) finished.';
        if ($skipped !== []) {
            $message .= ' '.$this->summarizeSkipped($skipped);
        }

        return [
            'ok' => $ok,
            'message' => $message,
            'runs' => $runs,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  list<array{capability: string, reason: string}>  $skipped
     */
    private function summarizeSkipped(array $skipped): string
    {
        return collect($skipped)
            ->map(fn (array $row): string => $row['capability'].': '.$row['reason'])
            ->implode(' | ');
    }
}
