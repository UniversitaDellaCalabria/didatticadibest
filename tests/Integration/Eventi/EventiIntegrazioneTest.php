<?php

declare(strict_types=1);

namespace Tests\Integration\Eventi;

use App\Core\Sito;
use App\Eventi\AgendaRepository;
use App\Eventi\Anagrafe;
use App\Eventi\AreaRepository;
use App\Eventi\AuleTurni;
use App\Eventi\EventoRepository;
use App\Eventi\PresentazioneComune;
use App\Eventi\ProgettoRepository;
use App\Eventi\RegoleIscrizioni;
use App\Eventi\ReportRepository;
use App\Eventi\ServizioAgenda;
use App\Eventi\ServizioEventi;
use App\Eventi\ServizioProgetti;
use App\Eventi\ServizioReport;
use App\Eventi\ServizioSchedaProgetto;
use App\Eventi\ServizioSeminari;
use App\Eventi\ServizioTurni;
use App\Eventi\StatisticheRepository;
use App\Eventi\TurnoRepository;
use App\Infrastructure\Storage\Upload;
use RuntimeException;
use Tests\Doppi\MailerFinto;
use Tests\Integration\DatabaseDiProva;

final class EventiIntegrazioneTest extends DatabaseDiProva
{
    private EventoRepository $eventi;
    private TurnoRepository $turni;
    private AreaRepository $aree;
    private MailerFinto $mailer;
    private Anagrafe $anagrafe;
    private RegoleIscrizioni $regole;
    /** @var list<string> */
    private array $auleSincronizzate = [];

