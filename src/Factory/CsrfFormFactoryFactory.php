<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Factory;

use Contenir\FormBuilder\Mezzio\Container\Services;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfFormFactory;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenManager;
use Contenir\FormBuilder\Service\FormBuilderInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

/**
 * Pairs the `Contenir\FormBuilder\Service\FormBuilderInterface` service with
 * the CSRF token manager.
 *
 * @api
 */
final class CsrfFormFactoryFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     */
    public function __invoke(ContainerInterface $container): CsrfFormFactory
    {
        return new CsrfFormFactory(
            Services::get($container, FormBuilderInterface::class, FormBuilderInterface::class),
            Services::get($container, CsrfTokenManager::class, CsrfTokenManager::class),
        );
    }
}
