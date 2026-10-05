<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Trait;

use Contenir\FormBuilder\Mezzio\ConfigProvider;
use Contenir\FormBuilder\Mezzio\Render\FormBlockRenderer;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Handler\FormPageHandler;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Mailer\RecordingMailer;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Session\InMemorySessionPersistence;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Template\PhpTemplateRenderer;
use Laminas\Diactoros\ConfigProvider as DiactorosConfigProvider;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Stdlib\ArrayUtils;
use Mezzio\Application;
use Mezzio\ConfigProvider as MezzioConfigProvider;
use Mezzio\Router\ConfigProvider as RouterConfigProvider;
use Mezzio\Router\FastRouteRouter\ConfigProvider as FastRouteConfigProvider;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\MethodNotAllowedMiddleware;
use Mezzio\Router\Middleware\RouteMiddleware;
use Mezzio\Session\ConfigProvider as SessionConfigProvider;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Session\SessionPersistenceInterface;
use Mezzio\Template\TemplateRendererInterface;
use PhpDb\Adapter\AdapterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * A Mezzio application on a laminas-servicemanager container built from the
 * real config providers (Mezzio, FastRoute, diactoros, mezzio-session and this
 * package), with the SQLite forms database, an in-memory session, a recording
 * mailer and a page at `/contact` that shows the `contact` form block.
 *
 * Use with {@see SqliteDatabaseTrait}; call {@see setUpApplication()} after
 * the database is set up.
 */
trait MezzioApplicationTrait
{
    private Application $app;

    private ServiceManager $container;

    private RecordingMailer $mailer;

    private InMemorySessionPersistence $sessions;

    /**
     * @param array<string, mixed> $formbuilder Merged over the package defaults.
     * @param array<string, mixed> $services    Extra services, by name.
     */
    protected function setUpApplication(array $formbuilder = [], array $services = []): void
    {
        $this->mailer   = new RecordingMailer();
        $this->sessions = new InMemorySessionPersistence();

        $config = [];
        foreach ([
            new MezzioConfigProvider(),
            new RouterConfigProvider(),
            new FastRouteConfigProvider(),
            new DiactorosConfigProvider(),
            new SessionConfigProvider(),
            new ConfigProvider(),
        ] as $provider) {
            $config = ArrayUtils::merge($config, $provider());
        }

        $config = ArrayUtils::merge($config, ['formbuilder' => $formbuilder, 'debug' => true]);

        /** @var array<string, mixed> $dependencies */
        $dependencies             = $config['dependencies'];
        $dependencies['services'] = [
            'config'                           => $config,
            AdapterInterface::class            => $this->adapter,
            SessionPersistenceInterface::class => $this->sessions,
            MailerInterface::class             => $this->mailer,
            TemplateRendererInterface::class   => $this->templates($config),
            ...$services,
        ];
        $dependencies['factories'][FormPageHandler::class] =
            static fn(ServiceManager $container): FormPageHandler => new FormPageHandler(
                $container->get(FormBlockRenderer::class),
            );

        $this->container = new ServiceManager($dependencies);
        $this->app       = $this->container->get(Application::class);
        $this->app->pipe(SessionMiddleware::class);
        $this->app->pipe(RouteMiddleware::class);
        $this->app->pipe(MethodNotAllowedMiddleware::class);
        $this->app->pipe(DispatchMiddleware::class);
        $this->app->pipe(new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return new EmptyResponse(404);
            }
        });
        $this->app->get('/contact', FormPageHandler::class, 'contact');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function templates(array $config): PhpTemplateRenderer
    {
        $templates = new PhpTemplateRenderer();
        /** @var array<string, list<string>> $paths */
        $paths = $config['templates']['paths'] ?? [];
        foreach ($paths as $namespace => $directories) {
            $templates->addPath($directories[0], $namespace);
        }

        return $templates;
    }
}
