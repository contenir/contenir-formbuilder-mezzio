<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Csrf;

use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenManager;
use Mezzio\Session\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

use function hash_hmac;
use function is_string;
use function preg_match;
use function str_repeat;
use function strtoupper;
use function substr;

#[Group('unit')]
final class CsrfTokenManagerTest extends TestCase
{
    private const string SESSION_VALUE = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /**
     * @return array<string, array{mixed}>
     */
    public static function rejectedTokenProvider(): array
    {
        $token = hash_hmac('sha256', data: 'contenir-formbuilder:contact', key: self::SESSION_VALUE);

        return [
            'missing'            => [null],
            'empty'              => [''],
            'not a string'       => [['x']],
            'another form'       => [hash_hmac('sha256', data: 'contenir-formbuilder:other', key: self::SESSION_VALUE)],
            'another secret'     => [hash_hmac(
                'sha256',
                data: 'contenir-formbuilder:contact',
                key: str_repeat('b', times: 64),
            )],
            'truncated'          => [substr($token, offset: 0, length: -1)],
            'upper-case'         => [strtoupper($token)],
            'with trailing data' => ["{$token}x"],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableSecretProvider(): array
    {
        return [
            'not a string'       => [123],
            'too short'          => [str_repeat('a', times: 63)],
            'too long'           => [str_repeat('a', times: 65)],
            'not hexadecimal'    => [str_repeat('g', times: 64)],
            'upper-case hex'     => [strtoupper(self::SESSION_VALUE)],
            'trailing line feed' => [self::SESSION_VALUE . "\n"],
            'leading text'       => ['x' . self::SESSION_VALUE],
        ];
    }

    #[Test]
    public function acceptsTheTokenIssuedForTheSessionAndForm(): void
    {
        $tokens  = new CsrfTokenManager();
        $session = new Session([]);

        static::assertTrue($tokens->isValid($session, 'contact', $tokens->token($session, 'contact')));
    }

    #[Test]
    #[DataProvider('unusableSecretProvider')]
    public function anUnusableSecretAcceptsNoToken(#[SensitiveParameter] mixed $secret): void
    {
        $session = new Session([CsrfTokenManager::SESSION_KEY => $secret]);
        $token   = hash_hmac('sha256', data: 'contenir-formbuilder:contact', key: is_string($secret) ? $secret : '');

        static::assertFalse((new CsrfTokenManager())->isValid($session, 'contact', $token));
    }

    #[Test]
    #[DataProvider('unusableSecretProvider')]
    public function anUnusableSecretIsReplaced(#[SensitiveParameter] mixed $secret): void
    {
        $session = new Session([CsrfTokenManager::SESSION_KEY => $secret]);

        (new CsrfTokenManager())->token($session, 'contact');

        static::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            (string) $session->get(CsrfTokenManager::SESSION_KEY),
        );
    }

    #[Test]
    public function aSessionThatWasNeverIssuedATokenRejectsEveryToken(): void
    {
        $tokens = new CsrfTokenManager();
        $issued = $tokens->token(new Session([]), 'contact');

        static::assertFalse($tokens->isValid(new Session([]), 'contact', $issued));
    }

    #[Test]
    public function aTokenFromAnotherSessionIsRejected(): void
    {
        $tokens  = new CsrfTokenManager();
        $mine    = new Session([]);
        $foreign = new Session([]);
        $tokens->token($mine, 'contact');

        static::assertFalse($tokens->isValid($mine, 'contact', $tokens->token($foreign, 'contact')));
    }

    #[Test]
    public function aTokenIsTheHmacOfTheSlugUnderTheSessionSecret(): void
    {
        $session = new Session([CsrfTokenManager::SESSION_KEY => self::SESSION_VALUE]);

        static::assertSame(
            hash_hmac('sha256', data: 'contenir-formbuilder:contact', key: self::SESSION_VALUE),
            (new CsrfTokenManager())->token($session, 'contact'),
        );
    }

    #[Test]
    public function checkingNeverCreatesASecret(): void
    {
        $session = new Session([]);

        (new CsrfTokenManager())->isValid($session, 'contact', 'anything');

        static::assertFalse($session->has(CsrfTokenManager::SESSION_KEY));
    }

    #[Test]
    public function eachSessionGetsItsOwnSecret(): void
    {
        $tokens = new CsrfTokenManager();
        $first  = new Session([]);
        $second = new Session([]);

        $tokens->token($first, 'contact');
        $tokens->token($second, 'contact');

        static::assertNotSame($first->get(CsrfTokenManager::SESSION_KEY), $second->get(CsrfTokenManager::SESSION_KEY));
    }

    #[Test]
    public function issuingATokenCreatesASecretOnce(): void
    {
        $tokens  = new CsrfTokenManager();
        $session = new Session([]);

        $first  = $tokens->token($session, 'contact');
        $secret = $session->get(CsrfTokenManager::SESSION_KEY);
        $second = $tokens->token($session, 'contact');

        static::assertSame(
            [1, $first, $secret],
            [
                preg_match('/^[0-9a-f]{64}$/D', (string) $secret),
                $second,
                $session->get(CsrfTokenManager::SESSION_KEY),
            ],
        );
    }

    #[Test]
    #[DataProvider('rejectedTokenProvider')]
    public function rejectsAnyOtherToken(#[SensitiveParameter] mixed $token): void
    {
        $session = new Session([CsrfTokenManager::SESSION_KEY => self::SESSION_VALUE]);

        static::assertFalse((new CsrfTokenManager())->isValid($session, 'contact', $token));
    }
}
