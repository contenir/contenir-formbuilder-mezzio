<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Csrf;

use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenManager;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenValidator;
use Mezzio\Session\Session;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class CsrfTokenValidatorTest extends TestCase
{
    #[Test]
    public function acceptsTheIssuedTokenWithNoMessages(): void
    {
        $tokens    = new CsrfTokenManager();
        $session   = new Session([]);
        $validator = new CsrfTokenValidator($tokens, $session, 'contact');

        static::assertSame([true, []], [
            $validator->isValid($tokens->token($session, 'contact')),
            $validator->getMessages(),
        ]);
    }

    #[Test]
    public function clearsTheMessagesOnceATokenIsAccepted(): void
    {
        $tokens    = new CsrfTokenManager();
        $session   = new Session([]);
        $validator = new CsrfTokenValidator($tokens, $session, 'contact');
        $validator->isValid('wrong');

        $validator->isValid($tokens->token($session, 'contact'));

        static::assertSame([], $validator->getMessages());
    }

    #[Test]
    public function hasNoMessagesBeforeValidating(): void
    {
        static::assertSame(
            [],
            (new CsrfTokenValidator(new CsrfTokenManager(), new Session([]), 'contact'))->getMessages(),
        );
    }

    #[Test]
    public function rejectsATokenForAnotherFormWithTheNotSameMessage(): void
    {
        $tokens    = new CsrfTokenManager();
        $session   = new Session([]);
        $validator = new CsrfTokenValidator($tokens, $session, 'contact');

        static::assertSame(
            [false, ['notSame' => 'The form submitted did not originate from the expected site']],
            [$validator->isValid($tokens->token($session, 'other')), $validator->getMessages()],
        );
    }
}
