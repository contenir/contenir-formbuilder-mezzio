<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio;

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
use Contenir\FormBuilder\Mezzio\Route\SubmitRoute;
use Contenir\FormBuilder\Mezzio\Route\SubmitRouteDelegator;
use Contenir\FormBuilder\Mezzio\State\FormStateStash;
use Contenir\FormBuilder\Mezzio\Token\TokenReplacerBuilder;
use Contenir\FormBuilder\Registrar\WebhookRegistrar;
use Contenir\FormBuilder\Service\FormBuilderInterface;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Service\TokenReplacer;
use Mezzio\Application;
use PhpDb\Adapter\AdapterInterface;

use function dirname;

/**
 * Registers the formbuilder services, the submit route, the `formbuilder`
 * template path and the `formbuilder` defaults with a Mezzio application.
 *
 * @api
 */
final class ConfigProvider
{
    /**
     * The `formbuilder` configuration keys and their defaults.
     *
     * @return array<string, mixed>
     */
    public function getDefaults(): array
    {
        return [
            'db_adapter'        => AdapterInterface::class,
            'site_context'      => [],
            'token_resolvers'   => [],
            'observers'         => [],
            'notification_from' => null,
            'upload_directory'  => null,
            'template'          => FormBlockRenderer::DEFAULT_TEMPLATE,
            'submit_route'      => [
                'path'       => SubmitRoute::DEFAULT_PATH,
                'name'       => SubmitHandler::ROUTE_NAME,
                'middleware' => [],
                'options'    => [],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getDependencies(): array
    {
        return [
            'aliases'    => [
                FormLoaderInterface::class      => PhpDbFormLoader::class,
                EntryRepositoryInterface::class => PhpDbEntryRepository::class,
                FormBuilderInterface::class     => FormBuilderService::class,
            ],
            'invokables' => [
                CsrfTokenManager::class => CsrfTokenManager::class,
                FormStateStash::class   => FormStateStash::class,
            ],
            'factories'  => [
                CsrfFormFactory::class            => CsrfFormFactoryFactory::class,
                PhpDbFormLoader::class            => PhpDbFormLoaderFactory::class,
                PhpDbEntryRepository::class       => PhpDbEntryRepositoryFactory::class,
                FormBuilderService::class         => FormBuilderServiceFactory::class,
                StoreSubmissionRegistrar::class   => StoreSubmissionRegistrarFactory::class,
                EmailNotificationRegistrar::class => EmailNotificationRegistrarFactory::class,
                WebhookRegistrar::class           => WebhookRegistrarFactory::class,
                TokenReplacerBuilder::class       => TokenReplacerBuilderFactory::class,
                TokenReplacer::class              => TokenReplacerFactory::class,
                Responder::class                  => ResponderFactory::class,
                SubmissionPipeline::class         => SubmissionPipelineFactory::class,
                SubmitHandler::class              => SubmitHandlerFactory::class,
                FormPresenter::class              => FormPresenterFactory::class,
                FormBlockRenderer::class          => FormBlockRendererFactory::class,
            ],
            'delegators' => [
                Application::class => [SubmitRouteDelegator::class],
            ],
        ];
    }

    /**
     * @return array{paths: array{formbuilder: list<string>}}
     */
    public function getTemplates(): array
    {
        return [
            'paths' => [
                'formbuilder' => [dirname(__DIR__) . '/templates'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
            'templates'    => $this->getTemplates(),
            'formbuilder'  => $this->getDefaults(),
        ];
    }
}
