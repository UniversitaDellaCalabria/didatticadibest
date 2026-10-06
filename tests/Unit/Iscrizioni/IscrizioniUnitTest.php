<?php

declare(strict_types=1);

namespace Tests\Unit\Iscrizioni;

use App\Iscrizioni\CaptchaPrenotazione;
use App\Iscrizioni\EsitoPrenotazione;
use App\Iscrizioni\LimitiPartecipanti;
use App\Iscrizioni\NotifichePrenotazione;
use App\Iscrizioni\OffertaNonValida;
use App\Iscrizioni\RichiestaPrenotazione;
use App\Iscrizioni\ServizioAreaPersonale;
use App\Iscrizioni\ServizioDisponibilita;
use App\Iscrizioni\StatoPrenotazione;
use App\Iscrizioni\Vista\CampiFormAdmin;
use App\Iscrizioni\Vista\MessaggiAreaPersonale;
use App\Iscrizioni\Vista\RiepilogoPrenotazione;
use PHPUnit\Framework\TestCase;
use Tests\Doppi\AuthSessioneInMemoria;
use Tests\Doppi\IscrizioniCampiAnagrafeFinti;
use Tests\Doppi\IscrizioniOrologioFisso;
use Tests\Doppi\IscrizioniRegoleFslFinte;

final class IscrizioniUnitTest extends TestCase
{
    public function testStatiDelleprenotazioni(): void
    {
        $this->assertSame(['confermata', 'richiesta_conferma', 'da_approvare'], StatoPrenotazione::chePrendonoPosto());
        $this->assertSame(StatoPrenotazione::Confermata, StatoPrenotazione::daDb(null), 'le vecchie righe senza stato sono confermate');
        $this->assertSame(StatoPrenotazione::InAttesa, StatoPrenotazione::daDb('in_attesa'));
        $this->assertNull(StatoPrenotazione::daDb('boh'));
        $this->assertTrue(StatoPrenotazione::DaApprovare->occupaPosto());
        $this->assertFalse(StatoPrenotazione::InAttesa->occupaPosto());
        $this->assertFalse(StatoPrenotazione::Scaduta->occupaPosto());
        $this->assertSame(['annullata', 'rifiutata', 'scaduta'], StatoPrenotazione::chiusi());
        $this->assertContains('in_attesa', StatoPrenotazione::inCorso());
    }

    public function testLimitiDeiPartecipanti(): void
    {
        $this->assertSame(['min' => 1, 'max' => null], LimitiPartecipanti::calcola(null));
        $this->assertSame(['min' => 8, 'max' => 25], LimitiPartecipanti::calcola(['min_studenti' => 8, 'max_studenti' => 25]));
        $this->assertSame(['min' => 10, 'max' => 12], LimitiPartecipanti::calcola(['min_studenti' => 8, 'max_studenti' => 25], ['min_partecipanti' => 10, 'max_partecipanti' => 12]), "l'edizione prevale sul progetto");
        $this->assertSame(['min' => 1, 'max' => 25], LimitiPartecipanti::calcola(['max_studenti' => 25], ['min_partecipanti' => 0]));
    }

    public function testTestoDeiLimiti(): void
    {
        $this->assertSame('da 15 a 30', LimitiPartecipanti::testo(15, 30));
        $this->assertSame('20', LimitiPartecipanti::testo(20, 20));
        $this->assertSame('almeno 15', LimitiPartecipanti::testo(15, null));
        $this->assertSame('fino a 30', LimitiPartecipanti::testo(1, 30));
        $this->assertSame('', LimitiPartecipanti::testo(1, null));
        $this->assertSame('', LimitiPartecipanti::testo(null, null));
    }

    public function testValidazioneDeiPartecipanti(): void
    {
        $d = ['per_scuole' => 1, 'min_studenti' => 8, 'max_studenti' => 25];
        $this->assertSame('Indica il numero di studenti partecipanti.', LimitiPartecipanti::valida([], $d));
        $this->assertSame('Il numero di studenti deve essere un numero intero.', LimitiPartecipanti::valida([CAMPO_PARTECIPANTI => '1.5'], $d));
        $this->assertSame('Il numero di studenti deve essere compreso tra 8 e 25.', LimitiPartecipanti::valida([CAMPO_PARTECIPANTI => '30'], $d));
        $this->assertSame('Il numero di studenti deve essere compreso tra 8 e 25.', LimitiPartecipanti::valida([CAMPO_PARTECIPANTI => '7'], $d));
        $this->assertNull(LimitiPartecipanti::valida([CAMPO_PARTECIPANTI => ' 25 '], $d));
        $this->assertNull(LimitiPartecipanti::valida([], ['per_scuole' => 0]), 'progetto non per le scuole: niente controllo');
        $this->assertSame('Il numero di studenti deve essere compreso tra 12 e 12.', LimitiPartecipanti::valida([CAMPO_PARTECIPANTI => '3'], $d, ['min_partecipanti' => 12, 'max_partecipanti' => 12]));
        $this->assertSame('Il numero di studenti deve essere compreso tra 5 e il massimo previsto.', LimitiPartecipanti::valida([CAMPO_PARTECIPANTI => '3'], ['per_scuole' => 1, 'min_studenti' => 5]));
    }

