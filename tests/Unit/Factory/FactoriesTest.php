<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Factory;

use ArrayObject;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfFormFactory;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenManager;
use Contenir\FormBuilder\Mezzio\Factory\CsrfFormFactoryFactory;
use Contenir\FormBuilder\Mezzio\Factory\EmailNotificationRegistrarFactory;
use Contenir\FormBuilder\Mezzio\Factory\FormBlockRendererFactory;
use Contenir\FormBuilder\Mezzio\Factory\FormBuilderServiceFactory;
use Contenir\FormBuilder\Mezzio\Factory\FormPresenterFactory;
use Contenir\FormBuilder\Mezzio\Factory\PhpDbEntryRepositoryFactory;
use Contenir\FormBuilder\Mezzio\Factory\PhpDbFormLoaderFactory;
use Contenir\FormBuilder\Mezzio\Factory\ResponderFactory;
use Contenir\FormBuilder\Mezzio\Factory\StoreSubmissionRegistrarFactory;
use Contenir\FormBuilder\Mezzio\Factory\SubmissionPipelineFactory;
use Contenir\FormBuilder\Mezzio\Factory\SubmitHandlerFactory;
use Contenir\FormBuilder\Mezzio\Factory\TokenReplacerBuilderFactory;
use Contenir\FormBuilder\Mezzio\Factory\TokenReplacerFactory;
use Contenir\FormBuilder\Mezzio\Factory\WebhookRegistrarFactory;
use Contenir\FormBuilder\Mezzio\Handler\SubmissionPipeline;
use Contenir\FormBuilder\Mezzio\Http\Responder;
use Contenir\FormBuilder\Mezzio\Loader\FormLoaderInterface;
use Contenir\FormBuilder\Mezzio\Registrar\EmailNotificationRegistrar;
use Contenir\FormBuilder\Mezzio\Registrar\StoreSubmissionRegistrar;
use Contenir\FormBuilder\Mezzio\Render\FormPresenter;
use Contenir\FormBuilder\Mezzio\Repository\EntryRepositoryInterface;
use Contenir\FormBuilder\Mezzio\State\FormStateStash;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Builder\FixedFormBuilder;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Container\InMemoryContainer;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\FormDefinitionFactory;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Loader\InMemoryFormLoader;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Mailer\RecordingMailer;
use Contenir\FormBuilder\Mezzio\Token\TokenReplacerBuilder;
use Contenir\FormBuilder\Registrar\WebhookRegistrar;
use Contenir\FormBuilder\Service\BuilderForm;
use Contenir\FormBuilder\Service\FormBuilderInterface;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use DateTimeImmutable;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Laminas\Form\Form;
use Mezzio\Router\RouterInterface;
use Mezzio\Session\Session;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use PhpDb\Adapter\AdapterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;
use stdClass;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

use function array_map;
use function count;
use function substr;
use function sys_get_temp_dir;

use const UPLOAD_ERR_OK;

/**
 * Each factory builds its service from a plain PSR-11 container.
 */
