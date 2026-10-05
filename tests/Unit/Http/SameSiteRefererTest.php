<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Http;

use Contenir\FormBuilder\Mezzio\Http\SameSiteReferer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Referer header is client-controlled: only a local path or a same-host
 * http(s) URL may be followed.
 */
#[Group('unit')]
final class SameSiteRefererTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null, int|null, string}>
     */
    public static function refererProvider(): array
    {
        return [
            'empty'                       => ['', 'site.example', null, '/'],
            'local path'                  => ['/contact?x=1#top', 'site.example', null, '/contact?x=1#top'],
            'root'                        => ['/', 'site.example', null, '/'],
            'local path, unknown host'    => ['/contact', null, null, '/contact'],
            'protocol-relative'           => ['//evil.example/p', 'site.example', null, '/'],
            'backslash after slash'       => ['/\\evil.example/p', 'site.example', null, '/'],
            'backslash anywhere'          => ['https://site.example\\@evil.example/', 'site.example', null, '/'],
            'carriage return'             => ["/p\rx", 'site.example', null, '/'],
            'line feed'                   => ["/p\nLocation: https://evil.example", 'site.example', null, '/'],
            'NUL'                         => ["/p\0", 'site.example', null, '/'],
            'unit separator'              => ["/p\x1F", 'site.example', null, '/'],
            'DEL'                         => ["/p\x7F", 'site.example', null, '/'],
            'tab'                         => ["/p\tx", 'site.example', null, '/'],
            'space is allowed'            => ['/p x', 'site.example', null, '/p x'],
            'same host https'             => ['https://site.example/p', 'site.example', null, 'https://site.example/p'],
            'same host http'              => ['http://site.example/p', 'site.example', null, 'http://site.example/p'],
            'host compared case-blind'    => ['https://SITE.example/p', 'site.example', null, 'https://SITE.example/p'],
            'request host upper-case'     => ['https://site.example/p', 'SITE.EXAMPLE', null, 'https://site.example/p'],
            'scheme compared case-blind'  => ['HTTPS://site.example/p', 'site.example', null, 'HTTPS://site.example/p'],
            'other host'                  => ['https://evil.example/p', 'site.example', null, '/'],
            'lookalike host'              => ['https://site.example.evil.example/p', 'site.example', null, '/'],
            'userinfo trick'              => ['https://site.example@evil.example/p', 'site.example', null, '/'],
            'other scheme'                => ['ftp://site.example/p', 'site.example', null, '/'],
            'javascript'                  => ['javascript:alert(1)', 'site.example', null, '/'],
            'data'                        => ['data:text/html,x', 'site.example', null, '/'],
            'no scheme or slash'          => ['site.example/p', 'site.example', null, '/'],
            'unparseable'                 => ['http:///p', 'site.example', null, '/'],
            'unknown request host'        => ['https://site.example/p', null, null, '/'],
            'empty request host'          => ['https://site.example/p', '', null, '/'],
            'port given, matches'         => [
                'https://site.example:8443/p',
                'site.example',
                8443,
                'https://site.example:8443/p',
            ],
            'port given, request default' => ['https://site.example:8443/p', 'site.example', null, '/'],
            'port given, differs'         => ['https://site.example:8443/p', 'site.example', 9443, '/'],
            'no port, request port'       => ['https://site.example/p', 'site.example', 8443, 'https://site.example/p'],
        ];
    }

    #[Test]
    #[DataProvider('refererProvider')]
    public function followsOnlyLocalPathsAndSameHostUrls(
        string $referer,
        ?string $host,
        ?int $port,
        string $expected,
    ): void {
        static::assertSame($expected, SameSiteReferer::resolve($referer, $host, $port));
    }
}
