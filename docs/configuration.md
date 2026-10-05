# Configuration

`ConfigProvider` registers:

| Key | Contents |
| --- | --- |
| `dependencies.aliases` | `FormLoaderInterface` → `PhpDbFormLoader`, `EntryRepositoryInterface` → `PhpDbEntryRepository`, `Contenir\FormBuilder\Service\FormBuilderInterface` → `FormBuilderService` |
| `dependencies.invokables` | `CsrfTokenManager`, `FormStateStash` |
| `dependencies.factories` | `PhpDbFormLoader`, `PhpDbEntryRepository`, `FormBuilderService` (core), `CsrfFormFactory`, `StoreSubmissionRegistrar`, `EmailNotificationRegistrar`, `WebhookRegistrar` (core), `TokenReplacerBuilder`, `TokenReplacer` (core), `Responder`, `SubmissionPipeline`, `SubmitHandler`, `FormPresenter`, `FormBlockRenderer` |
| `dependencies.delegators` | `Mezzio\Application` → `SubmitRouteDelegator` (the submit route) |
| `templates.paths.formbuilder` | The bundled `templates/` directory (`formbuilder::form`) |
| `formbuilder` | The defaults below |

## `formbuilder` keys

```php
return [
    'formbuilder' => [
        // Service id of the php-db adapter for the loader and the entry repository.
        'db_adapter'        => PhpDb\Adapter\AdapterInterface::class,
        // Static values for {site:*} merge tags. A configured base_url wins
        // over the one taken from the request.
        'site_context'      => ['admin_url' => 'https://admin.example.com'],
        // Extra merge-tag namespaces: namespace => service id of a callable
        // fn (string $key): ?string. Return null to leave the tag in place.
        'token_resolvers'   => ['settings' => App\Mail\SettingsResolver::class],
        // Extra observers, attached after the built-in ones. Ids that are not
        // registered, or do not resolve to an SplObserver, are skipped.
        'observers'         => [App\Form\CrmRegistrar::class],
        // From address of notifications that do not set one. Symfony Mailer
        // refuses a message without a From.
        'notification_from' => 'Website <noreply@example.com>',
        // Where uploads are staged before storage (default: sys_get_temp_dir()).
        'upload_directory'  => null,
        // Template rendered by FormBlockRenderer.
        'template'          => 'formbuilder::form',
        // The submit route, see below.
        'submit_route'      => [
            'path'       => '/forms/submit/{slug:[a-z0-9][a-z0-9\-]*}',
            'name'       => 'formbuilder.submit',
            'middleware' => [],
            'options'    => [],
        ],
    ],
];
```

### `submit_route`

`SubmitRouteDelegator` registers `POST {path}` named `{name}`, piping the
`middleware` service names before `SubmitHandler`, and applies `options` to
the route. The handler reads the form slug from the `slug` route attribute.

- The default `path` uses the FastRoute syntax. With laminas-router, set
  `'path' => '/forms/submit/:slug'` and put the slug constraint in
  `'options' => ['constraints' => ['slug' => '[a-z0-9][a-z0-9\-]*']]`.
- `FormPresenter` generates form actions from the route `name`, so keep it in
  step if you change it.
- Set `'submit_route' => false` to register the route yourself, for example
  in `config/routes.php`:

  ```php
  $app->post('/enquiries/{slug}', Contenir\FormBuilder\Mezzio\Handler\SubmitHandler::class, 'formbuilder.submit');
  ```

The session middleware must run before the handler: pipe
`Mezzio\Session\SessionMiddleware` globally (recommended), or list it in
`submit_route.middleware` and in front of every page that renders a form.

## Services the package needs

| Service | Used by |
| --- | --- |
| `PhpDb\Adapter\AdapterInterface` (or `formbuilder.db_adapter`) | `PhpDbFormLoader`, `PhpDbEntryRepository` |
| `Mezzio\Session\SessionPersistenceInterface` | `SessionMiddleware`, for CSRF tokens and the stash |
| `Psr\Http\Message\ResponseFactoryInterface`, `StreamFactoryInterface` | `Responder` |
| `Mezzio\Router\RouterInterface` | `FormPresenter`, for form actions |
| `Mezzio\Template\TemplateRendererInterface` | `FormBlockRenderer` |

## Optional services

| Service | Effect |
| --- | --- |
| `Symfony\Component\Mailer\MailerInterface` | Enables `EmailNotificationRegistrar` in the submit pipeline |
| `Contenir\Storage\StorageManager` | Enables `file` field uploads (default profile) |
| `Psr\Log\LoggerInterface` | Email and webhook failures are logged |
| `Psr\Clock\ClockInterface` | Entry timestamps (the system clock otherwise) |

A service registered under one of these names but of another type is ignored.
A required service of the wrong type (for example a `db_adapter` that is not a
php-db adapter) fails with an `UnexpectedValueException` naming the service.

Every class is `final`. Replace a service by registering your own factory or
implementation under its name; the extension points are
`FormLoaderInterface`, `EntryRepositoryInterface` and the core
`FormBuilderInterface` (for custom field types, register your own
`FormBuilderService` factory with your `FieldTypeRegistry`).
