<?php

declare(strict_types=1);

namespace Tests\Integration\Anagrafi;

use App\Anagrafi\CatalogoRepository;
use App\Anagrafi\CollegamentiRepository;
use App\Anagrafi\CollegamentoUtente;
use App\Anagrafi\CorsoRepository;
use App\Anagrafi\InsegnamentoRepository;
use App\Anagrafi\PermessiGestori;
use App\Anagrafi\PersonaRepository;
use App\Anagrafi\RegistroOperazioni;
use App\Anagrafi\ServizioCatalogo;
use App\Anagrafi\ServizioCorsi;
use App\Anagrafi\ServizioPersone;
use App\Anagrafi\SincronizzazioneAnagrafe;
use App\Anagrafi\StruttureRepository;
use App\Auth\Abilitazioni\AbilitazioniRepository;
use App\Auth\Abilitazioni\ModuliAree;
use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\Auth\UtenteRepository;
use App\Core\Orologio;
use App\Core\Sito;
use DateTimeImmutable;
use Tests\Doppi\AnagrafiClientApiFinto;
use Tests\Integration\DatabaseDiProva;

final class AnagrafiIntegrazioneTest extends DatabaseDiProva
{
    private string $radice;
    private AnagrafiClientApiFinto $api;
    private Sito $sito;
    private Orologio $orologio;
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $registro = [];

