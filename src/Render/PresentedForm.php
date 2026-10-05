<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Render;

use Contenir\FormBuilder\Definition\FormDefinition;
use Laminas\Form\FormInterface;

/**
 * A form ready for a template: the definition, the built and hydrated form,
 * its rendered markup, and whether this request follows a successful
 * submission of it.
 *
 * @api
 */
final readonly class PresentedForm
{
    /**
     * @param string $anchor The HTML id to wrap the form in; posted back as `_anchor`.
     * @param bool $submitted True when the request carries `?submit={slug}`.
     * @param string|null $successTitle `settings.success.title`, as configured.
     * @param string|null $successMessage `settings.success.message`, as configured.
     * @param array<array-key, array<array-key, mixed>> $errors Validation messages stashed by the last invalid
     *                                                         submission, keyed by element name; `_csrf` is set
     *                                                         when its token was rejected.
     *
     * @mago-expect lint:excessive-parameter-list Immutable value object; the promoted constructor is its public shape.
     */
    public function __construct(
        public FormDefinition $definition,
        public FormInterface $form,
        public string $html,
        public string $anchor,
        public bool $submitted,
        public ?string $successTitle,
        public ?string $successMessage,
        public array $errors,
    ) {}
}
