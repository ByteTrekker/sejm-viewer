<?php

declare(strict_types=1);

namespace Milczenie\Domain;

/**
 * Rodzaj glosowania, rozpoznawany z tytulu i opisu.
 *
 * Sluzy wylacznie do rozbicia zgodnosci klubow na sensowne grupy: kluby zgadzaja
 * sie niemal zawsze przy wnioskach formalnych, a roznia przy ustawach, wiec jedna
 * usredniona liczba zaciera cala tresc.
 *
 * Reguly sa waskie i jawne, a wszystko, czego nie rozpoznaja, ląduje w koszyku
 * "pozostale" - zamiast byc wciskane do najblizszej pasujacej kategorii.
 */
enum VotingCategory: string
{
    case FormalMotion = 'wnioski formalne';
    case Appointment = 'wybory i odwołania';
    case Budget = 'budżet';
    case SenateAmendment = 'uchwały Senatu';
    case Ratification = 'ratyfikacje';
    case ConfidenceVote = 'wotum';
    case GovernmentBill = 'projekty rządowe';
    case OtherBill = 'projekty poselskie i inne';
    case Other = 'pozostałe';

    /**
     * Kolejnosc ma znaczenie: glosowanie nad poprawkami Senatu do ustawy budzetowej
     * jest przede wszystkim wnioskiem formalnym albo etapem senackim, a dopiero
     * potem "budzetem". Pierwsza pasujaca regula wygrywa.
     *
     * @var array<string, string>
     */
    private const RULES = [
        'wnioski formalne' => '/wniosek formalny|wniosek o (przerw|odrzucenie w pierwszym|uzupełnienie porządku|zmian[ęe] w porządku)|reasumpcj/iu',
        'wybory i odwołania' => '/\b(wyb[óo]r|powołani[ae]|odwołani[ae]|kandydatur)|wniosek o wyrażenie zgody na pociągnięcie/iu',
        'wotum' => '/wotum (nieufności|zaufania)/iu',
        'uchwały Senatu' => '/uchwal[ei] Senatu|poprawk\w* Senatu/iu',
        'ratyfikacje' => '/ratyfikacj/iu',
        'budżet' => '/budżetow|budżet państwa|absolutorium/iu',
        'projekty rządowe' => '/rządow\w* projek\w*/iu',
        'projekty poselskie i inne' => '/(poselsk|obywatelsk|senack|prezydenck)\w* projek\w*/iu',
    ];

    public static function fromTitle(?string $title, ?string $topic = null): self
    {
        $blob = trim(($title ?? '') . ' ' . ($topic ?? ''));
        if ($blob === '') {
            return self::Other;
        }

        foreach (self::RULES as $value => $pattern) {
            if (preg_match($pattern, $blob) === 1) {
                return self::from($value);
            }
        }

        return self::Other;
    }
}
