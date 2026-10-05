<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Http;

use Contenir\FormBuilder\Definition\FormDefinition;
use Psr\Http\Message\UploadedFileInterface;
use Random\RandomException;
use RuntimeException;

use function bin2hex;
use function random_bytes;
use function rtrim;

use const UPLOAD_ERR_OK;

/**
 * Turns the request's PSR-7 uploads for the form's `file` fields into the
 * `$_FILES`-shaped array the core submission service reads.
 *
 * Each successful upload is moved with `UploadedFileInterface::moveTo()` to a
 * new, randomly named file in the staging directory: under a SAPI that is
 * `move_uploaded_file()`, which only accepts genuine uploads, and it works the
 * same for runtimes whose uploads are streams rather than temporary files.
 * The staged paths are the only ones {@see StagedUploads::isStaged()} accepts,
 * so nothing in the request can point the storage layer at another file.
 *
 * @api
 */
final readonly class UploadedFileStager
{
    public function __construct(
        private string $directory,
    ) {}

    /**
     * @param array<array-key, mixed> $uploads The request's uploaded files.
     *
     * @throws RandomException When no secure random source is available.
     * @throws RuntimeException When an upload cannot be moved; uploads staged so far are removed.
     *
     * @mago-expect analysis:mixed-assignment The uploaded files are untyped; each is checked with instanceof.
     */
    public function stage(FormDefinition $form, array $uploads): StagedUploads
    {
        $files    = [];
        $paths    = [];
        $complete = false;

        try {
            foreach ($form->getAllFields() as $field) {
                $upload = $uploads[$field->name] ?? null;
                if ('file' !== $field->type || ! $upload instanceof UploadedFileInterface) {
                    continue;
                }

                $path = '';
                if (UPLOAD_ERR_OK === $upload->getError()) {
                    $path    = $this->move($upload);
                    $paths[] = $path;
                }

                $files[$field->name] = [
                    'name'     => $upload->getClientFilename(),
                    'type'     => $upload->getClientMediaType(),
                    'size'     => $upload->getSize(),
                    'error'    => $upload->getError(),
                    'tmp_name' => $path,
                ];
            }

            $complete = true;

            return new StagedUploads($files, $paths);
        } finally {
            if (! $complete) {
                (new StagedUploads([], $paths))->cleanup();
            }
        }
    }

    /**
     * @throws RandomException
     * @throws RuntimeException
     */
    private function move(UploadedFileInterface $upload): string
    {
        $path = rtrim($this->directory, characters: '/') . '/formbuilder_' . bin2hex(random_bytes(16));
        $upload->moveTo($path);

        return $path;
    }
}
