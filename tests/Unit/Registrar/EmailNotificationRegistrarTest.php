<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Registrar;

use ArrayObject;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Mezzio\Registrar\EmailNotificationRegistrar;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\FormDefinitionFactory;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Mailer\RecordingMailer;
use Contenir\FormBuilder\Mezzio\Token\TokenReplacerBuilder;
use Contenir\FormBuilder\Service\BuilderForm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

use function array_map;
use function str_contains;
use function str_repeat;

#[Group('unit')]
final class EmailNotificationRegistrarTest extends TestCase
{
    private const string DEFAULT_FROM = 'site@example.com';

    /**
     * @return array<string, array{string|null}>
     */
    public static function blankFromProvider(): array
    {
        return [
            'not set' => [null],
            'empty'   => [''],
            'blank'   => ['   '],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function htmlTemplateProvider(): array
    {
        return [
            'lower-case markup'      => ['Hi<br>there'],
            'upper-case markup'      => ['Hi<BR>there'],
            'closing tag'            => ['Hi</p>'],
            'comment'                => ['Hi<!-- x -->'],
            'fields table'           => ['Entry: {entry:fields}'],
            'fields table, any case' => ['Entry: {ENTRY:Fields}'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function plainTemplateProvider(): array
    {
        return [
            'no markup'        => ['Hi there'],
            'a lone less-than' => ['1 < 2 and 3 > 2'],
            'a digit after <'  => ['a <3 b>'],
            'other entry tag'  => ['Entry {entry:id}'],
        ];
    }

    /**
     * @return array<string, array{SplSubject}>
     */
    public static function silentSubjectProvider(): array
    {
        return [
            'not a builder form' => [new class implements SplSubject {
                public function attach(SplObserver $observer): void {}

                public function detach(SplObserver $observer): void {}

                public function notify(): void {}
            }],
            'no registry'        => [new BuilderForm()],
            'no form definition' => [self::subject(['values' => []])],
            'spam submission'    => [self::subject(['form' => self::form([self::notification()]), 'spam' => true])],
            'only disabled'      => [self::subject(['form' => self::form([
                new NotificationDefinition(
                    id: 1,
                    name: 'Off',
                    toAddress: 'a@example.com',
                    enabled: false,
                ),
            ])])],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function subjectLineBreakProvider(): array
    {
        return [
            'CR LF'            => ["Ann\r\nBcc: evil@example.com", 'Hi Ann Bcc: evil@example.com'],
            'LF'               => ["Ann\nBcc: evil@example.com", 'Hi Ann Bcc: evil@example.com'],
            'CR'               => ["Ann\rBcc: evil@example.com", 'Hi Ann Bcc: evil@example.com'],
            'several in a row' => ["Ann\r\n\r\n\nBcc: evil@example.com", 'Hi Ann Bcc: evil@example.com'],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableContextProvider(): array
    {
        return [
            'context not an array' => ['x'],
            'site not an array'    => [['site' => 'x']],
            'no site'              => [[]],
        ];
    }

    /**
     * @param list<Address> $addresses
     *
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_map(static fn(Address $address): string => $address->toString(), $addresses);
    }

    /**
     * @param list<NotificationDefinition> $notifications
     */
    private static function form(array $notifications): FormDefinition
    {
        return new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
            notifications: $notifications,
        );
    }

    private static function notification(string $bodyTemplate = '', string $subject = 'S'): NotificationDefinition
    {
        return new NotificationDefinition(
            id: 1,
            name: 'Admin',
            toAddress: 'a@example.com',
            subject: $subject,
            bodyTemplate: $bodyTemplate,
        );
    }

    /**
     * @param array<string, mixed> $registry
     */
    private static function subject(array $registry): BuilderForm
    {
        $form           = new BuilderForm();
        $form->registry = new ArrayObject($registry);

        return $form;
    }

    #[Test]
    public function aDisabledNotificationDoesNotStopTheOnesAfterIt(): void
    {
        $mailer = new RecordingMailer();

        $this->registrar($mailer)->update(self::subject([
            'form' => self::form([
                new NotificationDefinition(
                    id: 1,
                    name: 'Off',
                    toAddress: 'a@example.com',
                    enabled: false,
                ),
                new NotificationDefinition(
                    id: 2,
                    name: 'On',
                    toAddress: 'b@example.com',
                    subject: 'S',
                ),
            ]),
        ]));

        static::assertSame([['b@example.com']], array_map(
            static fn(Email $email): array => self::addresses($email->getTo()),
            $mailer->sent,
        ));
    }

    #[Test]
    public function aFromAddressWithSurroundingSpaceIsTrimmed(): void
    {
        $mailer = new RecordingMailer();

        $this->registrar($mailer)->update(self::subject([
            'form'   => self::form([new NotificationDefinition(
                id: 1,
                name: 'Admin',
                toAddress: 'a@example.com',
                replyTo: ' {field:email} ',
                subject: 'S',
            )]),
            'values' => ['email' => 'ann@example.com'],
        ]));

        static::assertSame(['ann@example.com'], self::addresses($mailer->sent[0]->getReplyTo()));
    }

    #[Test]
    public function aNotificationFromAddressWinsOverTheDefault(): void
    {
        $mailer = new RecordingMailer();

        $this->registrar($mailer)->update(self::subject([
            'form' => self::form([new NotificationDefinition(
                id: 1,
                name: 'Admin',
                toAddress: 'a@example.com',
                fromAddress: 'Forms <forms@example.com>',
                subject: 'S',
            )]),
        ]));

        static::assertSame(['"Forms" <forms@example.com>'], self::addresses($mailer->sent[0]->getFrom()));
    }

    #[Test]
    #[DataProvider('blankFromProvider')]
    public function aNotificationWithoutAFromAddressUsesTheDefault(?string $fromAddress): void
    {
        $mailer = new RecordingMailer();

        $this->registrar($mailer)->update(self::subject([
            'form' => self::form([new NotificationDefinition(
                id: 1,
                name: 'Admin',
                toAddress: 'a@example.com',
                fromAddress: $fromAddress,
                subject: 'S',
            )]),
        ]));

        static::assertSame([self::DEFAULT_FROM], self::addresses($mailer->sent[0]->getFrom()));
    }

    #[Test]
    public function aReplyToResolvingToBlankIsLeftOut(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('notice');
        $mailer = new RecordingMailer();

        (new EmailNotificationRegistrar(new TokenReplacerBuilder(), $mailer, $logger, self::DEFAULT_FROM))->update(
            self::subject([
                'form'   => self::form([new NotificationDefinition(
                    id: 1,
                    name: 'Admin',
                    toAddress: 'a@example.com',
                    replyTo: '{field:blank}',
                    subject: 'S',
                )]),
                'values' => ['blank' => '  '],
            ]),
        );

        static::assertSame([], $mailer->sent[0]->getReplyTo());
    }

    #[Test]
    #[DataProvider('htmlTemplateProvider')]
    public function aTemplateWithMarkupOrTheFieldsTableIsSentAsHtml(string $template): void
    {
        static::assertNotNull($this->send($template, [])->getHtmlBody());
    }

    #[Test]
    #[DataProvider('plainTemplateProvider')]
    public function aTemplateWithoutMarkupIsSentAsPlainText(string $template): void
    {
        $email = $this->send($template, []);

        static::assertSame([null, $template], [$email->getHtmlBody(), $email->getTextBody()]);
    }

    #[Test]
    public function failuresWithoutALoggerAreSilent(): void
    {
        $mailer = new RecordingMailer(failing: [0]);

        (new EmailNotificationRegistrar(new TokenReplacerBuilder(), $mailer))->update(self::subject([
            'form' => self::form([new NotificationDefinition(
                id: 1,
                name: 'A',
                toAddress: 'a@example.com',
                fromAddress: 'bad address',
                subject: 'S',
            )]),
        ]));

        static::assertSame([], $mailer->sent);
    }

    #[Test]
    public function htmlBodyIsSentWithAPlainTextAlternative(): void
    {
        $body =
            '<html><head><title>T</title><style>p{}</style></head><body><script>x()</script>'
            . '<h1>Hi &amp; welcome</h1><p>Line one<br>Line two<BR/>Line three</p><div>A  	  lot</div><ul><li>One</li>'
            . '<li>Two</li></ul><table><tr><td>Cell</td></tr></table><h2>Sub</h2><p></p><p></p><p></p></body></html>';

        $email = $this->send($body, []);

        static::assertSame(
            [$body, "Hi & welcome\nLine one\nLine two\nLine three\nA lot\nOne\nTwo\nCell\nSub"],
            [$email->getHtmlBody(), $email->getTextBody()],
        );
    }

    #[Test]
    public function htmlFallbackDecodesEntitiesIncludingQuotes(): void
    {
        $email = $this->send('<p>&quot;Ann&#039;s&quot; &eacute;</p>', []);

        static::assertSame('"Ann\'s" é', $email->getTextBody());
    }

    #[Test]
    public function htmlFallbackDropsMultiLineHeadStyleAndScriptBlocks(): void
    {
        $email = $this->send(
            "<HEAD lang=\"en\">\n<title>T</title>\n</HEAD><STYLE type=\"text/css\">\np{}\n</STYLE>"
                . "<SCRIPT async>\nx()\n</SCRIPT><p>Kept</p>",
            [],
        );

        static::assertSame('Kept', $email->getTextBody());
    }

    #[Test]
    public function htmlFallbackKeepsAtMostOneBlankLine(): void
    {
        $email = $this->send("<div>One</div>\n\n\n\n<div>Two</div>\n\n<div>Three</div>", []);

        static::assertSame("One\n\nTwo\n\nThree", $email->getTextBody());
    }

    #[Test]
    public function htmlFallbackWrapsAtSeventyEightColumns(): void
    {
        $email = $this->send(
            '<p>' . str_repeat('a', times: 76) . ' b</p><p>' . str_repeat('c', times: 77) . ' d</p>',
            [],
        );

        static::assertSame(
            str_repeat('a', times: 76) . " b\n" . str_repeat('c', times: 77) . "\nd",
            $email->getTextBody(),
        );
    }

    #[Test]
    public function htmlTemplateEscapesSubmittedValues(): void
    {
        $email = $this->send('<p>From {field:name}</p>', ['name' => '<img src=x onerror=alert(1)>']);

        static::assertSame('<p>From &lt;img src=x onerror=alert(1)&gt;</p>', $email->getHtmlBody());
    }

    #[Test]
    public function htmlTemplateKeepsTheFieldsTableMarkupWithEscapedValues(): void
    {
        $mailer = new RecordingMailer();
        $fields = FormDefinitionFactory::withFields([FormDefinitionFactory::field('text', 'name')]);

        $this->registrar($mailer)->update(self::subject([
            'form'   => new FormDefinition(
                id: 1,
                slug: 'contact',
                title: 'Contact',
                sections: $fields->sections,
                notifications: [self::notification('<p>Entry</p>{entry:fields}')],
            ),
            'values' => ['name' => '<b>Ann</b>'],
        ]));

        $html = (string) $mailer->sent[0]->getHtmlBody();
        static::assertSame([true, true], [
            str_contains($html, '<td width="40%"'),
            str_contains($html, '&lt;b&gt;Ann&lt;/b&gt;</td>'),
        ]);
    }

    #[Test]
    public function invalidOrEmptyAddressesAreSkippedAndLogged(): void
    {
        $mailer = new RecordingMailer();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with($this->stringStartsWith('Form notification address "{field:missing}" skipped: '));

        (new EmailNotificationRegistrar(new TokenReplacerBuilder(), $mailer, $logger, self::DEFAULT_FROM))->update(
            self::subject([
                'form'   => self::form([new NotificationDefinition(
                    id: 1,
                    name: 'Admin',
                    toAddress: 'a@example.com',
                    fromAddress: 'forms@example.com',
                    replyTo: '{field:missing}',
                    subject: 'S',
                )]),
                'values' => ['blank' => '  '],
            ]),
        );

        static::assertSame([['forms@example.com'], []], [
            self::addresses($mailer->sent[0]->getFrom()),
            self::addresses($mailer->sent[0]->getReplyTo()),
        ]);
    }

    #[Test]
    public function mailerFailuresAreLoggedAndLaterNotificationsStillSend(): void
    {
        $mailer = new RecordingMailer(failing: [0]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Form notification "First" failed for form "contact": Send 0 failed');

        (new EmailNotificationRegistrar(new TokenReplacerBuilder(), $mailer, $logger, self::DEFAULT_FROM))->update(
            self::subject([
                'form' => self::form([
                    new NotificationDefinition(
                        id: 1,
                        name: 'First',
                        toAddress: 'a@example.com',
                        subject: 'S',
                    ),
                    new NotificationDefinition(
                        id: 2,
                        name: 'Second',
                        toAddress: 'b@example.com',
                        subject: 'S',
                    ),
                ]),
            ]),
        );

        static::assertSame([['b@example.com']], array_map(
            static fn(Email $email): array => self::addresses($email->getTo()),
            $mailer->sent,
        ));
    }

    #[Test]
    public function plainTextTemplateStaysPlainWhenAValueContainsMarkup(): void
    {
        $email = $this->send('From {field:name}', ['name' => '<a href="https://evil.example">Click</a>']);

        static::assertSame([null, 'From <a href="https://evil.example">Click</a>'], [
            $email->getHtmlBody(),
            $email->getTextBody(),
        ]);
    }

    #[Test]
    public function sendsAPlainTextNotificationWithResolvedTokens(): void
    {
        $mailer = new RecordingMailer();

        $this->registrar($mailer)->update(self::subject([
            'form'   => self::form([new NotificationDefinition(
                id: 1,
                name: 'Admin',
                toAddress: "admin@example.com, {field:email};\tnot-an-address  ,,",
                fromAddress: 'noreply@example.com',
                replyTo: '{field:email}',
                subject: 'New entry from {field:name}',
                bodyTemplate: 'Entry {entry:id} from {field:name}',
            )]),
            'values' => ['name' => 'Ann', 'email' => 'ann@example.com'],
            'entry'  => ['id' => 42],
        ]));

        $email = $mailer->sent[0];
        static::assertSame(
            [
                'New entry from Ann',
                'Entry 42 from Ann',
                ['admin@example.com', 'ann@example.com'],
                ['noreply@example.com'],
                ['ann@example.com'],
                [],
            ],
            [
                $email->getSubject(),
                $email->getTextBody(),
                self::addresses($email->getTo()),
                self::addresses($email->getFrom()),
                self::addresses($email->getReplyTo()),
                self::addresses($email->getBcc()),
            ],
        );
    }

    #[Test]
    #[DataProvider('silentSubjectProvider')]
    public function sendsNothingForSpamOrMissingNotifications(SplSubject $subject): void
    {
        $mailer = new RecordingMailer();

        $this->registrar($mailer)->update($subject);

        static::assertSame([], $mailer->sent);
    }

    #[Test]
    #[DataProvider('unusableContextProvider')]
    public function siteTokensAreLeftInPlaceWithoutAUsableSiteContext(mixed $context): void
    {
        $mailer = new RecordingMailer();

        $this->registrar($mailer)->update(self::subject([
            'form'    => self::form([self::notification('{site:base_url}')]),
            'context' => $context,
        ]));

        static::assertSame('{site:base_url}', $mailer->sent[0]->getTextBody());
    }

    #[Test]
    public function siteTokensComeFromTheSubmissionContext(): void
    {
        $mailer = new RecordingMailer();

        (new EmailNotificationRegistrar(
            new TokenReplacerBuilder(['admin_url' => 'https://admin.example']),
            $mailer,
            defaultFrom: self::DEFAULT_FROM,
        ))->update(self::subject([
            'form'    => self::form([self::notification('{site:base_url} {site:admin_url}')]),
            'context' => ['site' => ['base_url' => 'https://site.example']],
        ]));

        static::assertSame('https://site.example https://admin.example', $mailer->sent[0]->getTextBody());
    }

    #[Test]
    #[DataProvider('subjectLineBreakProvider')]
    public function subjectLineBreaksCollapseToASingleSpace(string $name, string $expected): void
    {
        $mailer = new RecordingMailer();

        $this->registrar($mailer)->update(self::subject([
            'form'   => self::form([self::notification(subject: 'Hi {field:name}')]),
            'values' => ['name' => $name],
        ]));

        static::assertSame($expected, $mailer->sent[0]->getSubject());
    }

    #[Test]
    public function unusableValuesAndEntryAreIgnored(): void
    {
        $mailer = new RecordingMailer();

        $this->registrar($mailer)->update(self::subject([
            'form'   => self::form([self::notification('{field:name} {entry:id}')]),
            'values' => 'x',
            'entry'  => 'y',
        ]));

        static::assertSame('{field:name} {entry:id}', $mailer->sent[0]->getTextBody());
    }

    private function registrar(RecordingMailer $mailer): EmailNotificationRegistrar
    {
        return new EmailNotificationRegistrar(new TokenReplacerBuilder(), $mailer, defaultFrom: self::DEFAULT_FROM);
    }

    /**
     * Send one notification with $bodyTemplate and return the message.
     *
     * @param array<string, mixed> $values
     */
    private function send(string $bodyTemplate, array $values): Email
    {
        $mailer = new RecordingMailer();

        $this->registrar($mailer)->update(self::subject([
            'form'   => self::form([self::notification($bodyTemplate)]),
            'values' => $values,
        ]));

        return $mailer->sent[0];
    }
}
