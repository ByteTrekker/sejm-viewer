<?php

declare(strict_types=1);

namespace Milczenie\Domain;

/**
 * Rozstrzygniecie pojedynczej pary (pytanie, adresat) wzgledem terminu ustawowego.
 *
 * Zyje w domenie, bo korzystaja z niej dwa raporty naraz - ranking adresatow
 * i ranking poslow. Powielenie tej logiki oznaczaloby dwie metodologie pod jedna
 * nazwa i liczby, ktore nie sumuja sie miedzy zakladkami.
 */
enum ResponseOutcome: string
{
    /** Odpowiedz przyszla w terminie. */
    case OnTime = 'na czas';

    /** Odpowiedz przyszla po terminie. */
    case Late = 'po terminie';

    /**
     * Odpowiedz jest, ale API nie podaje jej daty (wartownik 0000-12-30).
     * Nie wiadomo, czy w terminie - wypada z mianownika, zamiast udawac
     * punktualnosc albo milczenie.
     */
    case AnsweredWithoutDate = 'bez daty odpowiedzi';

    /** Brak odpowiedzi, termin uplynal. */
    case OverdueSilence = 'bez odpowiedzi po terminie';

    /** Brak odpowiedzi, termin jeszcze biegnie. */
    case StillInTime = 'w biegu';

    public static function classify(
        \DateTimeImmutable $forwarded,
        ?\DateTimeImmutable $firstReply,
        int $replyCount,
        \DateTimeImmutable $snapshot,
        int $deadlineDays,
    ): self {
        $deadline = $forwarded->modify(sprintf('+%d days', $deadlineDays));

        if ($firstReply !== null) {
            return $firstReply > $deadline ? self::Late : self::OnTime;
        }

        if ($replyCount > 0) {
            return self::AnsweredWithoutDate;
        }

        return $snapshot > $deadline ? self::OverdueSilence : self::StillInTime;
    }

    /** Czy pytanie wchodzi do mianownika terminowosci. */
    public function countsTowardsDeadline(): bool
    {
        return match ($this) {
            self::OnTime, self::Late, self::OverdueSilence => true,
            self::AnsweredWithoutDate, self::StillInTime => false,
        };
    }

    /** Czy termin zostal naruszony. */
    public function isFailure(): bool
    {
        return $this === self::Late || $this === self::OverdueSilence;
    }
}
