<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\Saml\AttributiSaml;
use PHPUnit\Framework\TestCase;

final class AttributiSamlTest extends TestCase
{
    public function testEmailSceltaPerTipoDiUtente(): void
    {
        $a = ['mail' => ['Mario@Gmail.com', 'mario.rossi@unical.it', 'm.rossi@studenti.unical.it']];
        self::assertSame('m.rossi@studenti.unical.it', AttributiSaml::email($a, 'studente'));
        self::assertSame('mario.rossi@unical.it', AttributiSaml::email($a, 'dipendente'));
        self::assertSame('mario@gmail.com', AttributiSaml::email($a, 'esterno'));
    }

    public function testEmailRipiegaSulPrimoValidoOVuota(): void
    {
        self::assertSame('a@unical.it', AttributiSaml::email(['urn:oid:0.9.2342.19200300.100.1.3' => ['a@unical.it']], 'studente'));
        self::assertSame('', AttributiSaml::email(['mail' => ['non-una-email']], 'esterno'));
    }

    public function testTipoUtente(): void
    {
        self::assertSame('studente', AttributiSaml::tipoUtente('123', '456'));
        self::assertSame('dipendente', AttributiSaml::tipoUtente(' ', '456'));
        self::assertSame('esterno', AttributiSaml::tipoUtente('', ''));
    }

    public function testCodiceFiscale(): void
    {
        self::assertSame('RSSMRA80A01H501U', AttributiSaml::codiceFiscale(['codice_fiscale' => ['RSSMRA80A01H501U']], false));
        self::assertSame('RSSMRA80A01H501U', AttributiSaml::codiceFiscale(['urn:oid:1.3.6.1.4.1.25178.1.2.15' => ['schac:personalUniqueID:it:CF:RSSMRA80A01H501U']], false));
        $spid = ['fiscalNumber' => ['TINIT-RSSMRA80A01H501U']];
        self::assertNull(AttributiSaml::codiceFiscale($spid, false), "l'accesso automatico non guarda gli attributi SPID");
        self::assertSame('RSSMRA80A01H501U', AttributiSaml::codiceFiscale($spid, true));
    }

    public function testNomeCognomeConRipiegoSuCn(): void
    {
        self::assertSame(['Mario', 'Rossi'], AttributiSaml::nomeCognome(['givenName' => ['Mario'], 'sn' => ['Rossi']]));
        self::assertSame(['Anna', 'Maria Verdi'], AttributiSaml::nomeCognome(['cn' => ['Anna Maria Verdi']]));
        self::assertSame(['Utente', ''], AttributiSaml::nomeCognome([]));
    }

    public function testMetadatiAccessoSpidConLivello(): void
    {
        $as = new class () {
            public function getAuthData(string $k): mixed
            {
                return ['saml:sp:IdP' => 'https://spid.example/idp', 'saml:sp:AuthnContext' => 'https://www.spid.gov.it/SpidL2',
                    'AuthnInstant' => 1700000000, 'saml:sp:SessionIndex' => 'abc'][$k] ?? null;
            }
        };
        $m = AttributiSaml::metadatiAccesso($as, ['fiscalNumber' => ['TINIT-RSSMRA80A01H501U'], 'spidCode' => ['X1']], '10.0.0.1');
        self::assertSame('spid', $m['metodo']);
        self::assertSame(2, $m['livello']);
        self::assertSame('RSSMRA80A01H501U', $m['cf']);
        self::assertSame('10.0.0.1', $m['ip']);
        self::assertSame(date('c', 1700000000), $m['istante']);
    }
}
