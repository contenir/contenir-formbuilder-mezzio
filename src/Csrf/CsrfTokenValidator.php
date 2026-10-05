<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Csrf;

use Laminas\Validator\ValidatorInterface;
use Mezzio\Session\SessionInterface;
use Override;

/**
 * Accepts only the token {@see CsrfTokenManager} issued for this session and
 * form.
 *
 * @api
 */
final class CsrfTokenValidator implements ValidatorInterface
{
    public const string NOT_SAME = 'notSame';

    public const string MESSAGE = 'The form submitted did not originate from the expected site';

    /** @var array<string, string> */
    private array $messages = [];

    public function __construct(
        private readonly CsrfTokenManager $tokens,
        private readonly SessionInterface $session,
        private readonly string $slug,
    ) {}

    /**
     * @return array<string, string>
     */
    #[Override]
    public function getMessages(): array
    {
        return $this->messages;
    }

    #[Override]
    public function isValid(mixed $value): bool
    {
        $valid          = $this->tokens->isValid($this->session, $this->slug, $value);
        $this->messages = $valid ? [] : [self::NOT_SAME => self::MESSAGE];

        return $valid;
    }
}
