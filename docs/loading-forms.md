# Loading forms

`PhpDbFormLoader` implements `FormLoaderInterface` (the four methods below);
register another implementation as `FormLoaderInterface::class` to load
definitions from elsewhere. It hydrates `Contenir\FormBuilder\Definition\FormDefinition`
aggregates from the forms schema through a php-db adapter, with one query per
level (form, sections, groups, rows, fields, notifications, webhooks), so the
cost does not grow with the form's size. Queries are prepared statements.

```php
$loader = new PhpDbFormLoader($adapter);

$loader->loadById(5);           // ?FormDefinition
$loader->loadBySlug('contact'); // ?FormDefinition
$loader->loadAll();             // list<FormDefinition>, by title
$loader->listSummaries();       // list<array{id, slug, title, status}>, by title, one query
```

A failed query throws the php-db exception (`PhpDb\Exception\ExceptionInterface`).

## Schema

Tables: `form`, `form_section`, `form_group`, `form_row`, `form_field`,
`form_notification`, `form_webhook`, plus `form_entry` and `form_entry_value`
for submissions. It is the same schema the laminas-mvc adapter and the Contenir
admin use; `tests/install-forms.sqlite.sql` is the SQLite DDL.

Children are ordered by `sort`, then id. JSON columns (`settings_json`,
`options_json`, `validators_json`, `filters_json`, `conditional_json`,
`conditions_json`, `headers_json`) are decoded leniently:

- Missing, NULL and invalid JSON become `[]` (or `null` for `conditional_json`
  and `conditions_json`).
- Validators without a scalar `type` are skipped; the rest go through
  `ValidatorDefinition::fromArray()`, which makes a non-scalar `message` null
  and non-array `options` `[]`.
- Filters keep non-empty strings; webhook headers keep string values.
- `col_span` is clamped to 1–4 and defaults to 4; a webhook `method` is
  upper-cased and an empty `secret` becomes null.

## Why php-db

The 2.x packages are moving from laminas-db to [php-db/phpdb](https://github.com/php-db/phpdb),
its maintained successor (`contenir/contenir-db-model` 2.0 is built on it), so
this adapter talks to a `PhpDb\Adapter\AdapterInterface`. The loader and the
repository use plain prepared SQL rather than `contenir-db-model` entities:
they only hydrate the core's value objects and append entries, which needs no
mapping layer, and it keeps the package off a release candidate.

php-db 0.6 is still a dev branch, so a site has to allow it in its root
`composer.json` (see [Installation](../README.md#installation)); this
package stays a release candidate until php-db 0.6.0 is tagged.
