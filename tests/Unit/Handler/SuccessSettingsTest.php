<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Handler;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Mezzio\Handler\SuccessSettings;
use Contenir\FormBuilder\Mezzio\Token\SubmissionTokens;
use Contenir\FormBuilder\Service\TokenReplacer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class SuccessSettingsTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, array<string, bool|string>}>
     */
    public static function payloadProvider(): array
    {
        return [
            'no settings'          => [[], ['ok' => true, 'mode' => 'redirect_referrer']],
            'unknown mode'         => [
                ['success' => ['mode' => 'teleport']],
                ['ok' => true, 'mode' => 'redirect_referrer'],
            ],
            'mode not a string'    => [
                ['success' => ['mode' => ['inline_message']]],
                ['ok' => true, 'mode' => 'redirect_referrer'],
            ],
            'success not an array' => [['success' => 'x'], ['ok' => true, 'mode' => 'redirect_referrer']],
            'referrer mode'        => [
                ['success' => ['mode' => 'redirect_referrer', 'title' => 'T']],
                ['ok' => true, 'mode' => 'redirect_referrer'],
            ],
            'redirect url'         => [
                ['success' => ['mode' => 'redirect_url', 'redirect_url' => '/thanks/{field:name}?e={entry:id}']],
                ['ok' => true, 'mode' => 'redirect_url', 'url' => '/thanks/Ann%20Lee?e=42'],
            ],
            'redirect url empty'   => [
                ['success' => ['mode' => 'redirect_url', 'redirect_url' => '']],
                ['ok' => true, 'mode' => 'redirect_url'],
            ],
            'redirect url missing' => [
                ['success' => ['mode' => 'redirect_url']],
                ['ok' => true, 'mode' => 'redirect_url'],
            ],
            'url in another mode'  => [
                ['success' => ['mode' => 'inline_message', 'redirect_url' => '/x']],
                ['ok' => true, 'mode' => 'inline_message', 'title' => '', 'message' => ''],
            ],
            'inline message'       => [
                ['success' => [
                    'mode'    => 'inline_message',
                    'title'   => 'Thanks {field:name}',
                    'message' => 'Entry {entry:id}',
                ]],
                ['ok' => true, 'mode' => 'inline_message', 'title' => 'Thanks Ann Lee', 'message' => 'Entry 42'],
            ],
            'inline without text'  => [
                ['success' => ['mode' => 'inline_message', 'title' => 5, 'message' => null]],
                ['ok' => true, 'mode' => 'inline_message', 'title' => '', 'message' => ''],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function form(array $settings): FormDefinition
    {
        return new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
            settings: $settings,
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function tokens(array $settings): SubmissionTokens
    {
        return new SubmissionTokens(new TokenReplacer(), self::form($settings), ['name' => 'Ann Lee'], ['id' => 42]);
    }

    #[Test]
    public function exposesTheConfiguredValues(): void
    {
        $settings = SuccessSettings::fromForm(self::form(['success' => [
            'mode'         => 'inline_message',
            'redirect_url' => '/x',
            'title'        => 'T',
            'message'      => 'M',
        ]]));

        static::assertSame(
            ['inline_message', '/x', 'T', 'M'],
            [$settings->mode, $settings->redirectUrl, $settings->title, $settings->message],
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, bool|string> $expected
     */
    #[Test]
    #[DataProvider('payloadProvider')]
    public function payloadAnswersTheConfiguredMode(array $settings, array $expected): void
    {
        static::assertSame(
            $expected,
            SuccessSettings::fromForm(self::form($settings))->payload(self::tokens($settings)),
        );
    }

    #[Test]
    public function redirectUrlIsNullUnlessTheModeIsRedirectUrl(): void
    {
        $settings = ['success' => ['mode' => 'redirect_referrer', 'redirect_url' => '/thanks']];

        static::assertNull(SuccessSettings::fromForm(self::form($settings))->redirectUrl(self::tokens($settings)));
    }

    #[Test]
    public function redirectUrlUrlEncodesMergeTagValues(): void
    {
        $settings = ['success' => ['mode' => 'redirect_url', 'redirect_url' => '/thanks?n={field:name}']];

        static::assertSame(
            '/thanks?n=Ann%20Lee',
            SuccessSettings::fromForm(self::form($settings))->redirectUrl(self::tokens($settings)),
        );
    }
}
