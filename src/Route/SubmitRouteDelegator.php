<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Route;

use Contenir\FormBuilder\Mezzio\Container\Services;
use Mezzio\Application;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

/**
 * Delegator factory for `Mezzio\Application` that registers the POST submit
 * route from `formbuilder.submit_route` when the application is created.
 * Registered by the {@see \Contenir\FormBuilder\Mezzio\ConfigProvider}; does
 * nothing when `formbuilder.submit_route` is `false`.
 *
 * @api
 */
final class SubmitRouteDelegator
{
    /**
     * @param callable(): Application $callback
     *
     * @throws ContainerExceptionInterface
     *
     * @mago-expect analysis:unused-parameter The delegator signature passes the service name.
     */
    public function __invoke(ContainerInterface $container, string $name, callable $callback): Application
    {
        $app   = $callback();
        $route = SubmitRoute::fromConfig(Services::config($container));
        if (null === $route) {
            return $app;
        }

        $app->route($route->path, $route->pipeline, ['POST'], $route->name)
            ->setOptions($route->options);

        return $app;
    }
}
