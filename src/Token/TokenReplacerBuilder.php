<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Token;

use Contenir\FormBuilder\Service\TokenReplacer;

use function array_replace;

/**
 * Builds {@see TokenReplacer} instances that share the configured `{site:*}`
 * values and extra merge-tag namespaces.
 *
 * Mezzio has no request in the container, so request-derived site values
 * (`base_url`) are passed to {@see build()} for each submission rather than
 * baked into a shared service. Configured values win over request-derived
 * ones, so a pinned `site_context.base_url` is never replaced by the
 * request's Host header.
 *
 * @api
 */
final readonly class TokenReplacerBuilder
{
    /**
     * @param array<string, mixed> $siteContext Static values for `{site:*}` tags.
     * @param array<string, callable(string): ?string> $resolvers Extra namespaces, keyed by name.
     */
    public function __construct(
        private array $siteContext = [],
        private array $resolvers = [],
    ) {}

    /**
     * @param array<string, mixed> $requestContext `{site:*}` values derived from the current request.
     */
    public function build(array $requestContext = []): TokenReplacer
    {
        $tokens = new TokenReplacer(array_replace($requestContext, $this->siteContext));
        foreach ($this->resolvers as $namespace => $resolver) {
            $tokens->register($namespace, $resolver);
        }

        return $tokens;
    }
}
