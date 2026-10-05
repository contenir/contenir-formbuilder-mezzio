<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Csrf;

use Contenir\FormBuilder\Service\FormBuilderInterface;
use Mezzio\Session\SessionInterface;
use SensitiveParameter;

/**
 * The form builder and CSRF tokens for a request's session: builds forms that
 * carry the session's token, and checks a submitted one.
 *
 * @api
 */
final readonly class CsrfFormFactory
{
    public function __construct(
        private FormBuilderInterface $builder,
        private CsrfTokenManager $tokens,
    ) {}

    public function accepts(SessionInterface $session, string $slug, #[SensitiveParameter] mixed $token): bool
    {
        return $this->tokens->isValid($session, $slug, $token);
    }

    public function builder(SessionInterface $session): SessionCsrfFormBuilder
    {
        return new SessionCsrfFormBuilder($this->builder, $this->tokens, $session);
    }
}
