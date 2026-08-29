<?php

declare(strict_types=1);

namespace Milczenie\Tests\Unit;

use DateTimeImmutable;
use Milczenie\Domain\ResponseOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseOutcome::class)]
final class ResponseOutcomeTest extends TestCase
{
    private const DEADLINE = 21;

    private function classify(string $forwarded, ?string $reply, int $replyCount, string $snapshot): ResponseOutcome
    {
        return ResponseOutcome::classify(
            new DateTimeImmutable($forwarded),
            $reply === null ? null : new DateTimeImmutable($reply),
            $replyCount,
            new DateTimeImmutable($snapshot),
            self::DEADLINE,
        );
    }

    public function test_reply_on_the_last_day_is_still_on_time(): void
    {
        // Granica: 21 dni to ostatni dzien terminu, nie pierwszy dzien opoznienia.
        self::assertSame(ResponseOutcome::OnTime, $this->classify('2024-01-01', '2024-01-22', 1, '2024-06-01'));
    }

    public function test_reply_one_day_later_is_late(): void
    {
        self::assertSame(ResponseOutcome::Late, $this->classify('2024-01-01', '2024-01-23', 1, '2024-06-01'));
    }

    public function test_reply_without_a_date_is_neither_punctual_nor_silence(): void
    {
        $outcome = $this->classify('2024-01-01', null, 1, '2024-06-01');

        self::assertSame(ResponseOutcome::AnsweredWithoutDate, $outcome);
        self::assertFalse($outcome->countsTowardsDeadline());
        self::assertFalse($outcome->isFailure());
    }

    public function test_silence_past_the_deadline_is_a_failure(): void
    {
        $outcome = $this->classify('2024-01-01', null, 0, '2024-06-01');

        self::assertSame(ResponseOutcome::OverdueSilence, $outcome);
        self::assertTrue($outcome->countsTowardsDeadline());
        self::assertTrue($outcome->isFailure());
    }

    public function test_silence_inside_the_deadline_leaves_the_denominator(): void
    {
        $outcome = $this->classify('2024-01-01', null, 0, '2024-01-10');

        self::assertSame(ResponseOutcome::StillInTime, $outcome);
        self::assertFalse($outcome->countsTowardsDeadline());
        self::assertFalse($outcome->isFailure());
    }

    public function test_snapshot_exactly_on_the_deadline_is_not_yet_silence(): void
    {
        self::assertSame(ResponseOutcome::StillInTime, $this->classify('2024-01-01', null, 0, '2024-01-22'));
    }

    public function test_a_dated_reply_wins_over_the_reply_count(): void
    {
        // Pytanie z kilkoma pismami, z ktorych tylko jedno ma date - liczy sie data.
        self::assertSame(ResponseOutcome::OnTime, $this->classify('2024-01-01', '2024-01-05', 3, '2024-06-01'));
    }

    public function test_on_time_and_late_both_count_towards_the_denominator(): void
    {
        self::assertTrue(ResponseOutcome::OnTime->countsTowardsDeadline());
        self::assertTrue(ResponseOutcome::Late->countsTowardsDeadline());
        self::assertFalse(ResponseOutcome::OnTime->isFailure());
        self::assertTrue(ResponseOutcome::Late->isFailure());
    }
}
