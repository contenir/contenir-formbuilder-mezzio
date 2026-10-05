<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\TestAsset\Builder;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Service\FormBuilderInterface;
use Laminas\Form\FormInterface;
use Override;

/**
 * Returns the same form for every definition, and records the definitions.
 */
final class FixedFormBuilder implements FormBuilderInterface
{
    /** @var list<FormDefinition> */
    public array $built = [];

    public function __construct(
        private readonly FormInterface $form,
    ) {}

    #[Override]
    public function build(FormDefinition $form): FormInterface
    {
        $this->built[] = $form;

        return $this->form;
    }
}
