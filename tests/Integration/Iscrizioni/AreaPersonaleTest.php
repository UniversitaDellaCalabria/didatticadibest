<?php

declare(strict_types=1);

namespace Tests\Integration\Iscrizioni;

use App\Iscrizioni\ServizioAreaPersonale;
use App\Iscrizioni\ServizioCheckin;

/** Area personale (elenco, messaggi, modifica e cambio turno, annullamento, posto offerto) e check-in con il QR del turno. */
final class AreaPersonaleTest extends IscrizioniBase
{
    private const EMAIL = 'luca@prova.it';
    private const BASE = 'http://prova.it/eventi';

    private function area(): ServizioAreaPersonale
    {
        return $this->servizio(ServizioAreaPersonale::class);
    }

    private function mia(int $turno, string $stato = 'confermata', array $extra = []): int
    {
        return $this->pren($turno, $stato, $extra + ['utente_id' => 4, 'email' => self::EMAIL, 'nome' => 'Luca', 'cognome' => 'Insegnante']);
    }

    public function testElencoConTurniAlternativiEPostiLiberi(): void
    {
        [$a, $b, $c] = $this->evento(10, [['max_posti' => 2, 'data_apertura' => null], ['max_posti' => 1, 'data_turno' => '2999-04-01'], ['max_posti' => 3, 'data_turno' => null, 'orario_inizio' => null, 'orario_fine' => null]]);
        [$passato] = $this->evento(11, [['data_turno' => '2000-01-01']]);
        $mia = $this->mia($a);
        $this->pren($b, 'confermata');
        $this->pren($a, 'in_attesa');
        $this->mia($passato);
        $this->pren($a, 'confermata', ['utente_id' => 99, 'email' => 'estraneo@prova.it']);

        $r = $this->area()->prenotazioni(4, self::EMAIL);
        $this->assertCount(1, $r['attive']);
        $this->assertCount(1, $r['passate']);
        $att = $r['attive'][0];
        $this->assertSame($mia, $att['id']);
        $this->assertSame('Evento 10', $att['evento_titolo']);
        $this->assertArrayNotHasKey('_is_attiva', $att);
        $alt = array_column($att['turni_alternativi'], null, 'id');
        $this->assertSame([$a, $b, $c], array_keys($alt), 'turni futuri: con data, poi senza data');
        $this->assertSame(0, $alt[$a]['posti_liberi'], 'conta solo le confermate: 2 posti, 2 occupati (la mia e quella di un altro)');
        $this->assertSame(0, $alt[$b]['posti_liberi']);
        $this->assertSame(3, $alt[$c]['posti_liberi']);
        $this->assertFalse($alt[$a]['is_closed']);
        $this->assertSame('Evento 11', $r['passate'][0]['evento_titolo']);
        $this->assertSame([], $r['passate'][0]['turni_alternativi']);
    }

    public function testTurniAlternativiChiusiEnonAperti(): void
    {
        [$a, $b, $c] = $this->evento(10, [['max_posti' => 5], ['max_posti' => 5, 'data_chiusura' => '2026-10-01 10:00:00'], ['max_posti' => 5, 'data_apertura' => '2999-01-01 10:00:00']]);
        $this->mia($a);
        $alt = array_column($this->area()->prenotazioni(4, self::EMAIL)['attive'][0]['turni_alternativi'], null, 'id');
        $this->assertFalse($alt[$a]['is_closed']);
        $this->assertTrue($alt[$b]['is_closed']);
        $this->assertTrue($alt[$c]['is_closed']);
    }

    public function testMessaggioAllaSegreteriaConAvvisoAiGestori(): void
    {
        [$t] = $this->evento(10, [[]], ['gestori_utenti_ids' => '2']);
        $this->db->esegui("INSERT INTO utenti (id, nome, cognome, email) VALUES (2, 'Gestore', 'G', 'gestore@prova.it')");
        $p = $this->mia($t);
        $this->db->esegui("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, messaggio, letto) VALUES (?, 'admin', 'ciao', 0)", [$p]);

        $this->assertFalse($this->area()->inviaMessaggio($p, 4, self::EMAIL, '   '), 'messaggio vuoto');
        $this->assertFalse($this->area()->inviaMessaggio($p, 99, 'estraneo@prova.it', 'ciao'), 'prenotazione altrui');
        $this->assertSame(0, (int) $this->db->valore("SELECT COUNT(*) FROM messaggi_prenotazioni WHERE mittente_tipo = 'utente'"));

        $this->assertTrue($this->area()->inviaMessaggio($p, 4, self::EMAIL, "  Salve <b>a tutti</b>\nsecondo rigo "));
        $m = $this->db->riga("SELECT * FROM messaggi_prenotazioni WHERE mittente_tipo = 'utente'");
        $this->assertSame("Salve &lt;b&gt;a tutti&lt;/b&gt;<br />\nsecondo rigo", $m['messaggio']);
        $this->assertSame(0, $m['letto']);
        $this->assertSame(4, $m['mittente_id']);
        $this->assertSame(1, (int) $this->db->valore("SELECT letto FROM messaggi_prenotazioni WHERE mittente_tipo = 'admin'"), 'i messaggi della segreteria risultano letti');
    }