#[Group('unit')]
final class FactoriesTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function notificationFromProvider(): array
    {
        return [
            'configured'   => [['notification_from' => 'forms@example.com'], ['forms@example.com']],
            'empty'        => [['notification_from' => ''], []],
            'not a string' => [['notification_from' => 5], []],
            'absent'       => [[], []],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function templateProvider(): array
    {
        return [
            'configured'   => [['template' => 'app::form'], 'app::form'],
            'empty'        => [['template' => ''], 'formbuilder::form'],
            'not a string' => [['template' => 5], 'formbuilder::form'],
            'absent'       => [[], 'formbuilder::form'],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function uploadDirectoryProvider(): array
    {
        return [
            'configured'   => [['upload_directory' => '/srv/uploads'], '/srv/uploads'],
            'empty'        => [['upload_directory' => ''], sys_get_temp_dir()],
            'not a string' => [['upload_directory' => 5], sys_get_temp_dir()],
            'absent'       => [[], sys_get_temp_dir()],
        ];
    }

    /**
     * @param array<string, mixed> $services
     * @param array<string, mixed> $config
     */
    private static function container(array $services = [], array $config = []): InMemoryContainer
    {
        return new InMemoryContainer([...$services, 'config' => ['formbuilder' => $config]]);
    }

    private static function notifiedForm(string $body): FormDefinition
    {
        return new FormDefinition(
            id: 1,
            slug: 'test',
            title: 'Test',
            notifications: [new NotificationDefinition(
                id: 1,
                name: 'N',
                toAddress: 'a@example.com',
                subject: 'S',
                bodyTemplate: $body,
            )],
        );
    }

    private static function notifyingSubject(): BuilderForm
    {
        $subject           = new BuilderForm();
        $subject->registry = new ArrayObject([
            'form' => new FormDefinition(
                id: 1,
                slug: 'c',
                title: 'C',
                notifications: [new NotificationDefinition(
                    id: 1,
                    name: 'N',
                    toAddress: 'a@example.com',
                    subject: 'S',
                )],
            ),
        ]);

        return $subject;
    }

    /**
     * @return array<string, mixed>
     */
    private static function pipelineServices(): array
    {
        return [
            CsrfFormFactory::class          => new CsrfFormFactory(
                new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory()),
                new CsrfTokenManager(),
            ),
            StoreSubmissionRegistrar::class => new StoreSubmissionRegistrar(new class implements
                EntryRepositoryInterface {
                /**
                 * @mago-expect lint:excessive-parameter-list Implements EntryRepositoryInterface::record().
                 */
                public function record(
                    int $formId,
                    array $values,
                    string $status,
                    ?string $ip,
                    ?int $userId,
                    array $meta = [],
                ): int {
                    return 5;
                }
            }),
            WebhookRegistrar::class         => new WebhookRegistrar(),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('templateProvider')]
    public function buildsTheBlockRendererForTheConfiguredTemplate(array $config, string $expected): void
    {
        $templates = $this->createMock(TemplateRendererInterface::class);
        $templates->expects($this->once())->method('render')->with($expected)->willReturn('');
        $router = $this->createStub(RouterInterface::class);
        $router->method('generateUri')->willReturn('/x');
        $presenter = new FormPresenter(
            new InMemoryFormLoader(FormDefinitionFactory::withFields([])),
            new CsrfFormFactory(new FixedFormBuilder(new Form()), new CsrfTokenManager()),
            new FormStateStash(),
            $router,
        );

        $service = (new FormBlockRendererFactory())(self::container([
            FormPresenter::class             => $presenter,
            TemplateRendererInterface::class => $templates,
        ], $config));
        $service->render(
            (new ServerRequest())->withAttribute(SessionMiddleware::SESSION_ATTRIBUTE, new Session([])),
            'test',
        );
    }

    #[Test]
    public function buildsTheCoreFormBuilder(): void
    {
        $form = (new FormBuilderServiceFactory())()->build(FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('email', 'email'),
        ]));

        static::assertSame([true, true], [$form->has('email'), $form->has('_csrf')]);
    }

    #[Test]
    public function buildsTheCsrfFormFactory(): void
    {
        $forms = (new CsrfFormFactoryFactory())(self::container([
            FormBuilderInterface::class => new FixedFormBuilder(new Form()),
            CsrfTokenManager::class     => new CsrfTokenManager(),
        ]));

        static::assertFalse($forms->accepts(new Session([]), 'contact', 'x'));
    }

    /**
     * Without a From address Symfony Mailer refuses the message.
     *
     * @param array<string, mixed> $config
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('notificationFromProvider')]
    public function buildsTheEmailRegistrarWithTheConfiguredDefaultFrom(array $config, array $expected): void
    {
        $mailer = new RecordingMailer();

        $service = (new EmailNotificationRegistrarFactory())(self::container([
            TokenReplacerBuilder::class => new TokenReplacerBuilder(),
            MailerInterface::class      => $mailer,
        ], $config));
        $service->update(self::notifyingSubject());

        static::assertSame($expected, array_map(
            static fn(Email $email): string => $email->getFrom()[0]->getAddress(),
            $mailer->sent,
        ));
    }

    #[Test]
    public function buildsTheEntryRepositoryWithTheRegisteredClock(): void
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->expects($this->once())->method('now')->willReturn(new DateTimeImmutable());

        $service = (new PhpDbEntryRepositoryFactory())(self::container([
            AdapterInterface::class => $this->createStub(AdapterInterface::class),
            ClockInterface::class   => $clock,
        ]));
        $service->record(1, [], 'complete', null, null);
    }

    #[Test]
    public function buildsTheEntryRepositoryWithTheSystemClockByDefault(): void
    {
        $repository = (new PhpDbEntryRepositoryFactory())(self::container([
            AdapterInterface::class => $this->createStub(AdapterInterface::class),
        ]));

        static::assertSame(0, $repository->record(1, [], 'complete', null, null));
    }

    #[Test]
    public function buildsTheLoaderOnTheConfiguredAdapter(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->once())->method('prepareQuery');

        $service = (new PhpDbFormLoaderFactory())(self::container(['db' => $adapter], ['db_adapter' => 'db']));
        $service->listSummaries();
    }

    #[Test]
    public function buildsThePresenterForTheConfiguredRouteName(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects($this->once())->method('generateUri')->with('forms.submit')->willReturn('/x');

        $service = (new FormPresenterFactory())(self::container(
            [
                FormLoaderInterface::class => new InMemoryFormLoader(FormDefinitionFactory::withFields([])),
                CsrfFormFactory::class     => new CsrfFormFactory(
                    new FixedFormBuilder(new Form()),
                    new CsrfTokenManager(),
                ),
                FormStateStash::class      => new FormStateStash(),
                RouterInterface::class     => $router,
            ],
            ['submit_route' => ['name' => 'forms.submit']],
        ));
        $service->present(
            (new ServerRequest())->withAttribute(SessionMiddleware::SESSION_ATTRIBUTE, new Session([])),
            'test',
        );
    }

    #[Test]
    public function buildsTheResponder(): void
    {
        $responder = (new ResponderFactory())(self::container([
            ResponseFactoryInterface::class => new ResponseFactory(),
            StreamFactoryInterface::class   => new StreamFactory(),
        ]));

        static::assertSame('{"ok":true}', (string) $responder->json(['ok' => true], status: 200)->getBody());
    }

    #[Test]
    public function buildsTheSharedTokenReplacer(): void
    {
        $tokens = (new TokenReplacerFactory())(self::container([
            TokenReplacerBuilder::class => new TokenReplacerBuilder(['base_url' => 'https://site.example']),
        ]));

        static::assertSame('https://site.example', $tokens->replace(
            '{site:base_url}',
            FormDefinitionFactory::withFields([]),
            [],
        ));
    }

    #[Test]
    public function buildsTheStoreRegistrarOnTheEntryRepository(): void
    {
        $repository = $this->createMock(EntryRepositoryInterface::class);
        $repository->expects($this->once())->method('record')->willReturn(9);
        $subject           = new BuilderForm();
        $subject->registry = new ArrayObject([
            'form'   => new FormDefinition(
                id: 1,
                slug: 'c',
                title: 'C',
            ),
            'values' => [],
        ]);

        $service = (new StoreSubmissionRegistrarFactory())(self::container([
            EntryRepositoryInterface::class => $repository,
        ]));
        $service->update($subject);

        static::assertSame(9, $subject->registry['entry_id']);
    }

    #[Test]
    public function buildsTheSubmitHandler(): void
    {
        $handler = (new SubmitHandlerFactory())(self::container([
            FormLoaderInterface::class  => new InMemoryFormLoader(),
            SubmissionPipeline::class   => (new SubmissionPipelineFactory())(self::container(self::pipelineServices())),
            FormStateStash::class       => new FormStateStash(),
            TokenReplacerBuilder::class => new TokenReplacerBuilder(),
            Responder::class            => new Responder(new ResponseFactory(), new StreamFactory()),
        ]));

        static::assertSame(
            404,
            $handler->handle(
                (new ServerRequest(method: 'POST'))->withAttribute('slug', 'missing'),
            )
                ->getStatusCode(),
        );
    }

    #[Test]
    public function buildsTheTokenReplacerBuilderFromTheConfiguration(): void
    {
        $builder = (new TokenReplacerBuilderFactory())(self::container(
            [
                'resolver.settings' => static fn(string $key): string => "s:{$key}",
                'resolver.broken'   => new stdClass(),
            ],
            [
                'site_context'    => ['admin_url' => 'https://admin.example'],
                'token_resolvers' => [
                    'broken'   => 'resolver.broken',
                    'missing'  => 'resolver.missing',
                    'notid'    => 5,
                    7          => 'resolver.settings',
                    'settings' => 'resolver.settings',
                ],
            ],
        ));

        static::assertSame(
            'https://admin.example s:x {broken:x} {missing:x} {notid:x}',
            $builder->build()->replace(
                '{site:admin_url} {settings:x} {broken:x} {missing:x} {notid:x}',
                FormDefinitionFactory::withFields([]),
                [],
            ),
        );
    }

    #[Test]
    public function buildsTheWebhookRegistrar(): void
    {
        static::assertInstanceOf(
            WebhookRegistrar::class,
            (new WebhookRegistrarFactory())(self::container([
                LoggerInterface::class => $this->createStub(LoggerInterface::class),
            ])),
        );
    }

    #[Test]
    public function theEmailRegistrarLogsThroughTheRegisteredLogger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $service = (new EmailNotificationRegistrarFactory())(self::container([
            TokenReplacerBuilder::class => new TokenReplacerBuilder(),
            MailerInterface::class      => new RecordingMailer(failing: [0]),
            LoggerInterface::class      => $logger,
        ]));
        $service->update(self::notifyingSubject());
    }

    #[Test]
    public function thePipelineAttachesTheBuiltInAndConfiguredObserversInOrder(): void
    {
        $mailer = new RecordingMailer();
        $seen   = [];
        $extra  = new class($mailer, $seen) implements SplObserver {
            /**
             * @param list<array{mixed, int}> $seen
             */
            public function __construct(
                private readonly RecordingMailer $mailer,
                private array &$seen,
            ) {}

            public function update(SplSubject $subject): void
            {
                $entry        = $subject instanceof BuilderForm ? $subject->registry['entry_id'] ?? null : null;
                $this->seen[] = [$entry, count($this->mailer->sent)];
            }
        };

        $this->submit(
            (new SubmissionPipelineFactory())(self::container(
                [
                    ...self::pipelineServices(),
                    MailerInterface::class            => $mailer,
                    EmailNotificationRegistrar::class => new EmailNotificationRegistrar(
                        new TokenReplacerBuilder(),
                        $mailer,
                        defaultFrom: 'site@example.com',
                    ),
                    'app.extra'                       => $extra,
                    'app.not_an_observer'             => new stdClass(),
                ],
                ['observers' => ['app.extra', 'app.missing', 'app.not_an_observer', 5]],
            )),
            self::notifiedForm('Entry {entry:id}'),
        );

        static::assertSame([[[5, 1]], 'Entry 5'], [$seen, $mailer->sent[0]->getTextBody()]);
    }

    #[Test]
    public function thePipelineLeavesOutEmailWithoutAMailer(): void
    {
        $mailer = new RecordingMailer();

        $this->submit(
            (new SubmissionPipelineFactory())(self::container(
                [
                    ...self::pipelineServices(),
                    EmailNotificationRegistrar::class => new EmailNotificationRegistrar(
                        new TokenReplacerBuilder(),
                        $mailer,
                    ),
                ],
                ['observers' => 'x'],
            )),
            self::notifiedForm('x'),
        );

        static::assertSame([], $mailer->sent);
    }

    /**
     * The upload double records where it is moved to without writing a file.
     *
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('uploadDirectoryProvider')]
    public function thePipelineStagesUploadsInTheConfiguredDirectory(array $config, string $expected): void
    {
        $target = '';
        $upload = $this->createStub(UploadedFileInterface::class);
        $upload->method('getError')->willReturn(UPLOAD_ERR_OK);
        $upload->method('moveTo')
            ->willReturnCallback(static function (string $path) use (&$target): void {
                $target = $path;
            });
        $session = new Session([]);

        $service = (new SubmissionPipelineFactory())(self::container(self::pipelineServices(), $config));
        $service->submit(
            FormDefinitionFactory::withFields([FormDefinitionFactory::field('file', 'cv')]),
            $session,
            ['_csrf' => (new CsrfTokenManager())->token($session, 'test')],
            ['cv' => $upload],
            [],
        );

        static::assertSame("{$expected}/formbuilder_", substr($target, offset: 0, length: -32));
    }

    #[Test]
    public function theTokenReplacerBuilderIgnoresUnusableConfiguration(): void
    {
        $builder = (new TokenReplacerBuilderFactory())(self::container(config: [
            'site_context'    => 'x',
            'token_resolvers' => 'x',
        ]));

        static::assertSame('{site:base_url}', $builder->build()->replace(
            '{site:base_url}',
            FormDefinitionFactory::withFields([]),
            [],
        ));
    }

    private function submit(SubmissionPipeline $pipeline, ?FormDefinition $form = null): void
    {
        $session = new Session([]);
        $token   = (new CsrfTokenManager())->token($session, 'test');

        $pipeline->submit($form ?? FormDefinitionFactory::withFields([]), $session, ['_csrf' => $token], [], []);
    }
}
