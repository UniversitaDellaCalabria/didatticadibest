<?php

declare(strict_types=1);

namespace Tests\Integration\Fsl;

use App\Fsl\ServizioConvenzioni;

/** Registro delle convenzioni: validità sul periodo, registrazione e modifica, stato delle prenotazioni, verifica delle iscrizioni, email. */
final class ConvenzioniTest extends FslBase
{
    private function servizioConvenzioni(): ServizioConvenzioni
    {
        return $this->servizio(ServizioConvenzioni::class);
    }

    public function testConvenzioneValidaSoloSeCopreTuttoIlPeriodo(): void
    {
        $s = $this->servizioConvenzioni();
        $this->convenzione('CSPS00001A', '2026-09-03', '2027-09-02');
        $this->convenzione('CSPS00002B', null, null);
        $this->convenzione('CSTF00003C', '2026-11-01', '2026-12-31');

        $this->assertSame('CSPS00001A', $s->valida('CSPS00001A', false, '2026-10-13', '2026-12-02')['scuola_codice']);
        $this->assertSame('CSPS00001A', $s->valida(' csps00001a ')['scuola_codice'], 'senza date: oggi, codice in minuscolo e con spazi');
        $this->assertNull($s->valida('CSPS00001A', false, '2026-10-13', '2027-09-03'), 'finisce dopo la scadenza');
        $this->assertNull($s->valida('CSPS00001A', false, '2026-09-02', '2026-10-01'), 'inizia prima della stipula');
        $this->assertNotNull($s->valida('CSPS00002B', false, '2000-01-01', '2099-01-01'), 'senza date: da sempre e senza scadenza');
        $this->assertNull($s->valida('CSTF00003C'), 'oggi non è ancora valida');
        $this->assertNull($s->valida('CSPS00009Z'));
        $this->assertNull($s->valida('X'), 'non è un codice meccanografico');
        $this->assertNull($s->valida(null));
        $this->assertSame('CSPS00001A', $s->valida('CSPS00001A', false, '2026-10-13', 'non-una-data')['scuola_codice'], 'data finale errata: il solo giorno iniziale');
    }

    public function testLeConvenzioniLetteRestanoInMemoriaFinoAllaRilettura(): void
    {
        $s = $this->servizioConvenzioni();
        $this->assertNull($s->valida('CSPS00001A'));
        $this->convenzione('CSPS00001A', '2026-01-01', '2027-01-01');
        $this->assertNull($s->valida('CSPS00001A'), 'stessa richiesta: risposta già in memoria');
        $this->assertNotNull($s->valida('CSPS00001A', true), 'dopo una registrazione si rilegge');
        $this->assertNotNull($s->valida('CSPS00001A'));
    }

