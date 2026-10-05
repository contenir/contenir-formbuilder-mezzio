# Submitting

`SubmitHandler` is a PSR-15 handler on the submit route (`POST /forms/submit/{slug}`
by default, see [Configuration](configuration.md#submit_route)). It loads the
form by the `slug` route attribute and runs the submission through
`SubmissionPipeline`, which:

1. checks the CSRF token (see [CSRF](#csrf));
2. stages the request's uploads for the form's `file` fields (see [Uploads](#uploads));
3. builds a core `FormSubmissionService` for the request, with a form
   builder bound to the session, the storage manager and the observers
   attached in order: `StoreSubmissionRegistrar`, `WebhookRegistrar`,
   `EmailNotificationRegistrar` (when a mailer is registered), then the
   `formbuilder.observers` services;
4. validates, detects spam and notifies the observers;
5. removes the staged uploads.

## Responses

A request whose `Accept` header contains `application/json` gets JSON; any
other is redirected. JSON is UTF-8 with `<`, `>`, `&`, `'` and `"` escaped.

| Situation | JSON | Redirect |
| --- | --- | --- |
| Not a POST | 405 `{"error": "Method not allowed"}`, `Allow: POST` | (always JSON) |
| No slug | 400 `{"error": "Missing slug"}` | (always JSON) |
| Unknown form | 404 `{"error": "Form not found"}` | (always JSON) |
| Rejected CSRF token | 422 `{"ok": false, "errors": {"_csrf": {"notSame": …}}}` | Back to the referrer, values and errors stashed |
| Invalid | 422 `{"ok": false, "errors": {…}}` | Back to the referrer, values and errors stashed |
| Valid or spam | 200 `{"ok": true, "mode": …}` | See success modes |

Spam (a filled honeypot) with a valid token is answered exactly like a
success. With the default route the router already answers other methods
with 405, and paths that are not slug-shaped with 404.

A request without a mezzio-session throws `MissingSessionException`: pipe
`Mezzio\Session\SessionMiddleware` before the route.

The submission context passed to observers is `ip` (the `REMOTE_ADDR` server
parameter), `user_id` (null), `meta` (`user_agent`, `referer`) and `site`
(`base_url`, from the request URI's scheme, host and non-default port).

## Success modes

`FormDefinition::$settings['success']` selects the outcome:

| `mode` | Redirect | JSON adds |
| --- | --- | --- |
| `redirect_referrer` (default, also for unknown modes) | Referrer with `?submit=<slug>` | |
| `redirect_url` | `redirect_url` with merge tags, values URL-encoded; the referrer when empty | `url` |
| `inline_message` | Referrer with `?submit=<slug>` | `title`, `message` with merge tags |

`{entry:id}` is available once the entry is stored. `{site:base_url}` is the
request's, unless `formbuilder.site_context.base_url` is configured.

## Back to the referrer

The redirect keeps the referrer's path and query, replaces any existing
`submit` parameter and drops its fragment. A POSTed `_anchor` that looks like
an HTML id (`[A-Za-z][\w-]*`) is appended as the fragment, so a mid-page form
scrolls back into view; `FormPresenter` adds one (`formbuilder-<slug>`) to
every form it renders. Without a referrer the target is `/`.

## The error stash

On an invalid, non-JSON submission the handler stores the POSTed values
(without `_anchor` and `_csrf`) and the validation messages in the session,
keyed by slug, under `contenir_formbuilder_stash_<slug>`:

```php
$stash->store($session, 'contact', $values, $errors);
$stash->consume($session, 'contact'); // ['values' => …, 'errors' => …] once, then null
```

Slugs are lower-cased and characters outside `[a-z0-9_-]` become `_`, so
`Contact` and `contact` share an entry. Malformed entries are discarded.
`FormPresenter` consumes the entry when it next renders the form.

## CSRF

`CsrfTokenManager` gives each session a random 256-bit secret, stored in the
session the first time a token is issued. A form's token is the HMAC-SHA256 of
its slug under that secret. `SessionCsrfFormBuilder` builds the form with the
core builder and adds a `CsrfElement` (a hidden `_csrf` input carrying the
token, with a required input checked by `CsrfTokenValidator`) in place of the
core's laminas-session `Csrf` element.

The token is checked twice: by `SubmissionPipeline`, before anything else, and
by the form's own input filter. The first check is what makes a forged
request harmless: it rejects the submission before uploads are staged and
before the honeypot is considered, so a cross-site POST cannot be stored even
as spam.

- A missing, empty or non-string token is rejected.
- A token for another form, or issued to another session, is rejected.
- A session that was never issued a token (a forged request, an expired
  session) rejects every token; checking never creates a secret.
- Tokens stay valid for the session, so several tabs, a re-submit after a
  validation error and AJAX retries all work.

## Uploads

`UploadedFileStager` turns the request's PSR-7 uploads for the form's `file`
fields into the `$_FILES`-shaped array the core service reads. A successful
upload is moved with `UploadedFileInterface::moveTo()` to a new, randomly
named file in `formbuilder.upload_directory`; under a SAPI that is
`move_uploaded_file()`, and runtimes whose uploads are streams work the same
way. The core service treats only those staged paths as uploads and stores
them on the `StorageManager` default profile under `forms/<slug>/`, replacing
the field's value with the stored path. Staged files are removed when the
submission ends, whether it succeeded or not, and nothing is staged when the
CSRF token is rejected. Without a `StorageManager` uploads are ignored.

## Security

| Guard | Where | Tested in |
| --- | --- | --- |
| CSRF token required, per session and form | `SubmissionPipeline`, `CsrfTokenManager`, `CsrfElement` | `CsrfTokenManagerTest`, `SessionCsrfFormBuilderTest`, `SubmitHandlerTest`, `ApplicationTest` |
| Referer redirects only to a local path or the request's host | `SameSiteReferer`, `ReturnTarget` | `SameSiteRefererTest`, `ReturnTargetTest`, `SubmitHandlerTest` |
| `_anchor` limited to an HTML id | `ReturnTarget` | `ReturnTargetTest` |
| HTML-or-text decided by the template; HTML values escaped | `EmailNotificationRegistrar` | `EmailNotificationRegistrarTest` |
| CR/LF collapsed in subjects | `EmailNotificationRegistrar` | `EmailNotificationRegistrarTest` |
| Redirect URLs URL-encode merge-tag values | `SuccessSettings` | `SuccessSettingsTest` |
| Only staged uploads reach storage | `UploadedFileStager`, `StagedUploads` | `UploadedFileStagerTest`, `SubmissionPipelineTest` |
| JSON escapes markup characters | `Responder` | `ResponderTest` |

None of these paths has a mutation-testing exclusion.
