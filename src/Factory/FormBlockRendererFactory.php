<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Factory;

use Contenir\FormBuilder\Mezzio\Container\Services;
use Contenir\FormBuilder\Mezzio\Render\FormBlockRenderer;
use Contenir\FormBuilder\Mezzio\Render\FormPresenter;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

use function is_string;

/**
 * Renders the template named by `formbuilder.template`
 * (default `formbuilder::form`).
 *
 * @api
 */
final class FormBlockRendererFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     *
     * @mago-expect analysis:mixed-assignment Configuration is untyped input; the template name is checked with is_string().
     */
    public function __invoke(ContainerInterface $container): FormBlockRenderer
    {
        $template = Services::config($container)['template'] ?? null;

        return new FormBlockRenderer(
            Services::get($container, FormPresenter::class, FormPresenter::class),
            Services::get($container, TemplateRendererInterface::class, TemplateRendererInterface::class),
            is_string($template) && '' !== $template ? $template : FormBlockRenderer::DEFAULT_TEMPLATE,
        );
    }
}
