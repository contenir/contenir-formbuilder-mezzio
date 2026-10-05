<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Integration\Handler;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfFormFactory;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenManager;
use Contenir\FormBuilder\Mezzio\Exception\MissingSessionException;
use Contenir\FormBuilder\Mezzio\Handler\SubmissionPipeline;
use Contenir\FormBuilder\Mezzio\Handler\SubmitHandler;
use Contenir\FormBuilder\Mezzio\Http\Responder;
use Contenir\FormBuilder\Mezzio\Http\UploadedFileStager;
use Contenir\FormBuilder\Mezzio\State\FormStateStash;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\FormDefinitionFactory;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Loader\InMemoryFormLoader;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Observer\RecordingObserver;
use Contenir\FormBuilder\Mezzio\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\FormBuilder\Mezzio\Token\TokenReplacerBuilder;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use Contenir\Storage\Adapter\InMemoryStorage;
use Contenir\Storage\StorageManager;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;
use Mezzio\Session\Session;
use Mezzio\Session\SessionMiddleware;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

use function array_keys;
use function file_put_contents;
use function glob;
use function json_decode;
use function mkdir;

use const UPLOAD_ERR_OK;

/**
 * Runs the handler over the real formbuilder submission pipeline, with a
 * mezzio-session, an in-memory loader and PSR-17 factories.
 */
