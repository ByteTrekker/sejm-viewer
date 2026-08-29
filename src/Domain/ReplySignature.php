<?php

declare(strict_types=1);

namespace Milczenie\Domain;

/**
 * Szczebel, na ktorym zapada odpowiedz na interpelacje. API podaje wylacznie
 * podpis tekstowy ("Sekretarz stanu Jan Kowalski"), wiec klasyfikujemy po tytule.
 *
 * To nie jest ocena jakosci - podsekretarz stanu bywa merytorycznie wlasciwszy
 * niz minister. Miara pokazuje, jak wysoko w hierarchii resort umieszcza kontakt
 * z parlamentem, i nic ponadto.
 */
enum ReplySignature: string
{
    case Minister = 'minister';
    case SecretaryOfState = 'sekretarz stanu';
    case UndersecretaryOfState = 'podsekretarz stanu';
    case Other = 'inne';
    case Unknown = 'brak podpisu';

    public static function fromAuthor(?string $author): self
    {
        $author = $author === null ? '' : trim($author);
        if ($author === '') {
            return self::Unknown;
        }

        // Kolejnosc ma znaczenie: "Podsekretarz stanu" zawiera w sobie "sekretarz stanu",
        // wiec dluzszy tytul musi byc sprawdzany pierwszy.
        return match (true) {
            (bool) preg_match('/^podsekretarz stanu/iu', $author) => self::UndersecretaryOfState,
            (bool) preg_match('/^sekretarz stanu/iu', $author) => self::SecretaryOfState,
            (bool) preg_match('/^(minister|prezes|wiceprezes|szef)\b/iu', $author) => self::Minister,
            default => self::Other,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Minister => 'Minister osobiście',
            self::SecretaryOfState => 'Sekretarz stanu',
            self::UndersecretaryOfState => 'Podsekretarz stanu',
            self::Other => 'Inny podpis',
            self::Unknown => 'Brak podpisu w API',
        };
    }
}
