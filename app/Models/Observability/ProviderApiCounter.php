<?php

namespace App\Models\Observability;

use Illuminate\Database\Eloquent\Model;

/**
 * Bounded 5-minute provider API counters (attempts + outcomes + latency sum).
 * No Authorization headers, tokens, or raw bodies.
 */
class ProviderApiCounter extends Model
{
    protected $fillable = [
        'provider',
        'operation',
        'window_started_at',
        'attempts',
        'successes',
        'auth_errors',
        'rate_limits',
        'client_errors',
        'server_errors',
        'timeouts',
        'network_errors',
        'latency_sum_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_started_at' => 'datetime',
            'attempts' => 'integer',
            'successes' => 'integer',
            'auth_errors' => 'integer',
            'rate_limits' => 'integer',
            'client_errors' => 'integer',
            'server_errors' => 'integer',
            'timeouts' => 'integer',
            'network_errors' => 'integer',
            'latency_sum_ms' => 'integer',
        ];
    }
}
