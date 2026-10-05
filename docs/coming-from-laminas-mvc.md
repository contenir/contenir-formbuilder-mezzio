# Coming from contenir-formbuilder-laminas-mvc

This package does on Mezzio what `contenir/contenir-formbuilder-laminas-mvc`
does on laminas-mvc, against the same forms schema and the same core. A site
moving to Mezzio replaces the laminas-mvc adapter with this one; the database,
form definitions, success settings, merge tags and front-end markup carry
over.

## Features

| laminas-mvc adapter | Mezzio adapter |
| --- | --- |
| `Module`, `ConfigProvider` (`service_manager`, `controllers`, `view_helpers`, `router`, `formbuilder`) | `ConfigProvider` (`dependencies`, `templates`, `formbuilder`); no `Module` |
| `Loader\FormLoaderInterface`, `Loader\LaminasDbFormLoader` (laminas-db) | `Loader\FormLoaderInterface` (same methods), `Loader\PhpDbFormLoader` (php-db) |
| `Repository\EntryRepositoryInterface`, `Repository\LaminasDbEntryRepository` | `Repository\EntryRepositoryInterface` (same `record()`; the `STATUS_*` constants moved to the interface), `Repository\PhpDbEntryRepository` (time from `Psr\Clock\ClockInterface` when registered) |
| `Controller\SubmitController::submitAction()` | `Handler\SubmitHandler` (PSR-15), with `Handler\SubmissionPipeline` |
| `forms-submit` route, `/forms/submit/:slug` | `formbuilder.submit` route, `POST /forms/submit/{slug:[a-z0-9][a-z0-9\-]*}`, registered by `Route\SubmitRouteDelegator`; see `formbuilder.submit_route` |
| JSON (`JsonModel`) vs redirect, on `Accept: application/json` | The same, as PSR-7 responses built by `Http\Responder` through PSR-17 factories |
| Success modes `redirect_referrer`, `redirect_url`, `inline_message` | The same (`Handler\SuccessSettings`) |
| `_anchor` fragment on referrer redirects | The same (`Http\ReturnTarget`); `FormPresenter` adds the field |
| `Controller\SameSiteReferer` (open-redirect guard) | `Http\SameSiteReferer`, same rules, compared with the PSR-7 request URI |
| `State\FormStateStash` on a laminas-session container (`ContenirFormBuilderFlash`) | `State\FormStateStash` on the request's mezzio-session (`contenir_formbuilder_stash_<slug>` keys); `_csrf` is no longer stashed |
| `formStashedState` view helper | Done by `Render\FormPresenter`, which hydrates the form from the stash; `PresentedForm::$errors` has the messages |
| `formMarkup` view helper, `Render\FormMarkup` (dispatching through `formElement`) | `Render\FormPresenter` / `Render\FormBlockRenderer` with the core `Contenir\FormBuilder\Render\FormMarkup` and the `formbuilder::form` template; same markup and classes |
| CSRF: the core's laminas-session `Csrf` element (5-minute tokens) | `Csrf\*`: a session-bound HMAC token per form in the mezzio-session, checked before anything else; see [Submitting](submitting.md#csrf) |
| `Registrar\StoreSubmissionRegistrar` | `Registrar\StoreSubmissionRegistrar`, unchanged |
| `Registrar\EmailNotificationRegistrar` on `Laminas\Mail\Transport\TransportInterface` | `Registrar\EmailNotificationRegistrar` on `Symfony\Component\Mailer\MailerInterface`; same HTML/text, escaping, subject and addressing rules |
| Core `WebhookRegistrar` | The same |
| File uploads from `$_FILES` via `contenir/storage` | PSR-7 uploads, staged by `Http\UploadedFileStager`, stored via `contenir/contenir-storage` |
| `Factory\TokenReplacerFactory` (shared `TokenReplacer`, `base_url` from the `Request` service) | `Token\TokenReplacerBuilder` builds one per submission with `base_url` from the request; `TokenReplacer::class` is still registered for code outside a request |
| `Container\Services` | `Container\Services` (internal), plus `adapter()` |

## Configuration keys

| laminas-mvc key | Mezzio key |
| --- | --- |
| `formbuilder.db_adapter` (default `Laminas\Db\Adapter\Adapter`) | `formbuilder.db_adapter` (default `PhpDb\Adapter\AdapterInterface`) |
| `formbuilder.site_context` | `formbuilder.site_context`, unchanged |
| `formbuilder.token_resolvers` | `formbuilder.token_resolvers`, unchanged |
| `formbuilder.observers` | `formbuilder.observers`, unchanged |
| `router.routes.forms-submit` | `formbuilder.submit_route` (`path`, `name`, `middleware`, `options`), or `false` to route it yourself |
| `controllers.factories` | Not applicable: Mezzio has no controllers |
| `view_helpers` (`formMarkup`, `formStashedState`) | Not applicable: see `FormPresenter` / `FormBlockRenderer` |
| | `formbuilder.notification_from`: new, the From address for notifications without one (Symfony Mailer requires a From) |
| | `formbuilder.upload_directory`: new, where uploads are staged |
| | `formbuilder.template`: new, the template `FormBlockRenderer` renders |

## Services

| laminas-mvc service | Mezzio service |
| --- | --- |
| `Laminas\Db\Adapter\Adapter` | `PhpDb\Adapter\AdapterInterface` |
| `Laminas\Mail\Transport\TransportInterface` | `Symfony\Component\Mailer\MailerInterface` |
| `Contenir\Storage\StorageManager` | The same |
| `Psr\Log\LoggerInterface` | The same |
| `Request` (for `{site:base_url}`) | Not needed: the handler passes the request's |
| laminas-session (started by the MVC application) | `Mezzio\Session\SessionMiddleware` and a `SessionPersistenceInterface` |

## Templates

Replace the laminas-view partial:

```phtml
<?php $stashed = $this->formStashedState($definition->slug); ?>
<?php if ($stashed !== null): ?>
    <?php $form->setData($stashed['values']); $form->setMessages($stashed['errors']); ?>
<?php endif ?>
<?= $isSuccess ? 'Thank you.' : $this->formMarkup($definition, $form) ?>
```

with a call in the page's handler:

```php
$html = $formBlockRenderer->render($request, 'contact');
```

or, for your own markup, `$formPresenter->present($request, 'contact')` and
the `PresentedForm` it returns. The form's markup and classes are the same.
