<?php

declare(strict_types=1);

namespace Milczenie\Tests\Unit;

use Milczenie\Domain\QuestionKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuestionKind::class)]
final class QuestionKindTest extends TestCase
{
    public function test_both_kinds_share_the_statutory_deadline_of_21_days(): void
    {
        // Regulamin Sejmu art. 192 ust. 1 (interpelacje) i art. 195 ust. 1 (zapytania).
        // Zmiana tej liczby przestawia caly ranking, wiec ma byc jawnie zapisana w tescie.
        self::assertSame(21, QuestionKind::Interpellation->deadlineDays());
        self::assertSame(21, QuestionKind::WrittenQuestion->deadlineDays());
    }

    public function test_each_kind_maps_to_its_own_api_endpoint(): void
    {
        self::assertSame('interpellations', QuestionKind::Interpellation->endpoint());
        self::assertSame('writtenQuestions', QuestionKind::WrittenQuestion->endpoint());
    }

    public function test_kind_is_built_from_the_value_stored_in_the_database(): void
    {
        self::assertSame(QuestionKind::Interpellation, QuestionKind::from('interpelacja'));
        self::assertSame(QuestionKind::WrittenQuestion, QuestionKind::from('zapytanie'));
    }
}