    public function testModificaDeiDatiSenzaCambioTurno(): void
    {
        [$t] = $this->evento(10, [[]]);
        $p = $this->mia($t, 'confermata', ['dati_custom_json' => json_encode(['allegato' => 'uploads/allegati_prenotazioni/a.pdf', 'classe' => '1A'])]);
        $msg = $this->area()->modifica($p, 4, self::EMAIL, 0, 'Luca', 'Rossi', 'nuova@prova.it', 'M1', ['custom_classe' => ' 2B ', 'altro' => 'x'], self::BASE);
        $this->assertStringContainsString('Prenotazione aggiornata con successo!', $msg);
        $r = $this->db->riga('SELECT * FROM prenotazioni WHERE id = ?', [$p]);
        $this->assertSame('Rossi', $r['cognome']);
        $this->assertSame('nuova@prova.it', $r['email']);
        $this->assertSame('M1', $r['matricola']);
        $this->assertSame('{"allegato":"uploads\/allegati_prenotazioni\/a.pdf","classe":"2B"}', $r['dati_custom_json'], 'gli allegati restano, i campi inviati si aggiornano');
        $this->assertSame($t, $r['turno_id']);
        $this->assertSame([], $this->mailer->inviate);
        $this->assertStringContainsString('Operazione non autorizzata', $this->area()->modifica($p, 99, 'altro@prova.it', 0, 'a', 'b', 'c@d.it', '', [], self::BASE));
    }

    public function testCambioTurnoVersoUnTurnoLiberoPromuoveLaCodaDiQuelloLasciato(): void
    {
        [$da, $a] = $this->evento(10, [['max_posti' => 1, 'abilita_lista_attesa' => 1], ['max_posti' => 5]]);
        $p = $this->mia($da);
        $coda = $this->pren($da, 'in_attesa', ['email' => 'coda@prova.it', 'nome' => 'Coda', 'convenzione' => 'si']);
        $msg = $this->area()->modifica($p, 4, self::EMAIL, $a, 'Luca', 'Insegnante', self::EMAIL, '', [], self::BASE);
        $this->assertStringContainsString('Modifica salvata. Turno aggiornato con successo!', $msg);
        $this->assertSame($a, $this->db->valore('SELECT turno_id FROM prenotazioni WHERE id = ?', [$p]));
        $this->assertSame('confermata', $this->stato($p));
        $this->assertSame('confermata', $this->stato($coda), 'chi era in coda ottiene il posto lasciato');
        $this->assertCount(1, $this->mailer->inviate);
        $this->assertSame('coda@prova.it', $this->mailer->inviate[0]['a']);
        $this->assertSame('Posto Disponibile! Prenotazione CONFERMATA', $this->mailer->inviate[0]['oggetto']);
        $this->assertStringContainsString('http://prova.it/eventi/stampa_ricevuta.php?code=', $this->mailer->inviate[0]['corpo']);
    }

    public function testCambioTurnoVersoUnTurnoPienoConAttesaOSenza(): void
    {
        [$da, $conAttesa, $senza] = $this->evento(10, [['max_posti' => 5], ['max_posti' => 1, 'abilita_lista_attesa' => 1], ['max_posti' => 1, 'abilita_lista_attesa' => 0]]);
        $this->pren($conAttesa, 'confermata');
        $this->pren($senza, 'confermata');
        $p = $this->mia($da, 'confermata', ['num_posti' => 1]);

        $this->assertStringContainsString('Il turno selezionato è esaurito.', $this->area()->modifica($p, 4, self::EMAIL, $senza, 'Luca', 'I', self::EMAIL, '', [], self::BASE));
        $this->assertSame($da, $this->db->valore('SELECT turno_id FROM prenotazioni WHERE id = ?', [$p]), 'nulla cambia, la transazione è annullata');

        $msg = $this->area()->modifica($p, 4, self::EMAIL, $conAttesa, 'Luca', 'I', self::EMAIL, '', [], self::BASE);
        $this->assertStringContainsString("Sei stato inserito in Lista d'Attesa per il nuovo orario.", $msg);
        $this->assertSame('in_attesa', $this->stato($p));
        $this->assertSame($conAttesa, $this->db->valore('SELECT turno_id FROM prenotazioni WHERE id = ?', [$p]));
    }

