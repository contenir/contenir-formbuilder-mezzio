<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Exception;

use Mezzio\Session\SessionMiddleware;
use RuntimeException;

use function sprintf;

/**
 * Thrown when a request reaches the submit handler or the form presenter
 * without a mezzio-session: CSRF tokens and the error stash live in the
 * session, so neither can work without it.
 *
 * @api
 */
final class MissingSessionException extends RuntimeException
{
    public static function forRequest(): self
    {
        return new self(sprintf(
            'contenir/contenir-formbuilder-mezzio needs a session: pipe %s before the formbuilder routes and register a session persistence.',
            SessionMiddleware::class,
        ));
    }
}
