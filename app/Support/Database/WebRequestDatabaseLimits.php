<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Throwable;

/**
 * A web request's PostgreSQL session gets a statement time limit (and a limit on sitting idle inside a transaction).
 * nginx gives up on a page after 60 s (504) while the query behind it kept a database backend and a PHP-FPM worker
 * busy; under load those piled up and every other page slowed down with them. Queue workers and artisan keep no limit:
 * their long statements are the point. `database.web_statement_timeout` = 0 turns it off.
 */
class WebRequestDatabaseLimits
{
    public function __construct(private readonly ?bool $web = null) {}

    public function handle(ConnectionEstablished $event): void
    {
        $this->apply($event->connection);
    }

    public function apply(Connection $connection): void
    {
        $seconds = (int) config('database.web_statement_timeout', 55);
        if ($seconds <= 0 || $connection->getDriverName() !== 'pgsql' || ! ($this->web ?? ! app()->runningInConsole())) {
            return;
        }
        try {
            $connection->statement(sprintf("set statement_timeout = '%ds'", $seconds));
            $connection->statement(sprintf("set idle_in_transaction_session_timeout = '%ds'", max($seconds, 60)));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
