<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Integration\Http;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Mezzio\Http\StagedUploads;
use Contenir\FormBuilder\Mezzio\Http\UploadedFileStager;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\FormDefinitionFactory;
use Contenir\FormBuilder\Mezzio\Tests\Trait\TemporaryDirectoryTrait;
use Laminas\Diactoros\UploadedFile;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_file;
use function mkdir;
use function preg_match;
use function preg_quote;
use function strlen;
use function unlink;

use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;

#[Group('integration')]
final class UploadedFileStagerTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private static function form(): FormDefinition
    {
        return FormDefinitionFactory::withFields([
            FormDefinitionFactory::field('text', 'name'),
            FormDefinitionFactory::field('file', 'cv'),
            FormDefinitionFactory::field('file', 'photo'),
        ]);
    }

    #[Test]
    public function aTrailingSlashOnTheDirectoryIsIgnored(): void
    {
        $staged = (new UploadedFileStager("{$this->path('staging')}/"))->stage(self::form(), ['cv' => $this->upload(
            'cv.pdf',
            'PDF',
        )]);

        static::assertStringStartsWith("{$this->path('staging')}/formbuilder_", $staged->files['cv']['tmp_name']);
    }

    #[Test]
    public function cleanupRemovesTheStagedFilesThatAreStillThere(): void
    {
        $staged = $this->stager()->stage(self::form(), [
            'cv'    => $this->upload('cv.pdf', 'PDF'),
            'photo' => $this->upload('me.jpg', 'JPG'),
        ]);
        unlink($staged->files['cv']['tmp_name']);

        $staged->cleanup();

        static::assertSame([], glob("{$this->path('staging')}/*"));
    }

    #[Test]
    public function describesUploadsInTheFilesArrayShape(): void
    {
        $staged = $this->stager()->stage(self::form(), [
            'cv'    => $this->upload('cv.pdf', 'PDF'),
            'photo' => new UploadedFile($this->path('none'), 0, UPLOAD_ERR_NO_FILE),
        ]);

        static::assertSame(
            [
                'cv'    => ['name' => 'cv.pdf', 'type' => 'application/pdf', 'size' => 3, 'error' => UPLOAD_ERR_OK],
                'photo' => [
                    'name'     => null,
                    'type'     => null,
                    'size'     => 0,
                    'error'    => UPLOAD_ERR_NO_FILE,
                    'tmp_name' => '',
                ],
            ],
            [
                'cv'    => [
                    'name'  => $staged->files['cv']['name'],
                    'type'  => $staged->files['cv']['type'],
                    'size'  => $staged->files['cv']['size'],
                    'error' => $staged->files['cv']['error'],
                ],
                'photo' => $staged->files['photo'],
            ],
        );
    }

    #[Test]
    public function failedUploadsAreNotMoved(): void
    {
        $upload = $this->createMock(UploadedFileInterface::class);
        $upload->method('getError')->willReturn(UPLOAD_ERR_NO_FILE);
        $upload->expects($this->never())->method('moveTo');

        $staged = $this->stager()->stage(self::form(), ['cv' => $upload]);

        static::assertSame('', $staged->files['cv']['tmp_name']);
    }

    #[Test]
    public function ignoresUploadsForFieldsThatAreNotFileFields(): void
    {
        $staged = $this->stager()->stage(self::form(), [
            'name'    => $this->upload('a.txt', 'A'),
            'unknown' => $this->upload('b.txt', 'B'),
            'photo'   => ['nested' => $this->upload('c.txt', 'C')],
        ]);

        static::assertSame([[], []], [$staged->files, glob("{$this->path('staging')}/*")]);
    }

    #[Test]
    public function keepsTheStagedFilesForTheSubmission(): void
    {
        $staged = $this->stager()->stage(self::form(), ['cv' => $this->upload('cv.pdf', 'PDF')]);

        static::assertTrue(is_file($staged->files['cv']['tmp_name']));
    }

    #[Test]
    public function movesEachSuccessfulUploadToARandomlyNamedFile(): void
    {
        $staged = $this->stager()->stage(self::form(), ['cv' => $this->upload('cv.pdf', 'PDF')]);

        $path = $staged->files['cv']['tmp_name'];
        static::assertSame(
            [1, 'PDF', true],
            [
                preg_match(
                    '~^' . preg_quote($this->path('staging'), delimiter: '~') . '/formbuilder_[0-9a-f]{32}$~D',
                    $path,
                ),
                file_get_contents($path),
                $staged->isStaged($path),
            ],
        );
    }

    #[Test]
    public function onlyStagedPathsCountAsUploads(): void
    {
        $staged = new StagedUploads([], [$this->path('staging/formbuilder_a')]);

        static::assertSame(
            [true, false, false],
            [
                $staged->isStaged($this->path('staging/formbuilder_a')),
                $staged->isStaged($this->path('staging/formbuilder_b')),
                $staged->isStaged('/etc/passwd'),
            ],
        );
    }

    #[Test]
    public function removesTheFilesStagedSoFarWhenAMoveFails(): void
    {
        $failing = $this->createStub(UploadedFileInterface::class);
        $failing->method('getError')->willReturn(UPLOAD_ERR_OK);
        $failing->method('moveTo')->willThrowException(new RuntimeException('Disk full'));

        try {
            $this->stager()->stage(self::form(), ['cv' => $this->upload('cv.pdf', 'PDF'), 'photo' => $failing]);
            static::fail('The failed move should have been rethrown.');
        } catch (RuntimeException $exception) {
            static::assertSame(['Disk full', []], [$exception->getMessage(), glob("{$this->path('staging')}/*")]);
        }
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        mkdir($this->path('staging'));
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    private function stager(): UploadedFileStager
    {
        return new UploadedFileStager($this->path('staging'));
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $source = $this->path("source-{$name}");
        file_put_contents($source, $content);

        return new UploadedFile($source, strlen($content), UPLOAD_ERR_OK, $name, 'application/pdf');
    }
}
