<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Csrf;

use Contenir\FormBuilder\Mezzio\Csrf\CsrfElement;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenManager;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenValidator;
use Mezzio\Session\Session;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class CsrfElementTest extends TestCase
{
    private CsrfTokenValidator $validator;

    #[Test]
    public function isAHiddenInput(): void
    {
        static::assertSame('hidden', (new CsrfElement('_csrf', 'token', $this->validator))->getAttribute('type'));
    }

    #[Test]
    public function itsValueIsAlwaysTheIssuedToken(): void
    {
        $element = new CsrfElement('_csrf', 'token', $this->validator);

        $element->setValue('posted');

        static::assertSame('token', $element->getValue());
    }

    #[Test]
    public function providesARequiredInputCheckedByTheValidator(): void
    {
        static::assertSame(
            ['name' => '_csrf', 'required' => true, 'validators' => [$this->validator]],
            (new CsrfElement('_csrf', 'token', $this->validator))->getInputSpecification(),
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->validator = new CsrfTokenValidator(new CsrfTokenManager(), new Session([]), 'contact');
    }
}
