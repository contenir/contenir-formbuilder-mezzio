<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Token;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Mezzio\Token\TokenReplacerBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class TokenReplacerBuilderTest extends TestCase
{
    private static function form(): FormDefinition
    {
        return new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
        );
    }

    #[Test]
    public function configuredSiteValuesWinOverRequestValues(): void
    {
        $tokens = (new TokenReplacerBuilder(['base_url' => 'https://pinned.example']))->build([
            'base_url'  => 'https://request.example',
            'admin_url' => 'https://admin.example',
        ]);

        static::assertSame(
            'https://pinned.example https://admin.example',
            $tokens->replace('{site:base_url} {site:admin_url}', self::form(), []),
        );
    }

    #[Test]
    public function eachBuildIsIndependent(): void
    {
        $builder = new TokenReplacerBuilder();
        $builder->build(['base_url' => 'https://first.example']);

        static::assertSame('{site:base_url}', $builder->build()->replace('{site:base_url}', self::form(), []));
    }

    #[Test]
    public function registersTheResolvers(): void
    {
        $tokens = (new TokenReplacerBuilder(resolvers: [
            'settings' => static fn(string $key): ?string => 'phone' === $key ? '555' : null,
            'other'    => static fn(string $key): string => "o:{$key}",
        ]))->build();

        static::assertSame(
            '555 {settings:fax} o:x',
            $tokens->replace('{settings:phone} {settings:fax} {other:x}', self::form(), []),
        );
    }

    #[Test]
    public function withoutSiteValuesSiteTagsAreLeftInPlace(): void
    {
        static::assertSame(
            '{site:base_url}',
            (new TokenReplacerBuilder())->build()
                ->replace('{site:base_url}', self::form(), []),
        );
    }
}
