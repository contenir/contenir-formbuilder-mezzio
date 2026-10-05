<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Handler;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfFormFactory;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenValidator;
use Contenir\FormBuilder\Mezzio\Http\UploadedFileStager;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Service\SubmissionResult;
use Contenir\Storage\Exception\StorageException;
use Contenir\Storage\StorageManager;
use Laminas\Form\Exception\ExceptionInterface as FormException;
use Mezzio\Session\SessionInterface;
use Random\RandomException;
use RuntimeException;
use SplObserver;

/**
 * Runs one submission through the core {@see FormSubmissionService}, built
 * for the request: its form builder is bound to the request's session for
 * CSRF, its uploads are the request's staged PSR-7 files, and the observers
 * are attached in order.
 *
 * The CSRF token is checked before anything else. A submission without the
 * token issued to this session for this form is rejected as invalid, with no
 * observer notified and no upload staged, even when the honeypot marks it as
 * spam (which the core service would otherwise answer, and store, like a
 * success).
 *
 * @api
 */
final readonly class SubmissionPipeline
{
    /**
     * @param list<SplObserver> $observers Attached to each submission, in order.
     */
    public function __construct(
        private CsrfFormFactory $forms,
        private UploadedFileStager $stager,
        private ?StorageManager $storage = null,
        private array $observers = [],
    ) {}

    /**
     * @param array<string, mixed> $post
     * @param array<array-key, mixed> $uploads The request's uploaded files.
     * @param array<string, mixed> $context ip, user_id, meta, site
     *
     * @throws FormException When Laminas rejects the built form.
     * @throws RandomException When no secure random source is available.
     * @throws RuntimeException When an upload cannot be staged.
     * @throws StorageException When the storage backend cannot store an upload.
     */
    public function submit(
        FormDefinition $form,
        SessionInterface $session,
        array $post,
        array $uploads,
        array $context,
    ): SubmissionResult {
        $builder = $this->forms->builder($session);
        if (! $this->forms->accepts($session, $form->slug, $post[FormBuilderService::CSRF_NAME] ?? null)) {
            return new SubmissionResult(
                valid: false,
                form: $builder->build($form),
                errors: [
                    FormBuilderService::CSRF_NAME => [CsrfTokenValidator::NOT_SAME => CsrfTokenValidator::MESSAGE],
                ],
            );
        }

        $staged = $this->stager->stage($form, $uploads);

        try {
            $service = new FormSubmissionService($builder, $this->storage, $staged->isStaged(...));
            foreach ($this->observers as $observer) {
                $service->attach($observer);
            }

            return $service->submit($form, $post, $staged->files, $context);
        } finally {
            $staged->cleanup();
        }
    }
}
