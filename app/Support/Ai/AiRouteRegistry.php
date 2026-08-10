<?php

namespace App\Support\Ai;

/**
 * Code-defined AI route descriptors. Modules register product routes;
 * Core stores only ordered provider/model steps.
 *
 * @phpstan-type RouteDescriptor array{
 *     key: string,
 *     name: string,
 *     module: string,
 *     description: string,
 *     default_steps: list<array{provider: string, model: string}>
 * }
 */
final class AiRouteRegistry
{
    /**
     * @var array<string, RouteDescriptor>
     */
    private array $routes = [];

    /**
     * @param  RouteDescriptor  $descriptor
     */
    public function register(array $descriptor): void
    {
        $key = $descriptor['key'];
        $this->routes[$key] = $descriptor;
    }

    public function has(string $routeKey): bool
    {
        return isset($this->routes[$routeKey]);
    }

    /**
     * @return RouteDescriptor
     */
    public function get(string $routeKey): array
    {
        if (! isset($this->routes[$routeKey])) {
            throw new \InvalidArgumentException('Unknown AI route: '.$routeKey);
        }

        return $this->routes[$routeKey];
    }

    /**
     * @return list<RouteDescriptor>
     */
    public function all(): array
    {
        return array_values($this->routes);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->routes);
    }
}
