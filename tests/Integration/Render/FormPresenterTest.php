<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Integration\Render;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfFormFactory;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenManager;
use Contenir\FormBuilder\Mezzio\Exception\MissingSessionException;
use Contenir\FormBuilder\Mezzio\Render\FormPresenter;
use Contenir\FormBuilder\Mezzio\State\FormStateStash;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\FormDefinitionFactory;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Loader\InMemoryFormLoader;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Router\RouterInterface;
use Mezzio\Session\Session;
use Mezzio\Session\SessionMiddleware;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_contains;
use function str_ends_with;
use function substr_count;

/**
 * Presents forms through the real core builder and renderer.
 */
#[Group('integration')]
final class FormPresenterTest extends TestCase
{
    private CsrfTokenManager $csrf;

    private Session $session;

    private FormStateStash $stash;

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function submittedProvider(): array
    {
        return [
            'this form'    => ['contact', true],
            'another form' => ['other', false],
            'not a string' => [['contact'], false],
            'absent'       => [null, false],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string|null, string|null}>
     */
    public static function successTextProvider(): array
    {
        return [
            'configured'           => [['success' => ['title' => 'Thanks', 'message' => 'Soon']], 'Thanks', 'Soon'],
            'not configured'       => [[], null, null],
            'success not an array' => [['success' => 'x'], null, null],
            'not strings'          => [['success' => ['title' => 5, 'message' => ['x']]], null, null],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function form(array $settings = [], string $slug = 'contact'): FormDefinition
    {
        return new FormDefinition(
            id: 1,
            slug: $slug,
            title: 'Contact',
            settings: $settings,
            sections: FormDefinitionFactory::withFields([
                FormDefinitionFactory::field('text', 'name', ['required' => true]),
            ])->sections,
        );
    }

    #[Test]
    public function addsTheAnchorFieldOnce(): void
    {
        $html = (string) $this->presenter()->present($this->request(), 'contact')?->html;

        static::assertSame(1, substr_count($html, needle: 'name="_anchor"'));
    }

    #[Test]
    public function exposesTheDefinitionAndTheBuiltForm(): void
    {
        $form      = self::form();
        $presented = $this->presenter($form)->present($this->request(), 'contact');

        static::assertSame([$form, true], [$presented?->definition, $presented?->form->has('name')]);
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[Test]
    #[DataProvider('successTextProvider')]
    public function exposesTheSuccessText(array $settings, ?string $title, ?string $message): void
    {
        $presented = $this->presenter(self::form($settings))->present($this->request(), 'contact');

        static::assertSame([$title, $message], [$presented?->successTitle, $presented?->successMessage]);
    }

    #[Test]
    public function failsWithoutASession(): void
    {
        $this->expectException(MissingSessionException::class);

        $this->presenter()->present(new ServerRequest(), 'contact');
    }

    #[Test]
    public function generatesTheActionFromTheConfiguredRouteName(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects($this->once())
            ->method('generateUri')
            ->with('forms.submit', ['slug' => 'contact'])
            ->willReturn('/f/contact');

        $presented = $this->presenter(
            router: $router,
            routeName: 'forms.submit',
        )->present($this->request(), 'contact');

        static::assertTrue(str_contains((string) $presented?->html, 'action="/f/contact"'));
    }

    #[Test]
    public function hydratesTheFormFromTheStashOnce(): void
    {
        $this->stash->store(
            $this->session,
            'contact',
            ['name' => 'Ann <Lee>'],
            [
                'name'  => ['isEmpty' => 'Value is required'],
                '_csrf' => ['notSame' => 'Expired'],
                'bad'   => 'not a message set',
            ],
        );

        $presented = $this->presenter()->present($this->request(), 'contact');
        $html      = (string) $presented?->html;

        static::assertSame(
            [
                true,
                true,
                ['name' => ['isEmpty' => 'Value is required'], '_csrf' => ['notSame' => 'Expired']],
                null,
            ],
            [
                str_contains($html, 'value="Ann &lt;Lee&gt;"'),
                str_contains($html, '<li>Value is required</li>'),
                $presented?->errors,
                $this->stash->consume($this->session, 'contact'),
            ],
        );
    }

    #[Test]
    #[DataProvider('submittedProvider')]
    public function isSubmittedWhenTheQueryNamesThisForm(mixed $submit, bool $expected): void
    {
        $request = $this->request()->withQueryParams(null === $submit ? [] : ['submit' => $submit]);

        static::assertSame($expected, $this->presenter()->present($request, 'contact')?->submitted);
    }

    #[Test]
    public function rendersTheFormWithTheSessionsTokenPointingAtTheSubmitRoute(): void
    {
        $presented = $this->presenter()->present($this->request(), 'contact');
        $html      = (string) $presented?->html;

        static::assertSame(
            [true, true, true, 'formbuilder-contact', false],
            [
                str_contains($html, 'action="/forms/submit/contact"'),
                str_contains(
                    $html,
                    "name=\"_csrf\" type=\"hidden\" id=\"_csrf\" value=\"{$this->csrf->token(
                        $this->session,
                        'contact',
                    )}\"",
                ),
                str_ends_with($html, '<input type="hidden" name="_anchor" value="formbuilder-contact"></form>'),
                $presented?->anchor,
                $presented?->submitted,
            ],
        );
    }

    #[Test]
    public function returnsNullForAnUnknownForm(): void
    {
        static::assertNull($this->presenter()->present($this->request(), 'missing'));
    }

    #[Test]
    public function theAnchorKeepsOnlyIdCharacters(): void
    {
        static::assertSame(
            ['formbuilder-Contact_us-2', 'formbuilder-a-b--c-'],
            [FormPresenter::anchorFor('Contact_us-2'), FormPresenter::anchorFor('a b"<c>')],
        );
    }

    #[Test]
    public function theStashedTokenNeverReplacesTheIssuedOne(): void
    {
        $this->stash->store($this->session, 'contact', ['_csrf' => 'stale'], []);

        $presented = $this->presenter()->present($this->request(), 'contact');

        static::assertSame($this->csrf->token($this->session, 'contact'), $presented?->form->get('_csrf')->getValue());
    }

    #[Test]
    public function withoutAStashThereAreNoErrors(): void
    {
        static::assertSame([], $this->presenter()->present($this->request(), 'contact')?->errors);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->csrf    = new CsrfTokenManager();
        $this->session = new Session([]);
        $this->stash   = new FormStateStash();
    }

    private function presenter(
        ?FormDefinition $form = null,
        ?RouterInterface $router = null,
        string $routeName = 'formbuilder.submit',
    ): FormPresenter {
        if (null === $router) {
            $router = $this->createStub(RouterInterface::class);
            $router->method('generateUri')->willReturn('/forms/submit/contact');
        }

        return new FormPresenter(
            new InMemoryFormLoader($form ?? self::form()),
            new CsrfFormFactory(new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory()), $this->csrf),
            $this->stash,
            $router,
            $routeName,
        );
    }

    private function request(): ServerRequest
    {
        return (new ServerRequest())->withAttribute(SessionMiddleware::SESSION_ATTRIBUTE, $this->session);
    }
}
