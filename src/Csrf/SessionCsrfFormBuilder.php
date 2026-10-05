<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Csrf;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Service\FormBuilderInterface;
use Contenir\FormBuilder\Service\FormBuilderService;
use Laminas\Form\Exception\ExceptionInterface as FormException;
use Laminas\Form\FormInterface;
use Mezzio\Session\SessionInterface;
use Override;
use Random\RandomException;

/**
 * Builds the form with the inner builder, then adds a {@see CsrfElement}
 * bound to the request's mezzio-session under the CSRF element's name, which
 * replaces the laminas-session CSRF element the core builder adds.
 *
 * The CSRF element is always added, even when the inner builder did not add
 * one, so every form this builder produces is token-checked.
 *
 * @api
 */
final readonly class SessionCsrfFormBuilder implements FormBuilderInterface
{
    public function __construct(
        private FormBuilderInterface $inner,
        private CsrfTokenManager $tokens,
        private SessionInterface $session,
    ) {}

    /**
     * @throws FormException When Laminas rejects the form or the CSRF element.
     * @throws RandomException When the session's secret cannot be generated.
     */
    #[Override]
    public function build(FormDefinition $form): FormInterface
    {
        $built = $this->inner->build($form);
        $built->add(new CsrfElement(
            FormBuilderService::CSRF_NAME,
            $this->tokens->token($this->session, $form->slug),
            new CsrfTokenValidator($this->tokens, $this->session, $form->slug),
        ));

        return $built;
    }
}
