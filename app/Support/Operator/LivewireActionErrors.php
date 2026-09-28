<?php

namespace App\Support\Operator;

use App\Support\Demo\DemoState;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Livewire\Component;
use WeakMap;

use function Livewire\on;

/**
 * A button on an operator page that points at a record which no longer exists (deleted in another tab, by a job, or
 * a stale list) answers with a Turkish notice instead of a 404 error dialog. Only actions (wire:click / submit) are
 * covered: opening a page for a missing record is still a 404.
 */
final class LivewireActionErrors
{
    public const string MISSING_RECORD = 'Bu kayıt artık yok (silinmiş ya da başka bir sekmede değişmiş olabilir). Sayfayı yenileyip listeden yeniden seçin.';

    public static function register(): void
    {
        /** @var WeakMap<Component, true> $inAction */
        $inAction = new WeakMap;

        on('call', function (mixed $component) use ($inAction): callable {
            if ($component instanceof Component) {
                $inAction[$component] = true;
            }

            return function (mixed $return) use ($component, $inAction): mixed {
                if ($component instanceof Component) {
                    unset($inAction[$component]);
                }

                return $return;
            };
        });

        on('exception', function (mixed $component, \Throwable $exception, callable $stopPropagation) use ($inAction): void {
            if (! $component instanceof Component || ! isset($inAction[$component]) || ! str_starts_with($component::class, 'App\\Livewire\\')
                || ! ($exception instanceof ModelNotFoundException || self::isBadIdentifier($exception))) {
                return;
            }
            $stopPropagation();
            self::notice($component, self::MISSING_RECORD);
        });
    }

    /**
     * PostgreSQL refuses a lookup by an id that is not a number or is out of the bigint range ("invalid input syntax
     * for type bigint", SQLSTATE 22P02 / 22003). On a read that only means "no such record" — SQLite simply finds none.
     */
    public static function isBadIdentifier(\Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && in_array((string) $exception->getCode(), ['22P02', '22003'], true)
            && str_starts_with(strtolower(ltrim($exception->getSql())), 'select');
    }

    /** Shows the notice on the page: its own message line when it has one, the shared flash, and the layout toast. */
    public static function notice(Component $component, string $message, string $tone = 'error'): void
    {
        if (property_exists($component, 'message') && property_exists($component, 'messageTone')) {
            $component->message = $message;
            $component->messageTone = $tone;
        }
        DemoState::flash($message, $tone === 'error' ? 'info' : $tone);
        $component->dispatch('operator-notice', message: $message, tone: $tone);
    }
}
