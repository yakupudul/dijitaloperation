<?php

namespace App\Support\Operator;

use App\Http\Middleware\RecordOperatorActivity;
use App\Services\Work\OperatorTime;
use Livewire\Component;

use function Livewire\on;

/**
 * Operatör süresi (Aşama 4): every button an operator presses on a panel screen (a Livewire action) is one click of its
 * minute, for the screen's brand when it has one. Polls, refreshes and lazy loads are the screen's own work, not clicks.
 */
final class OperatorClicks
{
    private const string NOT_A_CLICK = '/^(\$|__|refresh|poll|tick|heartbeat|load(?!More))/i';

    public static function register(): void
    {
        on('call', function (mixed $component, mixed $method): void {
            if (! $component instanceof Component || ! is_string($method) || preg_match(self::NOT_A_CLICK, $method) === 1
                || ! str_starts_with($component::class, 'App\\Livewire\\') || ($user = auth()->user()) === null) {
                return;
            }
            OperatorTime::record($user, self::brandOf($component), true);
        });
    }

    private static function brandOf(Component $component): ?int
    {
        foreach (['brand', 'brandId', 'brand_id'] as $property) {
            if (property_exists($component, $property)) {
                $brand = RecordOperatorActivity::brandOf($component->{$property} ?? null);
                if ($brand !== null) {
                    return $brand;
                }
            }
        }

        return null;
    }
}
