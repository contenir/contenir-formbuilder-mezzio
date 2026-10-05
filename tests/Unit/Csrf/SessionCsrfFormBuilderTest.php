<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Csrf;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfElement;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenManager;
use Contenir\FormBuilder\Mezzio\Csrf\SessionCsrfFormBuilder;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Builder\FixedFormBuilder;
use Laminas\Form\Element\Csrf;
use Laminas\Form\Element\Text;
use Laminas\Form\Form;
use Mezzio\Session\Session;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;

#[Group('unit')]
final class SessionCsrfFormBuilderTest extends TestCase
{
    private static function definition(): FormDefinition
    {
        return new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
        );
    }

    #[Test]
    public function addsTheCsrfElementWhenTheInnerBuilderDidNot(): void
    {
        $form    = new Form();
        $tokens  = new CsrfTokenManager();
        $session = new Session([]);

        (new SessionCsrfFormBuilder(new FixedFormBuilder($form), $tokens, $session))->build(self::definition());

        static::assertSame($tokens->token($session, 'contact'), $form->get('_csrf')->getValue());
    }

    #[Test]
    public function buildsWithTheInnerBuilder(): void
    {
        $inner = new FixedFormBuilder(new Form());

        (new SessionCsrfFormBuilder($inner, new CsrfTokenManager(), new Session([])))->build(self::definition());

        static::assertSame([self::definition()->slug], [$inner->built[0]->slug]);
    }

    #[Test]
    public function replacesTheLaminasSessionCsrfElement(): void
    {
        $form = new Form();
        $form->add(new Text('name'));
        $form->add(new Csrf('_csrf'));

        $built = (new SessionCsrfFormBuilder(
            new FixedFormBuilder($form),
            new CsrfTokenManager(),
            new Session([]),
        ))->build(self::definition());

        static::assertSame(
            [$form, ['name', '_csrf'], CsrfElement::class],
            [$built, array_keys($built->getElements()), $built->get('_csrf')::class],
        );
    }

    #[Test]
    public function theBuiltFormAcceptsTheSessionsToken(): void
    {
        $tokens  = new CsrfTokenManager();
        $session = new Session([]);
        $form    = (new SessionCsrfFormBuilder(new FixedFormBuilder(new Form()), $tokens, $session))->build(
            self::definition(),
        );

        $form->setData(['_csrf' => $tokens->token($session, 'contact')]);

        static::assertTrue($form->isValid());
    }

    #[Test]
    public function theBuiltFormRejectsATokenFromAnotherSession(): void
    {
        $tokens = new CsrfTokenManager();
        $form   = (new SessionCsrfFormBuilder(new FixedFormBuilder(new Form()), $tokens, new Session([])))->build(
            self::definition(),
        );

        $form->setData(['_csrf' => $tokens->token(new Session([]), 'contact')]);

        static::assertSame(
            [false, ['_csrf' => ['notSame' => 'The form submitted did not originate from the expected site']]],
            [$form->isValid(), $form->getMessages()],
        );
    }

    #[Test]
    public function theBuiltFormRequiresAToken(): void
    {
        $form = (new SessionCsrfFormBuilder(
            new FixedFormBuilder(new Form()),
            new CsrfTokenManager(),
            new Session([]),
        ))->build(self::definition());

        $form->setData([]);

        static::assertSame([false, ['isEmpty']], [$form->isValid(), array_keys($form->getMessages()['_csrf'] ?? [])]);
    }
}
