<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Factory;

use Contenir\FormBuilder\Mezzio\Container\Services;
use Contenir\FormBuilder\Mezzio\Http\Responder;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use UnexpectedValueException;

/**
 * Needs the PSR-17 `ResponseFactoryInterface` and `StreamFactoryInterface`
 * services (laminas-diactoros' ConfigProvider registers both).
 *
 * @api
 */
final class ResponderFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     */
    public function __invoke(ContainerInterface $container): Responder
    {
        return new Responder(
            Services::get($container, ResponseFactoryInterface::class, ResponseFactoryInterface::class),
            Services::get($container, StreamFactoryInterface::class, StreamFactoryInterface::class),
        );
    }
}