    public function testDomandaDiControllo(): void
    {
        $sessione = new AuthSessioneInMemoria();
        $orologio = new IscrizioniOrologioFisso();
        $cap = new CaptchaPrenotazione($sessione, $orologio);

        $d = $cap->domanda();
        $this->assertSame(1, preg_match('/^Quanto fa (\d) \+ (\d)\?$/', $d['domanda'], $m));
        $this->assertSame($d, $cap->domanda(), 'una sola domanda per richiesta');
        $this->assertArrayHasKey($d['id'], $sessione->dati['captcha_pren']);

        $somma = (string) ((int) $m[1] + (int) $m[2]);
        $this->assertStringContainsString('troppo in fretta', (string) $cap->verifica($d['id'], $somma), 'meno di 3 secondi');
        $this->assertArrayNotHasKey($d['id'], $sessione->dati['captcha_pren'], 'la domanda vale una volta sola');

        $d2 = (new CaptchaPrenotazione($sessione, $orologio))->domanda();
        $orologio->avanza('+10 seconds');
        $this->assertStringContainsString('non è corretta', (string) $cap->verifica($d2['id'], '99'));
        $d3 = (new CaptchaPrenotazione($sessione, $orologio))->domanda();
        $orologio->avanza('+10 seconds');
        $m3 = [];
        preg_match('/(\d) \+ (\d)/', $d3['domanda'], $m3);
        $this->assertNull($cap->verifica($d3['id'], ' ' . ((int) $m3[1] + (int) $m3[2]) . ' '));
        $this->assertStringContainsString('scaduta', (string) $cap->verifica($d3['id'], '1'), 'già usata');
        $this->assertStringContainsString('scaduta', (string) $cap->verifica('sconosciuta', '1'));

        $d4 = (new CaptchaPrenotazione($sessione, $orologio))->domanda();
        $orologio->avanza('+2 hours +1 second');
        $this->assertStringContainsString('scaduta', (string) $cap->verifica($d4['id'], '5'), 'dopo 2 ore');
    }

    public function testSiTengonoLeUltimeDieciDomande(): void
    {
        $sessione = new AuthSessioneInMemoria();
        for ($i = 0; $i < 12; ++$i) {
            (new CaptchaPrenotazione($sessione, new IscrizioniOrologioFisso()))->domanda();
        }
        $this->assertCount(10, $sessione->dati['captcha_pren']);
    }

    public function testMessaggiDellAreaPersonale(): void
    {
        $this->assertSame("<div class='alert alert-success fw-bold text-center my-3 shadow-sm'><i class='fa fa-paper-plane me-1'></i> Messaggio inviato con successo alla segreteria.</div>", MessaggiAreaPersonale::messaggioInviato());
        $this->assertStringContainsString('Modifica salvata. Sei stato inserito in Lista d\'Attesa per il nuovo orario.</div>', MessaggiAreaPersonale::modificaSalvata(true));
        $this->assertStringContainsString('Modifica salvata. Turno aggiornato con successo!</div>', MessaggiAreaPersonale::modificaSalvata(false));
        $this->assertStringContainsString('il termine era il 01/10/2026 alle 10:00. Per necessità', MessaggiAreaPersonale::annullamentoScaduto('2026-10-01 10:00:00'));
        $this->assertStringContainsString('Non è più possibile cambiare turno', MessaggiAreaPersonale::cambioTurnoScaduto('2026-10-01 10:00:00'));
        $this->assertStringContainsString('Modifica non salvata: a &lt;b&gt;</div>', MessaggiAreaPersonale::modificaNonSalvata('a <b>'));
        $this->assertStringContainsString('<div class=\'fw-bold mb-1\'>', MessaggiAreaPersonale::postoAccettatoInAttesaConvenzione('X'));
        $this->assertStringEndsWith('X</div>', MessaggiAreaPersonale::postoAccettatoInAttesaConvenzione('X'));
    }

