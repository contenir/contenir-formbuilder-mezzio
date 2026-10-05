<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit;

use Contenir\FormBuilder\Mezzio\ConfigProvider;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenManager;
use Contenir\FormBuilder\Mezzio\Loader\FormLoaderInterface;
use Contenir\FormBuilder\Mezzio\Loader\PhpDbFormLoader;
use Contenir\FormBuilder\Mezzio\Repository\EntryRepositoryInterface;
use Contenir\FormBuilder\Mezzio\Repository\PhpDbEntryRepository;
use Contenir\FormBuilder\Mezzio\Route\SubmitRouteDelegator;
use Contenir\FormBuilder\Mezzio\State\FormStateStash;
use Contenir\FormBuilder\Service\FormBuilderInterface;
use Contenir\FormBuilder\Service\FormBuilderService;
use Mezzio\Application;
use PhpDb\Adapter\AdapterInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function dirname;
use function realpath;

#[Group('unit')]
final class ConfigProviderTest extends TestCase
{
    #[Test]
    public function aliasesTheExtensionPointsToTheDefaultImplementations(): void
    {
        static::assertSame(
            [
                FormLoaderInterface::class      => PhpDbFormLoader::class,
                EntryRepositoryInterface::class => PhpDbEntryRepository::class,
                FormBuilderInterface::class     => FormBuilderService::class,
            ],
            (new ConfigProvider())->getDependencies()['aliases'],
        );
    }

    #[Test]
    public function decoratesTheApplicationWithTheSubmitRoute(): void
    {
        static::assertSame(
            [Application::class => [SubmitRouteDelegator::class]],
            (new ConfigProvider())->getDependencies()['delegators'],
        );
    }

    #[Test]
    public function documentsEveryFormbuilderKeyWithItsDefault(): void
    {
        static::assertSame(
            [
                'db_adapter'        => AdapterInterface::class,
                'site_context'      => [],
                'token_resolvers'   => [],
                'observers'         => [],
                'notification_from' => null,
                'upload_directory'  => null,
                'template'          => 'formbuilder::form',
                'submit_route'      => [
                    'path'       => '/forms/submit/{slug:[a-z0-9][a-z0-9\-]*}',
                    'name'       => 'formbuilder.submit',
                    'middleware' => [],
                    'options'    => [],
                ],
            ],
            (new ConfigProvider())->getDefaults(),
        );
    }

    #[Test]
    public function providesTheDependenciesTemplatesAndDefaults(): void
    {
        $provider = new ConfigProvider();

        static::assertSame(
            [
                'dependencies' => $provider->getDependencies(),
                'templates'    => $provider->getTemplates(),
                'formbuilder'  => $provider->getDefaults(),
            ],
            $provider(),
        );
    }

    #[Test]
    public function registersAFactoryForEachService(): void
    {
        static::assertCount(14, (new ConfigProvider())->getDependencies()['factories']);
    }

    #[Test]
    public function registersTheBundledTemplatesUnderTheFormbuilderNamespace(): void
    {
        $paths = (new ConfigProvider())->getTemplates()['paths'];

        static::assertSame(
            [['formbuilder'], realpath(dirname(__DIR__, levels: 2) . '/templates')],
            [array_keys($paths), realpath($paths['formbuilder'][0])],
        );
    }

    #[Test]
    public function registersTheStatelessServicesAsInvokables(): void
    {
        static::assertSame(
            [CsrfTokenManager::class => CsrfTokenManager::class, FormStateStash::class => FormStateStash::class],
            (new ConfigProvider())->getDependencies()['invokables'],
        );
    }
}
