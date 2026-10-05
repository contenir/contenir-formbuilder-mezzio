<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Integration\Route;

use Contenir\FormBuilder\Mezzio\Tests\Trait\MezzioApplicationTrait;
use Contenir\FormBuilder\Mezzio\Tests\Trait\SqliteDatabaseTrait;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Router\Route;
use Mezzio\Router\RouteCollectorInterface;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function array_filter;
use function array_values;

/**
 * The submit route as `formbuilder.submit_route` configures it.
 */
#[Group('integration')]
final class SubmitRouteDelegatorTest extends TestCase
{
    use MezzioApplicationTrait;
    use SqliteDatabaseTrait;

    #[Test]
    public function falseLeavesTheRouteOut(): void
    {
        $this->setUpApplication(['submit_route' => false]);

        static::assertSame(
            [[], 404],
            [$this->submitRoutes('formbuilder.submit'), $this->postTo('/forms/submit/contact')->getStatusCode()],
        );
    }

    #[Test]
    public function registersTheConfiguredPathNameMiddlewareAndOptions(): void
    {
        $marker = new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return $handler->handle($request)->withHeader('X-Marker', 'ran');
            }
        };
        $this->setUpApplication(
            [
                'submit_route' => [
                    'path'       => '/enquiries/{slug}',
                    'name'       => 'enquiries.submit',
                    'middleware' => ['app.marker'],
                    'options'    => ['defaults' => ['x' => 1]],
                ],
            ],
            ['app.marker' => $marker],
        );

        $routes   = $this->submitRoutes('enquiries.submit');
        $response = $this->postTo('/enquiries/missing');

        static::assertSame(
            ['/enquiries/{slug}', ['POST'], ['defaults' => ['x' => 1]], 404, 'ran'],
            [
                $routes[0]->getPath(),
                $routes[0]->getAllowedMethods(),
                $routes[0]->getOptions(),
                $response->getStatusCode(),
                $response->getHeaderLine('X-Marker'),
            ],
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpDatabase();
    }

    private function postTo(string $path): ResponseInterface
    {
        return $this->app->handle(new ServerRequest(
            uri: "https://site.example{$path}",
            method: 'POST',
            headers: ['Accept' => 'application/json'],
        ));
    }

    /**
     * @return list<Route>
     */
    private function submitRoutes(string $name): array
    {
        return array_values(array_filter(
            $this->container->get(RouteCollectorInterface::class)->getRoutes(),
            static fn(Route $route): bool => $route->getName() === $name,
        ));
    }
}
