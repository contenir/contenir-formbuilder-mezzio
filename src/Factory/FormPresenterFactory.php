<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Factory;

use Contenir\FormBuilder\Mezzio\Container\Services;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfFormFactory;
use Contenir\FormBuilder\Mezzio\Loader\FormLoaderInterface;
use Contenir\FormBuilder\Mezzio\Render\FormPresenter;
use Contenir\FormBuilder\Mezzio\Route\SubmitRoute;
use Contenir\FormBuilder\Mezzio\State\FormStateStash;
use Mezzio\Router\RouterInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

/**
 * Points forms at the route named by `formbuilder.submit_route.name`.
 *
 * @api
 */
final class FormPresenterFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     */
    public function __invoke(ContainerInterface $container): FormPresenter
    {
        return new FormPresenter(
            Services::get($container, FormLoaderInterface::class, FormLoaderInterface::class),
            Services::get($container, CsrfFormFactory::class, CsrfFormFactory::class),
            Services::get($container, FormStateStash::class, FormStateStash::class),
            Services::get($container, RouterInterface::class, RouterInterface::class),
            SubmitRoute::nameFromConfig(Services::config($container)),
        );
    }
}