    public function testCambioTurnoNonPiuConsentitoOltreIlTermineELimitiDiClasse(): void
    {
        [$da, $a] = $this->evento(10, [['max_posti' => 5, 'annullabile_fino' => '2026-10-01 10:00:00'], ['max_posti' => 5]]);
        $p = $this->mia($da);
        $this->assertStringContainsString('Non è più possibile cambiare turno: il termine era il 01/10/2026 alle 10:00.', $this->area()->modifica($p, 4, self::EMAIL, $a, 'L', 'I', self::EMAIL, '', [], self::BASE));
        $this->assertStringContainsString('Prenotazione aggiornata', $this->area()->modifica($p, 4, self::EMAIL, 0, 'L', 'I', self::EMAIL, '', [], self::BASE), 'i dati si possono sempre correggere');

        [$prog] = $this->evento(11, [['max_posti' => 5, 'min_partecipanti' => 8, 'max_partecipanti' => 25]], ['tipo' => 'progetto']);
        $this->db->esegui('INSERT INTO progetti_dettagli (evento_id, per_scuole) VALUES (11, 1)');
        $q = $this->mia($prog);
        $this->assertStringContainsString('Modifica non salvata: Il numero di studenti deve essere compreso tra 8 e 25.', $this->area()->modifica($q, 4, self::EMAIL, 0, 'L', 'I', self::EMAIL, '', ['custom_numero_partecipanti' => '40'], self::BASE));
        $this->assertStringContainsString('Prenotazione aggiornata', $this->area()->modifica($q, 4, self::EMAIL, 0, 'L', 'I', self::EMAIL, '', ['custom_numero_partecipanti' => '20'], self::BASE));
    }