    public function testConvenzioniDellaScuolaDallaPiuLontanaNellaScadenza(): void
    {
        $a = $this->convenzione('CSPS00001A', '2024-01-01', '2025-01-01');
        $b = $this->convenzione('CSPS00001A', '2025-01-02', '2026-01-01');
        $c = $this->convenzione('CSPS00001A', '2026-01-02', null);
        $this->convenzione('CSPS00002B', null, null);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->servizioConvenzioni()->dellaScuola('csps00001a'));
        $this->assertSame([$c, $b, $a], $ids, 'prima senza scadenza, poi dalla scadenza più lontana');
        $this->assertSame([], $this->servizioConvenzioni()->dellaScuola('xx'));
        $this->assertSame([], $this->servizioConvenzioni()->dellaScuola(null));
    }

    public function testSalvaRifiutaScuolaSconosciutaEDateInvertite(): void
    {
        $s = $this->servizioConvenzioni();
        $this->assertNull($s->salva(['scuola_codice' => 'NONESISTE1']));
        $this->assertNull($s->salva(['scuola_codice' => 'CSPS00002B', 'data_stipula' => '2027-01-01', 'scadenza' => '2026-01-01']));
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_scuole'));
    }

    public function testSalvaRegistraEModificaConDocentiEFile(): void
    {
        $s = $this->servizioConvenzioni();
        $esito = $s->salva([
            'scuola_codice' => 'csps00002b', 'data_stipula' => '2026-10-01', 'scadenza' => '2027-09-30', 'protocollo' => ' 7/26 ', 'note' => 'n',
            'docenti' => [['nome' => 'D', 'email' => 'BAD'], ['nome' => '', 'email' => ''], ['nome' => 'E', 'email' => 'E@X.IT']], 'file_convenzione' => 'uploads/convenzioni/f.pdf',
        ], 0, 'admin@x');
        $this->assertNotNull($esito);
        [$id, $aggiornate] = $esito;
        $this->assertSame(0, $aggiornate);
        $r = $this->riga('convenzioni_scuole', $id);
        $this->assertSame('CSPS00002B', $r['scuola_codice']);
        $this->assertSame('7/26', $r['protocollo']);
        $this->assertSame('admin@x', $r['registrata_da']);
        $this->assertSame('uploads/convenzioni/f.pdf', $r['file_convenzione']);
        $this->assertNull($r['file_allegato']);
        $this->assertSame([['nome' => 'D', 'email' => ''], ['nome' => 'E', 'email' => 'e@x.it']], json_decode($r['docenti_json'], true), 'senza riga vuota, email minuscola e solo se valida');

        // Modifica: i file non indicati restano, l'avviso di scadenza si riarma
        $this->db->esegui('UPDATE convenzioni_scuole SET avviso_scadenza_inviato = 1 WHERE id = ?', [$id]);
        $this->assertSame([$id, 0], $s->salva(['scuola_codice' => 'CSPS00002B', 'data_stipula' => '2026-10-01', 'scadenza' => '2028-09-30', 'protocollo' => 'bis'], $id));
        $r = $this->riga('convenzioni_scuole', $id);
        $this->assertSame('2028-09-30', $r['scadenza']);
        $this->assertSame('uploads/convenzioni/f.pdf', $r['file_convenzione']);
        $this->assertSame(0, (int) $r['avviso_scadenza_inviato']);
        $this->assertNull($r['docenti_json']);
        $this->assertSame('admin@x', $r['registrata_da'], "l'autore resta quello della registrazione");
    }

    public function testLaConvenzioneRegistrataCopreLePrenotazioniInAttesaEAvvisaLaScuola(): void
    {
        $inAttesa = $this->pren(200, ['stato' => 'da_approvare', 'convenzione' => 'no', 'email' => 'anna@prova.it']);
        $dichiarata = $this->pren(200, ['stato' => 'confermata', 'convenzione' => 'si', 'email' => 'bea@prova.it']);
        $altraScuola = $this->pren(200, ['scuola_codice' => 'CSPS00002B', 'convenzione' => 'no']);
        $gia = $this->pren(200, ['convenzione' => 'ricevuta']);

        $esito = $this->servizioConvenzioni()->registra('CSPS00001A', '2026-09-03', '2027-09-02', 'prot', 'nota', 'admin@x');
        $this->assertSame(2, $esito[1], 'in attesa e dichiarata');
        $this->assertSame(['confermata', 'ricevuta'], [$this->riga('prenotazioni', $inAttesa)['stato'], $this->riga('prenotazioni', $inAttesa)['convenzione']], 'turno senza approvazione: conferma');
        $this->assertSame('ricevuta', $this->riga('prenotazioni', $dichiarata)['convenzione']);
        $this->assertSame('no', $this->riga('prenotazioni', $altraScuola)['convenzione']);
        $this->assertSame('ricevuta', $this->riga('prenotazioni', $gia)['convenzione']);

        $this->assertCount(1, $this->mailer->inviate, "solo chi aspettava la convenzione ('no') riceve l'email");
        $mail = $this->mailer->inviate[0];
        $this->assertSame('anna@prova.it', $mail['a']);
        $this->assertSame('Convenzione ricevuta: Geologia sul campo', $mail['oggetto']);
        $this->assertStringContainsString('<strong>CONFERMATA</strong>', $mail['corpo']);
        $this->assertStringContainsString('https://portale.test/eventi/stampa_ricevuta.php?code=FS-1', $mail['corpo']);
        $this->assertSame('#112233', $mail['colore']);
    }

    public function testSegnaRicevutaNonConfermaSeIlTurnoRichiedeApprovazione(): void
    {
        $this->db->esegui('UPDATE turni SET richiede_approvazione = 1 WHERE id = 200');
        $p = $this->pren(200, ['stato' => 'da_approvare', 'convenzione' => 'no']);
        $this->assertTrue($this->servizioConvenzioni()->segnaRicevuta($p));
        $this->assertSame(['da_approvare', 'ricevuta'], [$this->riga('prenotazioni', $p)['stato'], $this->riga('prenotazioni', $p)['convenzione']]);
        $this->assertStringContainsString('resta in valutazione degli organizzatori', $this->mailer->inviate[0]['corpo']);
        $this->assertFalse($this->servizioConvenzioni()->segnaRicevuta($p), 'già ricevuta');
        $this->assertFalse($this->servizioConvenzioni()->segnaRicevuta(99999));
        $this->assertCount(1, $this->mailer->inviate);

        $senzaEmail = $this->pren(200, ['stato' => 'in_attesa', 'convenzione' => 'no']);
        $this->assertTrue($this->servizioConvenzioni()->segnaRicevuta($senzaEmail, false));
        $this->assertCount(1, $this->mailer->inviate, 'senza email se non richiesta');
    }

    public function testRichiestaDellaConvenzioneConTestoEModelli(): void
    {
        $p = $this->pren(200, ['stato' => 'da_approvare', 'convenzione' => 'no', 'email' => 'docente@prova.it', 'nome' => 'Marta', 'cognome' => 'Rossi']);
        $this->assertTrue($this->servizioConvenzioni()->richiedi($p));
        $m = $this->mailer->inviate[0];
        $this->assertSame('docente@prova.it', $m['a']);
        $this->assertSame('Convenzione con il Dipartimento - Geologia sul campo', $m['oggetto']);
        $this->assertStringContainsString('Gentile <strong>Marta Rossi</strong>', $m['corpo']);
        $this->assertStringContainsString('dal 13/10/2026 al 02/12/2026', $m['corpo']);
        $this->assertStringContainsString('(Edizione 1)', $m['corpo']);
        $this->assertStringContainsString('Codice della prenotazione: <strong>FS-1</strong>', $m['corpo']);
        $this->assertStringContainsString('convenzione_online.php?code=FS-1', $m['corpo']);

        $this->assertTrue($this->servizioConvenzioni()->richiedi($p, 'promemoria'));
        $this->assertSame('Promemoria: convenzione da inviare - Geologia sul campo', $this->mailer->inviate[1]['oggetto']);
        $this->assertStringContainsString('non abbiamo ancora ricevuto la convenzione', $this->mailer->inviate[1]['corpo']);

        $this->assertFalse($this->servizioConvenzioni()->richiedi(99999));
        $senzaMail = $this->pren(200, ['email' => 'non-valida']);
        $this->assertFalse($this->servizioConvenzioni()->richiedi($senzaMail));
        $this->assertCount(2, $this->mailer->inviate);
    }

    public function testLaRichiestaRicordaUnaConvenzioneCheNonCopreIlPeriodo(): void
    {
        $this->convenzione('CSPS00001A', '2025-01-01', '2026-11-02');
        $p = $this->pren(200, ['convenzione' => 'no']);
        $this->servizioConvenzioni()->richiedi($p);
        $corpo = $this->mailer->inviate[0]['corpo'];
        $this->assertStringContainsString('valida dal 01/01/2025 al 02/11/2026', $corpo);
        $this->assertStringContainsString('non copre il periodo dell\'attività', $corpo);
        $this->assertStringContainsString('nuova convenzione', $corpo);
    }

    public function testRicevutaDaGestoreRegistraLaConvenzioneMancante(): void
    {
        $p = $this->pren(200, ['convenzione' => 'no', 'stato' => 'in_attesa']);
        $altra = $this->pren(201, ['convenzione' => 'no', 'stato' => 'in_attesa']);
        $this->assertTrue($this->servizioConvenzioni()->ricevutaDaGestore($p, 'gestore@x'));
        $conv = $this->db->riga('SELECT * FROM convenzioni_scuole');
        $this->assertSame('CSPS00001A', $conv['scuola_codice']);
        $this->assertSame('2026-10-05', $conv['data_stipula'], "da oggi, per un anno e fino alla fine dell'attività");
        $this->assertSame('2027-10-04', $conv['scadenza']);
        $this->assertSame('gestore@x', $conv['registrata_da']);
        $this->assertStringContainsString('Registrata alla conferma della prenotazione FS-1', $conv['note']);
        $this->assertSame('ricevuta', $this->riga('prenotazioni', $altra)['convenzione'], 'vale anche per le altre prenotazioni della scuola');

        $this->assertTrue($this->servizioConvenzioni()->ricevutaDaGestore($altra, 'gestore@x'), 'già ricevuta: conta lo stato finale');
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_scuole'), 'nessuna seconda registrazione');
        $this->assertFalse($this->servizioConvenzioni()->ricevutaDaGestore(99999));
    }

    public function testRicevutaDaGestorePerScuolaScrittaAMano(): void
    {
        $p = $this->pren(200, ['scuola_codice' => null, 'convenzione' => 'no']);
        $this->assertTrue($this->servizioConvenzioni()->ricevutaDaGestore($p));
        $this->assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM convenzioni_scuole'), 'senza codice non si registra nulla');
        $this->assertSame('ricevuta', $this->riga('prenotazioni', $p)['convenzione']);
    }

    public function testVerificaDelleIscrizioniFsl(): void
    {
        $this->convenzione('CSPS00001A', '2026-09-03', '2027-09-02');
        $coperta = $this->pren(200, ['stato' => 'da_approvare', 'convenzione' => 'no']);
        $daStipulare = $this->pren(200, ['scuola_codice' => 'CSTF00003C', 'convenzione' => null]);
        $giaSegnata = $this->pren(201, ['scuola_codice' => 'CSTF00003C', 'convenzione' => 'no']);
        $aMano = $this->pren(200, ['scuola_codice' => null, 'convenzione' => null]);
        $manoRicevuta = $this->pren(200, ['scuola_codice' => null, 'convenzione' => 'ricevuta']);
        $annullata = $this->pren(200, ['scuola_codice' => 'CSTF00003C', 'stato' => 'annullata', 'convenzione' => null]);
        // Un evento non FSL con la convenzione chiesta: si aggiorna solo se arriva, lo stato resta com'è
        $this->db->esegui('UPDATE progetti_dettagli SET convenzione = 0 WHERE evento_id = 10');
        $altro = $this->pren(100, ['scuola_codice' => 'CSTF00003C', 'convenzione' => 'si']);

        $this->assertSame(['coperte' => 1, 'da_stipulare' => 2, 'nuove_da_stipulare' => 1, 'senza_codice' => 1], $this->servizioConvenzioni()->verifica());
        $this->assertSame(['confermata', 'ricevuta'], [$this->riga('prenotazioni', $coperta)['stato'], $this->riga('prenotazioni', $coperta)['convenzione']]);
        $r = $this->riga('prenotazioni', $daStipulare);
        $this->assertSame(['no', 3], [$r['convenzione'], (int) $r['conv_promemoria']], 'da stipulare, senza promemoria automatici');
        $this->assertSame('no', $this->riga('prenotazioni', $giaSegnata)['convenzione']);
        $this->assertNull($this->riga('prenotazioni', $aMano)['convenzione']);
        $this->assertSame('ricevuta', $this->riga('prenotazioni', $manoRicevuta)['convenzione']);
        $this->assertNull($this->riga('prenotazioni', $annullata)['convenzione']);
        $this->assertSame('si', $this->riga('prenotazioni', $altro)['convenzione']);
    }

    public function testApplicaSoloAlleConvenzioniCheCoprono(): void
    {
        $this->convenzione('CSPS00001A', '2026-10-14', '2027-09-02');
        $p = $this->pren(200, ['convenzione' => 'no']);
        $this->assertSame(0, $this->servizioConvenzioni()->applicaAllaScuola('CSPS00001A'), 'inizia dopo l\'attività');
        $this->assertSame('no', $this->riga('prenotazioni', $p)['convenzione']);
    }
}
