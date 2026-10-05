<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Http;

use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function json_encode;

use const JSON_HEX_AMP;
use const JSON_HEX_APOS;
use const JSON_HEX_QUOT;
use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Builds the submit handler's JSON and redirect responses through PSR-17
 * factories, so no PSR-7 implementation is assumed.
 *
 * JSON is encoded with `<`, `>`, `&`, `'` and `"` as unicode escapes, so a
 * payload carrying submitted values is safe to embed in HTML.
 *
 * @api
 */
final readonly class Responder
{
    private const int JSON_FLAGS =
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    /**
     * @param array<string, mixed> $payload
     *
     * @throws JsonException When the payload cannot be encoded.
     */
    public function json(array $payload, int $status): ResponseInterface
    {
        return $this->responses
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode($payload, self::JSON_FLAGS)));
    }

    public function redirect(string $url): ResponseInterface
    {
        return $this->responses->createResponse(302)->withHeader('Location', $url);
    }
}
