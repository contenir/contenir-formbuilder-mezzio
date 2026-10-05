<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Registrar;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Mezzio\Token\SubmissionTokens;
use Contenir\FormBuilder\Mezzio\Token\TokenReplacerBuilder;
use Contenir\FormBuilder\Service\BuilderForm;
use Override;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

use function explode;
use function filter_var;
use function html_entity_decode;
use function is_array;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_contains;
use function strip_tags;
use function strtolower;
use function trim;
use function wordwrap;

use const ENT_QUOTES;
use const FILTER_VALIDATE_EMAIL;

/**
 * Sends each enabled notification defined for the form after submission,
 * through a Symfony Mailer.
 *
 * Skips spam-flagged submissions. Failures are logged through the optional
 * PSR-3 logger rather than propagated: a mail error must never prevent the
 * entry being stored or the visitor getting their response.
 *
 * Merge tags are expanded with a `TokenReplacer` built for the
 * submission, so `{site:base_url}` reflects the request unless it is
 * configured. Subjects have CR/LF collapsed to a space. A body template that
 * contains markup or `{entry:fields}` is sent as HTML, with every merge-tag
 * value escaped, plus a plain-text alternative; any other template is sent as
 * plain text.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity Message assembly, addressing and HTML-to-text in one registrar, as in the laminas-mvc adapter.
 */
final readonly class EmailNotificationRegistrar implements SplObserver
{
    /**
     * @param string|null $defaultFrom From address for notifications that do not set one;
     *                                 Symfony Mailer refuses a message without a From.
     */
    public function __construct(
        private TokenReplacerBuilder $tokens,
        private MailerInterface $mailer,
        private ?LoggerInterface $log = null,
        private ?string $defaultFrom = null,
    ) {}

    /**
     * Reduce HTML to a plain-text fallback. Not a faithful rendering, just
     * enough for the alternative part to read sensibly.
     */
    private static function htmlToText(string $html): string
    {
        $text = (string) preg_replace(
            [
                '!<head\b[^>]*>.*?</head>!is',
                '!<style\b[^>]*>.*?</style>!is',
                '!<script\b[^>]*>.*?</script>!is',
                '!<br\s*/?>!i',
                '!</(p|div|h[1-6]|li|tr)>!i',
            ],
            ['', '', '', "\n", "\n"],
            $html,
        );
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, encoding: 'UTF-8');
        $text = (string) preg_replace(['/[\t ]+/', '/\n{3,}/'], [' ', "\n\n"], $text);

        return wordwrap(trim($text), width: 78);
    }

    /**
     * Whether the body template, before merge tags are expanded, is HTML: it
     * contains markup or `{entry:fields}`, which expands to an HTML table.
     *
     * Decided on the template, never the resolved body: otherwise a visitor
     * could turn a plain-text notification into an HTML one by submitting
     * markup, which would then be rendered unescaped.
     */
    private static function isHtmlTemplate(string $template): bool
    {
        return (
            preg_match('/<[a-z!\/][^>]*>/i', $template) === 1
                || str_contains(strtolower($template), '{entry:fields}')
        );
    }

    /**
     * The recipients in a list separated by commas, semicolons or white
     * space. Empty parts are left in: they fail the address check.
     *
     * @return list<string>
     */
    private static function splitAddresses(string $raw): array
    {
        return explode(' ', preg_replace('/[\s,;]+/', replacement: ' ', subject: $raw) ?? '');
    }

    /**
     * @mago-expect analysis:mixed-assignment The registry is an untyped bag; each entry is checked before use.
     * @mago-expect analysis:less-specific-argument Registry values and entry are keyed by field and attribute name.
     */
    #[Override]
    public function update(SplSubject $subject): void
    {
        $registry = $subject instanceof BuilderForm ? $subject->registry?->getArrayCopy() ?? [] : [];
        $form     = $registry['form'] ?? null;
        if (! $form instanceof FormDefinition || true === ($registry['spam'] ?? false)) {
            return;
        }

        $values  = $registry['values'] ?? [];
        $entry   = $registry['entry'] ?? [];
        $context = $registry['context'] ?? [];
        $site    = is_array($context) ? $context['site'] ?? [] : [];
        $tokens  = new SubmissionTokens(
            $this->tokens->build(is_array($site) ? $site : []),
            $form,
            is_array($values) ? $values : [],
            is_array($entry) ? $entry : [],
        );

        foreach ($form->notifications as $notification) {
            if (! $notification->enabled) {
                continue;
            }

            $this->dispatch($tokens, $notification, $form->slug);
        }
    }

    /**
     * Resolve merge tags in a From / Reply-To address and hand the result to
     * the setter. An address that is invalid once resolved is logged and
     * skipped, so one bad field cannot suppress the notification.
     *
     * @param callable(string): Email $apply
     */
    private function applyAddress(SubmissionTokens $tokens, ?string $raw, callable $apply): void
    {
        $resolved = trim($tokens->replace($raw ?? ''));
        if ('' === $resolved) {
            return;
        }

        try {
            $apply($resolved);
        } catch (Throwable $e) {
            $this->log?->notice(sprintf('Form notification address "%s" skipped: %s', $resolved, $e->getMessage()));
        }
    }

    private function applyBody(SubmissionTokens $tokens, Email $email, string $template): void
    {
        if (! self::isHtmlTemplate($template)) {
            $email->text($tokens->replace($template));

            return;
        }

        $html = $tokens->replaceForHtml($template);
        $email->html($html);
        $email->text(self::htmlToText($html));
    }

    private function dispatch(SubmissionTokens $tokens, NotificationDefinition $notification, string $slug): void
    {
        try {
            $email = new Email();
            $email->subject((string) preg_replace(
                '/[\r\n]+/',
                replacement: ' ',
                subject: $tokens->replace($notification->subject),
            ));

            $this->applyBody($tokens, $email, $notification->bodyTemplate ?? '');
            $this->applyAddress(
                $tokens,
                '' === trim($notification->fromAddress ?? '') ? $this->defaultFrom : $notification->fromAddress,
                $email->from(...),
            );
            $this->applyAddress($tokens, $notification->replyTo, $email->replyTo(...));

            foreach (self::splitAddresses($notification->toAddress) as $recipient) {
                $resolved = $tokens->replace($recipient);
                if (filter_var($resolved, FILTER_VALIDATE_EMAIL) !== false) {
                    $email->addTo($resolved);
                }
            }

            $this->mailer->send($email);
        } catch (Throwable $e) {
            $this->log?->warning(sprintf(
                'Form notification "%s" failed for form "%s": %s',
                $notification->name,
                $slug,
                $e->getMessage(),
            ));
        }
    }
}
