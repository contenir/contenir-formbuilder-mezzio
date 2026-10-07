# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

## [2.0.0-RC1] - 2026-10-06

First release: the Mezzio counterpart of
`contenir/contenir-formbuilder-laminas-mvc`, for `contenir/contenir-formbuilder`
2.0. The version starts at 2.0 to line up with the other Contenir 2.x
packages. See [Coming from laminas-mvc](docs/coming-from-laminas-mvc.md).

### Added

- `Loader\PhpDbFormLoader` and `Repository\PhpDbEntryRepository` over a
  php-db adapter, behind `FormLoaderInterface` and `EntryRepositoryInterface`.
- `Handler\SubmitHandler`, a PSR-15 handler for `POST /forms/submit/{slug}`,
  with `Handler\SubmissionPipeline`: JSON or redirect answers, the
  `redirect_referrer`, `redirect_url` and `inline_message` success modes,
  `_anchor` fragments, and a session stash of invalid submissions.
- `Route\SubmitRouteDelegator`, which registers the submit route from
  `formbuilder.submit_route`.
- Session CSRF protection for mezzio-session (`Csrf\*`), replacing the core's
  laminas-session CSRF element.
- `Render\FormPresenter`, `Render\FormBlockRenderer` and the `formbuilder::form`
  template.
- `Registrar\StoreSubmissionRegistrar`, `Registrar\EmailNotificationRegistrar`
  (Symfony Mailer) and the core webhook registrar in the submit pipeline.
- File uploads from PSR-7 requests, staged by `Http\UploadedFileStager` and
  stored through `contenir/contenir-storage`.
- `ConfigProvider` and factories for every service.

### Security

- Submissions are rejected unless they carry the CSRF token issued to the
  session for that form, before any upload is staged and before the honeypot
  is considered.
- The Referer is only followed back to a local path or the request's host.
- HTML notification bodies escape every merge-tag value; whether a body is
  HTML is decided by its template, never by submitted values. CR/LF in
  expanded subjects become spaces.
- Conflicts with `symfony/mailer` and `symfony/mime` releases affected by
  CVE-2026-45067, CVE-2026-45068 and CVE-2026-45070.
