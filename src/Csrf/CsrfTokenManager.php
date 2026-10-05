<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Csrf;

use Mezzio\Session\SessionInterface;
use Random\RandomException;
use SensitiveParameter;

use function bin2hex;
use function hash_equals;
use function hash_hmac;
use function is_string;
use function preg_match;
use function random_bytes;

/**
 * Issues and checks the per-form CSRF tokens.
 *
 * Each session gets a random 256-bit secret, stored in the session the first
 * time a token is issued. A form's token is the HMAC-SHA256 of its slug under
 * that secret, so it is stable for the session (several tabs, a re-submit
 * after a validation error and AJAX retries all keep working) and useless
 * with any other session or form.
 *
 * Checking never creates a secret: a session that was never issued a token
 * (a forged cross-site POST, or an expired session) rejects every token.
 *
 * @api
 */
final class CsrfTokenManager
{
    public const string SESSION_KEY = 'contenir_formbuilder_csrf_secret';

    private const string SECRET_PATTERN = '/^[0-9a-f]{64}$/D';

    public function isValid(SessionInterface $session, string $slug, #[SensitiveParameter] mixed $token): bool
    {
        $secret = $this->secret($session);

        return null !== $secret && is_string($token) && hash_equals($this->sign($secret, $slug), $token);
    }

    /**
     * @throws RandomException When no secure random source is available.
     */
    public function token(SessionInterface $session, string $slug): string
    {
        $secret = $this->secret($session);
        if (null === $secret) {
            $secret = bin2hex(random_bytes(32));
            $session->set(self::SESSION_KEY, $secret);
        }

        return $this->sign($secret, $slug);
    }

    /**
     * @mago-expect analysis:mixed-assignment Session data is untyped; the secret is checked before use.
     */
    private function secret(SessionInterface $session): ?string
    {
        $secret = $session->get(self::SESSION_KEY);

        return is_string($secret) && preg_match(self::SECRET_PATTERN, $secret) === 1 ? $secret : null;
    }

    private function sign(#[SensitiveParameter] string $secret, string $slug): string
    {
        return hash_hmac('sha256', "contenir-formbuilder:{$slug}", $secret);
    }
}