    public function testAnnullamentoLiberaIlPostoAllaCodaEAvvisaGestori(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 1, 'abilita_lista_attesa' => 1, 'nome_turno' => 'T1']], ['email_notifiche_extra' => 'extra@prova.it']);
        $p = $this->mia($t);
        $coda = $this->pren($t, 'in_attesa', ['email' => 'coda@prova.it', 'nome' => 'Coda', 'cognome' => 'Uno', 'matricola' => 'M3', 'convenzione' => 'no']);

        $msg = $this->area()->annulla($p, 4, self::EMAIL, self::BASE);
        $this->assertStringContainsString('Prenotazione annullata correttamente.', $msg);
        $this->assertSame('annullata', $this->stato($p), 'la riga resta nel DB');
        $this->assertSame('da_approvare', $this->stato($coda), 'senza convenzione chi era in coda ottiene il posto ma resta da approvare');

        $mail = $this->mailer->inviate;
        $this->assertSame(['luca@prova.it', 'extra@prova.it', 'coda@prova.it'], array_column($mail, 'a'));
        $this->assertSame('Cancellazione Prenotazione Confermata', $mail[0]['oggetto']);
        $this->assertStringContainsString('La tua prenotazione per l\'evento <strong>Evento 10</strong> è stata cancellata con successo.', $mail[0]['corpo']);
        $this->assertSame('Disdetta: Evento 10', $mail[1]['oggetto']);
        $this->assertStringContainsString('ha appena <strong>annullato</strong>', $mail[1]['corpo']);
        $this->assertSame('Posto Disponibile! Prenotazione CONFERMATA: Evento 10', $mail[2]['oggetto']);
        $this->assertStringContainsString('Ottime notizie <strong>Coda Uno</strong>!', $mail[2]['corpo']);
        $this->assertStringContainsString('Scarica / Stampa Ricevuta PDF', $mail[2]['corpo']);
    }

    public function testAnnullamentoConModelloDelPortale(): void
    {
        $this->db->esegui("UPDATE impostazioni_sistema SET email_canc_utente_oggetto = 'Addio {NOME}', email_canc_utente_corpo = '{TITOLO_EVENTO} {DATA_TURNO} {ORARIO_TURNO} {LUOGO} {CODICE_PRENOTAZIONE}[{LINK_RICEVUTA}]' WHERE id = 1");
        [$t] = $this->evento(10, [['nome_turno' => 'T1']]);
        $p = $this->mia($t, 'confermata', ['codice_prenotazione' => 'COD-1']);
        $this->area()->annulla($p, 4, self::EMAIL, self::BASE);
        $this->assertSame('Addio Luca', $this->mailer->inviate[0]['oggetto']);
        $this->assertSame('Evento 10 T1 · 01/03/2999 10:00–12:00 Aula COD-1[]', $this->mailer->inviate[0]['corpo']);
    }

    public function testAnnullamentoNegatoOEntroIlTermine(): void
    {
        [$t, $scaduto] = $this->evento(10, [[], ['annullabile_fino' => '2026-10-01 10:00:00']]);
        $altrui = $this->pren($t, 'confermata', ['utente_id' => 99, 'email' => 'altro@prova.it']);
        $this->assertStringContainsString('Errore o autorizzazione negata per l\'annullamento.', $this->area()->annulla($altrui, 4, self::EMAIL, self::BASE));
        $this->assertSame('confermata', $this->stato($altrui));

        $bloccata = $this->mia($scaduto);
        $this->assertStringContainsString('Non è più possibile annullare questa prenotazione: il termine era il 01/10/2026 alle 10:00.', $this->area()->annulla($bloccata, 4, self::EMAIL, self::BASE));
        $this->assertSame('confermata', $this->stato($bloccata));
        $attesa = $this->mia($scaduto, 'in_attesa');
        $this->assertStringContainsString('Prenotazione annullata correttamente.', $this->area()->annulla($attesa, 4, self::EMAIL, self::BASE), "chi è in lista d'attesa può sempre uscirne");
        $this->assertSame('annullata', $this->stato($attesa));
    }

    public function testConfermaDelPostoOfferto(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 1, 'abilita_lista_attesa' => 1, 'nome_turno' => 'T1']]);
        $p = $this->mia($t, 'richiesta_conferma', ['scadenza_conferma' => '2026-10-06 12:00:00']);
        $msg = $this->area()->rispondiAlPosto($p, 4, self::EMAIL, true);
        $this->assertStringContainsString('Posto confermato! Ti abbiamo inviato la ricevuta via email.', $msg);
        $this->assertSame('confermata', $this->stato($p));
        $this->assertSame('luca@prova.it', $this->mailer->inviate[0]['a']);
        $this->assertSame('Prenotazione CONFERMATA: Evento 10', $this->mailer->inviate[0]['oggetto']);
        $this->assertStringContainsString('hai confermato il tuo posto per <strong>Evento 10</strong> (T1 · 01/03/2999 · 10:00–12:00)', $this->mailer->inviate[0]['corpo']);
        $this->assertStringContainsString('http://prova.it/eventi/stampa_ricevuta.php?code=', $this->mailer->inviate[0]['corpo']);
        $this->assertStringContainsString('Questa offerta di posto non è più valida.', $this->area()->rispondiAlPosto($p, 4, self::EMAIL, true), 'già confermata');
    }

    public function testConfermaDelPostoDiUnaScuolaSenzaConvenzione(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 1]]);
        $p = $this->mia($t, 'richiesta_conferma', ['scadenza_conferma' => '2999-01-01 00:00:00', 'convenzione' => 'no']);
        $msg = $this->area()->rispondiAlPosto($p, 4, self::EMAIL, true);
        $this->assertSame('da_approvare', $this->stato($p));
        $this->assertStringContainsString("Posto accettato: la prenotazione sarà confermata all'arrivo della convenzione.", $msg);
        $this->assertStringContainsString('[istruzioni convenzione pagina ', $msg);
        $this->assertSame('Posto accettato, in attesa della convenzione: Evento 10', $this->mailer->inviate[0]['oggetto']);
        $this->assertStringContainsString('[istruzioni convenzione email ', $this->mailer->inviate[0]['corpo']);
    }

    public function testRinunciaPassaIlPostoAlProssimoInCoda(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 1, 'abilita_lista_attesa' => 1]]);
        $p = $this->mia($t, 'richiesta_conferma', ['scadenza_conferma' => '2999-01-01 00:00:00']);
        $coda = $this->pren($t, 'in_attesa', ['email' => 'coda@prova.it']);
        $msg = $this->area()->rispondiAlPosto($p, 4, self::EMAIL, false);
        $this->assertStringContainsString('Hai rinunciato al posto. Grazie per averlo lasciato ad altri.', $msg);
        $this->assertSame('annullata', $this->stato($p));
        $this->assertSame('richiesta_conferma', $this->stato($coda));
        $this->assertSame('coda@prova.it', $this->mailer->inviate[0]['a']);
    }

    public function testOffertaScadutaNonValidaOAltrui(): void
    {
        [$t] = $this->evento(10, [['max_posti' => 1]]);
        $scaduta = $this->mia($t, 'richiesta_conferma', ['scadenza_conferma' => '2026-10-04 10:00:00']);
        $this->assertStringContainsString('Il tempo per confermare il posto è scaduto e il posto è stato riassegnato.', $this->area()->rispondiAlPosto($scaduta, 4, self::EMAIL, true));
        $this->assertSame('richiesta_conferma', $this->stato($scaduta), 'nessuna modifica');
        $this->db->esegui("UPDATE prenotazioni SET stato = 'scaduta' WHERE id = ?", [$scaduta]);
        $this->assertStringContainsString('Il tempo per confermare il posto è scaduto', $this->area()->rispondiAlPosto($scaduta, 4, self::EMAIL, true));
        $altrui = $this->pren($t, 'richiesta_conferma', ['utente_id' => 99, 'email' => 'altro@prova.it']);
        $this->assertStringContainsString('Questa offerta di posto non è più valida.', $this->area()->rispondiAlPosto($altrui, 4, self::EMAIL, true));
        $this->assertSame('richiesta_conferma', $this->stato($altrui));
        $this->assertSame([], $this->mailer->inviate);
    }

    public function testRiquadroDelPostoOfferto(): void
    {
        [$t] = $this->evento(10, [[]]);
        $offerta = $this->mia($t, 'richiesta_conferma');
        $confermata = $this->mia($t, 'confermata');
        $scaduta = $this->mia($t, 'scaduta');
        $annullata = $this->mia($t, 'annullata');
        $r = $this->area()->riquadroOfferta($offerta, 4, self::EMAIL);
        $this->assertSame($offerta, $r['riquadro']['id']);
        $this->assertNull($r['messaggio']);
        $this->assertStringContainsString('Questo posto è già confermato.', (string) $this->area()->riquadroOfferta($confermata, 4, self::EMAIL)['messaggio']);
        $this->assertStringContainsString('è scaduto e il posto è stato riassegnato', (string) $this->area()->riquadroOfferta($scaduta, 4, self::EMAIL)['messaggio']);
        $this->assertStringContainsString('Offerta di posto non valida o non più disponibile.', (string) $this->area()->riquadroOfferta($annullata, 4, self::EMAIL)['messaggio']);
        $this->assertNull($this->area()->riquadroOfferta($offerta, 99, 'altro@prova.it')['riquadro']);
    }

    public function testQuestionariDaCompilare(): void
    {
        [$t] = $this->evento(10, [['data_turno' => '2000-01-01']]);
        [$senzaRegistro] = $this->evento(11, [['data_turno' => '2000-01-01']], ['abilita_presenze' => 0]);
        [$senzaSondaggio] = $this->evento(12, [['data_turno' => '2000-01-01']]);
        $this->db->esegui('INSERT INTO sondaggi (evento_id, attivo) VALUES (10, 1), (11, 1)');
        $this->mia($t, 'confermata', ['presente' => 1]);
        $this->mia($senzaRegistro, 'confermata');
        $this->mia($senzaSondaggio, 'confermata', ['presente' => 1]);
        $this->mia($t, 'confermata', ['presente' => 0]);
        $this->mia($t, 'annullata', ['presente' => 1]);
        $passate = $this->area()->prenotazioni(4, self::EMAIL)['passate'];

        $r = $this->area()->sondaggiDisponibili($passate);
        $this->assertSame(2, $r['disponibili']);
        $this->assertSame(['Evento 10', 'Evento 11'], array_values(array_column($r['eventi'], 'titolo')));
        foreach ($r['eventi'] as $e) {
            $this->assertMatchesRegularExpression('/^sondaggio\.php\?token=[0-9a-f]{32}$/', $e['link']);
        }
        $this->assertSame(2, (int) $this->db->valore('SELECT COUNT(*) FROM prenotazioni WHERE token_sondaggio IS NOT NULL'), 'il token si crea una volta e si conserva');
    }

    public function testCheckinConIlQrDelTurno(): void
    {
        $adesso = $this->orologio->adesso();
        [$aperto, $presto, $finito, $senzaData] = $this->evento(10, [
            ['token_checkin' => 'tk1', 'data_turno' => $adesso->format('Y-m-d'), 'orario_inizio' => '11:00:00', 'orario_fine' => '13:00:00'],
            ['token_checkin' => 'tk2', 'data_turno' => $adesso->format('Y-m-d'), 'orario_inizio' => '13:00:00', 'orario_fine' => '14:00:00'],
            ['token_checkin' => 'tk3', 'data_turno' => $adesso->format('Y-m-d'), 'orario_inizio' => '07:00:00', 'orario_fine' => '08:00:00'],
            ['token_checkin' => 'tk4', 'data_turno' => null, 'orario_inizio' => null, 'orario_fine' => null],
        ]);
        $p = $this->mia($aperto);
        $this->mia($senzaData);
        $cin = $this->servizio(ServizioCheckin::class);

        $r = $cin->autoRegistrazione($aperto, 'tk1', 4);
        $this->assertSame(['success', 'Check-in completato con successo! Presenza convalidata ufficialmente.', 'success', 'fa-check-circle'], [$r['esito'], $r['messaggio'], $r['colore'], $r['icona']]);
        $this->assertSame('Evento 10', $r['turno']['evento_titolo']);
        $this->assertSame(1, $this->db->valore('SELECT presente FROM prenotazioni WHERE id = ?', [$p]));
        $this->assertNotNull($this->db->valore('SELECT data_presenza FROM prenotazioni WHERE id = ?', [$p]));
        $this->assertSame('La tua presenza era già stata registrata. Nessuna ulteriore azione richiesta.', $cin->autoRegistrazione($aperto, 'tk1', 4)['messaggio']);

        $this->assertSame('error', $cin->autoRegistrazione(0, 'tk1', 4)['esito']);
        $this->assertSame('Dati del QR Code mancanti o incompleti. Prova a ripetere la scansione.', $cin->autoRegistrazione($aperto, '', 4)['messaggio']);
        $r = $cin->autoRegistrazione($aperto, 'sbagliato', 4);
        $this->assertSame('QR Code non valido o scaduto. La segreteria potrebbe aver ruotato il codice di sicurezza.', $r['messaggio']);
        $this->assertNull($r['turno']);
        $r = $cin->autoRegistrazione($presto, 'tk2', 4);
        $this->assertSame(['warning', 'warning', 'fa-clock'], [$r['esito'], $r['colore'], $r['icona']]);
        $this->assertStringContainsString('troppo presto', $r['messaggio']);
        $r = $cin->autoRegistrazione($finito, 'tk3', 4);
        $this->assertSame(['error', 'secondary', 'Il periodo per registrare la presenza a questo evento è terminato.'], [$r['esito'], $r['colore'], $r['messaggio']]);
        $this->assertStringContainsString('Non risulti iscritto a questo turno', $cin->autoRegistrazione($aperto, 'tk1', 77)['messaggio']);
        $this->assertSame('success', $cin->autoRegistrazione($senzaData, 'tk4', 4)['esito'], 'turno senza data: sempre aperto');
    }

    public function testCheckinSoloPerLeIscrizioniConfermate(): void
    {
        [$t] = $this->evento(10, [['token_checkin' => 'tk', 'data_turno' => '2026-10-05']]);
        $this->mia($t, 'in_attesa');
        $r = $this->servizio(ServizioCheckin::class)->autoRegistrazione($t, 'tk', 4);
        $this->assertSame('La tua iscrizione non è confermata (Stato: IN_ATTESA).', $r['messaggio']);
        $this->assertSame('exclamation', substr($r['icona'], 3, 11));
        $this->db->esegui('UPDATE prenotazioni SET stato = NULL');
        $this->assertSame('La tua iscrizione non è confermata (Stato: ).', $this->servizio(ServizioCheckin::class)->autoRegistrazione($t, 'tk', 4)['messaggio'], 'le vecchie righe senza stato come prima');
    }

    public function testPresenzaRegistrataDalGestore(): void
    {
        [$t] = $this->evento(10, [[]]);
        $p = $this->mia($t);
        $this->servizio(ServizioCheckin::class)->registraPresenza($p);
        $this->assertSame(1, $this->db->valore('SELECT presente FROM prenotazioni WHERE id = ?', [$p]));
    }
}
