<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Session;

use Contenir\FormBuilder\Mezzio\Exception\MissingSessionException;
use Contenir\FormBuilder\Mezzio\Session\RequestSession;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Session\Session;
use Mezzio\Session\SessionMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[Group('unit')]
final class RequestSessionTest extends TestCase
{
    #[Test]
    public function failsWhenTheAttributeIsNotASession(): void
    {
        $this->expectException(MissingSessionException::class);

        RequestSession::from((new ServerRequest())->withAttribute(
            SessionMiddleware::SESSION_ATTRIBUTE,
            new stdClass(),
        ));
    }

    #[Test]
    public function failsWithoutASession(): void
    {
        $this->expectException(MissingSessionException::class);
        $this->expectExceptionMessage(
            'contenir/contenir-formbuilder-mezzio needs a session: pipe Mezzio\Session\SessionMiddleware before the '
                . 'formbuilder routes and register a session persistence.',
        );

        RequestSession::from(new ServerRequest());
    }

    #[Test]
    public function returnsTheSessionMiddlewaresSession(): void
    {
        $session = new Session([]);

        static::assertSame(
            $session,
            RequestSession::from((new ServerRequest())->withAttribute(SessionMiddleware::SESSION_ATTRIBUTE, $session)),
        );
    }
}
