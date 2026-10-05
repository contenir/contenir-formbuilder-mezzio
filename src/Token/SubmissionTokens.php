<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Token;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Service\TokenReplacer;

/**
 * A {@see TokenReplacer} bound to one submission's form, values and entry.
 *
 * @internal
 */
final readonly class SubmissionTokens
{
    /**
     * @param array<string, mixed> $values field name => submitted value
     * @param array<string, mixed> $entry  entry attributes (id, date, ip, status)
     */
    public function __construct(
        private TokenReplacer $tokens,
        private FormDefinition $form,
        private array $values,
        private array $entry,
    ) {}

    public function replace(string $template): string
    {
        return $this->tokens->replace($template, $this->form, $this->values, $this->entry);
    }

    /**
     * Every resolved value HTML-escaped, for templates rendered as HTML.
     */
    public function replaceForHtml(string $template): string
    {
        return $this->tokens->replaceForHtml($template, $this->form, $this->values, $this->entry);
    }

    /**
     * Every resolved value URL-encoded, for templates expanded into a URL.
     */
    public function replaceForUrl(string $template): string
    {
        return $this->tokens->replaceForUrl($template, $this->form, $this->values, $this->entry);
    }
}
