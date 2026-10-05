<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Tests\Unit\Clock;

use Contenir\FormBuilder\Mezzio\Clock\SystemClock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class SystemClockTest extends TestCase
{
    #[Test]
    public function returnsTheCurrentTime(): void
    {
        $before = new DateTimeImmutable();
        $now    = (new SystemClock())->now();
        $after  = new DateTimeImmutable();

        static::assertSame([true, true], [$before <= $now, $now <= $after]);
    }
}
