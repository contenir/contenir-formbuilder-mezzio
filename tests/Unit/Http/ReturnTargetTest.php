<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Http;

use Contenir\FormBuilder\Mezzio\Http\ReturnTarget;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ReturnTargetTest extends TestCase
{
    /**
     * @return array<string, array{string, mixed, string|null, string}>
     */
    public static function urlProvider(): array
    {
        return [
            'success flagged'           => [
                'https://site.example/contact',
                null,
                'contact',
                'https://site.example/contact?submit=contact',
            ],
            'success with anchor'       => [
                'https://site.example/contact',
                'form-contact',
                'contact',
                'https://site.example/contact?submit=contact#form-contact',
            ],
            'invalid has no query'      => ['https://site.example/contact', null, null, 'https://site.example/contact'],
            'invalid with anchor'       => ['/contact', 'form-contact', null, '/contact#form-contact'],
            'existing query kept'       => ['/p?a=1&b=2', null, 'contact', '/p?a=1&b=2&submit=contact'],
            'existing submit replaced'  => ['/p?a=1&submit=old#top', null, 'contact', '/p?a=1&submit=contact'],
            'existing submit dropped'   => ['/p?submit=old', null, null, '/p'],
            'fragment dropped'          => ['/p#top', null, null, '/p'],
            'fragment replaced'         => ['/p#top', 'form', 'c', '/p?submit=c#form'],
            'no referrer'               => ['', null, 'contact', '/?submit=contact'],
            'foreign referrer'          => ['https://evil.example/p', null, 'contact', '/?submit=contact'],
            'unsafe anchor dropped'     => ['/p', '1bad', 'c', '/p?submit=c'],
            'anchor with a path tail'   => ['/p', 'top/../x', 'c', '/p?submit=c'],
            'anchor with a query'       => ['/p', 'top?x=1', 'c', '/p?submit=c'],
            'anchor with a line break'  => ['/p', "top\n", 'c', '/p?submit=c'],
            'anchor with a leading gap' => ['/p', ' top', 'c', '/p?submit=c'],
            'array anchor dropped'      => ['/p', ['x'], 'c', '/p?submit=c'],
            'anchor with word chars'    => ['/p', 'Form_contact-2', 'c', '/p?submit=c#Form_contact-2'],
            'slug encoded'              => ['/p', null, 'a b&c', '/p?submit=a+b%26c'],
        ];
    }

    private static function target(
        string $referer,
        mixed $anchor = null,
        string $uri = 'https://site.example/forms/submit/contact',
    ): ReturnTarget {
        $headers = '' === $referer ? [] : ['Referer' => $referer];

        return ReturnTarget::fromRequest(
            new ServerRequest(
                uri: $uri,
                method: 'POST',
                headers: $headers,
            ),
            null === $anchor ? [] : ['_anchor' => $anchor],
        );
    }

    #[Test]
    #[DataProvider('urlProvider')]
    public function buildsTheRedirectBackToTheReferrer(
        string $referer,
        mixed $anchor,
        ?string $successSlug,
        string $expected,
    ): void {
        static::assertSame($expected, self::target($referer, $anchor)->url($successSlug));
    }

    #[Test]
    public function comparesTheReferrerWithTheRequestHostAndPort(): void
    {
        static::assertSame(
            ['https://site.example:8443/p', '/'],
            [
                self::target('https://site.example:8443/p', uri: 'https://site.example:8443/forms/submit/c')->url(null),
                self::target('https://site.example:8443/p', uri: 'https://other.example:8443/forms/submit/c')->url(
                    null,
                ),
            ],
        );
    }
}
