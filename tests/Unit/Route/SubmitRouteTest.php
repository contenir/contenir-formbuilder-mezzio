<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Route;

use Contenir\FormBuilder\Mezzio\Handler\SubmitHandler;
use Contenir\FormBuilder\Mezzio\Route\SubmitRoute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class SubmitRouteTest extends TestCase
{
    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function defaultsProvider(): array
    {
        return [
            'no route config'    => [[]],
            'route not an array' => [['submit_route' => 'x']],
            'route is true'      => [['submit_route' => true]],
            'unusable values'    => [['submit_route' => [
                'path'       => '',
                'name'       => '',
                'middleware' => 'x',
                'options'    => 'x',
            ]]],
            'non-string values'  => [['submit_route' => ['path' => 5, 'name' => 5]]],
        ];
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function nameProvider(): array
    {
        return [
            'configured'        => [['submit_route' => ['name' => 'forms.submit']], 'forms.submit'],
            'route disabled'    => [['submit_route' => false], 'formbuilder.submit'],
            'not configured'    => [[], 'formbuilder.submit'],
            'empty name'        => [['submit_route' => ['name' => '']], 'formbuilder.submit'],
            'name not a string' => [['submit_route' => ['name' => ['x']]], 'formbuilder.submit'],
        ];
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[Test]
    #[DataProvider('defaultsProvider')]
    public function fallsBackToTheDefaults(array $config): void
    {
        $route = SubmitRoute::fromConfig($config);

        static::assertSame(
            ['/forms/submit/{slug:[a-z0-9][a-z0-9\-]*}', 'formbuilder.submit', [SubmitHandler::class], []],
            [$route?->path, $route?->name, $route?->pipeline, $route?->options],
        );
    }

    #[Test]
    public function falseDisablesTheRoute(): void
    {
        static::assertNull(SubmitRoute::fromConfig(['submit_route' => false]));
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[Test]
    #[DataProvider('nameProvider')]
    public function nameFromConfigReadsTheRouteName(array $config, string $expected): void
    {
        static::assertSame($expected, SubmitRoute::nameFromConfig($config));
    }

    #[Test]
    public function readsTheConfiguredRoute(): void
    {
        $route = SubmitRoute::fromConfig([
            'submit_route' => [
                'path'       => '/forms/submit/:slug',
                'name'       => 'forms.submit',
                'middleware' => ['a' => 'Session', 5, 'Csp'],
                'options'    => ['constraints' => ['slug' => '[a-z]+']],
            ],
        ]);

        static::assertSame(
            [
                '/forms/submit/:slug',
                'forms.submit',
                ['Session', 'Csp', SubmitHandler::class],
                ['constraints' => ['slug' => '[a-z]+']],
            ],
            [$route?->path, $route?->name, $route?->pipeline, $route?->options],
        );
    }
}
