<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Csrf;

use Laminas\Form\Element;
use Laminas\InputFilter\InputProviderInterface;
use Override;
use SensitiveParameter;

/**
 * Hidden input carrying the session's CSRF token for one form.
 *
 * Takes the place of the `Laminas\Form\Element\Csrf` element the core builder
 * adds, which needs laminas-session. Like that element, its value is always
 * the issued token, whatever data the form is populated with, and it provides
 * its own required input checked by {@see CsrfTokenValidator}.
 *
 * @api
 */
final class CsrfElement extends Element implements InputProviderInterface
{
    public function __construct(
        string $name,
        #[SensitiveParameter]
        private readonly string $token,
        private readonly CsrfTokenValidator $validator,
    ) {
        parent::__construct($name);
        $this->setAttribute('type', 'hidden');
    }

    /**
     * @return array{name: string, required: true, validators: list<CsrfTokenValidator>}
     */
    #[Override]
    public function getInputSpecification(): array
    {
        return [
            'name'       => (string) $this->getName(),
            'required'   => true,
            'validators' => [$this->validator],
        ];
    }

    #[Override]
    public function getValue(): string
    {
        return $this->token;
    }
}
