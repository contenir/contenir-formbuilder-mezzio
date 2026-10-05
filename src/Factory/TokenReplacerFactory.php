<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Factory;

use Contenir\FormBuilder\Mezzio\Container\Services;
use Contenir\FormBuilder\Mezzio\Token\TokenReplacerBuilder;
use Contenir\FormBuilder\Service\TokenReplacer;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

/**
 * A shared {@see TokenReplacer} with the configured `{site:*}` values and
 * namespaces, for code outside a request. The submit handler and the email
 * registrar build their own per submission, with `{site:base_url}` taken from
 * the request when it is not configured.
 *
 * @api
 */
final class TokenReplacerFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     */
    public function __invoke(ContainerInterface $container): TokenReplacer
    {
        return Services::get($container, TokenReplacerBuilder::class, TokenReplacerBuilder::class)->build();
    }
}
