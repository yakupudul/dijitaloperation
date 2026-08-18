<?php

namespace App\Services\RecurringAutomation;

use App\Contracts\RecurringAutomation\RecurringScheduleAdapter;
use App\Enums\RecurringScheduleKind;
use InvalidArgumentException;

/**
 * Registry of domain schedule adapters for the shared recurring automation engine.
 */
final class RecurringAutomationRegistry
{
    /** @var array<string, RecurringScheduleAdapter> */
    private array $adapters = [];

    /**
     * @param  iterable<RecurringScheduleAdapter>  $adapters
     */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(RecurringScheduleAdapter $adapter): void
    {
        $this->adapters[$adapter->kind()->value] = $adapter;
    }

    public function adapter(RecurringScheduleKind $kind): RecurringScheduleAdapter
    {
        $adapter = $this->adapters[$kind->value] ?? null;
        if ($adapter === null) {
            throw new InvalidArgumentException('Unknown recurring schedule kind: '.$kind->value);
        }

        return $adapter;
    }

    /**
     * @return list<RecurringScheduleAdapter>
     */
    public function all(): array
    {
        return array_values($this->adapters);
    }

    /**
     * @return list<RecurringScheduleKind>
     */
    public function kinds(): array
    {
        return array_map(
            static fn (RecurringScheduleAdapter $adapter): RecurringScheduleKind => $adapter->kind(),
            $this->all(),
        );
    }
}
