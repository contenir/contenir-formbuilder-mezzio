# Registrars

Registrars are `SplObserver`s notified by the core `FormSubmissionService`
with the `BuilderForm` and its `registry` (see the core package's
documentation), for valid and spam submissions. `SubmissionPipeline` attaches
them in this order:

1. `StoreSubmissionRegistrar`
2. `WebhookRegistrar`
3. `EmailNotificationRegistrar`, when a `Symfony\Component\Mailer\MailerInterface` is registered
4. each `formbuilder.observers` service that is an `SplObserver`

## StoreSubmissionRegistrar

Records every valid and spam submission through
`EntryRepositoryInterface::record()` (status `complete` or `spam`), then
writes `entry_id` and `entry_status` to the registry, so the observers that
follow and the handler see them. It does nothing without a `FormDefinition`
that has an id and an array of values. A database error is rethrown: the
visitor must not be told the submission succeeded.

## PhpDbEntryRepository

Implements `EntryRepositoryInterface::record()`; register another
implementation as `EntryRepositoryInterface::class` to store entries elsewhere.

```php
$id = $repository->record(
    formId: 5,
    values: ['name' => 'Ann', 'tags' => ['a', 'b']],
    status: EntryRepositoryInterface::STATUS_COMPLETE,
    ip: '10.0.0.1',
    userId: null,
    meta: ['user_agent' => '…'],
);
```

Inserts one `form_entry` row (with the clock's time and `meta_json`, NULL
when empty) and one `form_entry_value` row per value, in a transaction:
scalars go to `value_text`, arrays to `value_json`, anything else is stored as
NULL. On failure the transaction is rolled back and the exception rethrown.

Status constants, on the interface: `STATUS_PENDING`, `STATUS_COMPLETE`,
`STATUS_SPAM`, `STATUS_ARCHIVE`, `STATUS_REDACTED`.

## EmailNotificationRegistrar

Sends each enabled `NotificationDefinition` of the form through Symfony
Mailer. Spam is skipped.

- **Merge tags** are expanded in the subject, body and addresses with a
  `TokenReplacer` built for the submission by `TokenReplacerBuilder`, so
  `{site:base_url}` is the request's unless it is configured. Line breaks in
  the expanded subject become spaces.
- **Recipients:** `toAddress` is split on commas, semicolons and white space;
  each part is expanded and kept when it is a valid email address.
- **From and Reply-To:** expanded; an empty result is skipped, and one Symfony
  rejects is logged as a notice and skipped. A notification without a From
  address uses `formbuilder.notification_from`.
- **Body:** the *template* decides the format. A template containing HTML
  tags or `{entry:fields}` is an HTML body: every merge-tag value is
  HTML-escaped (`TokenReplacer::replaceForHtml()`), and a plain-text
  alternative is generated from it. Any other template is sent as plain text,
  even if a submitted value contains markup.
- **Failures** (including a message Symfony refuses, such as one without a
  From) are logged as warnings and never thrown, and do not stop the other
  notifications.

## WebhookRegistrar

The core `Contenir\FormBuilder\Registrar\WebhookRegistrar`, built with the
container's PSR-3 logger when there is one. See the core documentation.

## Your own observers

List their service ids in `formbuilder.observers`. They run after the
built-in registrars, so `entry_id` is in the registry, and the registry's
`context` carries `ip`, `user_id`, `meta` and `site` (see
[Submitting](submitting.md#responses)).
