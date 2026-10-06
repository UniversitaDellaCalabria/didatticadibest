<?php

declare(strict_types=1);

namespace Tests\Integration\Attestati;

use App\Attestati\AttestatoRepository;
use App\Attestati\RegolaAttestato;
use App\Attestati\ServizioAttestati;
use App\Attestati\ServizioCronAttestati;
use App\Core\Sito;
use App\Eventi\RegoleIscrizioni;
use App\Portale\ColoriAree;
use Tests\Doppi\MailerFinto;
use Tests\Doppi\OrologioFisso;
use Tests\Doppi\RegoleClasseFinte;
use Tests\Integration\DatabaseDiProva;

final class AttestatiIntegrazioneTest extends DatabaseDiProva
{
    private MailerFinto $mailer;
    private ServizioAttestati $servizio;
    private AttestatoRepository $repo;

    protected function tabelle(): array
    {
        return [
            'pagine_eventi' => "CREATE TABLE pagine_eventi (id INT PRIMARY KEY, titolo VARCHAR(150) DEFAULT '', colore_primario VARCHAR(20) DEFAULT '#0056B3', firma_nome VARCHAR(255) DEFAULT '',
                firma_titolo VARCHAR(255) DEFAULT '', logo_attestato_path VARCHAR(255) DEFAULT '', testo_attestato VARCHAR(300) NULL)",
            'eventi' => "CREATE TABLE eventi (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT, titolo VARCHAR(150) DEFAULT '', luogo VARCHAR(150) DEFAULT '', tipo VARCHAR(20) DEFAULT 'evento')",
            'turni' => 'CREATE TABLE turni (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT, nome_turno VARCHAR(150) NULL, data_turno DATE NULL, orario_inizio TIME NULL, orario_fine TIME NULL,
                min_partecipanti INT NULL, max_partecipanti INT NULL)',
            'progetti_dettagli' => 'CREATE TABLE progetti_dettagli (evento_id INT PRIMARY KEY, per_scuole TINYINT DEFAULT 1, attestati TINYINT DEFAULT 0, data_inizio DATE NULL, data_fine DATE NULL, ore_totali INT NULL)',
            'configurazione_portale' => "CREATE TABLE configurazione_portale (id INT PRIMARY KEY, logo_path VARCHAR(255) DEFAULT '', nome_portale VARCHAR(150) DEFAULT '', sottotitolo_portale VARCHAR(150) DEFAULT '')",
            'utenti' => 'CREATE TABLE utenti (id INT PRIMARY KEY, matricola_studente VARCHAR(30) NULL, matricola_dipendente VARCHAR(30) NULL, matricola VARCHAR(30) NULL)',
            'prenotazioni' => "CREATE TABLE prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, turno_id INT, utente_id INT NULL, codice_prenotazione VARCHAR(50) DEFAULT '', stato VARCHAR(30) NULL,
                presente INT DEFAULT 0, nome VARCHAR(100) NULL, cognome VARCHAR(100) NULL, email VARCHAR(150) NULL, matricola VARCHAR(50) NULL, dati_custom_json TEXT NULL, attestato_inviato TINYINT DEFAULT 0)",
            'partecipanti_prenotazione' => "CREATE TABLE partecipanti_prenotazione (id INT AUTO_INCREMENT PRIMARY KEY, prenotazione_id INT NOT NULL, cognome VARCHAR(100) NOT NULL DEFAULT '',
                nome VARCHAR(100) NOT NULL DEFAULT '', codice VARCHAR(20) NULL, escluso TINYINT NOT NULL DEFAULT 0, anonimizzato TINYINT NOT NULL DEFAULT 0, ordine INT NOT NULL DEFAULT 0, UNIQUE KEY uq_codice (codice))",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new MailerFinto();
        $this->repo = new AttestatoRepository($this->db);
        $this->servizio = $this->nuovoServizio();
        $this->db->esegui("INSERT INTO configurazione_portale (id, logo_path, nome_portale, sottotitolo_portale) VALUES (1, 'logo.png', 'Didattica DiBEST', 'Dipartimento')");
        $this->db->esegui("INSERT INTO pagine_eventi (id, titolo, colore_primario, firma_nome, firma_titolo, testo_attestato) VALUES (1, 'Area uno', '#112233', '', '', NULL), (2, 'FSL', '#445566', 'Anna Bianchi', 'La Direttrice', 'ha frequentato:')");
    }

    private function nuovoServizio(string $oggi = '2026-10-05'): ServizioAttestati
    {
        $regoleIscrizioni = new class () implements RegoleIscrizioni {
            public function postiOccupati(int $turnoId): int
            {
                return 0;
            }

            public function limitiPartecipanti(?array $dettagli, ?array $turno = null): array
            {
                return ['max' => !empty($turno['max_partecipanti']) ? (int) $turno['max_partecipanti'] : (!empty($dettagli['max_studenti']) ? (int) $dettagli['max_studenti'] : null), 'min' => 1];
            }

            public function decadiAtteseVincolate(int $prenotazioneId): int
            {
                return 0;
            }
        };

        return new ServizioAttestati(
            $this->repo,
            new RegoleClasseFinte($oggi),
            $regoleIscrizioni,
            $this->mailer,
            new ColoriAree($this->db),
            new Sito(sys_get_temp_dir(), 'https://portale.test/eventi'),
            new OrologioFisso($oggi . ' 12:00:00')
        );
    }

    private function evento(string $titolo, int $pagina, string $tipo, ?array $scheda = null): int
    {
        $id = $this->db->inserisci('INSERT INTO eventi (pagina_id, titolo, luogo, tipo) VALUES (?, ?, ?, ?)', [$pagina, $titolo, 'Aula 1', $tipo]);
        if ($scheda !== null) {
            $scheda += ['per_scuole' => 1, 'attestati' => 1, 'data_inizio' => null, 'data_fine' => null, 'ore_totali' => null];
            $this->db->esegui('INSERT INTO progetti_dettagli (evento_id, per_scuole, attestati, data_inizio, data_fine, ore_totali) VALUES (?, ?, ?, ?, ?, ?)', [$id, $scheda['per_scuole'], $scheda['attestati'], $scheda['data_inizio'], $scheda['data_fine'], $scheda['ore_totali']]);
        }

        return $id;
    }

    private function turno(int $evento, ?string $data = null, ?string $inizio = null, ?string $fine = null): int
    {
        return $this->db->inserisci('INSERT INTO turni (evento_id, nome_turno, data_turno, orario_inizio, orario_fine) VALUES (?, ?, ?, ?, ?)', [$evento, 'Turno', $data, $inizio, $fine]);
    }

    /** @param array<string, mixed> $v */
    private function prenotazione(int $turno, array $v = []): int
    {
        $v += ['codice' => 'PR' . random_int(1000, 9999), 'stato' => 'confermata', 'presente' => 1, 'nome' => 'Luca', 'cognome' => 'Rossi', 'email' => 'luca@x.it', 'utente' => null, 'custom' => null, 'inviato' => 0, 'matricola' => ''];

        return $this->db->inserisci(
            'INSERT INTO prenotazioni (turno_id, utente_id, codice_prenotazione, stato, presente, nome, cognome, email, matricola, dati_custom_json, attestato_inviato) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$turno, $v['utente'], $v['codice'], $v['stato'], $v['presente'], $v['nome'], $v['cognome'], $v['email'], $v['matricola'], $v['custom'], $v['inviato']]
        );
    }

    private function studente(int $prenotazione, string $cognome, string $nome, int $ordine = 0, ?string $codice = null, int $escluso = 0, int $anonimizzato = 0): int
    {
        return $this->db->inserisci(
            'INSERT INTO partecipanti_prenotazione (prenotazione_id, cognome, nome, codice, escluso, anonimizzato, ordine) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$prenotazione, $cognome, $nome, $codice, $escluso, $anonimizzato, $ordine]
        );
    }

    public function testRegolaDellAttestatoPerEventoEProgetto(): void
    {
        $normale = $this->evento('Evento', 1, 'evento');
        $conClasse = $this->evento('Evento con classe', 1, 'evento', ['attestati' => 1]);
        $senzaAttestati = $this->evento('Progetto no', 2, 'progetto', ['attestati' => 0]);
        $inCorso = $this->evento('Progetto in corso', 2, 'progetto', ['attestati' => 1, 'data_fine' => '2026-10-05']);
        $concluso = $this->evento('Progetto concluso', 2, 'progetto', ['attestati' => 1, 'data_fine' => '2026-10-04', 'per_scuole' => 1]);
        $singolo = $this->evento('Progetto singolo', 2, 'progetto', ['attestati' => 1, 'data_fine' => '2026-10-04', 'per_scuole' => 0]);
        $senzaData = $this->evento('Progetto senza data', 2, 'progetto', ['attestati' => 1, 'per_scuole' => 0]);

        self::assertSame(RegolaAttestato::Evento, $this->servizio->regola($normale));
        self::assertSame(RegolaAttestato::Gruppo, $this->servizio->regola($conClasse), 'evento con attestati per la classe');
        self::assertSame(RegolaAttestato::No, $this->servizio->regola($senzaAttestati));
        self::assertSame(RegolaAttestato::Attendi, $this->servizio->regola($inCorso), 'la data di fine di oggi non è ancora conclusa');
        self::assertSame(RegolaAttestato::Gruppo, $this->servizio->regola($concluso));
        self::assertSame(RegolaAttestato::Singolo, $this->servizio->regola($singolo));
        self::assertSame(RegolaAttestato::Singolo, $this->servizio->regola($senzaData));
        self::assertSame(RegolaAttestato::Evento, $this->servizio->regola(99999), 'evento inesistente');
        self::assertSame(RegolaAttestato::Singolo, $this->nuovoServizio('2026-10-06')->regola($singolo));
        self::assertSame(RegolaAttestato::Gruppo, $this->nuovoServizio('2026-10-06')->regola($inCorso), 'il giorno dopo la fine il progetto è concluso');
    }

    public function testElencoStudentiSostituitoEInOrdine(): void
    {
        $pr = $this->prenotazione($this->turno($this->evento('E', 1, 'evento', ['attestati' => 1])));
        $this->studente($pr, 'Vecchio', 'Uno');
        $this->servizio->salvaElenco($pr, [['cognome' => 'Rossi', 'nome' => 'Mario'], ['cognome' => 'Bianchi', 'nome' => 'Anna']]);
        $elenco = $this->servizio->partecipanti($pr);
        self::assertSame(['Rossi Mario', 'Bianchi Anna'], array_map(ServizioAttestati::nomePartecipante(...), $elenco));
        self::assertSame(['0', '1'], array_column($elenco, 'ordine'), 'ordine di inserimento (testo, come la vecchia query)');
        $this->servizio->salvaElenco($pr, []);
        self::assertSame([], $this->servizio->partecipanti($pr));
        self::assertSame([], $this->servizio->partecipanti(99999));
    }

    public function testCodiciAssegnatiUnaVoltaESenzaDoppioni(): void
    {
        $pr = $this->prenotazione($this->turno($this->evento('E', 1, 'evento', ['attestati' => 1])));
        $this->studente($pr, 'A', 'A', 0, 'AT-GIAPRESENTE');
        $this->studente($pr, 'B', 'B', 1);
        $this->studente($pr, 'C', 'C', 2, '');
        $this->servizio->assegnaCodici($pr);
        $codici = array_column($this->servizio->partecipanti($pr), 'codice');
        self::assertSame('AT-GIAPRESENTE', $codici[0], 'il codice già assegnato non cambia');
        self::assertMatchesRegularExpression('/^AT-[A-HJ-NP-Z2-9]{10}$/', (string) $codici[1]);
        self::assertMatchesRegularExpression('/^AT-[A-HJ-NP-Z2-9]{10}$/', (string) $codici[2]);
        self::assertCount(3, array_unique($codici));
        $this->servizio->assegnaCodici($pr);
        self::assertSame($codici, array_column($this->servizio->partecipanti($pr), 'codice'), 'una seconda chiamata non cambia nulla');
    }

    public function testInvioAttestatiDiGruppoMotiviDiRifiuto(): void
    {
        $prog = $this->evento('Progetto', 2, 'progetto', ['attestati' => 1, 'data_fine' => '2026-04-01']);
        $t = $this->turno($prog);
        self::assertSame("Questa attività non prevede attestati per gli studenti.", $this->servizio->inviaGruppo(99999));
        $nonDiClasse = $this->prenotazione($this->turno($this->evento('Normale', 1, 'evento')));
        self::assertSame("Questa attività non prevede attestati per gli studenti.", $this->servizio->inviaGruppo($nonDiClasse));

        $attesa = $this->prenotazione($t, ['stato' => 'in_attesa']);
        self::assertSame('La prenotazione non è confermata.', $this->servizio->inviaGruppo($attesa));
        $assente = $this->prenotazione($t, ['presente' => 0]);
        self::assertSame('Segna prima la presenza della classe.', $this->servizio->inviaGruppo($assente));
        $gia = $this->prenotazione($t, ['inviato' => 1]);
        self::assertSame('Attestati già inviati.', $this->servizio->inviaGruppo($gia));
        $vuota = $this->prenotazione($t);
        self::assertSame("L'elenco degli studenti è vuoto.", $this->servizio->inviaGruppo($vuota));
        $this->studente($vuota, 'Solo', 'Escluso', 0, null, 1);
        self::assertSame("L'elenco degli studenti è vuoto.", $this->servizio->inviaGruppo($vuota), 'gli esclusi non contano');
        $senzaMail = $this->prenotazione($t, ['email' => '']);
        $this->studente($senzaMail, 'Rossi', 'Mario');
        self::assertSame("Manca l'email del docente.", $this->servizio->inviaGruppo($senzaMail));
        self::assertSame([], $this->mailer->inviate);

        // Non ancora concluso: progetto con data di fine futura, evento con turno futuro
        $inCorso = $this->evento('Progetto in corso', 2, 'progetto', ['attestati' => 1, 'data_fine' => '2026-12-01']);
        $p2 = $this->prenotazione($this->turno($inCorso));
        $this->studente($p2, 'Rossi', 'Mario');
        self::assertSame('Il progetto non è ancora concluso.', $this->servizio->inviaGruppo($p2));
        $evFuturo = $this->evento('Evento futuro', 1, 'evento', ['attestati' => 1]);
        $p3 = $this->prenotazione($this->turno($evFuturo, '2026-12-01'));
        $this->studente($p3, 'Rossi', 'Mario');
        self::assertSame("L'evento non si è ancora svolto.", $this->servizio->inviaGruppo($p3));
        self::assertSame(0, (int) $this->db->valore('SELECT attestato_inviato FROM prenotazioni WHERE id = ?', [$p3]));
        self::assertSame(true, $this->servizio->inviaGruppo($p3, true), 'forzato dall\'admin: parte anche prima della fine');
    }

    public function testInvioAttestatiDiGruppoRiuscito(): void
    {
        $prog = $this->evento('Progetto "Scuole" & Co', 2, 'progetto', ['attestati' => 1, 'data_fine' => '2026-04-01']);
        $pr = $this->prenotazione($this->turno($prog), ['nome' => 'Maria', 'cognome' => "D'Angelo", 'codice' => 'COD A/B', 'email' => 'maria@scuola.it']);
        $this->studente($pr, 'Rossi', 'Mario', 0);
        $this->studente($pr, 'Verdi', 'Anna', 1);
        $this->studente($pr, 'Assente', 'Luca', 2, null, 1);

        self::assertTrue($this->servizio->inviaGruppo($pr));
        self::assertCount(1, $this->mailer->inviate);
        $m = $this->mailer->inviate[0];
        self::assertSame('maria@scuola.it', $m['a']);
        self::assertSame('Attestati degli studenti: Progetto "Scuole" & Co', $m['oggetto']);
        self::assertSame('#445566', $m['colore'], 'colore dell\'area');
        self::assertStringContainsString("<strong>Maria D&#039;Angelo</strong>", $m['corpo']);
        self::assertStringContainsString('al progetto <strong>Progetto &quot;Scuole&quot; &amp; Co</strong>', $m['corpo']);
        self::assertStringContainsString('attestati di partecipazione di 2 studenti</strong>', $m['corpo']);
        self::assertStringContainsString("href='https://portale.test/eventi/attestati_gruppo.php?code=COD+A%2FB'", $m['corpo']);
        self::assertStringContainsString("href='https://portale.test/eventi/area_personale.php'", $m['corpo']);
        self::assertSame(1, (int) $this->db->valore('SELECT attestato_inviato FROM prenotazioni WHERE id = ?', [$pr]));
        $codici = array_column($this->servizio->partecipanti($pr), 'codice');
        self::assertCount(3, array_filter($codici), 'i codici sono assegnati a tutti, anche all\'escluso (la lista è unica)');
        self::assertSame('Attestati già inviati.', $this->servizio->inviaGruppo($pr));
        self::assertTrue($this->servizio->inviaGruppo($pr, true), 'l\'admin può reinviare');
        self::assertCount(2, $this->mailer->inviate);

        // Evento (non progetto) con un solo studente: testo al singolare e "all'attività"
        $ev = $this->evento('Giornata', 1, 'evento', ['attestati' => 1]);
        $p2 = $this->prenotazione($this->turno($ev, '2026-09-01'));
        $this->studente($p2, 'Neri', 'Ugo');
        self::assertTrue($this->servizio->inviaGruppo($p2));
        self::assertStringContainsString("all'attività <strong>Giornata</strong>", $this->mailer->inviate[2]['corpo']);
        self::assertStringContainsString('attestati di partecipazione di 1 studente</strong>', $this->mailer->inviate[2]['corpo']);
    }

    public function testEmailAttestatoPersonaleSoloSeConcluso(): void
    {
        $ev = $this->evento('Seminario', 1, 'evento');
        $passato = $this->turno($ev, '2026-09-10', '10:00:00', '12:00:00');
        $futuro = $this->turno($ev, '2999-01-01', '10:00:00', '12:00:00');
        $senzaData = $this->turno($ev);

        self::assertFalse($this->servizio->inviaSeConcluso(99999));
        self::assertFalse($this->servizio->inviaSeConcluso($this->prenotazione($passato, ['presente' => 0])), 'assente');
        self::assertFalse($this->servizio->inviaSeConcluso($this->prenotazione($passato, ['inviato' => 1])), 'già inviato');
        self::assertFalse($this->servizio->inviaSeConcluso($this->prenotazione($passato, ['email' => ''])), 'senza email');
        self::assertFalse($this->servizio->inviaSeConcluso($this->prenotazione($futuro)), 'turno non ancora concluso');
        self::assertSame([], $this->mailer->inviate);

        $pr = $this->prenotazione($passato, ['nome' => 'Anna', 'cognome' => 'Verdi <b>', 'codice' => 'XYZ 1', 'email' => 'anna@x.it']);
        self::assertTrue($this->servizio->inviaSeConcluso($pr));
        $m = $this->mailer->inviate[0];
        self::assertSame('anna@x.it', $m['a']);
        self::assertSame('Il tuo Attestato è pronto: Seminario', $m['oggetto']);
        self::assertSame('#112233', $m['colore']);
        self::assertStringContainsString('<strong>Anna Verdi &lt;b&gt;</strong>', $m['corpo']);
        self::assertStringContainsString("all'evento <strong>Seminario</strong> del 10/09/2026.", $m['corpo']);
        self::assertStringContainsString("href='https://portale.test/eventi/stampa_attestato.php?code=XYZ+1'", $m['corpo']);
        self::assertStringEndsWith('<p>Cordiali saluti,<br>Il team Didattica DiBEST</p>', $m['corpo']);
        self::assertFalse($this->servizio->inviaSeConcluso($pr), 'una sola volta');

        $s = $this->prenotazione($senzaData);
        self::assertTrue($this->servizio->inviaSeConcluso($s), 'turno senza data: subito');
        self::assertStringContainsString("all'evento <strong>Seminario</strong>.</p>", $this->mailer->inviate[1]['corpo']);
    }

    public function testEmailAttestatoNeiProgetti(): void
    {
        $senza = $this->prenotazione($this->turno($this->evento('P no', 2, 'progetto', ['attestati' => 0])));
        $attesa = $this->prenotazione($this->turno($this->evento('P attesa', 2, 'progetto', ['attestati' => 1, 'data_fine' => '2026-12-01'])));
        self::assertFalse($this->servizio->inviaSeConcluso($senza));
        self::assertFalse($this->servizio->inviaSeConcluso($attesa));
        $singolo = $this->prenotazione($this->turno($this->evento('P singolo', 2, 'progetto', ['attestati' => 1, 'per_scuole' => 0, 'data_fine' => '2026-03-01'])));
        self::assertTrue($this->servizio->inviaSeConcluso($singolo));
        self::assertStringContainsString('stampa_attestato.php', $this->mailer->inviate[0]['corpo']);

        // Per le scuole partono gli attestati degli studenti al docente (non quello personale), solo con l'elenco
        $scuole = $this->evento('P scuole', 2, 'progetto', ['attestati' => 1, 'per_scuole' => 1, 'data_fine' => '2026-03-01']);
        $vuoto = $this->prenotazione($this->turno($scuole));
        self::assertFalse($this->servizio->inviaSeConcluso($vuoto), "elenco vuoto: niente invio");
        $pieno = $this->prenotazione($this->turno($scuole), ['email' => 'docente@scuola.it']);
        $this->studente($pieno, 'Rossi', 'Mario');
        self::assertTrue($this->servizio->inviaSeConcluso($pieno));
        self::assertSame('Attestati degli studenti: P scuole', $this->mailer->inviate[1]['oggetto']);
    }

    public function testMaxPartecipanti(): void
    {
        self::assertSame(12, $this->servizio->maxPartecipanti(['dati_custom_json' => '{"numero_partecipanti":"12"}'], null));
        self::assertSame(30, $this->servizio->maxPartecipanti(['dati_custom_json' => null, 'max_partecipanti' => '30'], null));
        self::assertSame(25, $this->servizio->maxPartecipanti(['dati_custom_json' => '{"numero_partecipanti":"0"}'], ['max_studenti' => '25']));
        self::assertSame(200, $this->servizio->maxPartecipanti([], null));
        self::assertSame(200, $this->servizio->maxPartecipanti(['dati_custom_json' => 'non json'], []));
    }

    public function testVerificaPubblicaDeiCodici(): void
    {
        $scuole = $this->evento('Progetto scuole', 2, 'progetto', ['attestati' => 1, 'per_scuole' => 1, 'data_fine' => '2026-03-01', 'data_inizio' => '2026-01-10', 'ore_totali' => 20]);
        $pr = $this->prenotazione($this->turno($scuole), ['codice' => 'CLASSE1']);
        $this->studente($pr, 'Rossi', 'Mario', 0, 'AT-STUDENTE01');
        $this->studente($pr, 'Verdi', 'Anna', 1, 'AT-STUDENTE02', 1);
        $this->studente($pr, 'R.', 'L.', 2, 'AT-STUDENTE03', 0, 1);

        $v = $this->servizio->verifica('AT-STUDENTE01');
        self::assertSame('Mario Rossi', $v['nome'], 'nome e cognome come stampati sull\'attestato');
        self::assertSame('Progetto scuole', $v['evento']);
        self::assertSame('20', $v['ore']);
        self::assertSame('Dal 10/01/2026 al 01/03/2026', $v['quando']);
        self::assertSame('FSL', $v['area']);
        self::assertFalse($v['anonimizzato']);
        self::assertNull($this->servizio->verifica('AT-STUDENTE02'), 'studente escluso');
        self::assertTrue($this->servizio->verifica('AT-STUDENTE03')['anonimizzato']);
        self::assertNull($this->servizio->verifica('AT-NONESISTE'));
        self::assertNull($this->servizio->verifica('CLASSE1'), 'per i progetti di classe vale solo il codice dello studente');

        $ev = $this->evento('Seminario', 1, 'evento');
        $this->db->esegui('UPDATE configurazione_portale SET nome_portale = ? WHERE id = 1', ['Portale']);
        $t = $this->turno($ev, '2026-09-10', '10:00:00', '12:30:00');
        $this->prenotazione($t, ['codice' => 'pers1abc', 'nome' => 'Luca', 'cognome' => 'Neri']);
        $this->prenotazione($t, ['codice' => 'ASSENTE1', 'presente' => 0]);
        $this->prenotazione($t, ['codice' => 'ATTESA01', 'stato' => 'in_attesa']);
        $d = $this->servizio->verifica('PERS1ABC');
        self::assertSame('Luca Neri', $d['nome']);
        self::assertSame('pers1abc', $d['codice'], 'il codice mostrato è quello della prenotazione');
        self::assertSame('2.5', $d['ore']);
        self::assertSame('In data 10/09/2026', $d['quando']);
        self::assertArrayNotHasKey('anonimizzato', $d);
        self::assertNull($this->servizio->verifica('ASSENTE1'));
        self::assertNull($this->servizio->verifica('ATTESA01'));
        $this->prenotazione($this->turno($this->evento('Con classe', 1, 'evento', ['attestati' => 1])), ['codice' => 'CLASSE2']);
        self::assertNull($this->servizio->verifica('CLASSE2'), 'attestati di classe: vale solo il codice dello studente');
    }

    public function testAttestatoPerCodiceConMatricolaEffettiva(): void
    {
        $t = $this->turno($this->evento('Seminario', 1, 'evento'), '2026-09-10');
        $this->db->esegui("INSERT INTO utenti (id, matricola_studente, matricola_dipendente, matricola) VALUES (7, NULL, 'D77', NULL), (8, '123456', NULL, NULL)");
        $this->prenotazione($t, ['codice' => 'M1', 'utente' => 7]);
        $this->prenotazione($t, ['codice' => 'M2', 'utente' => 8, 'matricola' => 'MAT-PREN']);
        $this->prenotazione($t, ['codice' => 'M3']);
        self::assertSame('D77', $this->servizio->perCodice('M1')['matricola_effettiva']);
        self::assertSame('MAT-PREN', $this->servizio->perCodice('M2')['matricola_effettiva'], 'prima quella dell\'iscrizione');
        self::assertSame('', $this->servizio->perCodice('M3')['matricola_effettiva']);
        self::assertNull($this->servizio->perCodice('NONESISTE'));
        self::assertSame('Didattica DiBEST', $this->servizio->perCodice('M1')['nome_portale']);
        self::assertSame(0, $this->servizio->idPerCodice('NONESISTE'));
        self::assertGreaterThan(0, $this->servizio->idPerCodice('M2'));
        self::assertSame($this->servizio->idPerCodice('M2'), $this->servizio->idPerCodice('m2'), 'la ricerca ignora le maiuscole come il database');
    }

    public function testPrenotazionePerAttestatiHaTuttiICampi(): void
    {
        $prog = $this->evento('Progetto', 2, 'progetto', ['attestati' => 1, 'per_scuole' => 1, 'data_inizio' => '2026-01-10', 'data_fine' => '2026-03-01', 'ore_totali' => 30]);
        $pr = $this->prenotazione($this->turno($prog));
        $p = $this->servizio->prenotazione($pr);
        self::assertSame('progetto', $p['evento_tipo']);
        self::assertSame('FSL', $p['pagina_titolo']);
        self::assertSame('Anna Bianchi', $p['firma_nome']);
        self::assertSame('#445566', $p['colore_primario']);
        self::assertSame('Didattica DiBEST', $p['nome_portale']);
        self::assertSame('1', $p['attestati'], 'come la vecchia query testuale: tutto stringhe');
        self::assertSame('30', $p['ore_totali']);
        self::assertNull($this->servizio->prenotazione(99999));
    }

    public function testCronInviaUnaVoltaSolaEPerLeRegoleGiuste(): void
    {
        $normale = $this->evento('Seminario', 1, 'evento');
        $passato = $this->turno($normale, '2026-09-10', '10:00:00', '12:00:00');
        $oggiMattina = $this->turno($normale, '2026-10-05', '09:00:00', '11:00:00');
        $oggiSera = $this->turno($normale, '2026-10-05', '18:00:00', '20:00:00');
        $a = $this->prenotazione($passato, ['email' => 'a@x.it', 'nome' => 'A <i>', 'cognome' => 'Uno']);
        $b = $this->prenotazione($oggiMattina, ['email' => 'b@x.it']);
        $c = $this->prenotazione($oggiSera, ['email' => 'c@x.it']);
        $d = $this->prenotazione($passato, ['email' => 'd@x.it', 'presente' => 0]);
        $e = $this->prenotazione($passato, ['email' => 'e@x.it', 'stato' => 'in_attesa']);
        $f = $this->prenotazione($passato, ['email' => 'f@x.it', 'inviato' => 1]);
        $senzaData = $this->prenotazione($this->turno($normale), ['email' => 'g@x.it']);
        $senza = $this->prenotazione($this->turno($this->evento('P no', 2, 'progetto', ['attestati' => 0])), ['email' => 'h@x.it']);
        $attesa = $this->prenotazione($this->turno($this->evento('P attesa', 2, 'progetto', ['attestati' => 1, 'data_fine' => '2026-12-01'])), ['email' => 'i@x.it']);
        $classe = $this->prenotazione($this->turno($this->evento('P scuole', 2, 'progetto', ['attestati' => 1, 'data_fine' => '2026-03-01'])), ['email' => 'l@scuola.it']);
        $this->studente($classe, 'Rossi', 'Mario');
        $classeVuota = $this->prenotazione($this->turno($this->evento('P scuole 2', 2, 'progetto', ['attestati' => 1, 'data_fine' => '2026-03-01'])), ['email' => 'm@scuola.it']);

        $cron = new ServizioCronAttestati($this->repo, $this->servizio, $this->mailer, new ColoriAree($this->db), new OrologioFisso('2026-10-05 12:00:00'));
        self::assertSame(4, $cron->invia('https://sito.test/eventi'), 'a, b e senza data (personali) + la classe con elenco (gruppo)');
        $destinatari = array_column($this->mailer->inviate, 'a');
        sort($destinatari);
        self::assertSame(['a@x.it', 'b@x.it', 'g@x.it', 'l@scuola.it'], $destinatari);
        foreach ([$a, $b, $senzaData, $classe] as $id) {
            self::assertSame(1, (int) $this->db->valore('SELECT attestato_inviato FROM prenotazioni WHERE id = ?', [$id]));
        }
        foreach ([$c, $d, $e, $senza, $attesa, $classeVuota] as $id) {
            self::assertSame(0, (int) $this->db->valore('SELECT attestato_inviato FROM prenotazioni WHERE id = ?', [$id]), "prenotazione $id");
        }
        $mailA = $this->mailer->inviate[0];
        self::assertSame('Il tuo Attestato è pronto: Seminario', $mailA['oggetto']);
        self::assertStringContainsString('<strong>A <i> Uno</strong>', $mailA['corpo'], 'il nome non viene protetto nel cron (come prima)');
        self::assertStringContainsString("href='https://sito.test/eventi/stampa_attestato.php?code=", $mailA['corpo']);
        self::assertStringContainsString("<p style='text-align: center; margin: 30px 0;'>\n                    <a href=", $mailA['corpo']);
        self::assertStringContainsString("</a>\n                  </p><p>In alternativa, puoi sempre", $mailA['corpo']);
        self::assertStringContainsString('è stato generato ed è ora disponibile per il download', $mailA['corpo']);
        self::assertSame(0, $cron->invia('https://sito.test/eventi'), 'la seconda esecuzione non invia più nulla');
        self::assertCount(4, $this->mailer->inviate);
    }
}
