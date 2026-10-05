<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Http;

use Contenir\FormBuilder\Mezzio\Http\Responder;
use JsonException;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function str_contains;

use const NAN;

#[Group('unit')]
final class ResponderTest extends TestCase
{
    private static function responder(): Responder
    {
        return new Responder(new ResponseFactory(), new StreamFactory());
    }

    #[Test]
    public function jsonEscapesMarkupCharactersAndKeepsSlashes(): void
    {
        $payload  = ['message' => '<a href="/x">Ann\'s & co</a>'];
        $response = self::responder()->json($payload, status: 422);
        $body     = (string) $response->getBody();

        static::assertSame(
            [422, 'application/json', $payload, false, false, false, false, false, true],
            [
                $response->getStatusCode(),
                $response->getHeaderLine('Content-Type'),
                json_decode($body, associative: true),
                str_contains($body, '<'),
                str_contains($body, '>'),
                str_contains($body, "'"),
                str_contains($body, '&'),
                str_contains($body, '\\"'),
                str_contains($body, '/x'),
            ],
        );
    }

    #[Test]
    public function jsonThrowsWhenThePayloadCannotBeEncoded(): void
    {
        $this->expectException(JsonException::class);

        self::responder()->json(['value' => NAN], status: 200);
    }

    #[Test]
    public function redirectIsAFoundResponseWithTheLocation(): void
    {
        $response = self::responder()->redirect('/contact?submit=contact#form');

        static::assertSame(
            [302, '/contact?submit=contact#form', ''],
            [$response->getStatusCode(), $response->getHeaderLine('Location'), (string) $response->getBody()],
        );
    }
}
