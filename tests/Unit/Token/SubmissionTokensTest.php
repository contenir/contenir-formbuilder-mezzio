<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Token;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Mezzio\Token\SubmissionTokens;
use Contenir\FormBuilder\Service\TokenReplacer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class SubmissionTokensTest extends TestCase
{
    private static function tokens(): SubmissionTokens
    {
        return new SubmissionTokens(
            new TokenReplacer(),
            new FormDefinition(
                id: 1,
                slug: 'contact',
                title: 'Contact',
            ),
            ['name' => '<Ann & Lee>'],
            ['id' => 42],
        );
    }

    #[Test]
    public function replaceForHtmlEscapesValues(): void
    {
        static::assertSame('&lt;Ann &amp; Lee&gt; 42', self::tokens()->replaceForHtml('{field:name} {entry:id}'));
    }

    #[Test]
    public function replaceForUrlEncodesValues(): void
    {
        static::assertSame(
            '/t?n=%3CAnn%20%26%20Lee%3E&e=42',
            self::tokens()->replaceForUrl('/t?n={field:name}&e={entry:id}'),
        );
    }

    #[Test]
    public function replaceInsertsValuesAsTheyAre(): void
    {
        static::assertSame('<Ann & Lee> 42 contact', self::tokens()->replace('{field:name} {entry:id} {form:slug}'));
    }
}
