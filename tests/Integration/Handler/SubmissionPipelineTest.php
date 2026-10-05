<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Integration\Handler;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfFormFactory;
use Contenir\FormBuilder\Mezzio\Csrf\CsrfTokenManager;
use Contenir\FormBuilder\Mezzio\Handler\SubmissionPipeline;
use Contenir\FormBuilder\Mezzio\Http\UploadedFileStager;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\FormDefinitionFactory;
use Contenir\FormBuilder\Mezzio\Tests\TestAsset\Observer\RecordingObserver;
use Contenir\FormBuilder\Mezzio\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use Laminas\Diactoros\UploadedFile;
use Mezzio\Session\Session;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplObserver;
use SplSubject;

use function file_put_contents;
use function glob;
use function is_file;
use function mkdir;

use const UPLOAD_ERR_OK;

#[Group('integration')]
final class SubmissionPipelineTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private CsrfTokenManager $csrf;

    private Session $session;

    private static function form(): FormDefinition
    {
        return new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
            sections: FormDefinitionFactory::withFields([
                FormDefinitionFactory::field('text', 'name'),
                FormDefinitionFactory::field('file', 'cv'),
            ])->sections,
        );
    }

    #[Test]
    public function aRejectedTokenReturnsTheBuiltFormWithTheCsrfError(): void
    {
        $result = $this->pipeline()->submit(self::form(), $this->session, [], [], []);

        static::assertSame(
            [
                false,
                false,
                true,
                ['_csrf' => ['notSame' => 'The form submitted did not originate from the expected site']],
            ],
            [$result->valid, $result->isSpam, $result->form->has('name'), $result->errors],
        );
    }

    #[Test]
    public function aRejectedTokenStagesNoUpload(): void
    {
        $this->pipeline()->submit(self::form(), $this->session, ['_csrf' => 'forged'], ['cv' => $this->upload()], []);

        static::assertSame([true, []], [is_file($this->path('upload')), glob("{$this->path('staging')}/*")]);
    }

    #[Test]
    public function attachesTheObserversInOrder(): void
    {
        $first  = new RecordingObserver(['entry_id' => 7]);
        $second = new RecordingObserver();

        $result = $this->pipeline([$first, $second])->submit(self::form(), $this->session, $this->post(), [], []);

        static::assertSame([7, 7], [$result->entryId, $second->registries[0]['entry_id'] ?? null]);
    }

    #[Test]
    public function passesTheContextThrough(): void
    {
        $observer = new RecordingObserver();

        $this->pipeline([$observer])->submit(self::form(), $this->session, $this->post(), [], ['ip' => '10.0.0.1']);

        static::assertSame(['ip' => '10.0.0.1'], $observer->registries[0]['context'] ?? null);
    }

    #[Test]
    public function stagedUploadsAreRemovedWhenTheSubmissionFails(): void
    {
        $failing = new class implements SplObserver {
            public function update(SplSubject $subject): void
            {
                throw new RuntimeException('Observer failed');
            }
        };

        try {
            $this->pipeline([$failing])->submit(
                self::form(),
                $this->session,
                $this->post(),
                ['cv' => $this->upload()],
                [],
            );
            static::fail('The observer failure should have been rethrown.');
        } catch (RuntimeException $exception) {
            static::assertSame(['Observer failed', []], [
                $exception->getMessage(),
                glob("{$this->path('staging')}/*"),
            ]);
        }
    }

    #[Test]
    public function withoutStorageAnUploadLeavesTheFieldEmpty(): void
    {
        $observer = new RecordingObserver();

        $this->pipeline([$observer])->submit(
            self::form(),
            $this->session,
            $this->post(),
            ['cv' => $this->upload()],
            [],
        );

        static::assertSame([null, []], [
            $observer->registries[0]['values']['cv'] ?? null,
            glob("{$this->path('staging')}/*"),
        ]);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        mkdir($this->path('staging'));
        $this->csrf    = new CsrfTokenManager();
        $this->session = new Session([]);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    /**
     * @param list<SplObserver> $observers
     */
    private function pipeline(array $observers = []): SubmissionPipeline
    {
        return new SubmissionPipeline(
            new CsrfFormFactory(new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory()), $this->csrf),
            new UploadedFileStager($this->path('staging')),
            observers: $observers,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function post(): array
    {
        return ['name' => 'Ann', '_csrf' => $this->csrf->token($this->session, 'contact')];
    }

    private function upload(): UploadedFile
    {
        file_put_contents($this->path('upload'), data: 'PDF');

        return new UploadedFile($this->path('upload'), 3, UPLOAD_ERR_OK, 'cv.pdf', 'application/pdf');
    }
}
