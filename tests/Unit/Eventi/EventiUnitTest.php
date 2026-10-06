<?php

declare(strict_types=1);

namespace Tests\Unit\Eventi;

use App\Eventi\Progetti;
use App\Eventi\ReferentiForm;
use App\Eventi\Righe;
use App\Eventi\ServizioReport;
use App\Eventi\ServizioSeminari;
use App\Eventi\Turni;
use App\Eventi\Vista\Progetti as VistaProgetti;
use App\Eventi\Vista\Seminario;
use PHPUnit\Framework\TestCase;

final class EventiUnitTest extends TestCase
{
    public function testOrarioEEtichettaDeiTurni(): void
    {
        self::assertSame('', Turni::orario([]));
        self::assertSame('09:30', Turni::orario(['orario_inizio' => '09:30:00']));
        self::assertSame('09:30–11:00', Turni::orario(['orario_inizio' => '09:30:00', 'orario_fine' => '11:00:00']));
        self::assertSame('Gruppo 1 · 22/09/2026 · 09:30–11:00', Turni::etichetta(['nome_turno' => 'Gruppo 1', 'data_turno' => '2026-09-22', 'orario_inizio' => '09:30:00', 'orario_fine' => '11:00:00']));
        self::assertSame('Turno', Turni::etichetta([]));
    }

    public function testTurnoConclusoESenzaDataNonScade(): void
    {
        self::assertFalse(Turni::concluso([]));
        self::assertTrue(Turni::concluso(['data_turno' => '2000-01-01']));
        self::assertFalse(Turni::concluso(['data_turno' => '2999-01-01', 'orario_fine' => '10:00:00']));
        self::assertTrue(Turni::concluso(['data_turno' => date('Y-m-d', strtotime('-1 day')), 'orario_fine' => '23:59:00']));
    }

    public function testFinestraDiPrenotazione(): void
    {
        $domani = date('Y-m-d H:i:s', strtotime('+1 day'));
        $tra10 = date('Y-m-d H:i:s', strtotime('+10 days'));
        $ieri = date('Y-m-d H:i:s', strtotime('-1 day'));
        $f = Turni::finestraPrenotazione([['data_turno' => '2999-01-01', 'data_chiusura' => $domani]]);
        self::assertStringStartsWith('Prenota entro il ' . date('d/m/Y', strtotime($domani)), $f['testo']);
        self::assertSame('#FEE2E2', $f['bg'], 'meno di 48 ore: urgente');
        self::assertSame('#FEF3C7', Turni::finestraPrenotazione([['data_turno' => '2999-01-01', 'data_chiusura' => $tra10]])['bg']);
        self::assertStringStartsWith('Prenotazioni dal', Turni::finestraPrenotazione([['data_turno' => '2999-01-01', 'data_apertura' => $tra10]])['testo']);
        self::assertSame('Prenotazioni chiuse', Turni::finestraPrenotazione([['data_turno' => '2999-01-01', 'data_chiusura' => $ieri]])['testo']);
        self::assertNull(Turni::finestraPrenotazione([['data_turno' => '2999-01-01']]), 'nessuna data impostata');
        self::assertNull(Turni::finestraPrenotazione([]));
        self::assertNull(Turni::finestraPrenotazione([['data_turno' => '2000-01-01', 'data_chiusura' => $ieri]]), 'turno concluso: ignorato');
    }

    public function testLinkGoogleCalendar(): void
    {
        $u = Turni::urlGoogleCalendar('A & B', '2026-10-10', '09:00', '10:30', 'Aula 1', 'Dettagli');
        self::assertStringStartsWith('https://calendar.google.com/calendar/render?action=TEMPLATE&text=A+%26+B&dates=20261010T090000/20261010T103000', $u);
        self::assertStringContainsString('&location=Aula+1', $u);
    }

    public function testPeriodoEStatoDelProgetto(): void
    {
        self::assertSame('Date da definire', Progetti::periodo(null));
        self::assertSame('Dal 13/10/2026 al 18/12/2026', Progetti::periodo(['data_inizio' => '2026-10-13', 'data_fine' => '2026-12-18']));
        self::assertSame('Il 13/10/2026', Progetti::periodo(['data_inizio' => '2026-10-13', 'data_fine' => '2026-10-13']));
        self::assertSame('Dal 13/10/2026', Progetti::periodo(['data_inizio' => '2026-10-13']));
        self::assertSame('Entro il 18/12/2026', Progetti::periodo(['data_fine' => '2026-12-18']));

        $aperto = ['max_posti' => 1, 'data_apertura' => date('Y-m-d H:i:s', strtotime('-1 day')), 'data_chiusura' => date('Y-m-d H:i:s', strtotime('+1 day'))];
        self::assertSame('aperte', Progetti::stato(null, $aperto, 0)['codice']);
        self::assertSame('chiuse', Progetti::stato(null, $aperto, 1)['codice'], 'posto occupato e niente lista d\'attesa');
        self::assertSame('attesa', Progetti::stato(null, $aperto + ['abilita_lista_attesa' => 1], 1)['codice']);
        self::assertSame('arrivo', Progetti::stato(null, ['max_posti' => 1, 'data_apertura' => date('Y-m-d H:i:s', strtotime('+2 days'))], 0)['codice']);
        self::assertSame('concluso', Progetti::stato(['data_fine' => '2000-01-01'], $aperto, 0)['codice']);
        self::assertSame('in_corso', Progetti::stato(['data_inizio' => '2000-01-01'], null, 0)['codice']);
        self::assertSame(1, Progetti::stato(null, $aperto, 0)['ordine']);
    }

