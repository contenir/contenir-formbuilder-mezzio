<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Session;

use Contenir\FormBuilder\Mezzio\Exception\MissingSessionException;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The mezzio-session that `SessionMiddleware` attached to the request.
 *
 * @internal
 */
final class RequestSession
{
    /**
     * @throws MissingSessionException When the request carries no session.
     *
     * @mago-expect analysis:mixed-assignment Request attributes are untyped; the session is checked with instanceof.
     */
    public static function from(ServerRequestInterface $request): SessionInterface
    {
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        if (! $session instanceof SessionInterface) {
            throw MissingSessionException::forRequest();
        }

        return $session;
    }
}
