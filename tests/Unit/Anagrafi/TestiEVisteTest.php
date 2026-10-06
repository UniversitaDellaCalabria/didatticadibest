<?php

declare(strict_types=1);

namespace Tests\Unit\Anagrafi;

use App\Anagrafi\Anagrafe;
use App\Anagrafi\Testi;
use App\Anagrafi\Vista\CampoScuola;
use App\Anagrafi\Vista\Corsi;
use App\Anagrafi\Vista\Persone;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TestiEVisteTest extends TestCase
{
    public function testNomiInMaiuscoloEMinuscolo(): void
    {
        self::assertSame("D'Amico Maria", Testi::maiuscoleNome("D'AMICO MARIA"));
        self::assertSame('Liceo Scientifico E. Fermi', Testi::maiuscoleScuola('LICEO SCIENTIFICO E. FERMI'));
        self::assertSame('IIS Fermi di Rende', Testi::maiuscoleScuola('IIS FERMI DI RENDE'));
        self::assertSame('Scienze geologiche', Testi::maiuscoleCorso('SCIENZE GEOLOGICHE'));
        self::assertSame('Scienze Geologiche', Testi::maiuscoleCorso('Scienze Geologiche'), 'già scritti in minuscolo restano come sono');
        self::assertSame('Liceo Fermi – Rende', Testi::etichettaScuola(['denominazione' => 'LICEO FERMI', 'comune' => 'RENDE']));
        self::assertSame('Liceo Rende', Testi::etichettaScuola(['denominazione' => 'LICEO RENDE', 'comune' => 'RENDE']));
    }

    public function testSeparaCognomeENome(): void
    {
        self::assertSame(['Rossi', 'Mario'], Testi::separaCognomeNome('ROSSI MARIO', 'mario.rossi'));
        self::assertSame(['Di Maio', 'Anna Maria'], Testi::separaCognomeNome('DI MAIO ANNA MARIA', 'annamaria.dimaio'));
        self::assertSame(['Rossi', 'Mario'], Testi::separaCognomeNome('ROSSI MARIO', 'senza.punto.coincidenza'), 'senza corrispondenza: prima parola = cognome');
    }

    public function testGruppoDelPersonale(): void
    {
        self::assertSame('docenti', Testi::gruppoPersonale('PO'));
        self::assertSame('pta', Testi::gruppoPersonale('NM'));
        self::assertSame('altro', Testi::gruppoPersonale('AU'));
        self::assertSame('docenti', Testi::gruppoPersonale('AU', true), 'contratto di docenza nell\'elenco dei docenti');
        self::assertSame('altro', Testi::gruppoPersonale('BR', true), 'borsisti e assegnisti restano in «Altro»');
    }

    public function testEtichetteEUrl(): void
    {
        $i = ['nome' => 'Chimica', 'partizione' => 'A-L', 'cds_nome' => 'Biologia', 'anno_corso' => 1, 'semestre' => 'Primo', 'docente' => 'Rossi'];
        self::assertSame('Chimica (A-L) · 1° anno · Primo · Rossi', Testi::etichettaInsegnamento($i));
        self::assertSame('Chimica (A-L) · Biologia · 1° anno · Primo · Rossi', Testi::etichettaInsegnamento($i, true));
        self::assertSame('https://www.unical.it/storage/teachers/a%20b/', Testi::urlPortalePersona(['id' => 'a b', 'docente' => 1]));
        self::assertSame('https://www.unical.it/storage/addressbook/x/', Testi::urlPortalePersona(['id' => 'x', 'docente' => 0]));
        self::assertSame('https://www.unical.it/storage/cds/55/', Testi::urlCorsoStudio(['regdid_id' => 55]));
        self::assertSame('', Testi::urlCorsoStudio(['regdid_id' => 0]));
        self::assertSame('Corso di laurea magistrale in Biologia', Testi::nomeSchedaCorso(['tipo' => 'LM', 'nome' => 'Biologia']));
        self::assertSame('Biologia (Laurea)', Testi::etichettaCorso(['nome' => 'Biologia', 'tipo_descrizione' => 'Laurea']));
        self::assertSame("Riga uno\nRiga due", Testi::testoDaHtmlApi('<p>Riga&nbsp;uno</p><p>Riga due</p>'));
        self::assertSame(12.5, Testi::cfuAttivitaApi(['StudyActivityCFU' => '12,5']));
        self::assertNull(Testi::cfuAttivitaApi(['StudyActivityCFU' => 'x']));
    }

    public function testAnnoAccademico(): void
    {
        self::assertSame(2025, Anagrafe::annoAccademico(new DateTimeImmutable('2026-08-31')));
        self::assertSame(2026, Anagrafe::annoAccademico(new DateTimeImmutable('2026-09-01')));
    }

    public function testViste(): void
    {
        self::assertStringContainsString('<svg', Persone::avatar(null, '/non/esiste', '', 36));
        self::assertStringContainsString('aria-label="Nessuna foto"', Persone::avatar('uploads/personale/x.jpg', '/non/esiste'));
        $rf = ['nome' => 'Mario', 'ruolo' => 'Referente', 'email' => 'm@x.it', 'telefono' => '0984 49'];
        $h = Persone::referentePubblico($rf, ['id' => 'm.r', 'ruolo' => 'Ordinario', 'ssd' => 'BIO/01'], [], 'Mario Rossi', '/x', '#123456');
        self::assertStringContainsString('href="persona.php?id=m.r"', $h);
        self::assertStringContainsString('Ordinario · BIO/01', $h);
        self::assertStringContainsString('href="tel:098449"', $h);
        self::assertStringContainsString('href="https://x.it/p"', Persone::referentePubblico(['nome' => 'A', 'link' => 'https://x.it/p'], null, [], '', '/x', '#000'));
        $s = CampoScuola::html('/portale/cerca_scuole.php', 'scuola', 'Liceo "X"', '', '', 'form-control', 'scu_abc');
        self::assertStringContainsString('name="custom_scuola"', $s);
        self::assertStringContainsString('value="Liceo &quot;X&quot;"', $s);
        self::assertStringContainsString('Clicca nel campo', $s);
        self::assertStringContainsString('Scuola dall\'anagrafe del Ministero', CampoScuola::html('/c', 's', 'L', 'CSPS00001A', '', 'c', 'scu_1'));
        self::assertSame('', Corsi::pubblico('', '', ''));
        self::assertStringContainsString('target="_blank"', Corsi::pubblico('Biologia', 'https://u/x', ''));
        self::assertSame('Biologia', Corsi::pubblico('Biologia', '', ''));
        $campo = Corsi::campo(['Laurea' => [['codice' => 'A', 'nome' => 'Biologia', 'tipo_descrizione' => 'Laurea']]], 'corso', 'Vecchio (Laurea)', '', 'form-select', 'c1');
        self::assertStringContainsString('<option value="Biologia (Laurea)">Biologia</option>', $campo);
        self::assertStringContainsString('<option value="Vecchio (Laurea)" selected>', $campo, 'un valore salvato non più in elenco resta selezionabile');
    }
}
