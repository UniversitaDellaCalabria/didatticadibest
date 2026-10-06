<?php

declare(strict_types=1);

namespace Tests\Integration\Iscrizioni;

use App\Iscrizioni\CaptchaPrenotazione;
use App\Iscrizioni\EsitoPrenotazione;
use App\Iscrizioni\RichiestaPrenotazione;
use App\Iscrizioni\ServizioPrenotazioni;

/** Prenotazione dal modulo pubblico di un'area: controlli, anti-robot, ultimo posto, lista d'attesa, convenzioni, email. */
final class PrenotazioneTest extends IscrizioniBase
{
    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $pagina
     */
    private function prenota(int $turno, string $email = 'ada@prova.it', array $post = [], ?int $utente = 5, int $posti = 1, array $pagina = [], int $ruolo = 5, string $nome = 'Ada'): EsitoPrenotazione
    {
        $r = new RichiestaPrenotazione(
            $pagina + ['id' => 1, 'slug' => 'openlab', 'limite_iscrizioni' => 'nessuno'],
            'openlab',
            $turno,
            $nome,
            'Lovelace',
            $email,
            (string) ($post['matricola'] ?? ''),
            $posti,
            $utente,
            $ruolo,
            [],
            $post,
            [],
            '10.0.0.1',
            'http://prova.it/eventi'
        );

        return $this->servizio(ServizioPrenotazioni::class)->prenota($r);
    }

    /** Campi della domanda di controllo risolta (e atteso il tempo minimo) per chi prenota senza accesso. @return array<string, string> */
    private function rispostaGiusta(): array
    {
        $d = (new CaptchaPrenotazione($this->sessione, $this->orologio))->domanda();
        preg_match('/(\d) \+ (\d)/', $d['domanda'], $m);
        $this->orologio->avanza('+10 seconds');

        return ['captcha_id' => $d['id'], 'captcha_risposta' => (string) ((int) $m[1] + (int) $m[2])];
    }

    /** @return array<string, mixed>|null */
    private function riga(string $email): ?array
    {
        return $this->db->riga('SELECT * FROM prenotazioni WHERE email = ? ORDER BY id DESC LIMIT 1', [$email]);
    }

    private function codice(EsitoPrenotazione $e): string
    {
        $this->assertSame(1, preg_match('/code=([A-Z]{2}-[0-9A-F]{8})/', $e->destinazione, $m), $e->destinazione);

        return $m[1];
    }

