<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Integration\Render;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Mezzio\ConfigProvider;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfFormFactory;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenManager;
use Contenir\FormBuilder\Mezzio\Render\FormBlockRenderer;
use Contenir\FormBuilder\Mezzio\Render\FormPresenter;
use Contenir\FormBuilder\Mezzio\Render\PresentedForm;
use Contenir\FormBuilder\Mezzio\State\FormStateStash;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\FormDefinitionFactory;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Loader\InMemoryFormLoader;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Template\PhpTemplateRenderer;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Router\RouterInterface;
use Mezzio\Session\Session;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_contains;

/**
 * Renders form blocks through the bundled `formbuilder::form` template.
 */
#[Group('integration')]
final class FormBlockRendererTest extends TestCase
{
    private Session $session;

    private FormStateStash $stash;

    /**
     * @param array<string, mixed> $settings
     */
    private static function form(array $settings = []): FormDefinition
    {
        return new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
            settings: $settings,
            sections: FormDefinitionFactory::withFields([FormDefinitionFactory::field('text', 'name')])->sections,
        );
    }

    #[Test]
    public function rendersNothingForAnUnknownForm(): void
    {
        $templates = $this->createMock(TemplateRendererInterface::class);
        $templates->expects($this->never())->method('render');

        static::assertSame('', (new FormBlockRenderer($this->presenter(), $templates))->render($this->request(), 'x'));
    }

    #[Test]
    public function rendersTheConfiguredTemplateWithThePresentedForm(): void
    {
        $templates = $this->createMock(TemplateRendererInterface::class);
        $templates->expects($this->once())
            ->method('render')
            ->with('app::form', $this->callback(
                static fn(array $params): bool => (
                    $params['form'] instanceof PresentedForm
                    && 'contact' === $params['form']->definition->slug
                ),
            ))
            ->willReturn('<rendered>');

        static::assertSame(
            '<rendered>',
            (new FormBlockRenderer($this->presenter(), $templates, 'app::form'))->render($this->request(), 'contact'),
        );
    }

    #[Test]
    public function theBundledTemplateExplainsARejectedToken(): void
    {
        $this->stash->store($this->session, 'contact', [], ['_csrf' => ['notSame' => 'x']]);

        static::assertTrue(str_contains($this->renderBundled(), 'class="formbuilder__notice" role="alert"'));
    }

    #[Test]
    public function theBundledTemplateHasADefaultSuccessTitleAndNoEmptyMessage(): void
    {
        $html = $this->renderBundled(self::form(['success' => ['message' => '']]), ['submit' => 'contact']);

        static::assertSame(
            [true, false],
            [
                str_contains($html, '<h2 class="formbuilder__success-title">Thank you</h2>'),
                str_contains($html, 'formbuilder__success-message'),
            ],
        );
    }

    #[Test]
    public function theBundledTemplateShowsTheEscapedSuccessText(): void
    {
        $html = $this->renderBundled(
            self::form(['success' => ['title' => 'Thanks <b>', 'message' => "Line & one\nLine two"]]),
            ['submit' => 'contact'],
        );

        static::assertSame(
            [true, true, false],
            [
                str_contains($html, '<h2 class="formbuilder__success-title">Thanks &lt;b&gt;</h2>'),
                str_contains($html, "<p class=\"formbuilder__success-message\">Line &amp; one<br />\nLine two</p>"),
                str_contains($html, '<form '),
            ],
        );
    }

    #[Test]
    public function theBundledTemplateWrapsTheFormInItsAnchor(): void
    {
        $html = $this->renderBundled();

        static::assertSame(
            [true, true, false, false],
            [
                str_contains($html, '<div class="formbuilder" id="formbuilder-contact">'),
                str_contains($html, '<form '),
                str_contains($html, 'formbuilder__success'),
                str_contains($html, 'formbuilder__notice'),
            ],
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->session = new Session([]);
        $this->stash   = new FormStateStash();
    }

    private function presenter(?FormDefinition $form = null): FormPresenter
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('generateUri')->willReturn('/forms/submit/contact');

        return new FormPresenter(
            new InMemoryFormLoader($form ?? self::form()),
            new CsrfFormFactory(
                new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory()),
                new CsrfTokenManager(),
            ),
            $this->stash,
            $router,
        );
    }

    /**
     * @param array<string, mixed> $query
     */
    private function renderBundled(?FormDefinition $form = null, array $query = []): string
    {
        $templates = new PhpTemplateRenderer();
        foreach ((new ConfigProvider())->getTemplates()['paths'] as $namespace => $paths) {
            $templates->addPath($paths[0], $namespace);
        }

        return (new FormBlockRenderer($this->presenter($form), $templates))->render(
            $this->request()->withQueryParams($query),
            'contact',
        );
    }

    private function request(): ServerRequest
    {
        return (new ServerRequest())->withAttribute(SessionMiddleware::SESSION_ATTRIBUTE, $this->session);
    }
}
