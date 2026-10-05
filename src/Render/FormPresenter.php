<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Render;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfFormFactory;
use Contenir\FormBuilder\Mezzio\Exception\MissingSessionException;
use Contenir\FormBuilder\Mezzio\Handler\SubmitHandler;
use Contenir\FormBuilder\Mezzio\Http\ReturnTarget;
use Contenir\FormBuilder\Mezzio\Loader\FormLoaderInterface;
use Contenir\FormBuilder\Mezzio\Session\RequestSession;
use Contenir\FormBuilder\Mezzio\State\FormStateStash;
use Contenir\FormBuilder\Render\FormMarkup;
use Laminas\Form\Exception\ExceptionInterface as FormException;
use Mezzio\Router\Exception\ExceptionInterface as RouterException;
use Mezzio\Router\RouterInterface;
use Psr\Http\Message\ServerRequestInterface;
use Random\RandomException;

use function array_filter;
use function is_array;
use function is_string;
use function preg_replace;

/**
 * Prepares a form for a page: loads the definition, builds the form with the
 * session's CSRF token, points it at the submit route, hydrates it from the
 * stash left by an invalid submission, and renders it with the core
 * {@see FormMarkup}.
 *
 * This is the Mezzio counterpart of the laminas-mvc `formMarkup` and
 * `formStashedState` view helpers: call it from the handler that renders the
 * page and pass the result to the template, or use {@see FormBlockRenderer}
 * to render the bundled `formbuilder::form` template in one step.
 *
 * @api
 */
final readonly class FormPresenter
{
    public function __construct(
        private FormLoaderInterface $loader,
        private CsrfFormFactory $forms,
        private FormStateStash $stash,
        private RouterInterface $router,
        private string $routeName = SubmitHandler::ROUTE_NAME,
    ) {}

    /**
     * The HTML id the form is wrapped in and returns to after a submission.
     * Characters outside `[A-Za-z0-9_-]` become `-`, so it needs no escaping.
     */
    public static function anchorFor(string $slug): string
    {
        return 'formbuilder-' . (preg_replace('/[^A-Za-z0-9_\-]/', replacement: '-', subject: $slug) ?? '');
    }

    /**
     * @mago-expect analysis:mixed-assignment Form settings are decoded JSON; only strings are kept.
     */
    private static function successText(FormDefinition $form, string $key): ?string
    {
        $success = $form->settings['success'] ?? null;
        $text    = is_array($success) ? $success[$key] ?? null : null;

        return is_string($text) ? $text : null;
    }

    /**
     * Null when no form has the slug.
     *
     * @throws FormException When Laminas rejects the built form.
     * @throws MissingSessionException When the request carries no session.
     * @throws RandomException When the session's CSRF secret cannot be generated.
     * @throws RouterException When the submit route cannot be generated.
     */
    public function present(ServerRequestInterface $request, string $slug): ?PresentedForm
    {
        $form = $this->loader->loadBySlug($slug);
        if (null === $form) {
            return null;
        }

        $session = RequestSession::from($request);
        $built   = $this->forms->builder($session)->build($form);
        $built->setAttribute('action', $this->router->generateUri($this->routeName, ['slug' => $form->slug]));

        $stashed = $this->stash->consume($session, $form->slug);
        $errors  = array_filter($stashed['errors'] ?? [], is_array(...));
        if (null !== $stashed) {
            $built->setData($stashed['values']);
            $built->setMessages($errors);
        }

        $anchor = self::anchorFor($form->slug);
        $field  = '<input type="hidden" name="' . ReturnTarget::ANCHOR_FIELD . "\" value=\"{$anchor}\">";

        return new PresentedForm(
            definition: $form,
            form: $built,
            html: (string) preg_replace('~</form>$~D', "{$field}</form>", (new FormMarkup())->render($form, $built)),
            anchor: $anchor,
            submitted: $form->slug === ($request->getQueryParams()['submit'] ?? null),
            successTitle: self::successText($form, 'title'),
            successMessage: self::successText($form, 'message'),
            errors: $errors,
        );
    }
}
