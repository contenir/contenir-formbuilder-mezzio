<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Factory;

use Contenir\FormBuilder\Mezzio\Container\Services;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfFormFactory;
use Contenir\FormBuilder\Mezzio\Handler\SubmissionPipeline;
use Contenir\FormBuilder\Mezzio\Http\UploadedFileStager;
use Contenir\FormBuilder\Mezzio\Registrar\EmailNotificationRegistrar;
use Contenir\FormBuilder\Mezzio\Registrar\StoreSubmissionRegistrar;
use Contenir\FormBuilder\Registrar\WebhookRegistrar;
use Contenir\Storage\StorageManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use SplObserver;
use Symfony\Component\Mailer\MailerInterface;
use UnexpectedValueException;

use function is_array;
use function is_string;
use function sys_get_temp_dir;

/**
 * Wires the submission pipeline. Observers are attached in this order: the
 * entry store, webhooks, email notifications (only when a
 * `Symfony\Component\Mailer\MailerInterface` is registered), then each
 * `formbuilder.observers` service id that resolves to an SplObserver. A
 * registered `Contenir\Storage\StorageManager` enables file uploads, staged
 * in `formbuilder.upload_directory` (default: the system temporary
 * directory).
 *
 * @api
 */
final class SubmissionPipelineFactory
{
    /**
     * @return list<SplObserver>
     *
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     *
     * @mago-expect analysis:mixed-assignment Configuration is untyped input; each id is checked with is_string().
     */
    private function observers(ContainerInterface $container): array
    {
        $observers = [
            Services::get($container, StoreSubmissionRegistrar::class, StoreSubmissionRegistrar::class),
            Services::get($container, WebhookRegistrar::class, WebhookRegistrar::class),
        ];

        if ($container->has(MailerInterface::class)) {
            $observers[] = Services::get(
                $container,
                EmailNotificationRegistrar::class,
                EmailNotificationRegistrar::class,
            );
        }

        $extra = Services::config($container)['observers'] ?? [];
        foreach (is_array($extra) ? $extra : [] as $id) {
            $observer = is_string($id) ? Services::optional($container, $id, SplObserver::class) : null;
            if (null !== $observer) {
                $observers[] = $observer;
            }
        }

        return $observers;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws UnexpectedValueException
     *
     * @mago-expect analysis:mixed-assignment Configuration is untyped input; the directory is checked with is_string().
     */
    public function __invoke(ContainerInterface $container): SubmissionPipeline
    {
        $directory = Services::config($container)['upload_directory'] ?? null;

        return new SubmissionPipeline(
            Services::get($container, CsrfFormFactory::class, CsrfFormFactory::class),
            new UploadedFileStager(is_string($directory) && '' !== $directory ? $directory : sys_get_temp_dir()),
            Services::optional($container, StorageManager::class, StorageManager::class),
            $this->observers($container),
        );
    }
}
