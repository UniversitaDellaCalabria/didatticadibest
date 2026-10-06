<?php

declare(strict_types=1);

namespace Tests\Integration\Core;

use App\Anagrafi\AnagrafePerEventi;
use App\Anagrafi\RegistroDaSessione as RegistroAnagrafi;
use App\Anagrafi\RegistroOperazioni as RegistroOperazioniAnagrafi;
use App\Attestati\AttestatiPerIscritti;
use App\Auth\Abilitazioni\ModuliAree;
use App\Core\App;
use App\Didattica\PianificatiDidattica;
use App\Eventi\Anagrafe;
use App\Eventi\FonteAreeEventi;
use App\Infrastructure\Mail\ImpaginatoreEmail;
use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Mail\MailerDaFunzione;
use App\Infrastructure\Pdf\FirmePades;
use App\Infrastructure\Pdf\VerificaFirme;
use App\Iscritti\Attestati;
use App\Iscritti\Iscrizioni;
use App\Iscritti\Pianificati;
use App\Iscrizioni\IscrizioniPerIscritti;
use App\Iscrizioni\RegistroDaSessione as RegistroIscrizioni;
use App\Iscrizioni\RegistroOperazioni as RegistroOperazioniIscrizioni;
use App\Portale\FonteAree;
use App\Portale\ImpaginatoreEmailPortale;
use App\Portale\ModuliAreePortale;
use mysqli;
use PHPUnit\Framework\TestCase;

/**
 * Il contenitore completo (tutti i moduli): ogni interfaccia che un modulo chiede a un altro è servita da una classe del modulo
 * che la fornisce (non esistono più adattatori verso le funzioni procedurali, tranne l'invio delle email).
 */
final class ContenitoreModuliTest extends TestCase
{
    private static ?mysqli $conn = null;

    public static function setUpBeforeClass(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = @new mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''));
        if (!$conn->connect_error) {
            self::$conn = $conn;
        }
    }

    protected function setUp(): void
    {
        if (self::$conn === null) {
            self::markTestSkipped('Database di prova non raggiungibile');
        }
    }

    public function testLeInterfacceTraModuliHannoUnaClasseDelModuloChePassa(): void
    {
        $c = App::per(self::$conn);
        $this->assertInstanceOf(AnagrafePerEventi::class, $c->get(Anagrafe::class));
        $this->assertInstanceOf(FonteAreeEventi::class, $c->get(FonteAree::class));
        $this->assertInstanceOf(ModuliAreePortale::class, $c->get(ModuliAree::class));
        $this->assertInstanceOf(IscrizioniPerIscritti::class, $c->get(Iscrizioni::class));
        $this->assertInstanceOf(AttestatiPerIscritti::class, $c->get(Attestati::class));
        $this->assertInstanceOf(RegistroAnagrafi::class, $c->get(RegistroOperazioniAnagrafi::class));
        $this->assertInstanceOf(RegistroIscrizioni::class, $c->get(RegistroOperazioniIscrizioni::class));
        $this->assertInstanceOf(FirmePades::class, $c->get(VerificaFirme::class));
        $this->assertInstanceOf(ImpaginatoreEmailPortale::class, $c->get(ImpaginatoreEmail::class));
        $this->assertInstanceOf(PianificatiDidattica::class, $c->get(Pianificati::class));
    }

    public function testLeEmailPassanoDallaFunzioneDelPortale(): void
    {
        $this->assertInstanceOf(MailerDaFunzione::class, App::per(self::$conn)->get(Mailer::class));
    }

    public function testSenzaUtenteCollegatoIlRegistroNonScriveNulla(): void
    {
        $sessione = $_SESSION ?? null;
        $_SESSION = [];
        try {
            App::per(self::$conn)->get(RegistroOperazioniAnagrafi::class)->registra('Prova', ['x' => 1]);
            $this->addToAssertionCount(1);
        } finally {
            if ($sessione === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $sessione;
            }
        }
    }
}
