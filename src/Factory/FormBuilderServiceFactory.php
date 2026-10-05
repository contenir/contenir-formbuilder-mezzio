<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Factory;

use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Validator\ValidatorFactory;

/**
 * Builds the core form builder with the standard field types and validators.
 * Register your own factory for `Contenir\FormBuilder\Service\FormBuilderInterface`
 * to add field types or replace the builder.
 *
 * @api
 */
final class FormBuilderServiceFactory
{
    public function __invoke(): FormBuilderService
    {
        return new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory());
    }
}
