<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Route;

use Contenir\FormBuilder\Mezzio\Handler\SubmitHandler;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;

/**
 * The submit route, read from `formbuilder.submit_route`.
 *
 * `path` uses the FastRoute syntax by default; set it in your router's syntax
 * (and any `options` it needs, such as laminas-router constraints) when you
 * use another router. `middleware` lists services piped before the handler.
 * Set `formbuilder.submit_route` to `false` to register the route yourself.
 *
 * @internal
 */
final readonly class SubmitRoute
{
    public const string DEFAULT_PATH = '/forms/submit/{slug:[a-z0-9][a-z0-9\-]*}';

    /**
     * @param non-empty-string $path
     * @param non-empty-string $name
     * @param non-empty-list<string> $pipeline The configured middleware, then the handler.
     * @param array<array-key, mixed> $options
     */
    private function __construct(
        public string $path,
        public string $name,
        public array $pipeline,
        public array $options,
    ) {}

    /**
     * Null when the route is disabled.
     *
     * @param array<array-key, mixed> $config The `formbuilder` configuration.
     *
     * @mago-expect analysis:mixed-assignment Configuration is untyped input; each value is checked before use.
     */
    public static function fromConfig(array $config): ?self
    {
        $route = $config['submit_route'] ?? [];
        if (false === $route) {
            return null;
        }

        $route      = is_array($route) ? $route : [];
        $path       = $route['path'] ?? null;
        $middleware = $route['middleware'] ?? [];
        $options    = $route['options'] ?? [];

        return new self(
            path: is_string($path) && '' !== $path ? $path : self::DEFAULT_PATH,
            name: self::nameFromConfig($config),
            pipeline: [
                ...(is_array($middleware) ? array_values(array_filter($middleware, is_string(...))) : []),
                SubmitHandler::class,
            ],
            options: is_array($options) ? $options : [],
        );
    }

    /**
     * The route name, also when the route is disabled (a site that registers
     * it itself is expected to keep the configured name).
     *
     * @param array<array-key, mixed> $config The `formbuilder` configuration.
     *
     * @return non-empty-string
     *
     * @mago-expect analysis:mixed-assignment Configuration is untyped input; the name is checked with is_string().
     */
    public static function nameFromConfig(array $config): string
    {
        $route = $config['submit_route'] ?? null;
        $name  = is_array($route) ? $route['name'] ?? null : null;

        return is_string($name) && '' !== $name ? $name : SubmitHandler::ROUTE_NAME;
    }
}
