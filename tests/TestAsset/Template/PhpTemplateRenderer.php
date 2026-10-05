<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\TestAsset\Template;

use Mezzio\Template\TemplatePath;
use Mezzio\Template\TemplateRendererInterface;
use Override;
use RuntimeException;

use function explode;
use function extract;
use function is_file;
use function ob_get_clean;
use function ob_start;

use const EXTR_SKIP;

/**
 * Renders `namespace::name` templates as plain PHP files, the way the
 * laminas-view and Plates renderers include a `.phtml`, with the parameters
 * extracted as variables.
 */
final class PhpTemplateRenderer implements TemplateRendererInterface
{
    /** @var array<string, string> */
    private array $paths = [];

    #[Override]
    public function addDefaultParam(string $templateName, string $param, mixed $value): void {}

    #[Override]
    public function addPath(string $path, ?string $namespace = null): void
    {
        $this->paths[(string) $namespace] = $path;
    }

    /**
     * @return list<TemplatePath>
     */
    #[Override]
    public function getPaths(): array
    {
        $paths = [];
        foreach ($this->paths as $namespace => $path) {
            $paths[] = new TemplatePath($path, $namespace);
        }

        return $paths;
    }

    /**
     * @param array<string, mixed>|object $params
     */
    #[Override]
    public function render(string $name, $params = []): string
    {
        [$namespace, $template] = explode('::', $name, limit: 2) + ['', ''];
        $file = ($this->paths[$namespace] ?? '') . "/{$template}.phtml";
        if (! is_file($file)) {
            throw new RuntimeException("Template \"{$name}\" not found.");
        }

        $render = static function (string $__file, array $__params): string {
            extract($__params, EXTR_SKIP);
            ob_start();
            include $__file;

            return (string) ob_get_clean();
        };

        return $render($file, (array) $params);
    }
}