#[Group('integration')]
#[Group('handler')]
final class SubmitHandlerTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const array ACCEPT_JSON = ['Accept' => 'application/json'];

    private const string URI = 'https://site.example/forms/submit/contact';

    private CsrfTokenManager $csrf;

    private Session $session;

    private FormStateStash $stash;

    /**
     * @return array<string, array{string, mixed, array{ip: string, site: array<string, string>}}>
     */
    public static function contextProvider(): array
    {
        return [
            'non-standard port'  => [
                'http://site.example:8080/f',
                '10.0.0.1',
                ['ip' => '10.0.0.1', 'site' => ['base_url' => 'http://site.example:8080']],
            ],
            'unknown host'       => ['/forms/submit/contact', '10.0.0.1', ['ip' => '10.0.0.1', 'site' => []]],
            'non-scalar address' => [
                'https://site.example/f',
                ['x'],
                ['ip' => '', 'site' => ['base_url' => 'https://site.example']],
            ],
            'no address'         => [
                'https://site.example/f',
                null,
                ['ip' => '', 'site' => ['base_url' => 'https://site.example']],
            ],
            'numeric address'    => [
                'https://site.example/f',
                7,
                ['ip' => '7', 'site' => ['base_url' => 'https://site.example']],
            ],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function jsonSuccessProvider(): array
    {
        return [
            'referrer mode'  => [[], ['ok' => true, 'mode' => 'redirect_referrer']],
            'redirect url'   => [
                ['success' => ['mode' => 'redirect_url', 'redirect_url' => '/thanks/{field:name}?e={entry:id}']],
                ['ok' => true, 'mode' => 'redirect_url', 'url' => '/thanks/Ann%20Lee?e=42'],
            ],
            'inline message' => [
                ['success' => [
                    'mode'    => 'inline_message',
                    'title'   => 'Thanks {field:name}',
                    'message' => 'Entry {entry:id}',
                ]],
                ['ok' => true, 'mode' => 'inline_message', 'title' => 'Thanks Ann Lee', 'message' => 'Entry 42'],
            ],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function missingSlugProvider(): array
    {
        return [
            'no slug'      => [null],
            'empty slug'   => [''],
            'not a string' => [5],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonPostProvider(): array
    {
        return [
            'GET'    => ['GET'],
            'PUT'    => ['PUT'],
            'DELETE' => ['DELETE'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rejectedTokenProvider(): array
    {
        return [
            'missing'      => [[]],
            'empty'        => [['_csrf' => '']],
            'wrong'        => [['_csrf' => 'forged']],
            'not a string' => [['_csrf' => ['x']]],
        ];
    }

    #[Test]
    public function aBodyThatIsNotAFormIsRejected(): void
    {
        $response = $this->handler()->handle($this->request(headers: self::ACCEPT_JSON)->withParsedBody(null));

        static::assertSame(['_csrf'], array_keys($this->json($response)['errors'] ?? []));
    }

    #[Test]
    public function acceptsALowerCasePostMethod(): void
    {
        static::assertSame(302, $this->handler()->handle($this->request()->withMethod('post'))->getStatusCode());
    }

    #[Test]
    public function aForeignReferrerRedirectsToTheSiteRoot(): void
    {
        $response = $this->handler()->handle($this->request(headers: ['Referer' => 'https://evil.example/p']));

        static::assertSame('/?submit=contact', $this->location($response));
    }

    #[Test]
    public function aJsonAcceptHeaderAmongOthersAnswersJson(): void
    {
        $response = $this->handler()->handle($this->request(
            post: ['name' => ''],
            headers: ['Accept' => 'text/html, application/json;q=0.9'],
        ));

        static::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function aRejectedTokenRedirectsBackWithTheValuesStashed(): void
    {
        $response = $this->handler()->handle($this->requestWithoutToken(
            post: ['name' => 'Ann Lee', '_csrf' => 'forged', '_anchor' => 'formbuilder-contact'],
            headers: ['Referer' => '/contact'],
        ));

        static::assertSame(
            [
                '/contact#formbuilder-contact',
                [
                    'values' => ['name' => 'Ann Lee'],
                    'errors' => ['_csrf' => [
                        'notSame' => 'The form submitted did not originate from the expected site',
                    ]],
                ],
            ],
            [$this->location($response), $this->stash->consume($this->session, 'contact')],
        );
    }

    /**
     * @param array<string, mixed> $token
     */
    #[Test]
    #[DataProvider('rejectedTokenProvider')]
    public function aSubmissionWithoutTheSessionsTokenIsRejected(#[SensitiveParameter] array $token): void
    {
        $observer = new RecordingObserver();

        $response = $this->handler(observers: [$observer])->handle($this->requestWithoutToken(
            post: ['name' => 'Ann Lee', ...$token],
            headers: self::ACCEPT_JSON,
        ));

        static::assertSame(
            [
                422,
                [
                    'ok'     => false,
                    'errors' => ['_csrf' => [
                        'notSame' => 'The form submitted did not originate from the expected site',
                    ]],
                ],
                [],
            ],
            [$response->getStatusCode(), $this->json($response), $observer->registries],
        );
    }

    #[Test]
    public function aTokenIssuedForAnotherFormIsRejected(): void
    {
        $response = $this->handler()->handle($this->requestWithoutToken(
            post: ['name' => 'Ann Lee', '_csrf' => $this->csrf->token($this->session, 'other')],
            headers: self::ACCEPT_JSON,
        ));

        static::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function aTokenIssuedToAnotherSessionIsRejected(): void
    {
        $observer = new RecordingObserver();
        $foreign  = $this->csrf->token(new Session([]), 'contact');
        $this->csrf->token($this->session, 'contact');

        $response = $this->handler(observers: [$observer])->handle($this->requestWithoutToken(
            post: ['name' => 'Ann Lee', '_csrf' => $foreign],
            headers: self::ACCEPT_JSON,
        ));

        static::assertSame([422, []], [$response->getStatusCode(), $observer->registries]);
    }

    /**
     * @param array{ip: string, site: array<string, string>} $expected
     */
    #[Test]
    #[DataProvider('contextProvider')]
    public function derivesTheIpAndSiteFromTheRequest(string $uri, mixed $remoteAddress, array $expected): void
    {
        $observer = new RecordingObserver();

        $this->handler(observers: [$observer])->handle($this->requestFrom(
            null === $remoteAddress ? [] : ['REMOTE_ADDR' => $remoteAddress],
            $uri,
        ));

        $context = $observer->registries[0]['context'] ?? [];
        static::assertSame($expected, ['ip' => $context['ip'] ?? null, 'site' => $context['site'] ?? null]);
    }

    #[Test]
    public function failsWithoutASession(): void
    {
        $this->expectException(MissingSessionException::class);

        $this->handler()->handle($this->request()->withoutAttribute(SessionMiddleware::SESSION_ATTRIBUTE));
    }

    #[Test]
    public function invalidJsonSubmissionAnswers422WithErrors(): void
    {
        $response = $this->handler()->handle($this->request(
            post: ['name' => ''],
            headers: self::ACCEPT_JSON,
        ));

        static::assertSame(
            [422, false, ['name']],
            [$response->getStatusCode(), $this->json($response)['ok'], array_keys($this->json($response)['errors'])],
        );
    }

    #[Test]
    public function invalidSubmissionStashesValuesAndRedirectsBack(): void
    {
        $response = $this->handler()->handle($this->request(
            post: ['name' => '', 'note' => 'x', '_anchor' => 'contact-form'],
            headers: ['Referer' => '/contact?submit=old#x'],
        ));

        $stashed = $this->stash->consume($this->session, 'contact');
        static::assertSame(
            ['/contact#contact-form', ['name' => '', 'note' => 'x'], ['isEmpty']],
            [$this->location($response), $stashed['values'] ?? null, array_keys($stashed['errors']['name'] ?? [])],
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('jsonSuccessProvider')]
    public function jsonSuccessDescribesTheConfiguredMode(array $settings, array $expected): void
    {
        $handler = $this->handler($this->form($settings), [new RecordingObserver(['entry_id' => 42])]);

        $response = $handler->handle($this->request(headers: self::ACCEPT_JSON));

        static::assertSame([200, $expected], [$response->getStatusCode(), $this->json($response)]);
    }

    #[Test]
    public function passesTheRequestContextToObservers(): void
    {
        $observer = new RecordingObserver();

        $this->handler(observers: [$observer])->handle(
            $this->requestFrom(['REMOTE_ADDR' => '10.0.0.1'])
                ->withHeader('User-Agent', 'UA/1')
                ->withHeader('Referer', '/contact'),
        );

        static::assertSame(
            [
                'ip'      => '10.0.0.1',
                'user_id' => null,
                'meta'    => ['user_agent' => 'UA/1', 'referer' => '/contact'],
                'site'    => ['base_url' => 'https://site.example'],
            ],
            $observer->registries[0]['context'] ?? null,
        );
    }

    #[Test]
    public function redirectUrlModeRedirectsToTheExpandedUrl(): void
    {
        $form = $this->form(['success' => ['mode' => 'redirect_url', 'redirect_url' => '/thanks?n={field:name}']]);

        static::assertSame('/thanks?n=Ann%20Lee', $this->location($this->handler($form)->handle($this->request())));
    }

    #[Test]
    #[DataProvider('missingSlugProvider')]
    public function rejectsAMissingSlug(mixed $slug): void
    {
        $response = $this->handler()->handle($this->request()->withAttribute('slug', $slug));

        static::assertSame([400, ['error' => 'Missing slug']], [$response->getStatusCode(), $this->json($response)]);
    }

    #[Test]
    public function rejectsAnUnknownForm(): void
    {
        $response = $this->handler()->handle($this->request()->withAttribute('slug', 'missing'));

        static::assertSame([404, ['error' => 'Form not found']], [$response->getStatusCode(), $this->json($response)]);
    }

    #[Test]
    #[DataProvider('nonPostProvider')]
    public function rejectsRequestsThatAreNotPosts(string $method): void
    {
        $response = $this->handler()->handle($this->request()->withMethod($method));

        static::assertSame(
            [405, 'POST', ['error' => 'Method not allowed']],
            [$response->getStatusCode(), $response->getHeaderLine('Allow'), $this->json($response)],
        );
    }

    #[Test]
    public function spamIsAnsweredLikeASuccess(): void
    {
        $observer = new RecordingObserver();

        $response = $this->handler(observers: [$observer])->handle($this->request(
            post: ['name' => '', 'hid' => 'bot'],
            headers: ['Referer' => '/c'],
        ));

        static::assertSame(['/c?submit=contact', true], [
            $this->location($response),
            $observer->registries[0]['spam'] ?? null,
        ]);
    }

    #[Test]
    public function spamWithoutTheTokenIsRejectedToo(): void
    {
        $observer = new RecordingObserver();

        $response = $this->handler(observers: [$observer])->handle($this->requestWithoutToken(
            post: ['name' => '', 'hid' => 'bot'],
            headers: self::ACCEPT_JSON,
        ));

        static::assertSame([422, []], [$response->getStatusCode(), $observer->registries]);
    }

    #[Test]
    public function storesUploadsAndRemovesTheStagedFiles(): void
    {
        $storage = new StorageManager();
        $storage->register(StorageManager::DEFAULT_PROFILE, new InMemoryStorage(), isPrimary: true);
        $observer = new RecordingObserver();
        $form     = new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
            sections: FormDefinitionFactory::withFields([
                FormDefinitionFactory::field('text', 'name'),
                FormDefinitionFactory::field('file', 'cv'),
            ])->sections,
        );
        file_put_contents($this->path('upload'), data: 'PDF');

        $this->handler($form, [$observer], $storage)->handle($this->request()->withUploadedFiles([
            'cv' => new UploadedFile($this->path('upload'), 3, UPLOAD_ERR_OK, 'cv.pdf', 'application/pdf'),
        ]));

        static::assertSame(
            ['forms/contact/cv.txt', true, []],
            [
                $observer->registries[0]['values']['cv'] ?? null,
                $storage->get(StorageManager::DEFAULT_PROFILE)->exists('forms/contact/cv.txt'),
                glob("{$this->path('staging')}/*"),
            ],
        );
    }

    #[Test]
    public function successRedirectsBackWithTheSubmitFlag(): void
    {
        $response = $this->handler()->handle($this->request(
            post: ['name' => 'Ann Lee', '_anchor' => 'formbuilder-contact'],
            headers: ['Referer' => 'https://site.example/contact?a=1'],
        ));

        static::assertSame(
            'https://site.example/contact?a=1&submit=contact#formbuilder-contact',
            $this->location($response),
        );
    }

    #[Test]
    public function successTokensSeeTheRequestSite(): void
    {
        $form = $this->form(['success' => ['mode' => 'inline_message', 'message' => '{site:base_url}/thanks']]);

        $response = $this->handler($form)->handle($this->request(headers: self::ACCEPT_JSON));

        static::assertSame('https://site.example/thanks', $this->json($response)['message'] ?? null);
    }

    #[Test]
    public function successWithoutAnEntryIdLeavesTheEntryTagInPlace(): void
    {
        $handler = $this->handler($this->form(['success' => ['mode' => 'inline_message', 'message' => '#{entry:id}']]));

        $response = $handler->handle($this->request(headers: self::ACCEPT_JSON));

        static::assertSame('#{entry:id}', $this->json($response)['message'] ?? null);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        mkdir($this->path('staging'));
        $this->csrf    = new CsrfTokenManager();
        $this->session = new Session([]);
        $this->stash   = new FormStateStash();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $server
     */
    private function build(array $body, array $headers, array $server, string $uri): ServerRequest
    {
        return (new ServerRequest(
            serverParams: $server,
            uri: $uri,
            method: 'POST',
            headers: $headers,
        ))->withParsedBody($body)
            ->withAttribute('slug', 'contact')
            ->withAttribute(SessionMiddleware::SESSION_ATTRIBUTE, $this->session);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function form(array $settings = []): FormDefinition
    {
        return new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
            settings: $settings,
            sections: FormDefinitionFactory::withFields([
                FormDefinitionFactory::field('text', 'name', ['required' => true]),
                FormDefinitionFactory::field('text', 'note'),
            ])->sections,
        );
    }

    /**
     * @param list<RecordingObserver> $observers
     */
    private function handler(
        ?FormDefinition $form = null,
        array $observers = [],
        ?StorageManager $storage = null,
    ): SubmitHandler {
        return new SubmitHandler(
            new InMemoryFormLoader($form ?? $this->form()),
            new SubmissionPipeline(
                new CsrfFormFactory(
                    new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory()),
                    $this->csrf,
                ),
                new UploadedFileStager($this->path('staging')),
                $storage,
                $observers,
            ),
            $this->stash,
            new TokenReplacerBuilder(),
            new Responder(new ResponseFactory(), new StreamFactory()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        static::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), associative: true);
    }

    private function location(ResponseInterface $response): string
    {
        static::assertSame(302, $response->getStatusCode());

        return $response->getHeaderLine('Location');
    }

    /**
     * A POST to the contact form carrying the session's token.
     *
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    private function request(array $post = ['name' => 'Ann Lee'], array $headers = []): ServerRequest
    {
        return $this->build($this->withToken($post), $headers, [], self::URI);
    }

    /**
     * A tokened POST with the given server parameters, to the given URI.
     *
     * @param array<string, mixed> $server
     */
    private function requestFrom(array $server, string $uri = self::URI): ServerRequest
    {
        return $this->build($this->withToken(['name' => 'Ann Lee']), [], $server, $uri);
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $headers
     */
    private function requestWithoutToken(array $post, array $headers = []): ServerRequest
    {
        return $this->build($post, $headers, [], self::URI);
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array<string, mixed>
     */
    private function withToken(array $post): array
    {
        return [...$post, '_csrf' => $this->csrf->token($this->session, 'contact')];
    }
}
