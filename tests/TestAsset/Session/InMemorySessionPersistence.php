<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\TestAsset\Session;

use Mezzio\Session\Session;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionPersistenceInterface;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One visitor's session, kept in memory between requests, as a cookie-backed
 * persistence would keep it for a browser. {@see forget()} starts a new
 * session, as a visitor without the cookie would.
 */
final class InMemorySessionPersistence implements SessionPersistenceInterface
{
    /** @var array<string, mixed> */
    public array $data = [];

    public function forget(): void
    {
        $this->data = [];
    }

    #[Override]
    public function initializeSessionFromRequest(ServerRequestInterface $request): SessionInterface
    {
        return new Session($this->data);
    }

    #[Override]
    public function persistSession(SessionInterface $session, ResponseInterface $response): ResponseInterface
    {
        $this->data = $session->toArray();

        return $response;
    }
}