    public function testPrenotazioneConfermataConEmailAChiPrenotaEAiGestori(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 5, 'nome_turno' => 'Gruppo A']], ['email_notifiche_extra' => 'extra@prova.it']);
        $e = $this->prenota($t, 'ada@prova.it', ['matricola' => 'M1']);
        $codice = $this->codice($e);
        $this->assertStringStartsWith('openlab.php?status=success&code=OP-', $e->destinazione);
        $this->assertStringEndsWith($codice, $e->destinazione, 'confermata: nessun parametro di stato');
        $this->assertNull($e->erroreSessione);

        $r = $this->riga('ada@prova.it');
        $this->assertSame('confermata', $r['stato']);
        $this->assertSame($codice, $r['codice_prenotazione']);
        $this->assertSame(5, $r['utente_id']);
        $this->assertSame('M1', $r['matricola']);
        $this->assertNull($r['dati_custom_json']);

        $this->assertCount(2, $this->mailer->inviate);
        $utente = $this->mailer->inviate[0];
        $this->assertSame('ada@prova.it', $utente['a']);
        $this->assertSame('Conferma Prenotazione Eventi', $utente['oggetto']);
        $this->assertStringContainsString('Gentile <strong>Ada Lovelace</strong>', $utente['corpo']);
        $this->assertStringContainsString('Prenotazione confermata per <strong>Evento 10</strong>', $utente['corpo']);
        $this->assertStringContainsString('📅 Gruppo A · 01/03/2999 | 🕒 10:00–12:00', $utente['corpo']);
        $this->assertStringContainsString("http://prova.it/eventi/stampa_ricevuta.php?code=$codice", $utente['corpo']);
        $this->assertStringContainsString('Aggiungi a Google Calendar', $utente['corpo']);
        $this->assertStringContainsString('http://prova.it/eventi/genera_ics.php?t_id=' . $t, $utente['corpo']);
        $this->assertSame('#0056B3', $utente['colore']);
        $gestore = $this->mailer->inviate[1];
        $this->assertSame('extra@prova.it', $gestore['a']);
        $this->assertSame('Nuova prenotazione (1 posto): Evento 10', $gestore['oggetto']);
        $this->assertStringContainsString('È stata registrata una nuova prenotazione per l\'evento <strong>Evento 10</strong>', $gestore['corpo']);
        $this->assertStringNotContainsString('Apri gli iscritti', $gestore['corpo'], 'gli indirizzi aggiuntivi non hanno accesso al pannello');
    }

    public function testModelliDellEmailDiConfermaDelPortale(): void
    {
        $this->db->esegui("UPDATE impostazioni_sistema SET email_conferma_oggetto = 'Prenotato {NOME}', email_conferma_corpo = 'Ciao {NOME} {COGNOME} ({MATRICOLA}), {TITOLO_EVENTO} il {DATA_TURNO} alle {ORARIO_TURNO} a {LUOGO}: {CODICE_PRENOTAZIONE} {LINK_RICEVUTA}' WHERE id = 1");
        [$t] = $this->evento(10, [['max_posti' => 5, 'data_turno' => null, 'orario_inizio' => null, 'nome_turno' => 'Solo nome']]);
        $codice = $this->codice($this->prenota($t, 'ada@prova.it', ['matricola' => 'M7']));
        $m = $this->mailer->inviate[0];
        $this->assertSame('Prenotato Ada', $m['oggetto']);
        $this->assertStringStartsWith("Ciao Ada Lovelace (M7), Evento 10 il Solo nome alle da definire a Aula: $codice <p style='margin-top:15px;'><a href='http://prova.it/eventi/stampa_ricevuta.php?code=$codice'", $m['corpo']);
        $this->assertStringNotContainsString('Google Calendar', $m['corpo'], 'turno senza data: niente pulsanti del calendario');
    }

    public function testUltimoPostoEPoiEsaurito(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 1]]);
        $primo = $this->prenota($t, 'uno@prova.it');
        $this->codice($primo);
        $secondo = $this->prenota($t, 'due@prova.it');
        $this->assertSame('openlab.php?status=full', $secondo->destinazione);
        $this->assertNull($this->riga('due@prova.it'), 'la transazione è annullata: nessuna riga');
        $this->assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM prenotazioni WHERE turno_id = ?', [$t]));
        $this->assertCount(1, array_filter($this->mailer->inviate, fn ($m) => $m['a'] === 'uno@prova.it'));
        $this->assertCount(0, array_filter($this->mailer->inviate, fn ($m) => $m['a'] === 'due@prova.it'));
    }

    public function testListaDAttesaQuandoIlTurnoEPieno(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 2, 'abilita_lista_attesa' => 1, 'abilita_multi_posto' => 1]]);
        $this->codice($this->prenota($t, 'a@prova.it', [], 5, 2));
        $e = $this->prenota($t, 'b@prova.it');
        $this->assertStringEndsWith('&st_tipo=attesa', $e->destinazione);
        $this->assertSame('in_attesa', $this->riga('b@prova.it')['stato']);
        $mail = array_values(array_filter($this->mailer->inviate, fn ($m) => $m['a'] === 'b@prova.it'))[0];
        $this->assertSame("Lista d'Attesa: Evento 10", $mail['oggetto']);
        $this->assertStringContainsString("Sei stato inserito in <strong>lista d'attesa</strong>", $mail['corpo']);
        $this->assertStringContainsString('Aggiungi a Google Calendar', $mail['corpo']);
    }

    public function testPiuPostiDellaCapienzaFinisconoInAttesa(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 4, 'abilita_lista_attesa' => 1, 'abilita_multi_posto' => 1]]);
        $this->prenota($t, 'a@prova.it', [], 5, 3);
        $e = $this->prenota($t, 'b@prova.it', [], 5, 2);
        $this->assertStringContainsString('st_tipo=attesa', $e->destinazione);
        $this->assertSame(2, $this->riga('b@prova.it')['num_posti']);
        $this->assertSame(3, $this->servizio(\App\Iscrizioni\PrenotazioneRepository::class)->postiOccupati($t));
    }

    public function testTurnoConApprovazione(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 5, 'richiede_approvazione' => 1]]);
        $e = $this->prenota($t, 'a@prova.it', [], 5, 1);
        $this->assertStringEndsWith('&st_tipo=approvare', $e->destinazione);
        $this->assertSame('da_approvare', $this->riga('a@prova.it')['stato']);
        $this->assertSame('Richiesta Ricevuta (In valutazione): Evento 10', $this->mailer->inviate[0]['oggetto']);
        $this->assertStringContainsString('La tua richiesta per <strong>1 posti</strong>', $this->mailer->inviate[0]['corpo']);
    }

    public function testControlliSuiDatiInviati(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 5]]);
        $this->assertSame('openlab.php?status=error', $this->prenota(99999)->destinazione, 'turno inesistente');
        $this->assertSame('openlab.php?status=error', $this->prenota($t, '', [], 5, 1, [], 5)->destinazione, 'email mancante');
        $this->assertSame('openlab.php?status=error', $this->prenota($t, 'a@prova.it', [], 5, 1, [], 5, '')->destinazione, 'nome mancante');
        $e = $this->prenota($t, 'non-valida');
        $this->assertSame('openlab.php?status=email', $e->destinazione);
        $this->assertSame('Indirizzo email non valido.', $e->erroreSessione);
        $e = $this->prenota($t, 'a@prova.it', ['email_conferma' => 'altra@prova.it']);
        $this->assertSame('Le due email non coincidono: riscrivile con attenzione.', $e->erroreSessione);
        $this->assertStringStartsWith('openlab.php?status=success', $this->prenota($t, 'a@prova.it', ['email_conferma' => ' A@prova.it '])->destinazione, 'la ripetizione si confronta senza maiuscole e spazi');
        $this->assertSame('openlab.php?status=dup', $this->prenota($t, 'a@prova.it')->destinazione, 'stessa email');
        $this->prenota($t, 'x@prova.it', ['matricola' => 'M5']);
        $this->assertSame('openlab.php?status=dup', $this->prenota($t, 'y@prova.it', ['matricola' => 'M5'])->destinazione, 'stessa matricola');
    }

    public function testTurnoDiUnAltraAreaOArchiviato(): void
    {
        $this->db->esegui("INSERT INTO pagine_eventi (id, slug, titolo) VALUES (2, 'fsl', 'FSL')");
        [$altra] = $this->evento(11, [['max_posti' => 5]], ['pagina_id' => 2]);
        [$archiviato] = $this->evento(12, [['max_posti' => 5]], ['archiviato' => 1]);
        $this->assertSame('openlab.php?status=error', $this->prenota($altra)->destinazione);
        $this->assertSame('openlab.php?status=error', $this->prenota($archiviato)->destinazione);
    }

    public function testRitornoAllaSchedaDelProgettoOdellEvento(): void
    {
        [$ev] = $this->evento(10, [['max_posti' => 5]]);
        [$prog] = $this->evento(11, [['max_posti' => 5]], ['tipo' => 'progetto']);
        $this->assertStringStartsWith('openlab.php?evento=10&status=success', $this->prenota($ev, 'a@prova.it', ['da_scheda' => '10'])->destinazione);
        $this->assertStringStartsWith('openlab.php?status=success', $this->prenota($ev, 'b@prova.it', ['da_scheda' => '99'])->destinazione);
        $this->db->esegui('INSERT INTO progetti_dettagli (evento_id, per_scuole) VALUES (11, 0)');
        $this->assertStringStartsWith('openlab.php?progetto=11&status=success', $this->prenota($prog, 'c@prova.it')->destinazione);
    }

    public function testFinestraDiPrenotazioneEAccessoRiservato(): void
    {
        [$chiuso, $nonAperto, $riservato, $libero] = [
            ...$this->evento(10, [['max_posti' => 5, 'data_chiusura' => '2026-10-01 10:00:00']]),
            ...$this->evento(11, [['max_posti' => 5, 'data_apertura' => '2026-10-10 10:00:00']]),
            ...$this->evento(12, [['max_posti' => 5]], ['ruolo_accesso_id' => 4]),
            ...$this->evento(13, [['max_posti' => 5]], ['richiede_prenotazione' => 0]),
        ];
        $this->assertSame('openlab.php?status=closed', $this->prenota($chiuso)->destinazione);
        $this->assertSame('openlab.php?status=notopened', $this->prenota($nonAperto)->destinazione);
        $this->assertSame('openlab.php?status=riservato', $this->prenota($riservato, 'a@prova.it', [], 5, 1, [], 5)->destinazione, 'ruolo diverso');
        $this->assertSame('openlab.php?status=riservato', $this->prenota($riservato, 'b@prova.it', $this->rispostaGiusta(), null)->destinazione, 'senza accesso (con la domanda di controllo risolta)');
        $this->assertStringContainsString('status=success', $this->prenota($riservato, 'c@prova.it', [], 5, 1, [], 4)->destinazione, 'ruolo giusto');
        $this->assertStringContainsString('status=success', $this->prenota($riservato, 'd@prova.it', [], 1, 1, [], 1)->destinazione, "l'amministratore può sempre");
        $this->assertSame('openlab.php?status=error', $this->prenota($libero)->destinazione, 'evento senza prenotazione');
    }

    public function testControlloAntiRobotPerChiPrenotaSenzaAccesso(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 5]]);
        $captcha = $this->servizio(CaptchaPrenotazione::class);

        $e = $this->prenota($t, 'a@prova.it', ['sito_web' => 'http://spam'], null);
        $this->assertSame('openlab.php?status=captcha', $e->destinazione);
        $this->assertSame('Prenotazione non registrata: riprova.', $e->erroreSessione);

        $e = $this->prenota($t, 'a@prova.it', [], null);
        $this->assertStringContainsString('scaduta', (string) $e->erroreSessione, 'senza domanda');

        $d = $captcha->domanda();
        $e = $this->prenota($t, 'a@prova.it', ['captcha_id' => $d['id'], 'captcha_risposta' => '5'], null);
        $this->assertStringContainsString('troppo in fretta', (string) $e->erroreSessione);

        $e = $this->prenota($t, 'a@prova.it', $this->rispostaGiusta(), null);
        $this->assertNull($e->erroreSessione, 'risposta giusta');
        $this->codice($e);
        $this->assertNull($this->riga('a@prova.it')['utente_id']);
        $this->assertSame(1, (int) $this->db->valore("SELECT COUNT(*) FROM rate_limit_attempts WHERE endpoint = 'prenotazione_pubblica'") - 2, 'ogni tentativo senza accesso si conta (il primo, con la trappola, no)');
    }

    public function testLimiteDiInvioPerIndirizzoIp(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 5]]);
        $hash = hash('sha256', '10.0.0.1' . 'prenotazione_pubblica');
        for ($i = 0; $i < 20; ++$i) {
            $this->db->esegui("INSERT INTO rate_limit_attempts (ip_hash, endpoint, hit_at) VALUES (?, 'prenotazione_pubblica', NOW())", [$hash]);
        }
        $e = $this->prenota($t, 'a@prova.it', [], null);
        $this->assertSame("Troppe prenotazioni da questa connessione: riprova tra un'ora o accedi con SPID/CIE.", $e->erroreSessione);
        $this->assertNull($this->riga('a@prova.it'));
    }

    public function testUnaSolaIscrizionePerAreaConBloccoPerPersona(): void
    {
        [$a] = $this->evento(10, [['max_posti' => 5]]);
        [$b] = $this->evento(11, [['max_posti' => 5]]);
        $pagina = ['limite_iscrizioni' => 'un_evento'];
        $this->codice($this->prenota($a, 'a@prova.it', [], 5, 1, $pagina));
        $e = $this->prenota($b, 'a@prova.it', [], 5, 1, $pagina);
        $this->assertSame('openlab.php?status=limite&ev=' . urlencode('Evento 10'), $e->destinazione);
        $this->assertNull($this->db->riga('SELECT id FROM prenotazioni WHERE turno_id = ?', [$b]));
        $this->assertStringContainsString('status=success', $this->prenota($b, 'altro@prova.it', [], 6, 1, $pagina)->destinazione, 'un\'altra persona può');
    }

    public function testConfermaDecadeLeAltreRichiesteDellaPersona(): void
    {
        $this->db->esegui("UPDATE pagine_eventi SET limite_iscrizioni = 'un_evento' WHERE id = 1");
        [$piena] = $this->evento(10, [['max_posti' => 1, 'abilita_lista_attesa' => 1]]);
        [$libera] = $this->evento(11, [['max_posti' => 5]]);
        $this->prenota($piena, 'x@prova.it', [], 6, 1, ['limite_iscrizioni' => 'un_evento']);
        $e = $this->prenota($piena, 'a@prova.it', [], 5, 1, ['limite_iscrizioni' => 'un_evento']);
        $this->assertStringContainsString('st_tipo=attesa', $e->destinazione);
        $this->prenota($libera, 'a@prova.it', [], 5, 1, ['limite_iscrizioni' => 'un_evento']);
        $this->assertSame('annullata', $this->db->valore("SELECT stato FROM prenotazioni WHERE email = 'a@prova.it' AND turno_id = ?", [$piena]));
        $this->assertSame("Liste d'attesa annullate: iscrizione confermata a Evento 11", array_values(array_filter($this->mailer->inviate, fn ($m) => str_starts_with($m['oggetto'], "Liste d'attesa annullate")))[0]['oggetto']);
    }

    public function testPrenotazioneDiClasseNumeroDiStudentiEConvenzione(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 5, 'min_partecipanti' => 8, 'max_partecipanti' => 25]]);
        $this->db->esegui('INSERT INTO progetti_dettagli (evento_id, per_scuole, attestati, convenzione) VALUES (10, 1, 1, 1)');

        $e = $this->prenota($t, 'a@prova.it', ['convenzione' => 'si']);
        $this->assertSame('openlab.php?status=studenti', $e->destinazione);
        $this->assertSame('Indica il numero di studenti partecipanti.', $e->erroreSessione);
        $e = $this->prenota($t, 'a@prova.it', ['custom_numero_partecipanti' => '30', 'convenzione' => 'si']);
        $this->assertSame('Il numero di studenti deve essere compreso tra 8 e 25.', $e->erroreSessione);
        $e = $this->prenota($t, 'a@prova.it', ['custom_numero_partecipanti' => '12']);
        $this->assertSame('Indica se la scuola ha già stipulato la convenzione con il Dipartimento.', $e->erroreSessione);
        $this->assertNull($this->riga('a@prova.it'));

        $e = $this->prenota($t, 'a@prova.it', ['custom_numero_partecipanti' => '12', 'convenzione' => 'no']);
        $this->assertStringEndsWith('&st_tipo=convenzione', $e->destinazione);
        $r = $this->riga('a@prova.it');
        $this->assertSame('da_approvare', $r['stato']);
        $this->assertSame('no', $r['convenzione']);
        $this->assertSame('{"numero_partecipanti":"12"}', $r['dati_custom_json']);
        $this->assertSame('Prenotazione in attesa della convenzione: Evento 10', $this->mailer->inviate[0]['oggetto']);
        $this->assertStringContainsString('[istruzioni convenzione email OP-', $this->mailer->inviate[0]['corpo']);

        $e = $this->prenota($t, 'b@prova.it', ['custom_numero_partecipanti' => '10', 'convenzione' => 'si']);
        $this->assertStringContainsString('status=success', $e->destinazione);
        $this->assertSame('confermata', $this->riga('b@prova.it')['stato']);
        $this->assertSame('si', $this->riga('b@prova.it')['convenzione']);
        $mail = array_values(array_filter($this->mailer->inviate, fn ($m) => $m['a'] === 'b@prova.it'))[0];
        $this->assertStringContainsString('inserisci il loro elenco (cognome e nome)', $mail['corpo'], 'attestati per la classe');
        $this->assertStringContainsString('http://prova.it/eventi/elenco_studenti.php?code=', $mail['corpo']);
    }

    public function testProgettoUnaSolaEdizioneEListaDAttesa(): void
    {
        [$e1, $e2] = $this->evento(10, [['max_posti' => 1, 'abilita_lista_attesa' => 1], ['max_posti' => 1, 'abilita_lista_attesa' => 1]], ['tipo' => 'progetto']);
        $this->db->esegui('INSERT INTO progetti_dettagli (evento_id, per_scuole, min_studenti, max_studenti) VALUES (10, 1, 5, 30)');
        $post = ['custom_numero_partecipanti' => '15'];
        $this->codice($this->prenota($e1, 'a@prova.it', $post, 5));
        $this->assertSame('openlab.php?progetto=10&status=altra_edizione', $this->prenota($e2, 'a@prova.it', $post, 5)->destinazione, 'stessa persona su un\'altra edizione');
        $this->assertSame('openlab.php?progetto=10&status=altra_edizione', $this->prenota($e2, 'a@prova.it', $post + $this->rispostaGiusta(), null)->destinazione, 'anche senza accesso, per email');
        $e = $this->prenota($e1, 'b@prova.it', $post, 6);
        $this->assertSame('openlab.php?progetto=10&status=success&code=' . $this->codice($e) . '&st_tipo=attesa', $e->destinazione);
    }

    public function testBloccoPerPersonaEAreaRestaPresoComePrima(): void
    {
        [$a] = $this->evento(10, [['max_posti' => 5]]);
        $pagina = ['limite_iscrizioni' => 'un_evento'];
        $this->codice($this->prenota($a, 'a@prova.it', [], 5, 1, $pagina));
        $nome = 'dibest_iscr_1_' . md5('a@prova.it');
        $this->assertSame(1, (int) $this->db->valore('SELECT IS_FREE_LOCK(?)', [$nome]), 'a fine prenotazione il blocco è rilasciato');
    }
}