    public function testCorpoDelleNotifiche(): void
    {
        $riepilogo = ['html' => 'CON-ADMIN', 'html_senza_admin' => 'SENZA'];
        $this->assertSame('<p>i</p>CON-ADMIN', NotifichePrenotazione::corpoPer(' A@x.it ', '<p>i</p>', $riepilogo, ['a@x.it']));
        $this->assertSame('<p>i</p>SENZA', NotifichePrenotazione::corpoPer('b@x.it', '<p>i</p>', $riepilogo, ['a@x.it']));
        $this->assertSame('<p>i</p>SENZA', NotifichePrenotazione::corpoPer('b@x.it', '<p>i</p>', $riepilogo, []));
    }

    public function testRiepilogoDellaPrenotazione(): void
    {
        $p = ['nome' => 'Ada', 'cognome' => 'Lovelace', 'email' => 'ada@x.it', 'matricola' => '', 'area_titolo' => 'Area', 'evento_titolo' => 'Evento <b>', 'nome_turno' => 'T1',
            'data_turno' => '2999-03-01', 'orario_inizio' => '10:00:00', 'orario_fine' => '12:00:00', 'luogo' => 'Aula', 'num_posti' => '0', 'stato' => 'in_attesa', 'convenzione' => 'no',
            'codice_prenotazione' => 'XX-1', 'data_prenotazione' => '2026-01-02 10:30:00', 'pagina_id' => '3', 'turno_id' => '7',
            'dati_custom_json' => json_encode(['scuola' => "Liceo\nA", 'allegato' => 'uploads/allegati_prenotazioni/a.pdf, uploads/allegati_prenotazioni/b.pdf', 'orfano_campo' => 'v', 'vuoto' => ' '])];
        $r = RiepilogoPrenotazione::costruisci($p, ['scuola' => 'Scuola', 'allegato' => 'Allegati', 'vuoto' => 'Vuoto'], 'https://x.it/eventi');
        $this->assertSame('Evento <b>', $r['oggetto_evento']);
        $this->assertStringContainsString('Ada Lovelace', $r['html']);
        $this->assertStringContainsString('Evento &lt;b&gt;', $r['html']);
        $this->assertStringContainsString("In lista d&#039;attesa", $r['html']);
        $this->assertStringContainsString('Da stipulare: prenotazione in attesa della convenzione', $r['html']);
        $this->assertStringContainsString('T1 · 01/03/2999 · 10:00–12:00', $r['html']);
        $this->assertStringContainsString('02/01/2026 10:30', $r['html']);
        $this->assertStringContainsString('>1</td>', $r['html'], 'almeno un posto');
        $this->assertStringNotContainsString('Matricola', $r['html'], 'i valori vuoti non compaiono');
        $this->assertStringContainsString('Liceo<br />', $r['html']);
        $this->assertStringContainsString('<a href="https://x.it/eventi/uploads/allegati_prenotazioni/a.pdf">Allegato 1</a> · <a href="https://x.it/eventi/uploads/allegati_prenotazioni/b.pdf">Allegato 2</a>', $r['html']);
        $this->assertStringContainsString('Orfano campo', $r['html'], 'valori di campi non più presenti, con etichetta ricavata dal nome');
        $this->assertLessThan(strpos($r['html'], 'Orfano campo'), strpos($r['html'], 'Allegati'), 'prima i campi nell\'ordine del modulo');
        $this->assertStringContainsString('https://x.it/eventi/admin/iscritti.php?p_id=3&amp;f_turno=7', $r['html']);
        $this->assertStringNotContainsString('Apri gli iscritti', $r['html_senza_admin']);
        $this->assertStringStartsWith('<table', $r['html_senza_admin']);
    }