    public function testPeriodoDelReport(): void
    {
        self::assertSame(['2026-01-01', '2026-12-31'], ServizioReport::periodo('2026'));
        self::assertSame(['2025-10-01', '2026-09-30'], ServizioReport::periodo('2025/2026'));
        self::assertSame([date('Y') . '-01-01', date('Y') . '-12-31'], ServizioReport::periodo('boh'));
        self::assertSame([date('Y') . '-01-01', date('Y') . '-12-31'], ServizioReport::periodo('2025/2030'), 'un anno accademico dura un anno');
    }

    public function testRigheRiportaITestoComeIlVecchioProtocollo(): void
    {
        self::assertSame(['id' => '5', 'x' => null, 'n' => '2.5'], Righe::riga(['id' => 5, 'x' => null, 'n' => 2.5]));
        self::assertNull(Righe::riga(null));
        self::assertSame([['a' => '1']], Righe::testo([['a' => 1]]));
    }

    public function testReferentiDalModulo(): void
    {
        $post = [
            'ref_nome' => ['Mario Rossi', 'Senza nulla', '', 'Link storto'],
            'ref_ruolo' => ['Referente', 'Tutor', '', ''],
            'ref_email' => ['MARIO@X.IT', 'rotta', '', ''],
            'ref_tel' => ['0984 49', 'abc', '', ''],
            'ref_link' => ['www.x.it', '', '', 'javascript:alert(1)'],
            'ref_notifiche' => ['1', '1', '0', '0'],
        ];
        $scartate = ['x'];
        $r = ReferentiForm::daPost($post, null, $scartate);
        self::assertCount(3, $r, 'la riga vuota si scarta');
        self::assertSame(['rotta'], $scartate);
        self::assertSame(['ruolo' => 'Referente', 'nome' => 'Mario Rossi', 'email' => 'mario@x.it', 'telefono' => '0984 49', 'link' => 'https://www.x.it', 'notifiche' => 1], $r[0]);
        self::assertSame('', $r[1]['email']);
        self::assertSame(0, $r[1]['notifiche'], 'senza email niente notifiche');
        self::assertSame('', $r[1]['telefono']);
        self::assertSame('', $r[2]['link'], 'solo http(s)');
        $troppi = ['ref_nome' => array_fill(0, 15, 'N')];
        self::assertCount(10, ReferentiForm::daPost($troppi, null, $scartate));
        self::assertSame([], ReferentiForm::daPost([], null, $scartate));
    }

    public function testSeminarioEdHtml(): void
    {
        self::assertFalse(ServizioSeminari::eSeminario([]));
        self::assertTrue(ServizioSeminari::eSeminario(['relatore' => 'Dott. X']));
        self::assertTrue(ServizioSeminari::eSeminario(['abstract' => 'Testo']));
        self::assertSame('', Seminario::riquadro([]));
        $ev = ['relatore' => 'Dott. <X>', 'relatore_ente' => 'Unical', 'abstract' => "Riga 1\nRiga 2", 'link_streaming' => 'https://x.it/live', 'link_registrazione' => '', 'slide_pdf' => 'uploads/s.pdf'];
        $h = Seminario::riquadro($ev);
        self::assertStringContainsString('Dott. &lt;X&gt;', $h);
        self::assertStringContainsString('Riga 1<br />' . "\n" . 'Riga 2', $h);
        self::assertStringContainsString('Segui in diretta', $h);
        self::assertStringContainsString('Slide (PDF)', $h);
        self::assertStringNotContainsString('Segui in diretta', Seminario::riquadro($ev, true), 'a evento concluso niente diretta');
    }

    public function testPulsanteDestinazione(): void
    {
        $h = VistaProgetti::pulsanteDestinazione(['url' => 'https://x.it/?a=1&b=2', 'nome' => 'x.it', 'esterno' => true], 'background:#fff');
        self::assertStringContainsString('target="_blank" rel="noopener"', $h);
        self::assertStringContainsString('href="https://x.it/?a=1&amp;b=2"', $h);
        self::assertStringContainsString('Vai a x.it', $h);
        self::assertStringNotContainsString('target=', VistaProgetti::pulsanteDestinazione(['url' => 'openlab.php', 'nome' => 'OPENLAB', 'esterno' => false], ''));
    }
}
