<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Http;

use function in_array;
use function is_file;
use function unlink;

/**
 * The uploads {@see UploadedFileStager} moved into its staging directory.
 *
 * @api
 */
final readonly class StagedUploads
{
    /**
     * @param array<string, array{name: ?string, type: ?string, size: ?int, error: int, tmp_name: string}> $files
     *        `$_FILES`-shaped entries, keyed by field name.
     * @param list<string> $paths The staged files.
     */
    public function __construct(
        public array $files = [],
        private array $paths = [],
    ) {}

    /**
     * Removes the staged files that are still there. The storage layer copies
     * an upload, so its staged file is no longer needed once submission ends.
     */
    public function cleanup(): void
    {
        foreach ($this->paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            unlink($path);
        }
    }

    /**
     * Whether $path is one of the staged files, for the core submission
     * service's "is this an upload" check.
     */
    public function isStaged(string $path): bool
    {
        return in_array($path, $this->paths, strict: true);
    }
}
