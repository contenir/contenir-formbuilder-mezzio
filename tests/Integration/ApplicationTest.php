<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Integration;

use Contenir\FormBuilder\Mezzio\Csrf\CsrfFormFactory;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenManager;
use Contenir\FormBuilder\Mezzio\Handler\SubmissionPipeline;
use Contenir\FormBuilder\Mezzio\Handler\SubmitHandler;
use Contenir\FormBuilder\Mezzio\Http\Responder;
use Contenir\FormBuilder\Mezzio\Loader\FormLoaderInterface;
use Contenir\FormBuilder\Mezzio\Loader\PhpDbFormLoader;
use Contenir\FormBuilder\Mezzio\Registrar\EmailNotificationRegistrar;
use Contenir\FormBuilder\Mezzio\Registrar\StoreSubmissionRegistrar;
use Contenir\FormBuilder\Mezzio\Render\FormBlockRenderer;
use Contenir\FormBuilder\Mezzio\Render\FormPresenter;
use Contenir\FormBuilder\Mezzio\Repository\EntryRepositoryInterface;
use Contenir\FormBuilder\Mezzio\Repository\PhpDbEntryRepository;
use Contenir\FormBuilder\Mezzio\State\FormStateStash;
use Contenir\FormBuilder\Mezzio\Tests\Trait\MezzioApplicationTrait;
use Contenir\FormBuilder\Mezzio\Tests\Trait\SqliteDatabaseTrait;
use Contenir\FormBuilder\Mezzio\Token\TokenReplacerBuilder;
use Contenir\FormBuilder\Registrar\WebhookRegistrar;
use Contenir\FormBuilder\Service\FormBuilderInterface;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Service\TokenReplacer;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Router\RouteCollectorInterface;
use Mezzio\Session\Session;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use function array_map;
use function json_decode;
use function parse_str;
use function parse_url;
use function preg_match;
use function str_contains;

use const PHP_URL_QUERY;

/**
 * Drives the package through a real Mezzio application: the container is
 * built from the config providers, requests go through the session, routing
 * and dispatch middleware, and forms come from (and entries go to) SQLite.
 */
#[Group('integration')]
#[Group('handler')]
final class ApplicationTest extends TestCase
{
    use MezzioApplicationTrait;
    use SqliteDatabaseTrait;

    /**
     * @return array<string, array{string, class-string}>
     */
    public static function serviceProvider(): array
    {
        return [
            'loader'            => [FormLoaderInterface::class, PhpDbFormLoader::class],
            'entry repository'  => [EntryRepositoryInterface::class, PhpDbEntryRepository::class],
            'form builder'      => [FormBuilderInterface::class, FormBuilderService::class],
            'csrf tokens'       => [CsrfTokenManager::class, CsrfTokenManager::class],
            'csrf forms'        => [CsrfFormFactory::class, CsrfFormFactory::class],
            'stash'             => [FormStateStash::class, FormStateStash::class],
            'store registrar'   => [StoreSubmissionRegistrar::class, StoreSubmissionRegistrar::class],
            'email registrar'   => [EmailNotificationRegistrar::class, EmailNotificationRegistrar::class],
            'webhook registrar' => [WebhookRegistrar::class, WebhookRegistrar::class],
            'token builder'     => [TokenReplacerBuilder::class, TokenReplacerBuilder::class],
            'token replacer'    => [TokenReplacer::class, TokenReplacer::class],
            'responder'         => [Responder::class, Responder::class],
            'pipeline'          => [SubmissionPipeline::class, SubmissionPipeline::class],
            'submit handler'    => [SubmitHandler::class, SubmitHandler::class],
            'presenter'         => [FormPresenter::class, FormPresenter::class],
            'block renderer'    => [FormBlockRenderer::class, FormBlockRenderer::class],
        ];
    }

    #[Test]
    public function aJsonSubmissionAnswersJson(): void
    {
        $token = $this->token((string) $this->get('/contact')->getBody());

        $response = $this->postJson(['name' => 'Ann', 'email' => 'ann@example.com', '_csrf' => $token]);

        static::assertSame(
            [200, ['ok' => true, 'mode' => 'redirect_referrer']],
            [$response->getStatusCode(), json_decode((string) $response->getBody(), associative: true)],
        );
    }

    #[Test]
    public function anInvalidSubmissionIsStashedForTheNextRender(): void
    {
        $token = $this->token((string) $this->get('/contact')->getBody());

        $this->post(['name' => '', 'email' => 'ann@', '_csrf' => $token]);
        $page = (string) $this->get('/contact')->getBody();

        static::assertSame([true, true, false], [
            str_contains($page, 'value="ann@"'),
            str_contains($page, 'formbuilder__errors'),
            str_contains($page, 'formbuilder__notice'),
        ]);
    }

    #[Test]
    public function anUnknownFormIsNotFound(): void
    {
        static::assertSame(404, $this->postJson([], path: '/forms/submit/missing')->getStatusCode());
    }

