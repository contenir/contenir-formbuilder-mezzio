<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\State;

use Contenir\FormBuilder\Mezzio\State\FormStateStash;
use Mezzio\Session\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;

#[Group('unit')]
final class FormStateStashTest extends TestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedEntryProvider(): array
    {
        return [
            'not an array'     => ['x'],
            'missing values'   => [['errors' => []]],
            'missing errors'   => [['values' => []]],
            'non-array values' => [['values' => 'x', 'errors' => []]],
            'non-array errors' => [['values' => [], 'errors' => 'x']],
        ];
    }

    #[Test]
    public function consumeIsOneShot(): void
    {
        $session = new Session([]);
        $stash   = new FormStateStash();
        $stash->store($session, 'contact', ['name' => 'Alice'], []);

        $stash->consume($session, 'contact');

        static::assertSame([null, false], [
            $stash->consume($session, 'contact'),
            $session->has('contenir_formbuilder_stash_contact'),
        ]);
    }

    #[Test]
    public function consumeReturnsNullWhenNothingStashed(): void
    {
        static::assertNull((new FormStateStash())->consume(new Session([]), 'contact'));
    }

    #[Test]
    public function entriesAreScopedPerSlug(): void
    {
        $session = new Session([]);
        $stash   = new FormStateStash();
        $stash->store($session, 'contact', ['name' => 'Alice'], []);
        $stash->store($session, 'enquiry', ['name' => 'Bob'], []);

        static::assertSame(
            [['name' => 'Alice'], ['name' => 'Bob']],
            [
                $stash->consume($session, 'contact')['values'] ?? null,
                $stash->consume($session, 'enquiry')['values'] ?? null,
            ],
        );
    }

    #[Test]
    public function entriesLiveInTheSessionUnderAPrefixedKey(): void
    {
        $session = new Session([]);

        (new FormStateStash())->store($session, 'contact', ['a' => 1], ['a' => ['x' => 'y']]);

        static::assertSame(
            ['contenir_formbuilder_stash_contact' => ['values' => ['a' => 1], 'errors' => ['a' => ['x' => 'y']]]],
            $session->toArray(),
        );
    }

    #[Test]
    public function keepsDigitsUnderscoresAndHyphens(): void
    {
        $session = new Session([]);

        (new FormStateStash())->store($session, 'a-b_c9', [], []);

        static::assertSame(['contenir_formbuilder_stash_a-b_c9'], array_keys($session->toArray()));
    }

    #[Test]
    #[DataProvider('malformedEntryProvider')]
    public function malformedEntriesAreDiscarded(mixed $entry): void
    {
        $session = new Session(['contenir_formbuilder_stash_contact' => $entry]);

        static::assertSame([null, false], [
            (new FormStateStash())->consume($session, 'contact'),
            $session->has('contenir_formbuilder_stash_contact'),
        ]);
    }

    #[Test]
    public function slugsThatDifferOnlyByCaseShareAnEntry(): void
    {
        $session = new Session([]);
        $stash   = new FormStateStash();
        $stash->store($session, 'Contact', ['name' => 'Alice'], []);

        static::assertSame(['name' => 'Alice'], $stash->consume($session, 'contact')['values'] ?? null);
    }

    #[Test]
    public function slugsWithDisallowedCharsAreNormalisedToTheSameKey(): void
    {
        $session = new Session([]);
        $stash   = new FormStateStash();
        $stash->store($session, 'contact form!', ['name' => 'Alice'], []);

        static::assertSame(
            [['contenir_formbuilder_stash_contact_form_'], ['name' => 'Alice']],
            [array_keys($session->toArray()), $stash->consume($session, 'contact_form_')['values'] ?? null],
        );
    }

    #[Test]
    public function storeThenConsumeReturnsTheStashedPayload(): void
    {
        $session = new Session([]);
        $stash   = new FormStateStash();
        $stash->store($session, 'contact', ['email' => 'alice@'], ['email' => ['emailAddressInvalid' => 'Invalid']]);

        static::assertSame(
            ['values' => ['email' => 'alice@'], 'errors' => ['email' => ['emailAddressInvalid' => 'Invalid']]],
            $stash->consume($session, 'contact'),
        );
    }
}
