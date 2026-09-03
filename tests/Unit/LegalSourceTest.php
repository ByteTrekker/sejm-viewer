<?php

declare(strict_types=1);

namespace Milczenie\Tests\Unit;

use Milczenie\Domain\LegalSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegalSource::class)]
final class LegalSourceTest extends TestCase
{
    public function test_every_threshold_the_project_uses_has_a_citation(): void
    {
        $keys = array_keys(LegalSource::all());

        // Kazdy prog, o ktory opiera sie ranking, musi dac sie sprawdzic u zrodla.
        self::assertContains('termin_interpelacje', $keys);
        self::assertContains('termin_zapytania', $keys);
        self::assertContains('vacatio_standard', $keys);
        self::assertContains('vacatio_wyjatek', $keys);
    }

    public function test_citation_points_at_the_text_of_the_act(): void
    {
        $source = LegalSource::all()['termin_interpelacje'];

        self::assertSame('art. 192 ust. 1', $source['przepis']);
        self::assertSame('M.P. 1992 nr 26 poz. 185', $source['adres']);
        self::assertSame('https://api.sejm.gov.pl/eli/acts/MP/1992/185/text.html', $source['url']);
        self::assertSame('https://api.sejm.gov.pl/eli/acts/MP/1992/185/text.pdf', $source['url_pdf']);
    }

    public function test_every_citation_links_somewhere_verifiable(): void
    {
        // Swiadomie nie linkujemy do ISAP: identyfikatory starszych aktow zawieraja
        // numer zeszytu, czego nie dalo sie sprawdzic zza ochrony antybotowej.
        foreach (LegalSource::all() as $key => $source) {
            self::assertStringStartsWith('https://api.sejm.gov.pl/eli/acts/', $source['url'], $key);
            self::assertStringNotContainsString('isap', $source['url'], $key);
        }
    }

    public function test_vacatio_exception_is_cited_alongside_the_standard(): void
    {
        // Bez art. 4 ust. 2 ranking vacatio legis czytalby sie jak zarzut lamania prawa.
        $all = LegalSource::all();

        self::assertSame($all['vacatio_standard']['akt'], $all['vacatio_wyjatek']['akt']);
        self::assertNotSame($all['vacatio_standard']['przepis'], $all['vacatio_wyjatek']['przepis']);
    }
}
