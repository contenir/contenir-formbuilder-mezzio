# contenir/contenir-formbuilder-mezzio

[![Continuous Integration](https://github.com/contenir/contenir-formbuilder-mezzio/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/contenir/contenir-formbuilder-mezzio/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/contenir/contenir-formbuilder-mezzio/graph/badge.svg)](https://codecov.io/gh/contenir/contenir-formbuilder-mezzio)

Mezzio adapter for [`contenir/contenir-formbuilder`](https://github.com/contenir/contenir-formbuilder),
for Mezzio sites that use Contenir forms without running the full CMS. It is
the Mezzio counterpart of
[`contenir/contenir-formbuilder-laminas-mvc`](https://github.com/contenir/contenir-formbuilder-laminas-mvc),
with the same features expressed with Mezzio plumbing:

- **`Loader\PhpDbFormLoader`** reads form definitions from the forms schema
  through a [php-db](https://github.com/php-db/phpdb) adapter, one query per
  level.
- **`Handler\SubmitHandler`**, a PSR-15 handler on `POST /forms/submit/{slug}`:
  CSRF check, validation, spam trap, file uploads, then a redirect or a JSON
  answer, with the visitor's values and errors stashed in the session for the
  next render when the submission is invalid.
- **Registrars** store the entry (`StoreSubmissionRegistrar` over
  `PhpDbEntryRepository`), send email notifications through Symfony Mailer
  (`EmailNotificationRegistrar`) and fire webhooks (the core
  `WebhookRegistrar`).
- **`Render\FormPresenter`** builds, hydrates and renders a form for a page;
  **`Render\FormBlockRenderer`** renders it, or its success state, through the
  `formbuilder::form` template and Mezzio's `TemplateRendererInterface`.
- **`ConfigProvider`** with factories for all of it, and a delegator that
  registers the submit route.

## Requirements

- PHP 8.3, 8.4 or 8.5
- `contenir/contenir-formbuilder` 2.2+
- mezzio/mezzio 3.18+, a Mezzio router (the default route path uses the
  FastRoute syntax, see [Configuration](docs/configuration.md#submit_route))
  and PSR-17 factories registered as `ResponseFactoryInterface` and
  `StreamFactoryInterface` (laminas-diactoros' ConfigProvider does this)
- mezzio/mezzio-session with a persistence (for example
  mezzio/mezzio-session-ext): the CSRF tokens and the error stash live in the
  session
- php-db/phpdb 0.6 and a database with the forms schema
  (`tests/install-forms.sqlite.sql` is the SQLite version)
- A template renderer when you use `FormBlockRenderer` (the bundled template
  is plain PHP: laminas-view and Plates render it)
- Optional: symfony/mailer 7.4.12+ for email notifications, and
  `contenir/contenir-storage` 2.2+ for file uploads

## Installation

```bash
composer require contenir/contenir-formbuilder-mezzio
```

With [laminas-component-installer](https://docs.laminas.dev/laminas-component-installer/)
the `Contenir\FormBuilder\Mezzio\ConfigProvider` is added to
`config/config.php`; without it, add the provider yourself.

Then make sure the session middleware runs before routing, as Mezzio sites
that use sessions do:

```php
// config/pipeline.php
$app->pipe(Mezzio\Session\SessionMiddleware::class);
$app->pipe(Mezzio\Router\Middleware\RouteMiddleware::class);
```

and register the services the package needs:

| Service | Purpose |
| --- | --- |
| `PhpDb\Adapter\AdapterInterface` (or the id in `formbuilder.db_adapter`) | Forms schema and entries |
| `Mezzio\Session\SessionPersistenceInterface` | Sessions, for CSRF and the error stash |
| `Symfony\Component\Mailer\MailerInterface` | Optional: enables email notifications |
| `Contenir\Storage\StorageManager` | Optional: enables `file` fields |
| `Psr\Log\LoggerInterface` | Optional: email and webhook failures are logged |
| `Psr\Clock\ClockInterface` | Optional: entry timestamps (the system clock otherwise) |

The submit route is registered for you at `POST /forms/submit/{slug}`, named
`formbuilder.submit`.

## Usage

Render a form on a page from your own handler:

```php
use Contenir\FormBuilder\Mezzio\Render\FormBlockRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Template\TemplateRendererInterface;

final readonly class ContactPageHandler implements RequestHandlerInterface
{
    public function __construct(
        private FormBlockRenderer $forms,
        private TemplateRendererInterface $templates,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new HtmlResponse($this->templates->render('app::contact', [
            'form' => $this->forms->render($request, 'contact'),
        ]));
    }
}
```

`$form` is the form, wrapped in `<div class="formbuilder" id="formbuilder-contact">`,
or its success message after a successful submission. For your own markup
use `FormPresenter::present()`, which returns the definition, the built and
hydrated Laminas form and its rendered HTML. See [Rendering](docs/rendering.md).

The form posts to the submit route, which answers with a redirect back to the
page (or JSON for `Accept: application/json`). See [Submitting](docs/submitting.md).

| Class | Purpose |
| --- | --- |
| `Handler\SubmitHandler` | The PSR-15 submit endpoint |
| `Handler\SubmissionPipeline` | One submission through the core `FormSubmissionService`: CSRF, uploads, observers |
| `Render\FormPresenter` | Load, build (with the session's CSRF token), hydrate from the stash, render |
| `Render\FormBlockRenderer` | `FormPresenter` plus the `formbuilder::form` template |
| `Render\PresentedForm` | What the presenter returns |
| `Loader\FormLoaderInterface`, `Loader\PhpDbFormLoader` | Form definitions |
| `Repository\EntryRepositoryInterface`, `Repository\PhpDbEntryRepository` | Entry storage |
| `Registrar\StoreSubmissionRegistrar`, `Registrar\EmailNotificationRegistrar` | Submission observers |
| `Csrf\CsrfTokenManager`, `Csrf\CsrfFormFactory`, `Csrf\SessionCsrfFormBuilder`, `Csrf\CsrfElement`, `Csrf\CsrfTokenValidator` | Session CSRF tokens |
| `State\FormStateStash` | One-shot stash of an invalid submission's values and errors |
| `Http\UploadedFileStager`, `Http\StagedUploads` | PSR-7 uploads for the core service |
| `Http\Responder` | PSR-17 JSON and redirect responses |
| `Token\TokenReplacerBuilder` | Merge-tag replacers with the configured `{site:*}` values and namespaces |
| `Route\SubmitRouteDelegator` | Registers the submit route on `Mezzio\Application` |
| `ConfigProvider` and the `Factory\*` classes | Container wiring |

| Page | Covers |
| --- | --- |
| [Configuration](docs/configuration.md) | The `formbuilder` keys, services and the submit route |
| [Loading forms](docs/loading-forms.md) | `PhpDbFormLoader` and the schema |
| [Submitting](docs/submitting.md) | `SubmitHandler`, responses, success modes, CSRF, the stash, uploads |
| [Rendering](docs/rendering.md) | `FormPresenter`, `FormBlockRenderer` and the `formbuilder::form` template |
| [Registrars](docs/registrars.md) | Entry storage, email notifications, webhooks, your own observers |
| [Coming from laminas-mvc](docs/coming-from-laminas-mvc.md) | Each laminas-mvc adapter feature and config key, and its Mezzio equivalent |

## Security

- **CSRF.** Every submission must carry the token issued to the visitor's
  session for that form; a missing, wrong or foreign-session token is
  rejected before anything is validated, stored, mailed or uploaded, even
  when the honeypot marks the request as spam.
- **Redirects stay on the site.** The Referer is followed back only when it
  is a local path (not `//host` or `/\host`) or an `http(s)` URL on the
  request's host; anything else redirects to `/`. A posted `_anchor` must look
  like an HTML id.
- **Notification emails.** Whether a body is HTML is decided by the template
  (markup or `{entry:fields}`), never by submitted values; HTML bodies escape
  every merge-tag value with `TokenReplacer::replaceForHtml()`. Line breaks in
  an expanded subject become spaces.
- **Uploads.** Only the request's PSR-7 uploads for the form's `file` fields
  reach the storage layer, moved first to randomly named staging files that
  are removed after the submission.
- **JSON** answers escape `<`, `>`, `&`, `'` and `"`.

See [Submitting](docs/submitting.md#security) for the details.

## Development

The QA toolchain is [php-db/phpdb-qa-tools](https://github.com/php-db/phpdb-qa-tools).
[Mago](https://mago.carthage.software/) is a standalone binary, installed
separately (`brew install mago`).

```bash
composer check             # everything below
composer cs-check          # mago format --check && mago lint
composer static-analysis   # mago analyze
composer test              # unit suite: in-memory sessions, containers and mailers, no I/O
composer test-integration  # integration suite: SQLite forms schema, a real Mezzio application, staged uploads
composer test-coverage     # both suites, clover.xml for Codecov
composer mutation-test     # Infection over both suites (needs Xdebug or PCOV)
```

## License

MIT. See [LICENSE](LICENSE).
