<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Factory;

use Contenir\FormBuilder\Mezzio\Container\Services;
use Contenir\FormBuilder\Mezzio\Registrar\EmailNotificationRegistrar;
use Contenir\FormBuilder\Mezzio\Token\TokenReplacerBuilder;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use UnexpectedValueException;

use function is_string;

/**
 * Needs a `Symfony\Component\Mailer\MailerInterface` service. The PSR-3
 * logger is used when one is registered, and `formbuilder.notification_from`
 * is the From address of notifications that do not set one.
 *
 * @api
 */
final class EmailNotificationRegistrarFactory
{
    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     *
     * @mago-expect analysis:mixed-assignment Configuration is untyped input; the address is checked with is_string().
     */
    public function __invoke(ContainerInterface $container): EmailNotificationRegistrar
    {
        $from = Services::config($container)['notification_from'] ?? null;

        return new EmailNotificationRegistrar(
            Services::get($container, TokenReplacerBuilder::class, TokenReplacerBuilder::class),
            Services::get($container, MailerInterface::class, MailerInterface::class),
            Services::optional($container, LoggerInterface::class, LoggerInterface::class),
            is_string($from) && '' !== $from ? $from : null,
        );
    }
}
