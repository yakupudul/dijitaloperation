<?php

namespace App\Services\Ai;

/**
 * Faz 14: "about how much will this click cost" — the route's first provider / model price for an expected
 * token count. Free models show "ücretsiz"; unknown prices show nothing.
 */
final class AiCostEstimator
{
    public function __construct(private readonly AiRouteResolver $routes, private readonly AiPricing $pricing) {}

    public function label(string $routeKey, int $inputTokens, int $outputTokens): ?string
    {
        $route = $this->routes->resolve($routeKey);
        if ($route->isEmpty() || $route->primaryProvider() === null || $route->primaryModel() === null) {
            return null;
        }
        if ($this->pricing->isFree((string) $route->primaryProvider(), (string) $route->primaryModel())) {
            return 'ücretsiz model';
        }
        $cost = $this->pricing->cost((string) $route->primaryProvider(), (string) $route->primaryModel(), $inputTokens, $outputTokens);

        return $cost === null ? null : '~$'.number_format(max($cost, 0.0001), $cost < 0.01 ? 4 : 2, ',', '.');
    }
}
