<?php

namespace Tests\Unit;

use App\Models\AgencySetting;
use App\Services\Operations\ErrorAlertReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/** New application errors reach the phone once per kind; expected ones (validation) do not. */
final class ErrorAlertReporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_errors_notify_once_and_expected_ones_do_not(): void
    {
        config(['moxdop-observability.error_alerts' => true]);
        Http::fake(['*' => Http::response('ok')]);
        AgencySetting::query()->create(['push_ntfy_url' => 'https://ntfy.example.test/moxdop', 'push_min_severity' => 'high']);

        app(ErrorAlertReporter::class)->report(ValidationException::withMessages(['x' => 'y']));
        $boom = new RuntimeException('boom');
        app(ErrorAlertReporter::class)->report($boom);
        app(ErrorAlertReporter::class)->report($boom);

        $rows = DB::table('push_notifications')->get();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('RuntimeException: boom', (string) $rows[0]->body);
    }
}
