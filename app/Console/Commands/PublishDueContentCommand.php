<?php

namespace App\Console\Commands;

use App\Services\Content\ContentCalendarPublisher;
use Illuminate\Console\Command;

/** moxdop:content:publish-due — publishes approved, due Business Profile posts from the content calendar. */
final class PublishDueContentCommand extends Command
{
    protected $signature = 'moxdop:content:publish-due';

    protected $description = 'Publish approved Business Profile posts from the content calendar whose time has come (ADR-073).';

    public function handle(ContentCalendarPublisher $publisher): int
    {
        $this->info($publisher->publishDue().' post(s) queued.');

        return self::SUCCESS;
    }
}
