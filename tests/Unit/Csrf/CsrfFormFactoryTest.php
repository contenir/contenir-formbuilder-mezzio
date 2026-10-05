<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Csrf;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfFormFactory;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenManager;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Builder\FixedFormBuilder;
use Laminas\Form\Form;
use Mezzio\Session\Session;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class CsrfFormFactoryTest extends TestCase
{
    #[Test]
    public function acceptsOnlyTheSessionsTokenForTheForm(): void
    {
        $tokens  = new CsrfTokenManager();
        $session = new Session([]);
        $forms   = new CsrfFormFactory(new FixedFormBuilder(new Form()), $tokens);
        $token   = $tokens->token($session, 'contact');

        static::assertSame(
            [true, false, false],
            [
                $forms->accepts($session, 'contact', $token),
                $forms->accepts($session, 'other', $token),
                $forms->accepts(new Session([]), 'contact', $token),
            ],
        );
    }

    #[Test]
    public function buildersCarryTheSessionsToken(): void
    {
        $tokens  = new CsrfTokenManager();
        $session = new Session([]);
        $form    = (new CsrfFormFactory(new FixedFormBuilder(new Form()), $tokens))->builder($session)
            ->build(new FormDefinition(
                id: 1,
                slug: 'contact',
                title: 'Contact',
            ));

        static::assertSame($tokens->token($session, 'contact'), $form->get('_csrf')->getValue());
    }
}
