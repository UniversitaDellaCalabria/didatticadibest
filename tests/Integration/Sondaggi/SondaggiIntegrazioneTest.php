<?php

declare(strict_types=1);

namespace Tests\Integration\Sondaggi;

use App\Portale\ColoriAree;
use App\Sondaggi\ServizioSondaggi;
use App\Sondaggi\SondaggioRepository;
use Tests\Doppi\MailerFinto;
use Tests\Integration\DatabaseDiProva;

final class SondaggiIntegrazioneTest extends DatabaseDiProva
{
    private MailerFinto $mailer;
    private ServizioSondaggi $servizio;

    protected function tabelle(): array
    {
        return [
            'pagine_eventi' => "CREATE TABLE pagine_eventi (id INT PRIMARY KEY, titolo VARCHAR(150) DEFAULT '', colore_primario VARCHAR(20) DEFAULT '#0056B3')",
            'eventi' => "CREATE TABLE eventi (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT, titolo VARCHAR(150) DEFAULT '', luogo VARCHAR(150) DEFAULT '', archiviato TINYINT DEFAULT 0, ordine INT DEFAULT 0, abilita_presenze TINYINT DEFAULT 1)",
            'turni' => 'CREATE TABLE turni (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT, nome_turno VARCHAR(150) NULL, data_turno DATE NULL, orario_inizio TIME NULL, orario_fine TIME NULL)',
            'prenotazioni' => "CREATE TABLE prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, turno_id INT, codice_prenotazione VARCHAR(50) DEFAULT '', stato VARCHAR(30) NULL, presente INT DEFAULT 0,
                nome VARCHAR(100) NULL, cognome VARCHAR(100) NULL, email VARCHAR(150) NULL, matricola VARCHAR(50) NULL, token_sondaggio VARCHAR(64) NULL, sondaggio_completato TINYINT NOT NULL DEFAULT 0)",
            'sondaggi' => "CREATE TABLE sondaggi (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT NOT NULL, titolo VARCHAR(255) NOT NULL, attivo TINYINT DEFAULT 0)",
            'sondaggi_domande' => "CREATE TABLE sondaggi_domande (id INT AUTO_INCREMENT PRIMARY KEY, sondaggio_id INT NOT NULL, testo_domanda VARCHAR(500) NOT NULL, tipo VARCHAR(50) DEFAULT 'text',
                obbligatorio TINYINT DEFAULT 0, opzioni TEXT NULL, condizione_json TEXT NULL, ordine INT DEFAULT 0)",
            'sondaggi_risposte' => 'CREATE TABLE sondaggi_risposte (id INT AUTO_INCREMENT PRIMARY KEY, sondaggio_id INT NOT NULL, domanda_id INT NOT NULL, risposta TEXT NULL, data_risposta DATETIME DEFAULT CURRENT_TIMESTAMP)',
            'impostazioni_sistema' => 'CREATE TABLE impostazioni_sistema (id INT PRIMARY KEY, email_sondaggio_oggetto VARCHAR(255) DEFAULT \'\', email_sondaggio_corpo TEXT NULL)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new MailerFinto();
        $this->servizio = new ServizioSondaggi(new SondaggioRepository($this->db), $this->mailer, new ColoriAree($this->db));
        $this->db->esegui("INSERT INTO pagine_eventi (id, titolo, colore_primario) VALUES (1, 'Area', '#112233'), (2, 'Altra', '#445566')");
        $this->db->esegui('INSERT INTO impostazioni_sistema (id) VALUES (1)');
    }

    private function evento(string $titolo = 'Seminario', int $area = 1, int $archiviato = 0): int
    {
        return $this->db->inserisci('INSERT INTO eventi (pagina_id, titolo, luogo, archiviato) VALUES (?, ?, ?, ?)', [$area, $titolo, 'Aula 1', $archiviato]);
    }

    private function sondaggio(int $evento, int $attivo = 1): int
    {
        return $this->db->inserisci('INSERT INTO sondaggi (evento_id, titolo, attivo) VALUES (?, ?, ?)', [$evento, 'Gradimento', $attivo]);
    }

    private function domanda(int $sondaggio, string $tipo, int $obbligatoria = 0, string $condizione = '', string $opzioni = '', int $ordine = 0): int
    {
        return $this->db->inserisci(
            'INSERT INTO sondaggi_domande (sondaggio_id, testo_domanda, tipo, obbligatorio, opzioni, condizione_json, ordine) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$sondaggio, "Domanda $tipo", $tipo, $obbligatoria, $opzioni, $condizione, $ordine]
        );
    }

    /** @param array<string, mixed> $v */
    private function prenotazione(int $evento, array $v = []): int
    {
        $v += ['stato' => 'confermata', 'presente' => 1, 'email' => 'luca@x.it', 'token' => null, 'completato' => 0, 'nome' => 'Luca', 'cognome' => 'Rossi', 'matricola' => '123', 'data' => '2026-09-10'];
        $turno = $this->db->inserisci('INSERT INTO turni (evento_id, data_turno, orario_inizio, orario_fine) VALUES (?, ?, ?, ?)', [$evento, $v['data'], '10:00:00', '12:00:00']);

        return $this->db->inserisci(
            'INSERT INTO prenotazioni (turno_id, stato, presente, nome, cognome, email, matricola, token_sondaggio, sondaggio_completato) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$turno, $v['stato'], $v['presente'], $v['nome'], $v['cognome'], $v['email'], $v['matricola'], $v['token'], $v['completato']]
        );
    }

    public function testLinkPersonaleESondaggioAttivo(): void
    {
        $ev = $this->evento();
        $s = $this->sondaggio($ev);
        $pr = $this->prenotazione($ev, ['token' => 'tok123']);
        $p = $this->servizio->prenotazionePerToken('tok123');
        self::assertSame($pr, $p['id'], 'tipi nativi, come le vecchie query preparate');
        self::assertSame($ev, $p['evento_id']);
        self::assertSame('Seminario', $p['evento_titolo']);
        self::assertNull($this->servizio->prenotazionePerToken('altro'));
        self::assertSame($s, $this->servizio->attivoDelEvento($ev)['id']);
        $this->db->esegui('UPDATE sondaggi SET attivo = 0');
        self::assertNull($this->servizio->attivoDelEvento($ev));
        $this->domanda($s, 'text', 0, '', '', 20);
        $primo = $this->domanda($s, 'rating', 1, '', '', 10);
        self::assertSame($primo, $this->servizio->domande($s)[0]['id'], 'in ordine');
    }

    public function testSalvataggioRisposteAnonime(): void
    {
        $ev = $this->evento();
        $s = $this->sondaggio($ev);
        $d1 = $this->domanda($s, 'rating', 1);
        $d2 = $this->domanda($s, 'matrice', 0, '', 'A,B');
        $d3 = $this->domanda($s, 'checkboxes', 0, '', 'x,y');
        $d4 = $this->domanda($s, 'text');
        $d5 = $this->domanda($s, 'text', 1, '{"se_id":1,"se_val":"x"}');
        $dSep = $this->domanda($s, 'separator', 1);
        $altro = $this->domanda($this->sondaggio($this->evento('Altro')), 'text');
        $pr = $this->prenotazione($ev);

        self::assertFalse($this->servizio->salvaRisposte($s, ['x' => 'a', $d4 => 'solo testo'], $pr, $errore));
        self::assertSame('Rispondi a tutte le domande obbligatorie.', $errore);
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM sondaggi_risposte'));
        self::assertSame(0, (int) $this->db->valore('SELECT sondaggio_completato FROM prenotazioni WHERE id = ?', [$pr]));

        $ok = $this->servizio->salvaRisposte($s, [
            $d1 => '4', $d2 => ['A' => '5', 'B' => '3'], $d3 => ['x', 'y'], $d4 => '  con spazi  ', $d5 => '', $dSep => '', $altro => 'di un altro sondaggio', 99999 => 'inesistente',
        ], $pr, $errore);
        self::assertTrue($ok);
        self::assertNull($errore);
        $risposte = $this->db->righe('SELECT domanda_id, risposta FROM sondaggi_risposte ORDER BY id');
        self::assertSame([$d1, $d2, $d3, $d4], array_column($risposte, 'domanda_id'));
        self::assertSame(['4', '{"A":"5","B":"3"}', '["x","y"]', 'con spazi'], array_column($risposte, 'risposta'));
        self::assertSame(1, (int) $this->db->valore('SELECT sondaggio_completato FROM prenotazioni WHERE id = ?', [$pr]));

        // Secondo invio: nessuna risposta in più e messaggio per l'utente
        self::assertFalse($this->servizio->salvaRisposte($s, [$d1 => '1'], $pr, $errore));
        self::assertSame('Hai già compilato questo questionario. Grazie per il tuo feedback!', $errore);
        self::assertSame(4, (int) $this->db->valore('SELECT COUNT(*) FROM sondaggi_risposte'));
    }

    public function testSoloValoriVuotiSegnaComunqueComeCompletato(): void
    {
        $ev = $this->evento();
        $s = $this->sondaggio($ev);
        $d = $this->domanda($s, 'text');
        $pr = $this->prenotazione($ev);
        self::assertTrue($this->servizio->salvaRisposte($s, [$d => ' ', 99 => 'x'], $pr));
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM sondaggi_risposte'));
        self::assertSame(1, (int) $this->db->valore('SELECT sondaggio_completato FROM prenotazioni WHERE id = ?', [$pr]));
    }

    public function testAutorizzazioneDiEventoSondaggioEDomanda(): void
    {
        $ev = $this->evento('Mio', 1);
        $fuori = $this->evento('Altra area', 2);
        $s = $this->sondaggio($ev);
        $sFuori = $this->sondaggio($fuori);
        $d = $this->domanda($s, 'text');
        $dFuori = $this->domanda($sFuori, 'text');
        self::assertTrue($this->servizio->autorizzato('evento', $ev, 1, ''));
        self::assertFalse($this->servizio->autorizzato('evento', $fuori, 1, ''));
        self::assertTrue($this->servizio->autorizzato('sondaggio', $s, 1, ''));
        self::assertFalse($this->servizio->autorizzato('sondaggio', $sFuori, 1, ''));
        self::assertTrue($this->servizio->autorizzato('domanda', $d, 1, ''));
        self::assertFalse($this->servizio->autorizzato('domanda', $dFuori, 1, ''));
        self::assertFalse($this->servizio->autorizzato('domanda', 99999, 1, ''));
        self::assertTrue($this->servizio->autorizzato('evento', $ev, 1, " AND e.id IN ($ev) "), 'gestore di questo evento');
        self::assertFalse($this->servizio->autorizzato('evento', $ev, 1, ' AND e.id IN (99999) '));
        self::assertFalse($this->servizio->autorizzato('domanda', $d, 1, ' AND e.id = -1 '));
        $this->expectException(\InvalidArgumentException::class);
        $this->servizio->autorizzato('altro', 1, 1, '');
    }

    public function testCreazioneEGestioneDelleDomande(): void
    {
        $ev = $this->evento();
        $this->servizio->crea($ev, "Titolo <b> con 'apici'");
        $scheda = $this->servizio->scheda($ev);
        self::assertSame("Titolo <b> con 'apici'", $scheda['sondaggio']['titolo']);
        self::assertSame('0', $scheda['sondaggio']['attivo'], 'creato spento (testo, come la vecchia query)');
        self::assertSame([], $scheda['domande']);
        self::assertNull($this->servizio->scheda($this->evento('Senza')));
        $s = (int) $scheda['sondaggio']['id'];

        $this->servizio->aggiungiDomanda($s, 'Prima?', 'radio', 'Sì,No', true, 0, '');
        $this->servizio->aggiungiDomanda($s, 'Perché?', 'text', '', false, 1, 'No');
        $this->servizio->aggiungiDomanda($s, 'Terza', 'rating', '', false, 5, '');
        $d = $this->servizio->scheda($ev)['domande'];
        self::assertSame(['0', '10', '20'], array_column($d, 'ordine'), 'a passi di 10');
        self::assertSame('1', $d[0]['obbligatorio']);
        self::assertSame('Sì,No', $d[0]['opzioni']);
        self::assertSame('', $d[0]['condizione_json']);
        self::assertSame('{"se_id":1,"se_val":"No"}', $d[1]['condizione_json']);
        self::assertSame('', ServizioSondaggi::condizioneJson(5, ''));
        self::assertSame('', ServizioSondaggi::condizioneJson(0, 'x'));
        self::assertSame('{"se_id":5,"se_val":"x"}', ServizioSondaggi::condizioneJson(5, 'x'));

        $id2 = (int) $d[1]['id'];
        $this->servizio->modificaDomanda($id2, 'Perché no?', 'textarea', ' ', true, 0, 'x');
        $m = $this->db->riga('SELECT * FROM sondaggi_domande WHERE id = ?', [$id2]);
        self::assertSame(['Perché no?', 'textarea', ' ', 1, ''], [$m['testo_domanda'], $m['tipo'], $m['opzioni'], $m['obbligatorio'], $m['condizione_json']]);

        $this->servizio->spostaDomanda($id2, false);
        $this->servizio->spostaDomanda((int) $d[2]['id'], true);
        self::assertSame([25, 5], [$this->db->valore('SELECT ordine FROM sondaggi_domande WHERE id = ?', [$id2]), $this->db->valore('SELECT ordine FROM sondaggi_domande WHERE id = ?', [(int) $d[2]['id']])]);
        $this->servizio->impostaOrdineDomanda($id2, 0);
        self::assertSame((int) $d[0]['id'], (int) $this->servizio->scheda($ev)['domande'][0]['id'], 'a pari ordine vince l\'id minore');

        $this->servizio->attiva($s, true);
        self::assertSame('1', $this->servizio->scheda($ev)['sondaggio']['attivo']);
        $this->servizio->attiva($s, false);
        self::assertSame('0', $this->servizio->scheda($ev)['sondaggio']['attivo']);

        $this->servizio->eliminaDomanda($id2);
        self::assertCount(2, $this->servizio->scheda($ev)['domande']);
    }

    public function testEliminazioneDelSondaggioConDomandeERisposte(): void
    {
        $ev = $this->evento();
        $altroEv = $this->evento('Altro');
        $s = $this->sondaggio($ev);
        $altro = $this->sondaggio($altroEv);
        $d = $this->domanda($s, 'text');
        $dAltro = $this->domanda($altro, 'text');
        $this->db->esegui('INSERT INTO sondaggi_risposte (sondaggio_id, domanda_id, risposta) VALUES (?, ?, ?), (?, ?, ?)', [$s, $d, 'a', $altro, $dAltro, 'b']);
        $this->servizio->elimina($s);
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM sondaggi WHERE id = ?', [$s]));
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM sondaggi_domande WHERE sondaggio_id = ?', [$s]));
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM sondaggi_risposte WHERE sondaggio_id = ?', [$s]));
        self::assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM sondaggi_risposte WHERE sondaggio_id = ?', [$altro]), 'quello degli altri resta');
    }

    public function testEventiDellAreaEStatisticheDeiRisultati(): void
    {
        $a = $this->evento('Attivo 1');
        $b = $this->evento('Attivo 2');
        $arch = $this->evento('Archiviato', 1, 1);
        $this->evento('Altra area', 2);
        self::assertSame(['Attivo 2', 'Attivo 1'], array_column($this->servizio->eventiDellArea(1, false, ''), 'titolo'), 'a pari ordine il più recente prima');
        self::assertSame([$arch], array_map('intval', array_column($this->servizio->eventiDellArea(1, true, ''), 'id')));
        self::assertSame([], $this->servizio->eventiDellArea(1, false, ' AND e.id = -1 '));
        self::assertSame([$b], array_map('intval', array_column($this->servizio->eventiDellArea(1, false, " AND e.id = $b "), 'id')));

        $s = $this->sondaggio($a);
        $stelle = $this->domanda($s, 'rating');
        $nps = $this->domanda($s, 'nps');
        $mat = $this->domanda($s, 'matrice');
        $rad = $this->domanda($s, 'radio');
        $txt = $this->domanda($s, 'text');
        $righe = [[$stelle, '5'], [$stelle, '4'], [$nps, '10'], [$nps, '99'], [$nps, '-3'], [$mat, '{"Org":"4","Doc":"2"}'], [$mat, '{"Org":"2"}'], [$mat, 'non json'], [$rad, ' Sì '], [$rad, 'Sì'], [$rad, 'No'], [$txt, 'libero 1'], [$txt, 'libero 2']];
        foreach ($righe as [$dom, $val]) {
            $this->db->esegui('INSERT INTO sondaggi_risposte (sondaggio_id, domanda_id, risposta) VALUES (?, ?, ?)', [$s, $dom, $val]);
        }
        $st = $this->servizio->scheda($a)['statistiche'];
        self::assertSame([2, 9], [$st[$stelle]['totale_voti'], $st[$stelle]['somma_voti']]);
        self::assertSame([3, 20], [$st[$nps]['totale_voti'], $st[$nps]['somma_voti']], 'NPS limitato tra 0 e 10');
        self::assertSame([2, 1, 0], [$st[$nps]['distribuzione_nps'][10], $st[$nps]['distribuzione_nps'][0], $st[$nps]['distribuzione_nps'][5]]);
        self::assertSame(['Org' => ['somma' => 6, 'tot' => 2], 'Doc' => ['somma' => 2, 'tot' => 1]], $st[$mat]['conteggi_matrice']);
        self::assertSame(2, $st[$mat]['totale_voti']);
        self::assertSame(['Sì' => 2, 'No' => 1], $st[$rad]['conteggi_opzioni']);
        self::assertSame(['libero 1', 'libero 2'], $st[$txt]['risposte']);
    }

    public function testEsportazioneRaggruppataPerCompilazione(): void
    {
        $ev = $this->evento();
        $s = $this->sondaggio($ev);
        $d1 = $this->domanda($s, 'rating', 0, '', '', 0);
        $d2 = $this->domanda($s, 'matrice', 0, '', '', 10);
        $this->db->esegui("INSERT INTO sondaggi_risposte (sondaggio_id, domanda_id, risposta, data_risposta) VALUES (?, ?, '5', '2026-09-12 10:00:00'), (?, ?, '{\"A\":\"4\"}', '2026-09-12 10:00:00'), (?, ?, '3', '2026-09-13 09:00:00')", [$s, $d1, $s, $d2, $s, $d1]);
        $e = $this->servizio->esportazione($s);
        self::assertSame([(string) $d1, (string) $d2], array_map('strval', array_keys($e['domande'])));
        self::assertSame(['2026-09-13 09:00:00', '2026-09-12 10:00:00'], array_keys($e['risposte']), 'dalla più recente');
        self::assertSame('5', $e['risposte']['2026-09-12 10:00:00'][$d1]);
        self::assertSame(['domande' => [], 'risposte' => []], $this->servizio->esportazione(99999));
    }

    public function testInvioDelLinkDelQuestionario(): void
    {
        $ev = $this->evento('Seminario <x>');
        $altro = $this->evento('Altro evento');
        $ok = $this->prenotazione($ev, ['nome' => 'Anna', 'cognome' => 'Verdi', 'email' => 'anna@x.it', 'matricola' => '777']);
        $conToken = $this->prenotazione($ev, ['email' => 'tok@x.it', 'token' => 'tokenfisso']);
        $this->prenotazione($ev, ['email' => '']);
        $this->prenotazione($ev, ['email' => 'compilato@x.it', 'completato' => 1]);
        $this->prenotazione($ev, ['email' => 'assente@x.it', 'presente' => 0]);
        $this->prenotazione($ev, ['email' => 'attesa@x.it', 'stato' => 'in_attesa']);
        $this->prenotazione($altro, ['email' => 'altro@x.it']);
        $this->db->esegui('UPDATE eventi SET abilita_presenze = 0 WHERE id = ?', [$altro]);
        $senzaPresenze = $this->evento('Senza presenze');
        $this->db->esegui('UPDATE eventi SET abilita_presenze = 0 WHERE id = ?', [$senzaPresenze]);
        $this->prenotazione($senzaPresenze, ['email' => 'libero@x.it', 'presente' => 0]);

        self::assertSame(2, $this->servizio->inviaLink($ev, 'https://sito.test/eventi'));
        self::assertSame(['anna@x.it', 'tok@x.it'], array_column($this->mailer->inviate, 'a'));
        $m = $this->mailer->inviate[0];
        self::assertSame('La tua opinione è importante! Sondaggio Evento: Seminario <x>', $m['oggetto']);
        self::assertSame('#112233', $m['colore']);
        self::assertStringContainsString('<strong>Anna Verdi</strong>', $m['corpo']);
        self::assertStringContainsString("all'evento <strong>Seminario <x></strong>", $m['corpo'], 'i segnaposto non vengono protetti (come prima)');
        $token = (string) $this->db->valore('SELECT token_sondaggio FROM prenotazioni WHERE id = ?', [$ok]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token, 'link creato se mancava');
        self::assertStringContainsString("href='https://sito.test/eventi/sondaggio.php?token=$token'", $m['corpo']);
        self::assertStringContainsString('📝 Compila il Questionario</a></div>', $m['corpo']);
        self::assertStringContainsString('sondaggio.php?token=tokenfisso', $this->mailer->inviate[1]['corpo']);
        self::assertSame('tokenfisso', $this->db->valore('SELECT token_sondaggio FROM prenotazioni WHERE id = ?', [$conToken]));

        // Senza presenze richieste l'invio non guarda il check-in
        self::assertSame(1, $this->servizio->inviaLink($senzaPresenze, 'https://sito.test/eventi'));
    }

    public function testInvioDelLinkConTestoPersonalizzato(): void
    {
        $this->db->esegui("UPDATE impostazioni_sistema SET email_sondaggio_oggetto = 'Dicci: {TITOLO_EVENTO} ({NOME})', email_sondaggio_corpo = '<p>{NOME} {COGNOME} {MATRICOLA} {DATA_TURNO} {ORARIO_TURNO} {LUOGO}</p>{LINK_SONDAGGIO}' WHERE id = 1");
        $ev = $this->evento('Giornata');
        $this->prenotazione($ev, ['nome' => 'Anna', 'cognome' => 'Verdi', 'matricola' => '777', 'data' => '2026-09-10']);
        self::assertSame(1, $this->servizio->inviaLink($ev, 'https://s.test'));
        $m = $this->mailer->inviate[0];
        self::assertSame('Dicci: Giornata (Anna)', $m['oggetto']);
        self::assertStringStartsWith('<p>Anna Verdi 777 10/09/2026 10:00–12:00 Aula 1</p><a href=', $m['corpo']);
        $this->db->esegui('UPDATE impostazioni_sistema SET email_sondaggio_oggetto = \'\', email_sondaggio_corpo = NULL');
        self::assertSame(0, $this->servizio->inviaLink($this->evento('Vuoto'), 'https://s.test'));
    }
}