    public function testCampiDelModuloPerLAmministratore(): void
    {
        $vista = new CampiFormAdmin(new IscrizioniRegoleFslFinte(), new IscrizioniCampiAnagrafeFinti());
        $campi = [
            ['id' => '1', 'nome_campo' => 'nome_x', 'etichetta' => 'Nome X', 'tipo_campo' => 'text', 'opzioni_select' => null],
            ['id' => '2', 'nome_campo' => 'sc', 'etichetta' => 'Sc', 'tipo_campo' => 'select', 'opzioni_select' => 'a, b'],
            ['id' => '3', 'nome_campo' => 'ck', 'etichetta' => 'Ck', 'tipo_campo' => 'checkboxes', 'opzioni_select' => 'a,b,c'],
            ['id' => '4', 'nome_campo' => 'sep', 'etichetta' => 'Parte due', 'tipo_campo' => 'separator', 'opzioni_select' => null],
            ['id' => '5', 'nome_campo' => 'sa', 'etichetta' => 'Scuola', 'tipo_campo' => 'scuola', 'opzioni_select' => null],
            ['id' => '6', 'nome_campo' => 'file_z', 'etichetta' => 'File', 'tipo_campo' => 'file', 'opzioni_select' => null],
            ['id' => '7', 'nome_campo' => CAMPO_PARTECIPANTI, 'etichetta' => 'N', 'tipo_campo' => 'number', 'opzioni_select' => null],
            ['id' => '8', 'nome_campo' => 'hid', 'etichetta' => 'H', 'tipo_campo' => 'hidden', 'opzioni_select' => 'fisso'],
            ['id' => '9', 'nome_campo' => 'rt', 'etichetta' => 'R', 'tipo_campo' => 'rating', 'opzioni_select' => 'a,b,c,d,e,f,g'],
        ];
        $html = $vista->html($campi, true, ['per_scuole' => 1], ['min' => 8, 'max' => 25], ['nome_x' => 'va"lore', 'sc' => 'b', 'ck' => 'a, c', 'file_z' => 'uploads/allegati_prenotazioni/f.pdf', '__scuola_codice' => 'CS1'], 'man');
        $this->assertStringContainsString('<input type="text" name="custom_nome_x" id="man_1" class="form-control form-control-sm" value="va&quot;lore">', $html);
        $this->assertStringContainsString('<option value="b" selected>b</option>', $html);
        $this->assertStringContainsString('name="custom_ck[]" id="man_3_0" value="a" checked', $html);
        $this->assertStringContainsString('id="man_3_1" value="b">', $html);
        $this->assertStringContainsString('<div class="small fw-bold text-uppercase text-secondary">Parte due</div>', $html);
        $this->assertStringContainsString('[scuola sa= codice=CS1]', $html);
        $this->assertStringContainsString('<a href="../uploads/allegati_prenotazioni/f.pdf" target="_blank">Allegato 1</a>', $html);
        $this->assertStringContainsString('Numero di studenti partecipanti</label><input type="number" name="custom_numero_partecipanti" id="man_7" class="form-control form-control-sm" value="" min="8" max="25" required>', $html);
        $this->assertStringContainsString('<input type="hidden" name="custom_hid" value="fisso">', $html);
        $this->assertStringContainsString('min="1" max="7"', $html, 'valutazione: tante stelle quante le opzioni (almeno 5)');

        $senzaClasse = $vista->html($campi, true, ['per_scuole' => 0], ['min' => 1, 'max' => null], [], 'man');
        $this->assertStringNotContainsString('custom_numero_partecipanti', $senzaClasse, 'il numero di partecipanti c\'è solo nelle prenotazioni di classe');
    }

    public function testTestoDeiPostiLiberi(): void
    {
        $this->assertSame('1 posto libero', ServizioDisponibilita::testoPostiLiberi(1));
        $this->assertSame('7 posti liberi', ServizioDisponibilita::testoPostiLiberi(7));
        $this->assertSame('', ServizioDisponibilita::testoPostiLiberi(0));
        $this->assertSame('', ServizioDisponibilita::testoPostiLiberi(-3));
        $this->assertSame('Posti disponibili', ServizioDisponibilita::testoPostiLiberi(POSTI_SENZA_LIMITE));
        $this->assertSame('Posti disponibili', ServizioDisponibilita::testoPostiLiberi(5000));
    }

    public function testTermineDiAnnullamento(): void
    {
        $this->assertTrue(ServizioAreaPersonale::annullamentoScaduto(['annullabile_fino' => '2000-01-01 10:00:00']));
        $this->assertFalse(ServizioAreaPersonale::annullamentoScaduto(['annullabile_fino' => '2999-01-01 10:00:00']));
        $this->assertFalse(ServizioAreaPersonale::annullamentoScaduto(['annullabile_fino' => null]));
        $this->assertFalse(ServizioAreaPersonale::annullamentoScaduto(null));
    }

    public function testRichiestaEdEsito(): void
    {
        $r = new RichiestaPrenotazione(['id' => '4'], 'openlab', 12, 'A', 'B', 'a@b.it', '', 1, 7, 5, ['2'], [], [], '1.2.3.4', 'http://x');
        $this->assertSame(4, $r->paginaId());
        $this->assertTrue($r->connesso());
        $anonima = new RichiestaPrenotazione([], 'openlab', 12, 'A', 'B', 'a@b.it', '', 1, null, 5, [], [], [], '1.2.3.4', 'http://x');
        $this->assertSame(1, $anonima->paginaId(), 'senza configurazione si usa la prima area');
        $this->assertFalse($anonima->connesso());
        $e = new EsitoPrenotazione('openlab.php?status=email', 'Errore');
        $this->assertSame('Errore', $e->erroreSessione);
        $this->assertNull((new EsitoPrenotazione('openlab.php'))->erroreSessione);
    }

    public function testOffertaNonValida(): void
    {
        $this->assertTrue((new OffertaNonValida(true))->scaduta);
        $this->assertFalse((new OffertaNonValida(false))->scaduta);
    }
}
