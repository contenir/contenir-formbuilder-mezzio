<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Http;

use Psr\Http\Message\ServerRequestInterface;

use function http_build_query;
use function is_string;
use function parse_str;
use function preg_match;
use function strlen;
use function strstr;
use function substr;

/**
 * Where a non-JSON submission returns to: the referring page on this site
 * (see {@see SameSiteReferer}), scrolled back to the form when the request
 * posted an `_anchor`.
 *
 * @internal
 */
final readonly class ReturnTarget
{
    public const string ANCHOR_FIELD = '_anchor';

    private function __construct(
        private string $referer,
        private string $anchor,
    ) {}

    /**
     * @param array<array-key, mixed> $post
     */
    public static function fromRequest(ServerRequestInterface $request, array $post): self
    {
        $uri = $request->getUri();

        return new self(
            SameSiteReferer::resolve($request->getHeaderLine('Referer'), $uri->getHost(), $uri->getPort()),
            self::anchor($post[self::ANCHOR_FIELD] ?? null),
        );
    }

    /**
     * Restricts an `_anchor` to a safe HTML id: it is appended to a redirect
     * URL, so it must not carry path or query components.
     */
    private static function anchor(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Za-z][\w\-]*$/D', $value) === 1 ? $value : '';
    }

    /**
     * The referrer with `?submit={slug}` for a successful submission, or
     * without a `submit` parameter for an invalid one (pass null). Any
     * `submit` parameter already on the referrer is replaced and its fragment
     * is dropped; the anchor, when there is one, becomes the fragment.
     */
    public function url(?string $successSlug): string
    {
        $referer = (string) strstr("{$this->referer}#", needle: '#', before_needle: true);
        $base    = (string) strstr("{$referer}?", needle: '?', before_needle: true);
        $query   = [];
        parse_str(substr($referer, strlen($base) + 1), $query);
        unset($query['submit']);
        if (null !== $successSlug) {
            $query['submit'] = $successSlug;
        }

        $url = [] === $query ? $base : "{$base}?" . http_build_query($query);

        return '' === $this->anchor ? $url : "{$url}#{$this->anchor}";
    }
}