    #[Test]
    public function aSubmissionIsStoredMailedAndAnswersWithTheSuccessState(): void
    {
        $token = $this->token((string) $this->get('/contact')->getBody());

        $response = $this->post([
            'name'    => 'Ann',
            'email'   => 'ann@example.com',
            '_csrf'   => $token,
            '_anchor' => 'formbuilder-contact',
        ]);
        $page = (string) $this->get('/contact?submit=contact')->getBody();

        static::assertSame(
            [
                302,
                'https://site.example/contact?submit=contact#formbuilder-contact',
                [
                    ['name',  'Ann'],
                    ['email', 'ann@example.com'],
                ],
                ['New entry from Ann'],
                true,
            ],
            [
                $response->getStatusCode(),
                $response->getHeaderLine('Location'),
                array_map(
                    static fn(array $row): array => [$row['field_name'], $row['value_text']],
                    $this->rows('SELECT field_name, value_text FROM form_entry_value ORDER BY form_entry_value_id'),
                ),
                array_map(static fn($email): ?string => $email->getSubject(), $this->mailer->sent),
                str_contains($page, 'formbuilder__success'),
            ],
        );
    }

    #[Test]
    public function aSubmissionWithoutTheTokenIsRejectedAndTheFormShowsWhy(): void
    {
        $this->get('/contact');

        $response = $this->post(['name' => 'Ann', 'email' => 'ann@example.com']);
        $page     = (string) $this->get('/contact')->getBody();

        static::assertSame(
            [302, 'https://site.example/contact', [], [], true, true],
            [
                $response->getStatusCode(),
                $response->getHeaderLine('Location'),
                $this->rows('SELECT * FROM form_entry'),
                $this->mailer->sent,
                str_contains($page, 'formbuilder__notice'),
                str_contains($page, 'value="Ann"'),
            ],
        );
    }

    #[Test]
    public function aTokenFromAnotherSessionIsRejected(): void
    {
        $token = $this->token((string) $this->get('/contact')->getBody());
        $this->sessions->forget();

        $response = $this->postJson(['name' => 'Ann', 'email' => 'ann@example.com', '_csrf' => $token]);

        static::assertSame([422, []], [$response->getStatusCode(), $this->rows('SELECT * FROM form_entry')]);
    }

    #[Test]
    public function registersThePostOnlySubmitRoute(): void
    {
        static::assertSame(
            [['/forms/submit/{slug:[a-z0-9][a-z0-9\-]*}', 'formbuilder.submit', ['POST']]],
            $this->routes('formbuilder.submit'),
        );
    }

    /**
     * @param class-string $type
     */
    #[Test]
    #[DataProvider('serviceProvider')]
    public function theContainerBuildsEveryService(string $name, string $type): void
    {
        static::assertInstanceOf($type, $this->container->get($name));
    }

    #[Test]
    public function thePageShowsTheFormWithATokenForThisSession(): void
    {
        $html = (string) $this->get('/contact')->getBody();

        static::assertSame(
            [true, true, true],
            [
                str_contains($html, '<div class="formbuilder" id="formbuilder-contact">'),
                str_contains($html, 'action="/forms/submit/contact"'),
                (new CsrfTokenManager())->isValid(new Session($this->sessions->data), 'contact', $this->token($html)),
            ],
        );
    }

    #[Test]
    public function theSubmitRouteOnlyAnswersPosts(): void
    {
        static::assertSame(405, $this->get('/forms/submit/contact')->getStatusCode());
    }

    #[Test]
    public function theSubmitRouteOnlyMatchesSlugShapedPaths(): void
    {
        static::assertSame(404, $this->post([], path: '/forms/submit/Contact')->getStatusCode());
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->seed();
        $this->setUpApplication(['notification_from' => 'site@example.com']);
    }

    private function get(string $path): ResponseInterface
    {
        $request = new ServerRequest(
            uri: "https://site.example{$path}",
            method: 'GET',
        );
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        return $this->app->handle($request->withQueryParams($query));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body, string $path = '/forms/submit/contact'): ResponseInterface
    {
        return $this->app->handle($this->postRequest($body, $path));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(array $body, string $path = '/forms/submit/contact'): ResponseInterface
    {
        return $this->app->handle($this->postRequest($body, $path)->withHeader('Accept', 'application/json'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postRequest(array $body, string $path): ServerRequest
    {
        return (new ServerRequest(
            uri: "https://site.example{$path}",
            method: 'POST',
            headers: ['Referer' => 'https://site.example/contact'],
        ))->withParsedBody($body);
    }

    /**
     * @return list<array{string, string|null, list<string>|null}>
     */
    private function routes(string $name): array
    {
        $routes = [];
        foreach ($this->container->get(RouteCollectorInterface::class)->getRoutes() as $route) {
            if ($route->getName() !== $name) {
                continue;
            }

            $routes[] = [$route->getPath(), $route->getName(), $route->getAllowedMethods()];
        }

        return $routes;
    }

    private function seed(): void
    {
        $form    = $this->insert('form', ['slug' => 'contact', 'title' => 'Contact']);
        $section = $this->insert('form_section', ['form_id' => $form, 'key' => 'main']);
        $group   = $this->insert('form_group', ['form_section_id' => $section]);
        $row     = $this->insert('form_row', ['form_group_id' => $group]);
        $this->insert('form_field', ['form_row_id' => $row, 'type' => 'text', 'name' => 'name', 'required' => 1]);
        $this->insert('form_field', [
            'form_row_id'     => $row,
            'type'            => 'email',
            'name'            => 'email',
            'sort'            => 1,
            'validators_json' => '[{"type":"email"}]',
        ]);
        $this->insert('form_notification', [
            'form_id'    => $form,
            'name'       => 'Admin',
            'to_address' => 'admin@example.com',
            'subject'    => 'New entry from {field:name}',
        ]);
    }

    private function token(string $html): string
    {
        return preg_match('/name="_csrf" type="hidden" id="_csrf" value="([0-9a-f]{64})"/', $html, $matches) === 1
            ? $matches[1]
            : '';
    }
}
