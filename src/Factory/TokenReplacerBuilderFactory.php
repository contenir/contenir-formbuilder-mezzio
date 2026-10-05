<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Factory;

use Contenir\FormBuilder\Mezzio\Container\Services;
use Contenir\FormBuilder\Mezzio\Token\TokenReplacerBuilder;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;

use function is_array;
use function is_callable;
use function is_string;

/**
 * Builds the {@see TokenReplacerBuilder} from `formbuilder.site_context`
 * (static `{site:*}` values) and `formbuilder.token_resolvers` (namespace =>
 * service id of a callable `fn (string $key): ?string`):
 *
 *     'formbuilder' => [
 *         'token_resolvers' => [
 *             'settings' => App\Mail\SettingsResolver::class,
 *         ],
 *     ],
 *
 * Resolvers whose service is not registered or not callable are skipped.
 *
 * @api
 */
final class TokenReplacerBuilderFactory
{
    /**
     * @throws ContainerExceptionInterface
     *
     * @mago-expect analysis:mixed-assignment Configuration is untyped input; each value is checked before use.
     * @mago-expect analysis:less-specific-nested-argument-type Resolvers are documented to return ?string; TokenReplacer enforces it.
     * @mago-expect analysis:less-specific-argument Configuration keys are strings by convention.
     */
    public function __invoke(ContainerInterface $container): TokenReplacerBuilder
    {
        $config      = Services::config($container);
        $siteContext = $config['site_context'] ?? [];
        $configured  = $config['token_resolvers'] ?? [];

        $resolvers = [];
        foreach (is_array($configured) ? $configured : [] as $namespace => $serviceId) {
            if (! is_string($namespace) || ! is_string($serviceId) || ! $container->has($serviceId)) {
                continue;
            }

            $resolver = $container->get($serviceId);
            if (is_callable($resolver)) {
                $resolvers[$namespace] = $resolver;
            }
        }

        return new TokenReplacerBuilder(is_array($siteContext) ? $siteContext : [], $resolvers);
    }
}
