<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Auth\Abilitazioni\AbilitazioniAttesaRepository;
use App\Auth\Abilitazioni\AbilitazioniRepository;
use App\Auth\Abilitazioni\ModuliAree;
use App\Auth\Abilitazioni\PermessiGestoreAree;
use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\Auth\Abilitazioni\ServizioPerimetri;
use App\Auth\LimiteRichieste;
use App\Auth\LogAccessiRepository;
use App\Auth\RateLimitRepository;
use App\Auth\ServizioUtenti;
use App\Auth\UtenteRepository;
use Tests\Integration\DatabaseDiProva;

final class AuthIntegrazioneTest extends DatabaseDiProva
{
    private ServizioAbilitazioni $abil;
    private UtenteRepository $utenti;

    protected function tabelle(): array
    {
        return [
            'utenti' => "CREATE TABLE utenti (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(80) DEFAULT '', cognome VARCHAR(80) DEFAULT '', email VARCHAR(150) NULL,
                ruolo_id INT DEFAULT 5, ruoli_secondari VARCHAR(100) DEFAULT '', email_personalizzata TINYINT DEFAULT 0, ultimo_accesso DATETIME NULL)",
            'prenotazioni' => 'CREATE TABLE prenotazioni (id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT)',
            'pagine_eventi' => "CREATE TABLE pagine_eventi (id INT PRIMARY KEY, gestore_utente_id INT DEFAULT 0, gestori_utenti_ids VARCHAR(200) DEFAULT '',
                permessi_gestori_json TEXT NULL, notifiche_gestori_ids VARCHAR(200) NULL)",
            'eventi' => "CREATE TABLE eventi (id INT PRIMARY KEY, pagina_id INT, tipo VARCHAR(20) NULL, titolo VARCHAR(100) DEFAULT '', ordine INT DEFAULT 0, archiviato TINYINT DEFAULT 0,
                gestori_utenti_ids VARCHAR(200) DEFAULT '', permessi_gestori_json TEXT NULL)",
            'progetti_dettagli' => 'CREATE TABLE progetti_dettagli (evento_id INT PRIMARY KEY, convenzione TINYINT DEFAULT 0)',
            'abilitazioni_ambito' => 'CREATE TABLE abilitazioni_ambito (utente_id INT, tipo VARCHAR(40), pagina_id INT, creata_da INT DEFAULT 0, PRIMARY KEY (utente_id, tipo, pagina_id))',
            'abilitazioni_attesa' => "CREATE TABLE abilitazioni_attesa (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(150), persona_id VARCHAR(40) NULL, nominativo VARCHAR(200) DEFAULT '',
                pagina_id INT, permessi VARCHAR(20), eventi_ids VARCHAR(200) DEFAULT '', creata_da INT, ambito VARCHAR(40), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
            'rate_limit_attempts' => 'CREATE TABLE rate_limit_attempts (id INT AUTO_INCREMENT PRIMARY KEY, ip_hash VARCHAR(64), endpoint VARCHAR(60), hit_at DATETIME)',
            'log_accessi' => "CREATE TABLE log_accessi (id INT AUTO_INCREMENT PRIMARY KEY, utente_id INT, email VARCHAR(150) NULL, nome VARCHAR(80), cognome VARCHAR(80),
                ip VARCHAR(45), user_agent VARCHAR(512), tipo VARCHAR(20), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->utenti = new UtenteRepository($this->db);
        $moduli = new class () implements ModuliAree {
            public function disponibile(): bool
            {
                return true;
            }

            public function moduloDiArea(array $pagina): string
            {
                return (string) ($pagina['modulo'] ?? 'orientamento');
            }
        };
        $this->abil = new ServizioAbilitazioni(new AbilitazioniRepository($this->db), $this->utenti, $moduli);
        $this->db->esegui("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari) VALUES
            (1, 'Ada', 'Admin', 'Ada@Unical.it', 1, ''), (2, 'Gino', 'Gestore', 'gino@unical.it', 5, '1'), (3, 'Zoe', 'Zeta', NULL, 5, ''), (4, 'Ugo', 'Uno', 'ugo@x.it', 5, '')");
        $this->db->esegui("INSERT INTO pagine_eventi (id, gestore_utente_id, gestori_utenti_ids, permessi_gestori_json) VALUES (10, 3, '', '{\"4\":[\"full\"]}'), (11, 0, '', NULL)");
        $this->db->esegui("INSERT INTO eventi (id, pagina_id, tipo, titolo) VALUES (100, 10, 'progetto', 'P'), (101, 10, NULL, 'E'), (102, 11, 'evento', 'Altro')");
        $this->db->esegui('INSERT INTO progetti_dettagli (evento_id, convenzione) VALUES (100, 1)');
    }

    public function testEmailAmministratoriValideMinuscoleSenzaDoppioni(): void
    {
        self::assertSame(['ada@unical.it', 'gino@unical.it'], (new ServizioUtenti($this->utenti))->emailAmministratori());
    }

    public function testAggiornaEmailRifiutaQuellaDiUnAltro(): void
    {
        $s = new ServizioUtenti($this->utenti);
        self::assertSame('Questa email è già associata a un altro account.', $s->aggiornaEmail(4, 'gino@unical.it'));
        self::assertTrue($s->aggiornaEmail(4, 'nuova@x.it'));
        self::assertSame('1', (string) $this->db->valore('SELECT email_personalizzata FROM utenti WHERE id = 4'));
    }

    public function testAggiornaDaAdminEEliminaConPrenotazioni(): void
    {
        $this->utenti->aggiornaDaAdmin(4, 3, '1,2', 'Ugo2', 'Uno2', 'u2@x.it');
        $r = $this->utenti->perId(4);
        self::assertSame([3, '1,2', 'Ugo2', 'u2@x.it', 1], [(int) $r['ruolo_id'], $r['ruoli_secondari'], $r['nome'], $r['email'], (int) $r['email_personalizzata']]);
        $this->utenti->aggiornaDaAdmin(4, 5, '', null, '', null);
        self::assertSame('Ugo2', $this->utenti->perId(4)['nome'], 'senza nome né email restano quelli di prima');
        $this->db->esegui('INSERT INTO prenotazioni (utente_id) VALUES (4), (4), (1)');
        $this->utenti->eliminaConPrenotazioni(4);
        self::assertNull($this->utenti->perId(4));
        self::assertSame('1', (string) $this->db->valore('SELECT COUNT(*) FROM prenotazioni'));
    }

    public function testGestoriENotificheDiUnArea(): void
    {
        $this->abil->assegnaAmbito(2, 'eventi', 10, 1, ['eventi' => 'x']);
        self::assertEqualsCanonicalizing([3, 4, 2], $this->abil->gestoriIdsArea(10));
        self::assertNull($this->abil->notificheGestoriAttive(10));
        $this->abil->impostaNotificaGestore(10, 3, false);
        self::assertEqualsCanonicalizing([4, 2], $this->abil->notificheGestoriAttive(10));
        self::assertSame(['ugo@x.it'], $this->abil->emailGestoriEvento(100), 'Zoe ha le notifiche spente; il perimetro «eventi» di Gino non comprende un progetto');
        $evento = $this->abil->emailGestoriEvento(101, true);
        sort($evento);
        self::assertSame(['gino@unical.it', 'ugo@x.it'], $evento, "l'evento comprende anche Gino (perimetro «eventi»)");
        self::assertSame([], $this->abil->emailGestoriEvento(999));
    }

    public function testPerimetriProgettiEdEventi(): void
    {
        self::assertFalse($this->abil->assegnaAmbito(2, 'inesistente', 10, 1, ['eventi' => 'x']));
        self::assertFalse($this->abil->assegnaAmbito(2, 'eventi', 0, 1, ['eventi' => 'x']));
        self::assertTrue($this->abil->assegnaAmbito(2, 'progetti', 10, 1, ['progetti' => 'x']));
        self::assertTrue($this->abil->haAmbito(2, 'progetti', 10));
        self::assertFalse($this->abil->haAmbito(2, 'progetti', 11));
        self::assertSame([100], $this->abil->attivitaDaAmbiti(2, 10));
        self::assertSame("(e.tipo = 'progetto')", $this->abil->sqlAttivitaAmbiti(2, 10));
        self::assertSame('', $this->abil->sqlAttivitaAmbiti(2, 11));
        self::assertTrue($this->abil->utenteGestisceAttivita(2, 100));
        self::assertFalse($this->abil->utenteGestisceAttivita(2, 101));
        self::assertTrue($this->abil->utenteGestisceAttivita(3, 101), 'gestore principale dell\'area');
        $this->abil->revocaAmbito(2, null, 10);
        self::assertFalse($this->abil->haAmbito(2, 'progetti', 10), 'la cache si aggiorna dopo la revoca');
    }

    public function testModuliInteriEFsl(): void
    {
        $tipi = ['modulo_fsl' => 'x', 'modulo_orientamento' => 'x', 'fsl' => 'x'];
        $this->abil->assegnaAmbito(4, 'modulo_orientamento', 55, 1, $tipi);
        self::assertTrue($this->abil->haAmbito(4, 'modulo_orientamento', 0), 'i moduli interi non hanno area');
        self::assertTrue($this->abil->haModulo(4, 'fsl'), 'Orientamento comprende la FSL');
        self::assertFalse($this->abil->haModulo(4, ''));
        self::assertEqualsCanonicalizing([10, 11], $this->abil->areeDaAmbiti(4));
        self::assertContains(4, $this->abil->idsAmbitoAttivita(100));
        self::assertNotContains(4, $this->abil->idsAmbitoAttivita(100, false));
        self::assertTrue($this->abil->utenteHaAbilitazioni(4));
        self::assertFalse($this->abil->utenteHaAbilitazioni(1));
        self::assertFalse($this->abil->utenteHaAbilitazioni(0));
    }

    public function testSalvataggioPerimetroEAttesa(): void
    {
        $registro = new class () implements PermessiGestoreAree {
            /** @var list<string> */
            public array $chiamate = [];

            public function revoca(int $paginaId, int $utenteId): void
            {
                $this->chiamate[] = "revoca $paginaId $utenteId";
            }

            public function applica(int $utenteId, string $ambito, int $paginaId, array $eventiIds, int $da): bool
            {
                $this->chiamate[] = "applica $utenteId $ambito $paginaId " . implode(',', $eventiIds);

                return true;
            }
        };
        $repo = new AbilitazioniRepository($this->db);
        $attesa = new AbilitazioniAttesaRepository($this->db);
        $s = new ServizioPerimetri($repo, $attesa, $this->abil, $registro);
        $this->abil->assegnaAmbito(3, 'eventi', 10, 1, ['eventi' => 'x']);
        $s->salva(3, 10, ['area' => 'area', 'tipi' => [], 'attivita' => [], 'fsl' => 'nessuno', 'fsl_parti' => [], 'moduli' => []], 1);
        self::assertSame(['revoca 10 3', 'applica 3 area 10 '], $registro->chiamate);
        self::assertSame('0', (string) $this->db->valore('SELECT gestore_utente_id FROM pagine_eventi WHERE id = 10'), 'il gestore principale legacy viene tolto');
        self::assertFalse($this->abil->haAmbito(3, 'eventi', 10));

        $s->mettiInAttesa('nuova@x.it', null, 'Nuova', 10, [['area', 10, []], ['attivita', 10, [100, 101]]], 1);
        $s->mettiInAttesa('nuova@x.it', null, 'Nuova', 10, [['fsl', 0, []]], 1);
        $righe = $attesa->perArea(10);
        self::assertCount(1, $righe, 'la nuova scelta sostituisce la precedente');
        self::assertSame('fsl', $righe[0]['ambito']);
        self::assertNull($s->annullaAttesa(999, 10));
        self::assertSame('nuova@x.it', $s->annullaAttesa((int) $righe[0]['id'], 10));
        self::assertSame([], $attesa->perArea(10));
    }

    public function testLimiteRichiesteBloccaDopoIlMassimo(): void
    {
        $l = new LimiteRichieste(new RateLimitRepository($this->db));
        self::assertTrue($l->consenti('1.2.3.4', 'login', 2));
        self::assertTrue($l->consenti('1.2.3.4', 'login', 2));
        self::assertFalse($l->consenti('1.2.3.4', 'login', 2));
        self::assertTrue($l->consenti('5.6.7.8', 'login', 2), 'un altro IP ha il suo conteggio');
        self::assertTrue($l->consenti('1.2.3.4', 'altra', 2), 'e un\'altra pagina pure');
        self::assertNotContains('1.2.3.4', array_column($this->db->righe('SELECT ip_hash FROM rate_limit_attempts'), 'ip_hash'), "l'IP non si salva in chiaro");
    }

    public function testLogAccessiConFiltri(): void
    {
        $log = new LogAccessiRepository($this->db);
        $log->registra(1, 'ada@unical.it', 'Ada', 'Admin', '10.0.0.1', 'Firefox', 'sso');
        $log->registra(2, null, 'Gino', 'Gestore', '10.0.0.2', 'Chrome', 'sso');
        self::assertSame(2, $log->conta('', '', ''));
        self::assertSame(1, $log->conta('ada', '', ''));
        self::assertSame(1, $log->conta('10.0.0.2', '', ''));
        self::assertSame(0, $log->conta('', '2999-01-01', ''));
        self::assertSame(2, $log->conta('', '2000-01-01', '2999-01-01'));
        self::assertCount(1, $log->pagina('', '', '', 1, 0));
        self::assertCount(1, $log->pagina('', '', '', 5, 1));
    }
}
