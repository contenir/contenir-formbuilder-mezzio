<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\State;

use Mezzio\Session\SessionInterface;

use function is_array;
use function preg_replace;
use function strtolower;

/**
 * One-shot session stash for invalid submissions.
 *
 * On a non-JSON validation failure the submit handler stores the submitted
 * values and the form's validation messages here, then redirects back to the
 * page that hosts the form. The next render of that form reads and clears the
 * entry with {@see consume()}, so the visitor sees their values and the
 * inline error messages instead of an empty form.
 *
 * Entries are keyed by form slug, so several forms on one page do not
 * collide, and live in the request's mezzio-session.
 *
 * @api
 */
final class FormStateStash
{
    public const string SESSION_PREFIX = 'contenir_formbuilder_stash_';

    private static function key(string $slug): string
    {
        $clean = preg_replace('/[^a-z0-9_\-]/', replacement: '_', subject: strtolower($slug)) ?? '';

        return self::SESSION_PREFIX . $clean;
    }

    /**
     * Read and remove the stash for $slug. Malformed entries are discarded.
     *
     * @return array{values: array<array-key, mixed>, errors: array<array-key, mixed>}|null
     *
     * @mago-expect analysis:mixed-assignment Session data is untyped; the shape is checked before it is returned.
     */
    public function consume(SessionInterface $session, string $slug): ?array
    {
        $key  = self::key($slug);
        $data = $session->get($key);
        $session->unset($key);

        $values = is_array($data) ? $data['values'] ?? null : null;
        $errors = is_array($data) ? $data['errors'] ?? null : null;
        if (! is_array($values) || ! is_array($errors)) {
            return null;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @param array<array-key, mixed> $values Submitted values, keyed by field name.
     * @param array<array-key, mixed> $errors Laminas validation messages, keyed by field name.
     */
    public function store(SessionInterface $session, string $slug, array $values, array $errors): void
    {
        $session->set(self::key($slug), ['values' => $values, 'errors' => $errors]);
    }
}
