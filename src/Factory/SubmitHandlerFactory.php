<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Factory;

use Contenir\FormBuilder\Mezzio\Container\Services;
use Contenir\FormBuilder\Mezzio\Handler\SubmissionPipeline;
use Contenir\FormBuilder\Mezzio\Handler\SubmitHandler;
use Contenir\FormBuilder\Mezzio\Http\Responder;
use Contenir\FormBuilder\Mezzio\Loader\FormLoaderInterface;
use Contenir\FormBuilder\Mezzio\State\FormStateStash;
use Contenir\FormBuilder\Mezzio\Token\TokenReplacerBuilder;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

/**
 * @api
 */
final class SubmitHandlerFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     */
    public function __invoke(ContainerInterface $container): SubmitHandler
    {
        return new SubmitHandler(
            Services::get($container, FormLoaderInterface::class, FormLoaderInterface::class),
            Services::get($container, SubmissionPipeline::class, SubmissionPipeline::class),
            Services::get($container, FormStateStash::class, FormStateStash::class),
            Services::get($container, TokenReplacerBuilder::class, TokenReplacerBuilder::class),
            Services::get($container, Responder::class, Responder::class),
        );
    }
}
