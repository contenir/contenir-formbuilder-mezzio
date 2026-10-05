<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Container;

use ArrayObject;
use Contenir\FormBuilder\Mezzio\Container\Services;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Container\InMemoryContainer;
use PhpDb\Adapter\AdapterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

#[Group('unit')]
final class ServicesTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, array<array-key, mixed>}>
     */
    public static function configProvider(): array
    {
        return [
            'no config service'    => [[], []],
            'config not an array'  => [['config' => 'x'], []],
            'no formbuilder key'   => [['config' => ['other' => 1]], []],
            'section not an array' => [['config' => ['formbuilder' => 'x']], []],
            'section'              => [['config' => ['formbuilder' => ['db_adapter' => 'db']]], ['db_adapter' => 'db']],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableAdapterIdProvider(): array
    {
        return [
            'empty string' => [''],
            'not a string' => [5],
        ];
    }

    #[Test]
    public function adapterDefaultsToTheAdapterInterfaceService(): void
    {
        $adapter = $this->createStub(AdapterInterface::class);

        static::assertSame($adapter, Services::adapter(new InMemoryContainer([AdapterInterface::class => $adapter])));
    }

    #[Test]
    #[DataProvider('unusableAdapterIdProvider')]
    public function adapterFallsBackToTheDefaultServiceForAnUnusableId(mixed $adapterId): void
    {
        $adapter   = $this->createStub(AdapterInterface::class);
        $container = new InMemoryContainer([
            'config'                => ['formbuilder' => ['db_adapter' => $adapterId]],
            AdapterInterface::class => $adapter,
        ]);

        static::assertSame($adapter, Services::adapter($container));
    }

    #[Test]
    public function adapterRejectsAServiceThatIsNotAnAdapter(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'Service "PhpDb\\Adapter\\AdapterInterface" must be an instance of PhpDb\\Adapter\\AdapterInterface, stdClass given.',
        );

        Services::adapter(new InMemoryContainer([AdapterInterface::class => new stdClass()]));
    }

    #[Test]
    public function adapterUsesTheConfiguredService(): void
    {
        $adapter   = $this->createStub(AdapterInterface::class);
        $container = new InMemoryContainer([
            'config'                => ['formbuilder' => ['db_adapter' => 'db.forms']],
            'db.forms'              => $adapter,
            AdapterInterface::class => $this->createStub(AdapterInterface::class),
        ]);

        static::assertSame($adapter, Services::adapter($container));
    }

    /**
     * @param array<string, mixed> $services
     * @param array<array-key, mixed> $expected
     */
    #[Test]
    #[DataProvider('configProvider')]
    public function configReturnsTheFormbuilderSection(array $services, array $expected): void
    {
        static::assertSame($expected, Services::config(new InMemoryContainer($services)));
    }

    #[Test]
    public function getRejectsAServiceOfTheWrongType(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Service "svc" must be an instance of ArrayObject, stdClass given.');

        Services::get(new InMemoryContainer(['svc' => new stdClass()]), 'svc', ArrayObject::class);
    }

    #[Test]
    public function getReturnsAServiceOfTheExpectedType(): void
    {
        $service = new ArrayObject();

        static::assertSame($service, Services::get(
            new InMemoryContainer(['svc' => $service]),
            'svc',
            ArrayObject::class,
        ));
    }

    #[Test]
    public function optionalReturnsNullWhenMissingOrOfTheWrongType(): void
    {
        $container = new InMemoryContainer(['wrong' => new stdClass(), 'right' => new ArrayObject()]);

        static::assertSame(
            [null, null, true],
            [
                Services::optional($container, 'missing', ArrayObject::class),
                Services::optional($container, 'wrong', ArrayObject::class),
                Services::optional($container, 'right', ArrayObject::class) instanceof ArrayObject,
            ],
        );
    }
}
