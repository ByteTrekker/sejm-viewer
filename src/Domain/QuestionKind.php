<?php

declare(strict_types=1);

namespace Milczenie\Domain;

enum QuestionKind: string
{
    case Interpellation = 'interpelacja';
    case WrittenQuestion = 'zapytanie';

    public function endpoint(): string
    {
        return match ($this) {
            self::Interpellation => 'interpellations',
            self::WrittenQuestion => 'writtenQuestions',
        };
    }

    /**
     * Regulamin Sejmu RP:
     *  - art. 192 ust. 1 - odpowiedz na interpelacje w terminie 21 dni od otrzymania,
     *  - art. 195 ust. 1 - odpowiedz na zapytanie poselskie w terminie 21 dni.
     * Prog potwierdzony empirycznie: rozklad czasu odpowiedzi ma wyrazny uskok na 21. dniu.
     */
    public function deadlineDays(): int
    {
        return 21;
    }
}
