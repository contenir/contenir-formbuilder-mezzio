<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Factory;

use Contenir\FormBuilder\Mezzio\Container\Services;
use Contenir\FormBuilder\Mezzio\Registrar\StoreSubmissionRegistrar;
use Contenir\FormBuilder\Mezzio\Repository\EntryRepositoryInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

/**
 * @api
 */
final class StoreSubmissionRegistrarFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     */
    public function __invoke(ContainerInterface $container): StoreSubmissionRegistrar
    {
        return new StoreSubmissionRegistrar(
            Services::get($container, EntryRepositoryInterface::class, EntryRepositoryInterface::class),
        );
    }
}
