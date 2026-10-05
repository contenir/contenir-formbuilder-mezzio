<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Handler;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Mezzio\Token\SubmissionTokens;

use function in_array;
use function is_array;
use function is_string;

/**
 * A form's `settings.success`: what a successful submission answers.
 *
 * `mode` is `redirect_referrer` (also for missing or unknown modes),
 * `redirect_url` or `inline_message`.
 *
 * @internal
 */
final readonly class SuccessSettings
{
    private function __construct(
        public string $mode,
        public ?string $redirectUrl,
        public string $title,
        public string $message,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment Form settings are decoded JSON; only the success map is kept.
     */
    public static function fromForm(FormDefinition $form): self
    {
        $stored = $form->settings['success'] ?? [];
        $stored = is_array($stored) ? $stored : [];
        $mode   = self::text($stored, 'mode');
        $url    = self::text($stored, 'redirect_url');

        return new self(
            mode: in_array($mode, FormDefinition::SUCCESS_MODES, strict: true)
                ? $mode
                : FormDefinition::SUCCESS_REDIRECT_REFERRER,
            redirectUrl: '' === $url ? null : $url,
            title: self::text($stored, 'title'),
            message: self::text($stored, 'message'),
        );
    }

    /**
     * @param array<array-key, mixed> $stored
     *
     * @mago-expect analysis:mixed-assignment Form settings are decoded JSON; only strings are kept.
     */
    private static function text(array $stored, string $key): string
    {
        $value = $stored[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * The JSON answer: `ok` and `mode`, plus `url` for a redirect URL, or
     * `title` and `message` with merge tags expanded for an inline message.
     *
     * @return array<string, bool|string>
     */
    public function payload(SubmissionTokens $tokens): array
    {
        $payload = ['ok' => true, 'mode' => $this->mode];
        $url     = $this->redirectUrl($tokens);
        if (null !== $url) {
            $payload['url'] = $url;
        }

        if (FormDefinition::SUCCESS_INLINE_MESSAGE === $this->mode) {
            $payload['title']   = $tokens->replace($this->title);
            $payload['message'] = $tokens->replace($this->message);
        }

        return $payload;
    }

    /**
     * The configured redirect URL with merge-tag values URL-encoded, when the
     * mode is `redirect_url` and a URL is set; otherwise null, and the
     * visitor goes back to the referrer.
     */
    public function redirectUrl(SubmissionTokens $tokens): ?string
    {
        return FormDefinition::SUCCESS_REDIRECT_URL === $this->mode && null !== $this->redirectUrl
            ? $tokens->replaceForUrl($this->redirectUrl)
            : null;
    }
}
