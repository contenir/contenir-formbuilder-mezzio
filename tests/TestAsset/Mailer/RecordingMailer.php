<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\TestAsset\Mailer;

use Override;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

use function in_array;

/**
 * Keeps every message it is asked to send, after checking it as a transport
 * would; or fails the sends whose (zero-based) positions are listed.
 */
final class RecordingMailer implements MailerInterface
{
    /** @var list<Email> */
    public array $sent = [];

    private int $attempts = 0;

    /**
     * @param list<int> $failing
     */
    public function __construct(
        private readonly array $failing = [],
    ) {}

    #[Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $attempt = $this->attempts++;
        if (in_array($attempt, $this->failing, strict: true)) {
            throw new RuntimeException("Send {$attempt} failed");
        }

        $message->ensureValidity();
        if ($message instanceof Email) {
            $this->sent[] = $message;
        }
    }
}
