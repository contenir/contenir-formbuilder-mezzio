<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Render;

use Contenir\FormBuilder\Mezzio\Exception\MissingSessionException;
use Laminas\Form\Exception\ExceptionInterface as FormException;
use Mezzio\Router\Exception\ExceptionInterface as RouterException;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ServerRequestInterface;
use Random\RandomException;

/**
 * Renders a form block, the form or its success state, through the
 * application's template renderer.
 *
 * The template (`formbuilder::form` by default) receives the
 * {@see PresentedForm} as `form`. The bundled template is plain PHP and works
 * with the laminas-view and Plates renderers; with another engine, configure
 * `formbuilder.template` and provide your own.
 *
 * @api
 */
final readonly class FormBlockRenderer
{
    public const string DEFAULT_TEMPLATE = 'formbuilder::form';

    public function __construct(
        private FormPresenter $presenter,
        private TemplateRendererInterface $templates,
        private string $template = self::DEFAULT_TEMPLATE,
    ) {}

    /**
     * An empty string when no form has the slug.
     *
     * @throws FormException When Laminas rejects the built form.
     * @throws MissingSessionException When the request carries no session.
     * @throws RandomException When the session's CSRF secret cannot be generated.
     * @throws RouterException When the submit route cannot be generated.
     */
    public function render(ServerRequestInterface $request, string $slug): string
    {
        $form = $this->presenter->present($request, $slug);

        return null === $form ? '' : $this->templates->render($this->template, ['form' => $form]);
    }
}