    protected function tabelle(): array
    {
        return [
            'personale_ateneo' => "CREATE TABLE personale_ateneo (id VARCHAR(80) PRIMARY KEY, cognome VARCHAR(100) NOT NULL DEFAULT '', nome VARCHAR(100) NOT NULL DEFAULT '', email VARCHAR(150) DEFAULT '',
                telefono VARCHAR(60) DEFAULT '', ufficio VARCHAR(255) DEFAULT '', ruolo_cod VARCHAR(10) DEFAULT '', ruolo VARCHAR(150) DEFAULT '', struttura_cod VARCHAR(20) DEFAULT '', struttura VARCHAR(255) DEFAULT '',
                gruppo VARCHAR(10) NOT NULL DEFAULT 'altro', docente TINYINT NOT NULL DEFAULT 0, ssd_cod VARCHAR(20) DEFAULT '', ssd VARCHAR(150) DEFAULT '', origine VARCHAR(20) DEFAULT '', attivo TINYINT NOT NULL DEFAULT 1,
                aggiornata_il DATETIME NULL, uscita_il DATETIME NULL, dettaglio_json MEDIUMTEXT NULL, dettaglio_il DATETIME NULL)",
            'personale_modifiche' => "CREATE TABLE personale_modifiche (persona_id VARCHAR(80) PRIMARY KEY, telefono VARCHAR(60) DEFAULT '', ufficio VARCHAR(255) DEFAULT '', ricevimento TEXT NULL, bio TEXT NULL,
                sito VARCHAR(255) DEFAULT '', aggiornata_il DATETIME NULL)",
            'anagrafe_strutture' => "CREATE TABLE anagrafe_strutture (codice VARCHAR(20) PRIMARY KEY, nome VARCHAR(255) NOT NULL DEFAULT '', persone INT NOT NULL DEFAULT 0, corsi INT NOT NULL DEFAULT 0,
                ultima_sync DATETIME NULL, esito VARCHAR(255) DEFAULT '', aggiunta_il DATETIME DEFAULT CURRENT_TIMESTAMP)",
            'corsi_studio' => "CREATE TABLE corsi_studio (codice VARCHAR(20) PRIMARY KEY, nome VARCHAR(255) NOT NULL DEFAULT '', tipo VARCHAR(10) DEFAULT '', tipo_descrizione VARCHAR(100) DEFAULT '', classe VARCHAR(255) DEFAULT '',
                anno INT NULL, lingua VARCHAR(100) DEFAULT '', durata INT NULL, dipartimento_cod VARCHAR(20) DEFAULT '', visibile TINYINT NOT NULL DEFAULT 1, presente TINYINT NOT NULL DEFAULT 1, aggiornato_il DATETIME NULL, regdid_id INT NULL)",
            'insegnamenti' => "CREATE TABLE insegnamenti (id INT PRIMARY KEY, codice VARCHAR(30) DEFAULT '', nome VARCHAR(255) NOT NULL DEFAULT '', cds_cod VARCHAR(20) DEFAULT '', cds_nome VARCHAR(255) DEFAULT '',
                anno_corso TINYINT NULL, anno_accademico SMALLINT NULL, coorte SMALLINT NULL, semestre VARCHAR(60) DEFAULT '', ssd_cod VARCHAR(20) DEFAULT '', ssd VARCHAR(150) DEFAULT '', lingua VARCHAR(60) DEFAULT '',
                docente VARCHAR(150) DEFAULT '', docente_id VARCHAR(30) DEFAULT '', partizione VARCHAR(150) DEFAULT '', padre_id INT NULL, dipartimento_cod VARCHAR(20) DEFAULT '', presente TINYINT NOT NULL DEFAULT 1,
                aggiornato_il DATETIME NULL, cfu DECIMAL(5,1) NULL)",
            'ateneo_cds' => "CREATE TABLE ateneo_cds (codice VARCHAR(20) NOT NULL, anno SMALLINT NOT NULL, nome VARCHAR(255) NOT NULL DEFAULT '', tipo VARCHAR(10) DEFAULT '', tipo_descrizione VARCHAR(100) DEFAULT '',
                dipartimento_cod VARCHAR(20) DEFAULT '', dipartimento VARCHAR(255) DEFAULT '', aggiornato_il DATETIME NULL, PRIMARY KEY (codice, anno))",
            'ateneo_insegnamenti' => "CREATE TABLE ateneo_insegnamenti (id INT PRIMARY KEY, cds_cod VARCHAR(20) NOT NULL DEFAULT '', coorte SMALLINT NOT NULL DEFAULT 0, anno_corso TINYINT NULL, codice VARCHAR(30) DEFAULT '',
                nome VARCHAR(255) NOT NULL DEFAULT '', cfu DECIMAL(5,1) NULL, ssd_cod VARCHAR(20) DEFAULT '', ssd VARCHAR(150) DEFAULT '', partizione VARCHAR(150) DEFAULT '', semestre VARCHAR(60) DEFAULT '', docente VARCHAR(150) DEFAULT '')",
            'ateneo_insegnamenti_scaricati' => 'CREATE TABLE ateneo_insegnamenti_scaricati (cds_cod VARCHAR(20) NOT NULL, coorte SMALLINT NOT NULL, n INT NOT NULL DEFAULT 0, scaricato_il DATETIME NULL, PRIMARY KEY (cds_cod, coorte))',
            'utenti' => "CREATE TABLE utenti (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(80) DEFAULT '', cognome VARCHAR(80) DEFAULT '', email VARCHAR(150) NULL, ruolo_id INT DEFAULT 5,
                ruoli_secondari VARCHAR(100) DEFAULT '', persona_id VARCHAR(80) NULL, ultimo_accesso DATETIME NULL)",
            'ruoli' => 'CREATE TABLE ruoli (id INT PRIMARY KEY, nome VARCHAR(100))',
            'abilitazioni_attesa' => "CREATE TABLE abilitazioni_attesa (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(150), persona_id VARCHAR(40) NULL, nominativo VARCHAR(200) DEFAULT '', pagina_id INT,
                permessi VARCHAR(20), eventi_ids VARCHAR(200) DEFAULT '', creata_da INT, ambito VARCHAR(40), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
            'abilitazioni_ambito' => 'CREATE TABLE abilitazioni_ambito (utente_id INT, tipo VARCHAR(40), pagina_id INT, creata_da INT DEFAULT 0, PRIMARY KEY (utente_id, tipo, pagina_id))',
            'pagine_eventi' => "CREATE TABLE pagine_eventi (id INT PRIMARY KEY, gestore_utente_id INT DEFAULT 0, gestori_utenti_ids VARCHAR(200) DEFAULT '', permessi_gestori_json TEXT NULL, notifiche_gestori_ids VARCHAR(200) NULL)",
            'eventi' => "CREATE TABLE eventi (id INT PRIMARY KEY, pagina_id INT, tipo VARCHAR(20) NULL, titolo VARCHAR(100) DEFAULT '', archiviato TINYINT DEFAULT 0, gestori_utenti_ids VARCHAR(200) DEFAULT '', permessi_gestori_json TEXT NULL)",
            'progetti_dettagli' => 'CREATE TABLE progetti_dettagli (evento_id INT PRIMARY KEY, convenzione TINYINT DEFAULT 0, referenti_json TEXT NULL, insegnamento_id INT NULL)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->radice = sys_get_temp_dir() . '/anagrafi_prova_' . uniqid();
        mkdir($this->radice . '/cache', 0755, true);
        $this->sito = new Sito($this->radice);
        $this->api = new AnagrafiClientApiFinto();
        $this->registro = [];
        $this->orologio = new class () implements Orologio {
            public function adesso(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-05 10:00:00');
            }
        };
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->radice . '/cache/*') ?: [], glob($this->radice . '/uploads/personale/*') ?: []) as $f) {
            @unlink($f);
        }
        @rmdir($this->radice . '/cache');
        @rmdir($this->radice . '/uploads/personale');
        @rmdir($this->radice . '/uploads');
        @rmdir($this->radice);
    }

    private function catalogo(): ServizioCatalogo
    {
        return new ServizioCatalogo(new CatalogoRepository($this->db), new CorsoRepository($this->db), new InsegnamentoRepository($this->db), $this->api, $this->orologio);
    }

    private function collegamento(): CollegamentoUtente
    {
        $moduli = new class () implements ModuliAree {
            public function disponibile(): bool
            {
                return true;
            }

            public function moduloDiArea(array $pagina): string
            {
                return 'orientamento';
            }
        };
        $abil = new ServizioAbilitazioni(new AbilitazioniRepository($this->db), new UtenteRepository($this->db), $moduli);
        $registro = new class ($this->registro) implements RegistroOperazioni {
            /** @param list<array{0: string, 1: array<string, mixed>}> $righe */
            public function __construct(private array &$righe)
            {
            }

            public function registra(string $azione, array $dettagli): void
            {
                $this->righe[] = [$azione, $dettagli];
            }
        };

        return new CollegamentoUtente(new CollegamentiRepository($this->db), new PersonaRepository($this->db), new PermessiGestori(new AbilitazioniRepository($this->db), $abil), $registro);
    }

    private function sincronizzazione(): SincronizzazioneAnagrafe
    {
        return new SincronizzazioneAnagrafe(
            $this->db,
            $this->api,
            new StruttureRepository($this->db),
            new PersonaRepository($this->db),
            new CorsoRepository($this->db),
            new InsegnamentoRepository($this->db),
            $this->catalogo(),
            $this->collegamento(),
            $this->sito
        );
    }

    private function persone(): ServizioPersone
    {
        return new ServizioPersone(new PersonaRepository($this->db), new CollegamentiRepository($this->db), $this->api, $this->sito);
    }

    private function preparaApi(): void
    {
        $this->api->elenchi['addressbook/?structuretree=D1'] = [
            ['ID' => 'mario.rossi', 'Name' => 'ROSSI MARIO', 'Email' => ['Mario.Rossi@unical.it'], 'TelOffice' => ['0984 49'], 'OfficeReference' => ['Cubo 1'],
                'Roles' => [['Role' => 'ND', 'RoleDescription' => 'Tecnico', 'Priority' => 1, 'StructureCod' => 'D1', 'Structure' => 'Dip Uno'], ['Role' => 'PO', 'RoleDescription' => 'Ordinario', 'Priority' => 9, 'StructureCod' => 'D1', 'Structure' => 'Dip Uno']]],
            ['ID' => 'anna.verdi', 'Name' => 'VERDI ANNA', 'Email' => ['non-email'], 'Roles' => [['Role' => 'ND', 'RoleDescription' => 'Tecnico', 'Priority' => 1, 'StructureCod' => 'D1', 'Structure' => 'Dip Uno']]],
        ];
        $this->api->elenchi['teachers/?department=D1'] = [
            ['TeacherID' => 'mario.rossi', 'TeacherSSDCod' => 'BIO/01', 'TeacherSSDDescription' => 'Botanica'],
            ['TeacherID' => 'luca.bianchi', 'TeacherName' => 'BIANCHI LUCA', 'TeacherRole' => 'AU', 'TeacherRoleDescription' => 'Contratto', 'TeacherSSDCod' => '000', 'TeacherDepartmentCod' => 'D1', 'TeacherDepartmentName' => 'Dip Uno', 'Email' => ['l.b@unical.it']],
        ];
        $this->api->elenchi['cds/?departmentcod=D1'] = [
            ['CdSCod' => 'C1', 'CdSName' => 'BIOLOGIA', 'AcademicYear' => 2025, 'CourseType' => 'L', 'CourseTypeDescription' => 'Laurea', 'RegDidId' => 77],
            ['CdSCod' => 'C0', 'CdSName' => 'BIOLOGIA', 'AcademicYear' => 2024, 'CourseType' => 'L', 'CourseTypeDescription' => 'Laurea'],
            ['CdSCod' => 'C9', 'CdSName' => 'VECCHIO', 'AcademicYear' => 2019, 'CourseType' => 'L', 'CourseTypeDescription' => 'Laurea'],
        ];
        $this->api->elenchi['cds/'] = [
            ['CdSCod' => 'C1', 'CdSName' => 'BIOLOGIA', 'AcademicYear' => 2025, 'CourseType' => 'L', 'CourseTypeDescription' => 'Laurea', 'DepartmentCod' => 'D1', 'DepartmentName' => 'Dip Uno'],
            ['CdSCod' => 'Z1', 'CdSName' => 'ANTICO', 'AcademicYear' => 2010, 'CourseType' => 'L'],
        ];
    }

    public function testSincronizzazioneDelPersonaleEDeiCorsi(): void
    {
        $this->preparaApi();
        $this->db->esegui("INSERT INTO anagrafe_strutture (codice, nome) VALUES ('D1', '')");
        $this->db->esegui("INSERT INTO personale_ateneo (id, cognome, nome, origine, attivo, aggiornata_il) VALUES ('uscito.x', 'X', 'U', 'D1', 1, '2020-01-01 00:00:00')");
        $r = $this->sincronizzazione()->sincronizza();
        self::assertSame(['D1' => ['ok' => true, 'esito' => '3 persone, 3 corsi di studio, 0 insegnamenti']], $r);
        $m = $this->db->riga("SELECT * FROM personale_ateneo WHERE id = 'mario.rossi'");
        self::assertSame(['Rossi', 'Mario', 'mario.rossi@unical.it', 'PO', 'docenti', '1', 'BIO/01', 'rubrica' === $m['origine'] ? 'x' : 'D1'], [$m['cognome'], $m['nome'], $m['email'], $m['ruolo_cod'], $m['gruppo'], (string) $m['docente'], $m['ssd_cod'], $m['origine']]);
        $a = $this->db->riga("SELECT * FROM personale_ateneo WHERE id = 'anna.verdi'");
        self::assertSame(['', 'pta'], [$a['email'], $a['gruppo']], 'email non valida scartata');
        $l = $this->db->riga("SELECT * FROM personale_ateneo WHERE id = 'luca.bianchi'");
        self::assertSame(['docenti', ''], [$l['gruppo'], $l['ssd_cod']], 'docente a contratto del dipartimento; settore «000» vuoto');
        self::assertSame('0', (string) $this->db->valore("SELECT attivo FROM personale_ateneo WHERE id = 'uscito.x'"), 'chi non compare più è segnato come uscito');
        $s = $this->db->riga("SELECT * FROM anagrafe_strutture WHERE codice = 'D1'");
        self::assertSame(['Dip Uno', '3'], [$s['nome'], (string) $s['persone']]);
        $corsi = array_column($this->db->righe('SELECT codice, visibile FROM corsi_studio ORDER BY codice'), 'visibile', 'codice');
        self::assertSame(['C0' => '0', 'C1' => '1', 'C9' => '0'], array_map('strval', $corsi), 'visibile solo il più recente; il doppione e quello di 3+ anni prima nascosti');
        self::assertSame('1', (string) $this->db->valore("SELECT COUNT(*) FROM ateneo_cds"), 'catalogo di Ateneo: i corsi più vecchi di 7 anni si scartano');
        self::assertFileExists($this->radice . '/cache/anagrafe_sync.txt');
    }

    public function testConLeApiNonRaggiungibiliSiConservanoIDati(): void
    {
        $this->db->esegui("INSERT INTO anagrafe_strutture (codice, nome) VALUES ('D1', '')");
        $this->db->esegui("INSERT INTO personale_ateneo (id, cognome, attivo, aggiornata_il) VALUES ('p.uno', 'Uno', 1, '2020-01-01 00:00:00')");
        $this->api->elenchi['addressbook/?structuretree=D1'] = null;
        $r = $this->sincronizzazione()->sincronizza();
        self::assertSame(['D1' => ['ok' => false, 'esito' => 'API non raggiungibili: dati precedenti conservati']], $r);
        self::assertSame('1', (string) $this->db->valore("SELECT attivo FROM personale_ateneo WHERE id = 'p.uno'"));
        self::assertLessThan(time() - 5 * 86400, (int) filemtime($this->radice . '/cache/anagrafe_sync.txt'), 'si riprova tra un giorno');
    }

    public function testSincronizzazioneDegliInsegnamenti(): void
    {
        $r = fn (int $id, int $anno, string $nome): array => ['StudyActivityID' => $id, 'StudyActivityName' => $nome, 'StudyActivityYear' => $anno, 'StudyActivityCdSCod' => 'C1', 'StudyActivityCdSName' => 'BIOLOGIA',
            'StudyActivityCFU' => '9', 'StudyActivityTeacherName' => 'ROSSI MARIO', 'StudyActivityFathers' => [['StudyActivityID' => 5]], 'StudyActivityPartitionDes' => 'A-L'];
        // anno accademico corrente 2026: erogato = coorte + anno di corso - 1
        $this->api->elenchi['activities/?department=D1&academic_year=2026'] = [$r(1, 1, 'CHIMICA'), $r(2, 2, 'FISICA')];
        $this->api->elenchi['activities/?department=D1&academic_year=2025'] = [$r(3, 2, 'BOTANICA'), $r(4, 1, 'FUORI')];
        $n = $this->sincronizzazione()->sincronizzaInsegnamenti('D1');
        self::assertSame(3, $n, 'quelli tenuti nell\'anno in corso e nel successivo');
        $i = $this->db->riga('SELECT * FROM insegnamenti WHERE id = 1');
        self::assertSame(['Chimica', 2026, 2026, 'Rossi Mario', 5, 'A-L'], [$i['nome'], (int) $i['anno_accademico'], (int) $i['coorte'], $i['docente'], (int) $i['padre_id'], $i['partizione']]);
        self::assertSame('9.0', (string) $i['cfu']);
        // un secondo giro senza FISICA: non più presente
        $this->api->elenchi['activities/?department=D1&academic_year=2026'] = [$r(1, 1, 'CHIMICA')];
        $this->sincronizzazione()->sincronizzaInsegnamenti('D1');
        self::assertSame('0', (string) $this->db->valore('SELECT presente FROM insegnamenti WHERE id = 2'));
        $this->api->elenchi['activities/?department=D1&academic_year=2026'] = null;
        self::assertNull($this->sincronizzazione()->sincronizzaInsegnamenti('D1'), 'API non raggiungibili');
    }

    public function testCatalogoDiAteneo(): void
    {
        $this->db->esegui("INSERT INTO ateneo_cds (codice, anno, nome, tipo, tipo_descrizione, dipartimento) VALUES ('C1', 2025, 'Biologia', 'L', 'Laurea', 'Dip'), ('C1', 2024, 'Biologia', 'L', 'Laurea', 'Dip'), ('M1', 2025, 'Chimica', 'LM', 'Magistrale', 'Dip')");
        $c = $this->catalogo();
        self::assertSame(['L' => 'Laurea (triennale)', 'LM' => 'Laurea magistrale'], $c->tipi());
        self::assertSame([2025, 2024], $c->anni('L'));
        self::assertSame([['codice' => 'C1', 'nome' => 'Biologia', 'dipartimento' => 'Dip', 'anni' => [2025, 2024]]], $c->corsi('L'));
        self::assertSame([['codice' => 'C1', 'nome' => 'Biologia', 'dipartimento' => 'Dip', 'anni' => [2024]]], $c->corsi('L', 2024));
        self::assertSame('Biologia', $c->corso('C1')['nome']);
        self::assertSame(range(2027, 2020), $c->anni('XX'), 'senza dati: gli ultimi anni');
        // insegnamenti: scaricati la prima volta, poi dalla copia locale
        $this->api->elenchi['activities/?cds=C1&academic_year=2024'] = [
            ['StudyActivityID' => 11, 'StudyActivityName' => 'CHIMICA', 'StudyActivityCdSCod' => 'C1', 'StudyActivityYear' => 1, 'StudyActivityCFU' => 6],
            ['StudyActivityID' => 12, 'StudyActivityName' => 'ALTRO CORSO', 'StudyActivityCdSCod' => 'ZZ', 'StudyActivityYear' => 1],
        ];
        $ins = $c->insegnamenti('C1', 2024);
        self::assertSame(['Chimica'], array_column($ins, 'nome'), 'solo quelli del corso');
        $n = count($this->api->chiamate);
        $c->insegnamenti('C1', 2024);
        self::assertCount($n, $this->api->chiamate, 'copia locale recente: nessuna chiamata');
        self::assertSame([], $c->insegnamenti('C1', 1800));
        self::assertSame([], $c->insegnamenti('', 2024));
    }

    public function testCollegamentoDelloUtenteAllaPersona(): void
    {
        $this->db->esegui("INSERT INTO ruoli (id, nome) VALUES (6, 'Docenti'), (7, 'Personale tecnico amministrativo'), (3, 'Studenti')");
        $this->db->esegui("INSERT INTO personale_ateneo (id, cognome, email, gruppo, attivo) VALUES ('mario.rossi', 'Rossi', 'mario.rossi@unical.it', 'docenti', 1)");
        $this->db->esegui("INSERT INTO utenti (id, nome, email, ruoli_secondari) VALUES (1, 'Mario', 'mario.rossi@unical.it', '7,3')");
        $this->db->esegui("INSERT INTO pagine_eventi (id) VALUES (10)");
        $this->db->esegui("INSERT INTO abilitazioni_attesa (email, pagina_id, eventi_ids, creata_da, ambito) VALUES ('mario.rossi@unical.it', 10, '', 9, 'area')");
        $sec = $this->collegamento()->collega(1, '');
        self::assertSame('3,6', $sec, 'tolto il gruppo automatico vecchio, aggiunto quello attuale');
        self::assertSame('mario.rossi', $this->db->valore('SELECT persona_id FROM utenti WHERE id = 1'));
        self::assertSame('0', (string) $this->db->valore('SELECT COUNT(*) FROM abilitazioni_attesa'), 'abilitazione in attesa applicata e tolta');
        self::assertSame('{"1":["full"]}', $this->db->valore('SELECT permessi_gestori_json FROM pagine_eventi WHERE id = 10'));
        self::assertSame('Attivata abilitazione in attesa', $this->registro[0][0] ?? '');
        self::assertNull($this->collegamento()->collega(999, ''), 'utente inesistente');
        // persona tolta dall'anagrafe: collegamento azzerato
        $this->db->esegui("DELETE FROM personale_ateneo");
        $this->collegamento()->scollegaSenzaPersona();
        self::assertNull($this->db->valore('SELECT persona_id FROM utenti WHERE id = 1'));
    }

    public function testPermessiDeiGestoriNelleAreeENelleAttivita(): void
    {
        $this->db->esegui("INSERT INTO pagine_eventi (id, gestori_utenti_ids, permessi_gestori_json) VALUES (10, '5,7', '{\"7\":[\"eventi\"]}')");
        $this->db->esegui("INSERT INTO eventi (id, pagina_id, gestori_utenti_ids, permessi_gestori_json) VALUES (100, 10, '7', '{\"7\":[\"full\"]}'), (101, 10, '', NULL), (200, 11, '', NULL)");
        $abil = new ServizioAbilitazioni(new AbilitazioniRepository($this->db), new UtenteRepository($this->db), new class () implements ModuliAree {
            public function disponibile(): bool
            {
                return false;
            }

            public function moduloDiArea(array $pagina): string
            {
                return '';
            }
        });
        $p = new PermessiGestori(new AbilitazioniRepository($this->db), $abil);
        $p->assegna(10, 7, ['full', 'finto']);
        self::assertSame('{"7":["full"]}', $this->db->valore('SELECT permessi_gestori_json FROM pagine_eventi WHERE id = 10'), 'tolti prima i permessi precedenti, solo permessi ammessi');
        self::assertSame('5', $this->db->valore('SELECT gestori_utenti_ids FROM pagine_eventi WHERE id = 10'));
        self::assertSame('[]', $this->db->valore('SELECT permessi_gestori_json FROM eventi WHERE id = 100'));
        self::assertSame('', $this->db->valore('SELECT gestori_utenti_ids FROM eventi WHERE id = 100'));
        $p->assegna(10, 8, ['iscritti'], [101, 200]);
        self::assertSame('{"8":["iscritti"]}', $this->db->valore('SELECT permessi_gestori_json FROM eventi WHERE id = 101'));
        self::assertNull($this->db->valore('SELECT permessi_gestori_json FROM eventi WHERE id = 200'), 'attività di un\'altra area: ignorata');
        $p->assegna(0, 8, ['full']);
        self::assertTrue($p->applica(9, 'attivita', 10, [101], 1));
        self::assertFalse($p->applica(9, 'attivita', 10, [], 1));
        self::assertSame('{"8":["iscritti"],"9":["full"]}', $this->db->valore('SELECT permessi_gestori_json FROM eventi WHERE id = 101'));
        self::assertTrue($p->applica(9, 'area', 10));
        $p->revoca(10, 9);
        self::assertSame('{"8":["iscritti"]}', $this->db->valore('SELECT permessi_gestori_json FROM eventi WHERE id = 101'));
    }

    public function testPersoneRicercaSchedaEModifiche(): void
    {
        $this->db->esegui("INSERT INTO personale_ateneo (id, cognome, nome, email, telefono, ruolo, gruppo, docente, attivo, dettaglio_json, dettaglio_il) VALUES
            ('mario.rossi', 'Rossi', 'Mario', 'm@u.it', '0984 1', 'Ordinario', 'docenti', 1, 1, '{\"bio\":\"Biologo\",\"telefoni\":[\"0984 2\"]}', NOW()),
            ('anna.verdi', 'Verdi', 'Anna', 'a@u.it', '', 'Tecnico', 'pta', 0, 0, NULL, NULL)");
        $s = $this->persone();
        self::assertSame([], $s->cerca('', '', 'XX', ''), 'ruolo inesistente');
        self::assertSame([], $s->cerca('', '', '', ''), 'serve almeno un filtro');
        self::assertSame(['mario.rossi'], array_column($s->cerca('ros mar', '', '', ''), 'id'), 'tutte le parole devono comparire');
        self::assertSame(['mario.rossi'], array_column($s->cerca('', 'docenti', '', ''), 'id'));
        self::assertNull($s->persona('x'), 'identificatore non valido');
        self::assertSame('Rossi', $s->persona('mario.rossi')['cognome']);
        $p = $s->persona('mario.rossi');
        $scheda = $s->scheda($p);
        self::assertSame(['0984 2', 'Biologo', []], [$scheda['valori']['telefono'], $scheda['valori']['bio'], $scheda['modificati']]);
        self::assertSame('Il telefono può contenere solo numeri, spazi, + - / ( ) e virgole.', $s->salvaModifiche('mario.rossi', ['telefono' => 'abc']));
        self::assertNull($s->salvaModifiche('mario.rossi', ['telefono' => '0984 3', 'sito' => 'esempio.it', 'bio' => '<b>Nuovo</b> profilo']));
        $scheda = $s->scheda($p);
        self::assertSame(['0984 3', 'Nuovo profilo', 'https://esempio.it'], [$scheda['valori']['telefono'], $scheda['valori']['bio'], $scheda['valori']['sito']]);
        self::assertSame(['telefono', 'bio', 'sito'], $scheda['modificati']);
        self::assertSame('Il sito web non è un indirizzo valido.', $s->salvaModifiche('mario.rossi', ['sito' => 'https://']));
    }

    public function testDettaglioDaApiConFoto(): void
    {
        $this->db->esegui("INSERT INTO personale_ateneo (id, cognome, docente) VALUES ('mario.rossi', 'Rossi', 1)");
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $this->api->risposte['teachers/mario.rossi/'] = ['results' => ['ORCID' => '0000-0001-2345-678X', 'ShortBio' => '<p>Ciao</p>', 'ReceptionHours' => 'Lun 10-12<br>Mar 9',
            'CVPathIta' => '//cv.it/x.pdf', 'TeacherWebSite' => ['http://non-https.it', 'https://ok.it'], 'PhotoPath' => 'https://storage.portale.unical.it/foto.png', 'TeacherTelOffice' => ['1', '2', '3', '4']]];
        $this->api->file['https://storage.portale.unical.it/foto.png'] = (string) $png;
        $s = $this->persone();
        $d = $s->dettaglio((array) $s->persona('mario.rossi'));
        self::assertSame(['0000-0001-2345-678X', 'Ciao', "Lun 10-12\nMar 9", 'https://cv.it/x.pdf', ['https://ok.it'], ['1', '2', '3']], [$d['orcid'], $d['bio'], $d['ricevimento'], $d['cv_ita'], $d['siti'], $d['telefoni']]);
        self::assertSame('uploads/personale/mario.rossi.png', $d['foto']);
        self::assertFileExists($this->radice . '/uploads/personale/mario.rossi.png');
        self::assertNotNull($this->db->valore("SELECT dettaglio_il FROM personale_ateneo WHERE id = 'mario.rossi'"), 'scheda salvata');
        $n = count($this->api->chiamate);
        $fresca = $this->db->riga("SELECT * FROM personale_ateneo WHERE id = 'mario.rossi'");
        self::assertSame($d, $s->dettaglio($fresca));
        self::assertCount($n, $this->api->chiamate, 'scheda fresca (meno di 7 giorni): nessuna chiamata');
    }

    public function testAvvisiSuGestoriEReferentiUsciti(): void
    {
        $this->db->esegui("INSERT INTO personale_ateneo (id, cognome, attivo, uscita_il) VALUES ('p.uscito', 'Uscito', 0, '2026-01-01'), ('p.attivo', 'Attivo', 1, NULL)");
        $this->db->esegui("INSERT INTO utenti (id, nome, cognome, persona_id) VALUES (1, 'U', 'Uscito', 'p.uscito'), (2, 'A', 'Attivo', 'p.attivo')");
        $this->db->esegui("INSERT INTO pagine_eventi (id, permessi_gestori_json) VALUES (10, '{\"1\":[\"full\"],\"2\":[\"full\"]}')");
        $this->db->esegui("INSERT INTO eventi (id, pagina_id, titolo, tipo) VALUES (100, 10, 'Progetto', 'progetto')");
        $this->db->esegui("INSERT INTO progetti_dettagli (evento_id, referenti_json) VALUES (100, '[{\"persona_id\":\"p.uscito\",\"nome\":\"U\"},{\"persona_id\":\"sconosciuto\"},{\"persona_id\":\"p.attivo\"}]')");
        $a = $this->persone()->avvisi();
        self::assertSame([1], array_map(static fn (array $u): int => (int) $u['id'], $a['gestori']));
        self::assertSame(['U', 'sconosciuto'], array_column($a['referenti'], 'nome'));
        $this->db->esegui('DELETE FROM personale_ateneo');
        self::assertSame(['gestori' => [], 'referenti' => []], $this->persone()->avvisi(), 'anagrafe mai sincronizzata');
    }

    public function testCorsiVisibiliEInsegnamentiPerCorso(): void
    {
        $this->db->esegui("INSERT INTO corsi_studio (codice, nome, tipo, tipo_descrizione, visibile, presente) VALUES ('C1', 'Biologia', 'L', 'Laurea', 1, 1), ('M1', 'Chimica', 'LM', 'Laurea Magistrale', 1, 1), ('N1', 'Nascosto', 'L', 'Laurea', 0, 1), ('F1', 'Altro', 'FI', '', 1, 1)");
        $this->db->esegui("INSERT INTO insegnamenti (id, nome, cds_nome, anno_accademico, anno_corso, presente, partizione, cfu, ssd_cod) VALUES (1, 'Chimica', 'Biologia', 2025, 1, 1, '', 6, 'CHIM/03'), (2, 'Fisica', 'Biologia', 2026, 1, 1, 'A', NULL, ''), (3, 'Zoologia', '', 2026, 2, 1, '', 3, '')");
        $c = new ServizioCorsi(new CorsoRepository($this->db), new InsegnamentoRepository($this->db), $this->catalogo(), $this->api, $this->sito);
        self::assertSame(['Laurea', 'Laurea Magistrale', 'Altri corsi'], array_keys($c->visibili()), 'per tipo: laurea, magistrale, poi gli altri');
        self::assertSame('Biologia', $c->corso('C1')['nome']);
        self::assertNull($c->corso(str_repeat('x', 21)));
        self::assertSame(['Altri insegnamenti', 'Biologia'], array_keys($c->insegnamentiPerCorso()), 'anno in corso (2026)');
        self::assertSame(['Biologia'], array_keys($c->insegnamentiPerCorso(2025)));
        $scelta = $c->insegnamentiDipartimentoScelta();
        self::assertSame([3, 2], array_keys($scelta));
        self::assertSame('Fisica (A)', $scelta[2]['nome']);
        self::assertSame('Chimica', $c->insegnamento(1)['nome']);
        self::assertNull($c->insegnamento(0));
    }
}
