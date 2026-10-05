<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\TestAsset\Handler;

use Contenir\FormBuilder\Mezzio\Render\FormBlockRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A site page that shows the `contact` form block, as a site handler would.
 */
final readonly class FormPageHandler implements RequestHandlerInterface
{
    public function __construct(
        private FormBlockRenderer $forms,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new HtmlResponse("<main>{$this->forms->render($request, 'contact')}</main>");
    }
}