    protected function tabelle(): array
    {
        return [
            'pagine_eventi' => "CREATE TABLE pagine_eventi (id INT PRIMARY KEY, slug VARCHAR(80), titolo VARCHAR(150) DEFAULT '', colore_primario VARCHAR(20) DEFAULT '#0056B3', visibile TINYINT DEFAULT 1, ordine INT DEFAULT 0,
                tipo_area VARCHAR(20) DEFAULT '', ambito VARCHAR(20) DEFAULT '', mostra_in_home TINYINT DEFAULT 1)",
            'sottocategorie' => 'CREATE TABLE sottocategorie (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT, nome VARCHAR(100), ordine INT DEFAULT 0, affiancata_in_alto TINYINT DEFAULT 0)',
            'eventi' => "CREATE TABLE eventi (id INT AUTO_INCREMENT PRIMARY KEY, pagina_id INT, sottocategoria_id INT NULL, titolo VARCHAR(150) DEFAULT '', luogo VARCHAR(150) DEFAULT '', descrizione TEXT NULL,
                descrizione_breve TEXT NULL, locandina_path VARCHAR(255) DEFAULT '', allegato_pdf VARCHAR(255) NULL, tipo VARCHAR(20) DEFAULT 'evento', archiviato TINYINT DEFAULT 0, ordine INT DEFAULT 0,
                ruolo_accesso_id INT DEFAULT 0, richiede_prenotazione TINYINT DEFAULT 1, ambiti VARCHAR(100) DEFAULT '', relatore VARCHAR(255) DEFAULT '', relatore_ente VARCHAR(255) DEFAULT '',
                relatore_persona_id VARCHAR(80) NULL, abstract TEXT NULL, link_streaming VARCHAR(500) DEFAULT '', link_registrazione VARCHAR(500) DEFAULT '', slide_pdf VARCHAR(255) NULL,
                email_notifiche_extra VARCHAR(255) NULL, blocca_auto_archivio TINYINT DEFAULT 0, is_evidenza TINYINT DEFAULT 0, abilita_presenze TINYINT DEFAULT 0, gestori_utenti_ids VARCHAR(200) DEFAULT '')",
            'turni' => "CREATE TABLE turni (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT, nome_turno VARCHAR(150) NULL, data_turno DATE NULL, orario_inizio TIME NULL, orario_fine TIME NULL, max_posti INT DEFAULT 30,
                data_apertura DATETIME NULL, data_chiusura DATETIME NULL, annullabile_fino DATETIME NULL, min_partecipanti INT NULL, max_partecipanti INT NULL, abilita_lista_attesa TINYINT DEFAULT 0,
                abilita_multi_posto TINYINT DEFAULT 0, richiede_approvazione TINYINT DEFAULT 0, risorsa_id INT NULL)",
            'prenotazioni' => "CREATE TABLE prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, turno_id INT, codice_prenotazione VARCHAR(40) DEFAULT '', stato VARCHAR(30) NULL, num_posti INT DEFAULT 1,
                nome VARCHAR(80) DEFAULT '', cognome VARCHAR(80) DEFAULT '', email VARCHAR(150) DEFAULT '', presente TINYINT DEFAULT 0, attestato_inviato TINYINT DEFAULT 0, scuola_codice VARCHAR(20) NULL, dati_custom_json TEXT NULL,
                data_prenotazione DATETIME DEFAULT CURRENT_TIMESTAMP)",
            'messaggi_prenotazioni' => 'CREATE TABLE messaggi_prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, prenotazione_id INT)',
            'partecipanti_prenotazione' => 'CREATE TABLE partecipanti_prenotazione (id INT AUTO_INCREMENT PRIMARY KEY, prenotazione_id INT)',
            'prenotazioni_risorse' => "CREATE TABLE prenotazioni_risorse (id INT AUTO_INCREMENT PRIMARY KEY, turno_id INT NULL, stato VARCHAR(30) DEFAULT 'confermata')",
            'campi_form' => "CREATE TABLE campi_form (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT NULL, pagina_id INT NULL, nome_campo VARCHAR(80), etichetta VARCHAR(150) DEFAULT '', ordine INT DEFAULT 0)",
            'progetti_dettagli' => "CREATE TABLE progetti_dettagli (evento_id INT PRIMARY KEY, struttura VARCHAR(255) DEFAULT '', data_inizio DATE NULL, data_fine DATE NULL, periodo_note VARCHAR(255) DEFAULT '', destinatari VARCHAR(255) DEFAULT '',
                modalita VARCHAR(100) DEFAULT '', ore_totali INT NULL, incontri_previsti INT NULL, min_studenti INT NULL, max_studenti INT NULL, referenti_json TEXT NULL, info_extra_json TEXT NULL, moduli_json TEXT NULL,
                obiettivi TEXT NULL, conoscenze TEXT NULL, competenze TEXT NULL, per_scuole TINYINT DEFAULT 1, attestati TINYINT DEFAULT 0, convenzione TINYINT DEFAULT 0, dedicata_scuole TINYINT DEFAULT 0,
                corso_codice VARCHAR(20) NULL, corsi_codici TEXT NULL, destinazione VARCHAR(300) NULL, insegnamento_id INT NULL, updated_at DATETIME NULL)",
            'sondaggi' => 'CREATE TABLE sondaggi (id INT AUTO_INCREMENT PRIMARY KEY, evento_id INT, attivo TINYINT DEFAULT 1, titolo VARCHAR(150) DEFAULT \'\')',
            'sondaggi_domande' => 'CREATE TABLE sondaggi_domande (id INT AUTO_INCREMENT PRIMARY KEY, sondaggio_id INT, testo VARCHAR(255) DEFAULT \'\', condizione_json TEXT NULL)',
            'sondaggi_risposte' => 'CREATE TABLE sondaggi_risposte (id INT AUTO_INCREMENT PRIMARY KEY, sondaggio_id INT, domanda_id INT NULL)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->eventi = new EventoRepository($this->db);
        $this->turni = new TurnoRepository($this->db);
        $this->aree = new AreaRepository($this->db);
        $this->mailer = new MailerFinto();
        $this->auleSincronizzate = [];
        $this->anagrafe = new class () implements Anagrafe {
            public array $corsi = ['LM01' => ['codice' => 'LM01']];

            public function persona(string $id): ?array
            {
                return $id === 'mario.rossi' ? ['id' => 'mario.rossi', 'dettaglio_il' => '2026-01-01'] : null;
            }

            public function completaPersona(array $persona): void
            {
            }

            public function insegnamento(int $id): ?array
            {
                return $id === 7 ? ['id' => 7] : null;
            }

            public function corsoStudio(string $codice): ?array
            {
                return $this->corsi[$codice] ?? null;
            }

            public function assicuraCampiProgetto(int $paginaId): void
            {
                $GLOBALS['prova_eventi_campi_progetto'][] = $paginaId;
            }
        };
        $db = $this->db;
        $this->regole = new class ($db) implements RegoleIscrizioni {
            public function __construct(private \App\Core\Database $db)
            {
            }

            public function postiOccupati(int $turnoId): int
            {
                return (int) $this->db->valore("SELECT COALESCE(SUM(num_posti), 0) FROM prenotazioni WHERE turno_id = ? AND IFNULL(stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')", [$turnoId]);
            }

            public function limitiPartecipanti(?array $dettagli, ?array $turno = null): array
            {
                return ['min' => 1, 'max' => null];
            }

            public function decadiAtteseVincolate(int $prenotazioneId): int
            {
                return 0;
            }
        };
        $GLOBALS['prova_eventi_campi_progetto'] = [];
    }

    private function servizioEventi(): ServizioEventi
    {
        return new ServizioEventi($this->db, $this->eventi, $this->anagrafe);
    }

    private function servizioTurni(): ServizioTurni
    {
        $aule = new class ($this) implements AuleTurni {
            public function __construct(private EventiIntegrazioneTest $t)
            {
            }

            public function sincronizza(int $turnoId, array &$avvisi, int $utenteId = 0): void
            {
                $this->t->registraAula("$turnoId:$utenteId");
            }
        };
        $presentazione = new class () implements PresentazioneComune {
            public function coloreValido(mixed $hex, string $predefinito = '#B30000'): string
            {
                return $predefinito;
            }

            public function testoPostiLiberi(int $liberi): string
            {
                return "$liberi liberi";
            }

            public function coloreAreaTurno(int $turnoId): string
            {
                return '#112233';
            }
        };

        return new ServizioTurni($this->turni, $this->eventi, $this->servizioEventi(), $this->regole, $aule, $presentazione, $this->mailer, new Sito(sys_get_temp_dir(), 'https://portale.test/eventi'));
    }

    public function registraAula(string $voce): void
    {
        $this->auleSincronizzate[] = $voce;
    }

    private function nuovoEvento(string $titolo, int $pagina = 1, string $tipo = 'evento'): int
    {
        return $this->db->inserisci('INSERT INTO eventi (pagina_id, titolo, tipo) VALUES (?, ?, ?)', [$pagina, $titolo, $tipo]);
    }

    private function nuovoTurno(int $evento, array $v = []): int
    {
        $v += ['nome_turno' => 'T', 'data_turno' => '2999-05-01', 'orario_inizio' => '09:00:00', 'orario_fine' => '11:00:00', 'max_posti' => 10];

        return $this->db->inserisci('INSERT INTO turni (evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti) VALUES (?, ?, ?, ?, ?, ?)', [$evento, $v['nome_turno'], $v['data_turno'], $v['orario_inizio'], $v['orario_fine'], $v['max_posti']]);
    }

    public function testDuplicaEventoCopiaTurniFormSondaggiEScheda(): void
    {
        $ev = $this->nuovoEvento('Evento', 1, 'progetto');
        $this->db->esegui('UPDATE eventi SET archiviato = 1, ambiti = ? WHERE id = ?', ['ricerca', $ev]);
        $t = $this->nuovoTurno($ev, ['nome_turno' => 'Gruppo 1']);
        $this->nuovoTurno($ev, ['nome_turno' => '']);
        $this->db->esegui("INSERT INTO prenotazioni (turno_id, nome) VALUES (?, 'Iscritto')", [$t]);
        $this->db->esegui("INSERT INTO campi_form (evento_id, nome_campo, etichetta) VALUES (?, 'scuola', 'Scuola')", [$ev]);
        $this->db->esegui("INSERT INTO progetti_dettagli (evento_id, struttura, per_scuole) VALUES (?, 'DiBEST', 1)", [$ev]);
        $s = $this->db->inserisci('INSERT INTO sondaggi (evento_id, attivo, titolo) VALUES (?, 1, ?)', [$ev, 'Gradimento']);
        $d1 = $this->db->inserisci('INSERT INTO sondaggi_domande (sondaggio_id, testo) VALUES (?, ?)', [$s, 'Domanda 1']);
        $this->db->inserisci('INSERT INTO sondaggi_domande (sondaggio_id, testo, condizione_json) VALUES (?, ?, ?)', [$s, 'Domanda 2', json_encode(['se_id' => $d1, 'valore' => 'si'])]);
        $this->db->inserisci('INSERT INTO sondaggi_domande (sondaggio_id, testo, condizione_json) VALUES (?, ?, ?)', [$s, 'Domanda 3', json_encode(['se_id' => 99999])]);

        $r = $this->servizioEventi()->duplicaEvento($ev);

        self::assertSame(2, $r['turni']);
        self::assertSame(1, $r['sondaggi']);
        $copia = $this->eventi->perId($r['evento']);
        self::assertSame('Evento (copia)', $copia['titolo']);
        self::assertSame('0', $copia['archiviato'], 'la copia non è archiviata');
        self::assertSame('ricerca', $copia['ambiti']);
        self::assertCount(2, $this->turni->perEvento($r['evento']));
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM prenotazioni WHERE turno_id IN (SELECT id FROM turni WHERE evento_id = ?)', [$r['evento']]), 'niente iscritti');
        self::assertSame('Scuola', $this->db->valore('SELECT etichetta FROM campi_form WHERE evento_id = ?', [$r['evento']]));
        self::assertSame('DiBEST', $this->db->valore('SELECT struttura FROM progetti_dettagli WHERE evento_id = ?', [$r['evento']]));
        self::assertSame(0, (int) $this->db->valore('SELECT attivo FROM sondaggi WHERE evento_id = ?', [$r['evento']]), 'il sondaggio copiato non è attivo');
        $nuovi = $this->db->righe('SELECT id, condizione_json FROM sondaggi_domande WHERE sondaggio_id = (SELECT id FROM sondaggi WHERE evento_id = ?) ORDER BY id', [$r['evento']]);
        self::assertCount(3, $nuovi);
        self::assertSame((int) $nuovi[0]['id'], json_decode((string) $nuovi[1]['condizione_json'], true)['se_id'], 'la condizione punta alla domanda nuova');
        self::assertNull($nuovi[2]['condizione_json'], 'condizione su una domanda che non esiste: tolta');
        self::assertSame(1, $this->db->valore('SELECT COUNT(*) FROM sondaggi WHERE evento_id = ?', [$ev]), 'l\'originale resta com\'è');
    }

    public function testDuplicaEventoSenzaTurniEEventoInesistente(): void
    {
        $ev = $this->nuovoEvento('Solo scheda');
        $this->nuovoTurno($ev);
        $r = $this->servizioEventi()->duplicaEvento($ev, false);
        self::assertSame(0, $r['turni']);
        self::assertCount(0, $this->turni->perEvento($r['evento']));
        $this->expectException(RuntimeException::class);
        $this->servizioEventi()->duplicaEvento(99999);
    }

    public function testDuplicaTurnoMarcaLaCopia(): void
    {
        $ev = $this->nuovoEvento('E');
        $t = $this->nuovoTurno($ev, ['nome_turno' => 'Gruppo 1']);
        $senza = $this->nuovoTurno($ev, ['nome_turno' => '']);
        $s = $this->servizioEventi();
        $copia = $s->duplicaTurno($t, $ev, true);
        $copiaSenzaNome = $s->duplicaTurno($senza, $ev, true);
        $identica = $s->duplicaTurno($t, $ev, false);
        self::assertSame('Gruppo 1 (copia)', $this->turni->nome($copia));
        self::assertNull($this->turni->nome($copiaSenzaNome), 'senza nome resta senza nome');
        self::assertSame('Gruppo 1', $this->turni->nome($identica));
        self::assertSame('2999-05-01', $this->turni->nomeEData($copia)['data_turno']);
    }

    public function testEliminaEventoEDipendenze(): void
    {
        $ev = $this->nuovoEvento('Da eliminare');
        $altro = $this->nuovoEvento('Resta');
        $t = $this->nuovoTurno($ev);
        $tAltro = $this->nuovoTurno($altro);
        $pr = $this->db->inserisci("INSERT INTO prenotazioni (turno_id, nome) VALUES (?, 'A')", [$t]);
        $this->db->esegui('INSERT INTO messaggi_prenotazioni (prenotazione_id) VALUES (?)', [$pr]);
        $this->db->esegui('INSERT INTO partecipanti_prenotazione (prenotazione_id) VALUES (?)', [$pr]);
        $this->db->esegui("INSERT INTO prenotazioni_risorse (turno_id, stato) VALUES (?, 'confermata')", [$t]);
        $this->db->esegui("INSERT INTO campi_form (evento_id, nome_campo) VALUES (?, 'x')", [$ev]);
        $this->db->esegui('INSERT INTO progetti_dettagli (evento_id) VALUES (?)', [$ev]);
        $s = $this->db->inserisci('INSERT INTO sondaggi (evento_id) VALUES (?)', [$ev]);
        $d = $this->db->inserisci('INSERT INTO sondaggi_domande (sondaggio_id) VALUES (?)', [$s]);
        $this->db->esegui('INSERT INTO sondaggi_risposte (sondaggio_id, domanda_id) VALUES (?, ?)', [$s, $d]);

        self::assertTrue($this->servizioEventi()->eliminaEvento($ev));

        foreach (['eventi' => 'id', 'turni' => 'evento_id', 'campi_form' => 'evento_id', 'progetti_dettagli' => 'evento_id', 'sondaggi' => 'evento_id'] as $tab => $col) {
            self::assertSame(0, (int) $this->db->valore("SELECT COUNT(*) FROM $tab WHERE $col = ?", [$ev]), $tab);
        }
        foreach (['prenotazioni', 'messaggi_prenotazioni', 'partecipanti_prenotazione', 'sondaggi_domande', 'sondaggi_risposte'] as $tab) {
            self::assertSame(0, (int) $this->db->valore("SELECT COUNT(*) FROM $tab"), $tab);
        }
        self::assertSame('annullata', $this->db->valore('SELECT stato FROM prenotazioni_risorse'), 'l\'aula torna libera');
        self::assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM eventi WHERE id = ?', [$altro]));
        self::assertSame(1, (int) $this->db->valore('SELECT COUNT(*) FROM turni WHERE id = ?', [$tAltro]));
    }

    public function testPromuoviLaListaDAttesaQuandoSiLiberanoPosti(): void
    {
        $ev = $this->nuovoEvento('Laboratorio');
        $t = $this->nuovoTurno($ev, ['max_posti' => 3]);
        $this->db->esegui("INSERT INTO prenotazioni (turno_id, codice_prenotazione, stato, num_posti, nome, email, data_prenotazione) VALUES
            (?, 'C1', 'confermata', 3, 'Pieno', 'pieno@x.it', '2026-01-01 10:00:00'),
            (?, 'C2', 'in_attesa', 2, 'Anna <b>', 'anna@x.it', '2026-01-02 10:00:00'),
            (?, 'C3', 'in_attesa', 5, 'Troppi', 'troppi@x.it', '2026-01-03 10:00:00')", [$t, $t, $t]);
        $s = $this->servizioTurni();
        self::assertSame(0, $s->promuoviAttesa($t), 'nessun posto libero');

        $this->db->esegui('UPDATE turni SET max_posti = 6 WHERE id = ?', [$t]);
        self::assertSame(1, $s->promuoviAttesa($t), 'si promuove Anna (2 posti), non chi ne chiede 5');
        self::assertSame('confermata', $this->db->valore("SELECT stato FROM prenotazioni WHERE codice_prenotazione = 'C2'"));
        self::assertSame('in_attesa', $this->db->valore("SELECT stato FROM prenotazioni WHERE codice_prenotazione = 'C3'"));
        self::assertCount(1, $this->mailer->inviate);
        $m = $this->mailer->inviate[0];
        self::assertSame('anna@x.it', $m['a']);
        self::assertSame('Posto Confermato: Laboratorio', $m['oggetto']);
        self::assertSame('#112233', $m['colore']);
        self::assertStringContainsString('Anna &lt;b&gt;', $m['corpo']);
        self::assertStringContainsString('https://portale.test/eventi/stampa_ricevuta.php?code=C2', $m['corpo']);
    }

    public function testSalvaITurniDellEvento(): void
    {
        $ev = $this->nuovoEvento('E');
        $esistente = $this->nuovoTurno($ev, ['nome_turno' => 'Da tenere']);
        $conIscritti = $this->nuovoTurno($ev, ['nome_turno' => 'Con iscritti', 'data_turno' => '2999-06-01']);
        $daTogliere = $this->nuovoTurno($ev, ['nome_turno' => 'Da togliere']);
        $this->db->esegui("INSERT INTO prenotazioni (turno_id, stato) VALUES (?, 'confermata')", [$conIscritti]);
        $this->db->esegui("INSERT INTO prenotazioni (turno_id, stato) VALUES (?, 'annullata')", [$daTogliere]);
        $riga = static fn (int $id, ?string $nome, ?string $data, array $extra = []): array => $extra + ['id' => $id, 'nome' => $nome, 'data' => $data, 'in' => null, 'fi' => null, 'max' => 8, 'ap' => null,
            'ch' => null, 'ann' => null, 'min' => null, 'maxs' => null, 'wa' => 1, 'mp' => 0, 'app' => 0, 'aula' => null];
        $avvisi = [];

        $promossi = $this->servizioTurni()->salvaTurniEvento($ev, [
            $riga($esistente, 'Rinominato', '2999-05-02', ['aula' => 5]),
            $riga(0, 'Nuovo', '2999-07-01'),
        ], $avvisi, 42);

        self::assertSame(0, $promossi);
        $tutti = $this->turni->perEvento($ev);
        $nomi = array_column($tutti, 'nome_turno');
        self::assertContains('Rinominato', $nomi);
        self::assertContains('Nuovo', $nomi);
        self::assertContains('Con iscritti', $nomi, 'con iscritti attivi non si elimina');
        self::assertNotContains('Da togliere', $nomi, 'con sole prenotazioni annullate si elimina');
        self::assertCount(1, $avvisi);
        self::assertStringContainsString('non ho eliminato il turno "Con iscritti · 01/06/2999"', $avvisi[0]);
        self::assertSame(5, (int) $this->db->valore('SELECT risorsa_id FROM turni WHERE id = ?', [$esistente]));
        self::assertNull($this->db->valore("SELECT risorsa_id FROM turni WHERE nome_turno = 'Nuovo'"));
        self::assertCount(2, $this->auleSincronizzate, 'aule sincronizzate per i turni salvati, con l\'utente');
        self::assertStringEndsWith(':42', $this->auleSincronizzate[0]);
        self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM prenotazioni WHERE turno_id = ?', [$daTogliere]));
    }

    public function testSchedaDelProgettoConEdizioni(): void
    {
        $s = new ServizioSchedaProgetto($this->db, $this->eventi, new ProgettoRepository($this->db), $this->servizioTurni(), $this->anagrafe);
        $evento = ['titolo' => 'Progetto', 'luogo' => 'Lab', 'desc' => '<p>D</p>', 'evid' => 1, 'ord' => 2, 'notif_csv' => 'a@b.it', 'attestati' => 1, 'locandina' => null, 'pdf' => null, 'elimina_locandina' => false, 'elimina_pdf' => false];
        $d = ['struttura' => 'DiBEST', 'data_inizio' => '2999-01-10', 'data_fine' => null, 'periodo_note' => '', 'destinatari' => 'Scuole', 'modalita' => '', 'ore_totali' => 20, 'incontri_previsti' => null, 'min_studenti' => 5, 'max_studenti' => 25];
        $json = ['referenti_json' => '[{"nome":"R"}]', 'info_json' => null, 'moduli_json' => null, 'obiettivi' => null, 'conoscenze' => null, 'competenze' => null, 'per_scuole' => 1, 'attestati' => 1];
        $ed = static fn (int $id, string $nome): array => ['id' => $id, 'nome' => $nome, 'posti' => 1, 'apertura' => '2999-01-01 08:00:00', 'chiusura' => '2999-01-20 18:00:00', 'min' => 10, 'max' => 20];

        $r = $s->salva(3, 0, $evento, $d, $json, 'LM01', null, [$ed(0, 'Ed A'), $ed(0, '')], 1, 0, 1);

        $p = $this->eventi->perId($r['evento']);
        self::assertSame('progetto', $p['tipo']);
        self::assertSame('-1', $p['ruolo_accesso_id'], 'iscrizione solo con accesso');
        self::assertSame('3', $p['pagina_id']);
        self::assertSame('a@b.it', $p['email_notifiche_extra']);
        $scheda = $this->eventi->dettagliProgetti([$r['evento']])[$r['evento']];
        self::assertSame('DiBEST', $scheda['struttura']);
        self::assertSame('LM01', $scheda['corso_codice']);
        self::assertSame('1', (string) $scheda['convenzione']);
        self::assertSame([['nome' => 'R']], $scheda['referenti']);
        $turni = $this->turni->perEvento($r['evento']);
        self::assertSame(['Ed A', 'Edizione 2'], array_column($turni, 'nome_turno'));
        self::assertSame('1', $turni[0]['abilita_lista_attesa']);
        self::assertSame([3], $GLOBALS['prova_eventi_campi_progetto']);

        // modifica: un'edizione in meno (quella senza iscritti si elimina), un'iscritta si tiene
        $this->db->esegui("INSERT INTO prenotazioni (turno_id, stato) VALUES (?, 'confermata')", [(int) $turni[0]['id']]);
        $r2 = $s->salva(3, $r['evento'], ['titolo' => 'Progetto 2', 'elimina_locandina' => true] + $evento, $d, $json, '', 'openlab', [], 0, 0, 0);
        self::assertSame([], $r2['non_tolte'], 'rimando: le edizioni non si toccano');
        $this->db->esegui('UPDATE progetti_dettagli SET destinazione = NULL WHERE evento_id = ?', [$r['evento']]);
        $r3 = $s->salva(3, $r['evento'], $evento, $d, $json, '', null, [$ed((int) $turni[1]['id'], 'Seconda')], 0, 1, 0);
        self::assertSame(['Ed A'], $r3['non_tolte'], 'l\'edizione con iscritti non si elimina');
        self::assertSame(['Ed A', 'Seconda'], array_column($this->turni->perEvento($r['evento']), 'nome_turno'));
        self::assertNull($this->eventi->dettagliProgetti([$r['evento']])[$r['evento']]['corso_codice']);
    }

    public function testRollbackSeLaSchedaDelProgettoFallisce(): void
    {
        self::$conn->query('DROP TABLE progetti_dettagli');
        $s = new ServizioSchedaProgetto($this->db, $this->eventi, new ProgettoRepository($this->db), $this->servizioTurni(), $this->anagrafe);
        $evento = ['titolo' => 'Non deve restare', 'luogo' => '', 'desc' => '', 'evid' => 0, 'ord' => 0, 'notif_csv' => null, 'attestati' => 0, 'locandina' => null, 'pdf' => null, 'elimina_locandina' => false, 'elimina_pdf' => false];
        $d = array_fill_keys(['struttura', 'data_inizio', 'data_fine', 'periodo_note', 'destinatari', 'modalita', 'ore_totali', 'incontri_previsti', 'min_studenti', 'max_studenti'], null);
        $json = ['referenti_json' => null, 'info_json' => null, 'moduli_json' => null, 'obiettivi' => null, 'conoscenze' => null, 'competenze' => null, 'per_scuole' => 1, 'attestati' => 0];
        try {
            $s->salva(1, 0, $evento, $d, $json, '', null, [], 0, 0, 0);
            self::fail('doveva fallire');
        } catch (RuntimeException) {
            self::assertSame(0, (int) $this->db->valore('SELECT COUNT(*) FROM eventi'), 'niente a metà');
        }
    }

    public function testSchedaEventoReferentiCorsoEInsegnamento(): void
    {
        $ev = $this->nuovoEvento('E');
        $s = $this->servizioEventi();
        $s->salvaCorso($ev, ['unaltra' => 'x']);
        self::assertFalse($this->eventi->haScheda($ev), 'senza campi struttura/corso non si fa nulla');
        $s->salvaCorso($ev, ['struttura' => '  ', 'corso_codice' => 'XX']);
        self::assertFalse($this->eventi->haScheda($ev), 'tutto vuoto e nessuna scheda: niente');
        $s->salvaCorso($ev, ['struttura' => 'DiBEST', 'corso_codice' => 'LM01']);
        $sc = $this->eventi->dettagliProgetti([$ev])[$ev];
        self::assertSame(['DiBEST', 'LM01'], [$sc['struttura'], $sc['corso_codice']]);
        $s->salvaCorso($ev, ['struttura' => '', 'corso_codice' => '']);
        self::assertSame('', $this->eventi->dettagliProgetti([$ev])[$ev]['struttura'], 'con la scheda già presente si svuota');
        $s->salvaInsegnamento($ev, []);
        $s->salvaInsegnamento($ev, ['insegnamento_id' => '7']);
        self::assertSame('7', $this->eventi->dettagliProgetti([$ev])[$ev]['insegnamento_id']);
        $s->salvaInsegnamento($ev, ['insegnamento_id' => '8']);
        self::assertNull($this->eventi->dettagliProgetti([$ev])[$ev]['insegnamento_id']);
        $s->salvaReferenti($ev, [['nome' => 'Società è']]);
        self::assertSame('[{"nome":"Società è"}]', $this->db->valore('SELECT referenti_json FROM progetti_dettagli WHERE evento_id = ?', [$ev]));
        $s->salvaReferenti($ev, []);
        self::assertNull($this->db->valore('SELECT referenti_json FROM progetti_dettagli WHERE evento_id = ?', [$ev]));
    }

    private function areeAgenda(): void
    {
        $this->db->esegui("INSERT INTO pagine_eventi (id, slug, titolo, colore_primario, tipo_area, ambito, ordine) VALUES
            (1, 'openlab', 'OpenLab', '#112233', 'eventi', 'orientamento', 1),
            (2, 'fsl', 'FSL', '#445566', 'fsl', '', 2),
            (3, 'aule', 'Aule', '#778899', 'calendario', '', 3),
            (4, 'nascosta', 'Nascosta', '#000000', 'eventi', '', 4)");
        $this->db->esegui('UPDATE pagine_eventi SET visibile = 0 WHERE id = 4');
        $this->db->esegui('UPDATE pagine_eventi SET mostra_in_home = 0 WHERE id = 2');
    }

    private function agenda(): ServizioAgenda
    {
        return new ServizioAgenda(new AgendaRepository($this->db), $this->aree, new Sito(sys_get_temp_dir(), 'https://portale.test/eventi'));
    }

    public function testAgendaFiltriOrdineEDataDaDefinire(): void
    {
        $this->areeAgenda();
        $b = $this->nuovoEvento('Beta seminario', 1);
        $a = $this->nuovoEvento('Alfa orientamento', 1);
        $senza = $this->nuovoEvento('Senza data', 1);
        $fsl = $this->nuovoEvento('Progetto scuole', 2, 'progetto');
        $this->nuovoEvento('In area calendario', 3);
        $this->nuovoEvento('In area nascosta', 4);
        $archiviato = $this->nuovoEvento('Archiviato', 1);
        $riservato = $this->nuovoEvento('Riservato', 1);
        $this->db->esegui('UPDATE eventi SET archiviato = 1 WHERE id = ?', [$archiviato]);
        $this->db->esegui('UPDATE eventi SET ruolo_accesso_id = 2 WHERE id = ?', [$riservato]);
        $this->db->esegui("UPDATE eventi SET ambiti = 'ricerca', relatore = 'Prof. Verdi', relatore_ente = 'CNR' WHERE id = ?", [$b]);
        foreach ([$archiviato, $riservato] as $x) {
            $this->nuovoTurno($x);
        }
        $this->nuovoTurno($b, ['data_turno' => '2999-03-01', 'orario_inizio' => '10:00:00']);
        $this->nuovoTurno($b, ['data_turno' => '2999-03-08', 'orario_inizio' => '10:00:00']);
        $this->nuovoTurno($a, ['data_turno' => '2999-02-01']);
        $this->nuovoTurno($a, ['data_turno' => '2000-01-01']);
        $this->nuovoTurno($fsl, ['data_turno' => '2999-04-01', 'orario_inizio' => null, 'orario_fine' => null]);
        $this->db->esegui('INSERT INTO turni (evento_id, nome_turno, data_turno) VALUES (?, ?, NULL)', [$senza, 'Da definire']);
        $this->db->esegui('INSERT INTO progetti_dettagli (evento_id, per_scuole) VALUES (?, 1)', [$fsl]);
        $s = $this->agenda();

        $tutti = $s->eventi();
        self::assertSame(['Alfa orientamento', 'Beta seminario', 'Progetto scuole', 'Senza data'], array_column($tutti, 'titolo'), 'in ordine di prossima data, «da definire» in fondo; né archiviati, né riservati, né aree calendario o nascoste');
        $beta = $tutti[1];
        self::assertSame('2999-03-01', $beta['prossima_data']);
        self::assertSame('10:00', $beta['prossimo_orario']);
        self::assertSame(['ricerca'], $beta['ambiti']);
        self::assertSame('openlab.php?evento=' . $b, $beta['url']);
        self::assertSame(2, (int) $beta['n_turni']);
        self::assertSame('OpenLab', $beta['area']['titolo']);
        self::assertFalse($beta['per_scuole']);
        self::assertSame('fsl.php?progetto=' . $fsl, $tutti[2]['url']);
        self::assertTrue($tutti[2]['per_scuole']);
        self::assertNull($tutti[3]['prossima_data']);
        self::assertSame('', $tutti[3]['prossimo_orario']);

        self::assertSame(['Beta seminario'], array_column($s->eventi(['ambito' => 'ricerca']), 'titolo'));
        self::assertSame(['Progetto scuole'], array_column($s->eventi(['scuole' => true]), 'titolo'));
        self::assertSame(['Beta seminario'], array_column($s->eventi(['q' => 'VERDI']), 'titolo'), 'la ricerca guarda anche il relatore');
        self::assertSame(['Alfa orientamento', 'Beta seminario'], array_column($s->eventi(['limite' => 2]), 'titolo'));
        self::assertSame(['Alfa orientamento', 'Beta seminario', 'Senza data'], array_column($s->eventi(['solo_home' => true]), 'titolo'), 'solo le aree mostrate in home');
        self::assertSame(['Progetto scuole'], array_column($s->eventi(['pagine' => [2]]), 'titolo'));
        self::assertSame($tutti, $s->eventiInProgramma(), 'è la fonte degli avvisi');

        $n = $s->contaAmbiti($tutti);
        self::assertSame(4, $n['']);
        self::assertSame(1, $n['scuole']);
        self::assertSame(1, $n['ricerca']);
        self::assertSame(3, $n['orientamento']);
    }

    public function testCalendarioIcsDellAgenda(): void
    {
        $this->areeAgenda();
        $e = $this->nuovoEvento('Seminario; con, virgole', 1);
        $this->db->esegui("UPDATE eventi SET luogo = 'Aula 1', relatore = 'Prof. X', relatore_ente = 'Unical', link_streaming = 'https://x.it/live' WHERE id = ?", [$e]);
        $this->nuovoTurno($e, ['nome_turno' => 'Prima', 'data_turno' => '2999-03-01', 'orario_inizio' => '10:00:00', 'orario_fine' => null]);
        $this->nuovoTurno($e, ['nome_turno' => 'Seconda', 'data_turno' => '2999-03-02', 'orario_inizio' => null, 'orario_fine' => null]);
        $s = $this->agenda();
        $ics = $s->ics($s->eventi(), 'Agenda, DiBEST');
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        self::assertStringContainsString('X-WR-CALNAME:Agenda\, DiBEST', $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertStringContainsString("DTSTART;TZID=Europe/Rome:29990301T100000\r\nDTEND;TZID=Europe/Rome:29990301T110000", $ics, 'senza fine: un\'ora');
        self::assertStringContainsString("DTSTART;VALUE=DATE:29990302\r\nDTEND;VALUE=DATE:29990303", $ics);
        self::assertStringContainsString('SUMMARY:Seminario\; con\, virgole – Prima', $ics);
        self::assertStringContainsString('LOCATION:Aula 1', $ics);
        self::assertStringContainsString('DESCRIPTION:Relatore: Prof. X (Unical)\nDiretta: https://x.it/live', $ics);
        self::assertStringContainsString('URL:https://portale.test/eventi/openlab.php?evento=' . $e, $ics);
        self::assertStringContainsString('CATEGORIES:Orientamento', $ics);
        self::assertStringContainsString('UID:turno-', $ics);
        self::assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        self::assertSame("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//DiBEST//Agenda//IT\r\nCALSCALE:GREGORIAN\r\nX-WR-CALNAME:Vuoto\r\nX-WR-TIMEZONE:Europe/Rome\r\nEND:VCALENDAR\r\n", $s->ics([], 'Vuoto'));
    }

    public function testReportPerAmbito(): void
    {
        $this->areeAgenda();
        $e = $this->nuovoEvento('Giornata', 1);
        $this->db->esegui("UPDATE eventi SET ambiti = 'orientamento,ricerca', relatore = 'Prof. X' WHERE id = ?", [$e]);
        $t1 = $this->nuovoTurno($e, ['data_turno' => '2026-03-10', 'orario_inizio' => '09:00:00', 'orario_fine' => '11:30:00']);
        $t2 = $this->nuovoTurno($e, ['data_turno' => '2026-04-02', 'orario_inizio' => null, 'orario_fine' => null]);
        $fuori = $this->nuovoEvento('Fuori periodo', 1);
        $this->nuovoTurno($fuori, ['data_turno' => '2025-12-31']);
        $this->db->esegui("INSERT INTO prenotazioni (turno_id, stato, num_posti, presente, scuola_codice, dati_custom_json) VALUES
            (?, 'confermata', 1, 1, 'CSPS01', '{\"numero_partecipanti\":\"25\"}'),
            (?, NULL, 2, 0, 'CSPS02', NULL),
            (?, 'in_attesa', 4, 0, NULL, NULL),
            (?, 'confermata', 1, 1, NULL, '{\"numero_partecipanti\":\"0\"}')", [$t1, $t1, $t1, $t2]);
        $s = new ServizioReport(new ReportRepository($this->db), $this->aree);

        [$dal, $al] = ServizioReport::periodo('2026');
        $d = $s->dati($dal, $al);

        self::assertCount(1, $d['eventi']);
        $ev = $d['eventi'][0];
        self::assertSame(['orientamento', 'ricerca'], $ev['ambiti']);
        self::assertSame('OpenLab', $ev['area']);
        self::assertSame('Prof. X', $ev['relatore']);
        self::assertSame(2, $ev['incontri']);
        self::assertSame(2.5, $ev['ore']);
        self::assertSame(1 + 2 + 1, $ev['iscrizioni'], 'solo le confermate, per posti');
        self::assertSame(25 + 2 + 1, $ev['partecipanti'], 'per le classi il numero dichiarato');
        self::assertSame(25 + 1, $ev['presenze']);
        self::assertSame(2, $ev['scuole']);
        self::assertSame('2026-03-10', $ev['prima']);
        self::assertSame('2026-04-02', $ev['ultima']);
        self::assertSame(1, $d['ambiti']['orientamento']['eventi']);
        self::assertSame(0, $d['ambiti']['didattica']['eventi']);
        self::assertSame(1, $d['totale']['eventi'], 'ogni evento è contato una volta');
        self::assertSame(['2026-03' => ['orientamento' => 1, 'ricerca' => 1]], $d['mesi']);
        self::assertSame(['eventi' => [], 'ambiti' => [], 'totale' => [], 'mesi' => []], $s->dati('2030-01-01', '2030-12-31'));
        $xlsx = $s->excel($d, '2026');
        self::assertIsString($xlsx);
        self::assertFileExists($xlsx);
        self::assertStringStartsWith('PK', (string) file_get_contents($xlsx), 'un file Excel (zip)');
        unlink($xlsx);
    }

    public function testStatisticheDellArea(): void
    {
        $this->areeAgenda();
        $e = $this->nuovoEvento('Con iscritti', 1);
        $illimitato = $this->nuovoEvento('Senza limite', 1);
        $t = $this->nuovoTurno($e, ['max_posti' => 20, 'data_turno' => '2999-01-01']);
        $this->nuovoTurno($illimitato, ['max_posti' => 5000]);
        $this->db->esegui("INSERT INTO prenotazioni (turno_id, stato, num_posti, presente, data_prenotazione) VALUES
            (?, 'confermata', 3, 1, NOW()), (?, 'richiesta_conferma', 2, 0, NOW()), (?, 'in_attesa', 4, 0, NOW()), (?, 'scaduta', 1, 0, NOW()), (?, 'annullata', 6, 0, '2000-01-01 10:00:00')", [$t, $t, $t, $t, $t]);
        $st = new StatisticheRepository($this->db);

        self::assertSame(['confermate' => 5, 'attesa' => 4, 'perse' => 1, 'capienza' => 20], $st->kpi(1, ''));
        self::assertSame(['confermate' => 5, 'attesa' => 4, 'perse' => 1, 'annullate' => 6, 'presenti' => 3, 'capienza' => 20], $st->kpiCompleti(1, ''));
        self::assertSame(['confermate' => 0, 'attesa' => 0, 'perse' => 0, 'capienza' => 0], $st->kpi(99, ''));
        $g = $st->graficoEventi(1, '');
        self::assertSame(['"Con iscritti"'], $g['nomi'], 'il turno senza limite e senza iscritti non compare');
        self::assertSame([5], $g['occupati']);
        self::assertSame(['20'], $g['capienza']);
        $turni = $st->turniCompleti(1, '');
        self::assertCount(2, $turni);
        self::assertSame('5', $turni[0]['confermati']);
        self::assertSame('4', $turni[0]['attesa']);
        self::assertSame('6', $turni[0]['annullate']);
        self::assertSame('3', $turni[0]['presenti']);
        self::assertSame($st->turni(1, '')[0]['confermati'], $turni[0]['confermati']);
        $trend = $st->trendIscrizioni(1, '', 30);
        self::assertCount(1, $trend, 'la prenotazione del 2000 è fuori dai 30 giorni');
        self::assertSame(date('Y-m-d'), $trend[0]['giorno']);
        self::assertSame('4', $trend[0]['cnt']);
        self::assertCount(5, $st->iscrizioniPerEsportazione(1, ''));
        self::assertSame(0, $st->kpi(1, 'AND 1 = 0')['confermate']);
    }

    public function testRicercaArchivioEdElencoAdmin(): void
    {
        $this->areeAgenda();
        $sez = $this->db->inserisci("INSERT INTO sottocategorie (pagina_id, nome, ordine) VALUES (1, 'Sez', 1)");
        $a = $this->nuovoEvento('Genetica avanzata', 1);
        $arch = $this->nuovoEvento('Vecchio evento', 1);
        $p = $this->nuovoEvento('Un progetto', 1, 'progetto');
        $nascosta = $this->nuovoEvento('Genetica nascosta', 4);
        $this->db->esegui('UPDATE eventi SET archiviato = 1, sottocategoria_id = ? WHERE id = ?', [$sez, $arch]);
        $this->nuovoTurno($arch, ['data_turno' => '2024-05-05']);
        $this->nuovoTurno($a, ['data_turno' => '2999-05-05']);
        $this->nuovoTurno($a, ['data_turno' => '2999-05-01']);
        self::assertSame(['Genetica avanzata'], array_column($this->eventi->cerca('genetica'), 'titolo'), 'solo aree visibili');
        self::assertSame('2999-05-01', $this->eventi->cerca('genetica')[0]['prossima_data']);
        $a1 = $this->eventi->archivio(1);
        self::assertCount(1, $a1);
        self::assertSame('Sez', $a1[0]['nome_sottocategoria']);
        self::assertSame(2024, (int) $a1[0]['anno_evento']);
        $adm = $this->eventi->archiviatiAdmin(1, '');
        self::assertSame('1', $adm[0]['tot_turni']);
        self::assertSame('0', $adm[0]['tot_iscritti']);
        self::assertSame(['Genetica avanzata'], array_column($this->eventi->normaliAdmin(1, ''), 'titolo'));
        self::assertSame(1, $this->eventi->contaProgetti(1, ''));
        self::assertSame(1, $this->eventi->contaNormali(1));
        self::assertSame(['Un progetto'], array_column($this->eventi->progettiAdmin(1, ''), 'titolo'));
        self::assertTrue($this->eventi->progettoAutorizzato($p, 1, ''));
        self::assertFalse($this->eventi->progettoAutorizzato($p, 2, ''));
        self::assertFalse($this->eventi->progettoAutorizzato($a, 1, ''), 'non è un progetto');
        $conTurni = $this->eventi->conTurniAdmin(1, 0, '');
        $genetica = array_values(array_filter($conTurni, static fn (array $e): bool => $e['titolo'] === 'Genetica avanzata'))[0];
        self::assertSame(['2999-05-01', '2999-05-05'], array_column($genetica['turni'], 'data_turno'));
        $this->eventi->ripristina($arch);
        self::assertSame('1', $this->eventi->perId($arch)['blocca_auto_archivio']);
        $this->eventi->archiviaConclusi(1, date('Y-m-d H:i:s'));
        self::assertSame('1', $this->eventi->perId($arch)['archiviato'], 'senza turni futuri: archiviato');
        self::assertSame('0', $this->eventi->perId($a)['archiviato'], 'con turni futuri: resta');
        $this->eventi->salvaOrdineProgetti(1, [$p]);
        self::assertSame('1', $this->eventi->perId($p)['ordine']);
    }

    public function testProgettiDestinazioneEdEdizioni(): void
    {
        $this->areeAgenda();
        $s = new ServizioProgetti($this->eventi, $this->aree, $this->regole);
        self::assertNull($s->destinazione(null));
        self::assertNull($s->destinazione(['destinazione' => '  ']));
        self::assertSame(['url' => 'openlab.php', 'nome' => 'OpenLab', 'esterno' => false], $s->destinazione(['destinazione' => 'OpenLab.php']));
        self::assertSame(['url' => 'https://www.unical.it/x', 'nome' => 'unical.it', 'esterno' => true], $s->destinazione(['destinazione' => 'https://www.unical.it/x']));
        self::assertNull($s->destinazione(['destinazione' => 'area_eliminata']), 'area che non esiste più');
        self::assertNull($s->destinazione(['destinazione' => 'ftp://x.it']));

        $p = $this->nuovoEvento('Progetto', 2, 'progetto');
        $t1 = $this->nuovoTurno($p, ['max_posti' => 1, 'nome_turno' => '']);
        $t2 = $this->nuovoTurno($p, ['max_posti' => 1, 'nome_turno' => 'Seconda']);
        $this->db->esegui("INSERT INTO prenotazioni (turno_id, stato, nome, cognome, email, data_prenotazione) VALUES (?, 'confermata', 'Scuola', 'Uno', 'u@x.it', '2026-01-01 10:00:00'), (?, 'in_attesa', 'A', 'B', 'a@x.it', NOW()), (?, 'in_attesa', 'C', 'D', 'c@x.it', NOW())", [$t1, $t1, $t1]);
        $turni = $this->turni->perEvento($p);
        $info = $s->infoEdizioni(['per_scuole' => 1], $turni, [$t2 => 'confermata']);
        self::assertCount(2, $info['edizioni']);
        $e1 = $info['edizioni'][0];
        self::assertSame('Edizione 1', $e1['etichetta']);
        self::assertSame(2, $e1['attesa']);
        self::assertSame('Scuola', $e1['assegnata']['nome']);
        self::assertFalse($e1['libera']);
        self::assertSame('Seconda', $info['edizioni'][1]['etichetta']);
        self::assertTrue($info['edizioni'][1]['libera']);
        self::assertSame($t2, $info['mio_turno']);
        self::assertSame('confermata', $info['mio']);
        self::assertSame('aperte', $info['stato']['codice'], 'basta un\'edizione aperta');
        self::assertSame(1, $info['liberi']);
        self::assertSame('chiuse', $s->infoEdizioni(null, [])['stato']['codice']);
    }

    public function testSeminarioSalvaERespingeValoriNonValidi(): void
    {
        $e = $this->nuovoEvento('Seminario');
        $radice = sys_get_temp_dir() . '/eventi_seminari_' . uniqid();
        mkdir($radice, 0755, true);
        $s = new ServizioSeminari($this->eventi, $this->anagrafe, new Upload(), new Sito($radice));

        $s->salva($e, [
            'relatore' => '  Dott. Bianchi ', 'relatore_ente' => 'Unical', 'relatore_persona_id' => 'mario.rossi', 'abstract' => '<b>Testo</b> lungo',
            'link_streaming' => 'https://x.it/live', 'link_registrazione' => 'javascript:alert(1)',
        ]);
        $r = $this->eventi->perId($e);
        self::assertSame('Dott. Bianchi', $r['relatore']);
        self::assertSame('mario.rossi', $r['relatore_persona_id']);
        self::assertSame('Testo lungo', $r['abstract'], 'senza HTML');
        self::assertSame('https://x.it/live', $r['link_streaming']);
        self::assertSame('', $r['link_registrazione'], 'solo indirizzi http(s)');

        $s->salva($e, ['relatore_persona_id' => 'sconosciuto', 'abstract' => '  ']);
        $r = $this->eventi->perId($e);
        self::assertNull($r['relatore_persona_id'], 'persona che non esiste nell\'anagrafe');
        self::assertNull($r['abstract']);
        self::assertSame('', $r['relatore']);

        $this->eventi->impostaSlide($e, 'uploads/s.pdf');
        $s->salva($e, ['elimina_slide' => '1'], ['slide_pdf' => ['error' => UPLOAD_ERR_NO_FILE]]);
        self::assertNull($this->eventi->perId($e)['slide_pdf']);
        rmdir($radice);
    }

    public function testSezioniCampiFormEIscrizioniPerEsportazione(): void
    {
        $ev = $this->nuovoEvento('Evento', 1);
        $t = $this->nuovoTurno($ev);
        $this->db->esegui("INSERT INTO campi_form (evento_id, nome_campo, etichetta) VALUES (?, 'scuola', 'Scuola'), (?, 'classe', 'Classe')", [$ev, $ev]);
        $this->db->esegui("INSERT INTO prenotazioni (turno_id, stato, cognome) VALUES (?, 'confermata', 'Zeta'), (?, 'confermata', 'Alfa')", [$t, $t]);
        self::assertSame(['scuola' => 'Scuola', 'classe' => 'Classe'], $this->eventi->campiFormEvento($ev));
        self::assertSame(['Alfa', 'Zeta'], array_column($this->eventi->iscrizioniPerRendicontazione($ev), 'cognome'));
        $this->eventi->creaSezione(1, 'Prima', 2, 1);
        $id = (int) $this->db->valore('SELECT id FROM sottocategorie');
        $this->eventi->impostaSezioneInAlto($id, 2, 0);
        self::assertSame(1, (int) $this->db->valore('SELECT affiancata_in_alto FROM sottocategorie'), 'solo la sezione della propria area');
        $this->eventi->impostaSezioneInAlto($id, 1, 0);
        self::assertSame(0, (int) $this->db->valore('SELECT affiancata_in_alto FROM sottocategorie'));
        self::assertSame([['id' => (string) $id, 'pagina_id' => '1', 'nome' => 'Prima', 'ordine' => '2', 'affiancata_in_alto' => '0']], $this->eventi->sottocategorie(1));
    }
}
