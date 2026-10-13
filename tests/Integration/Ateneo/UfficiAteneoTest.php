<?php

declare(strict_types=1);

namespace Tests\Integration\Ateneo;

use App\Anagrafi\ClientApiAteneo;
use App\Ateneo\ServizioUfficiAteneo;
use App\Core\Container;
use App\Core\Database;
use App\Core\Sito;
use Tests\Doppi\AnagrafiClientApiFinto;
use Tests\Integration\DatabaseDiProva;

/** Uffici di Ateneo: creazione, persone dall'anagrafe o a mano, appartenenza a più uffici, avvisi e blocco dell'eliminazione. */
final class UfficiAteneoTest extends DatabaseDiProva
{
    private Container $c;

    protected function tabelle(): array
    {
        return [
            'uffici_ateneo' => "CREATE TABLE uffici_ateneo (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(150) NOT NULL DEFAULT '', descrizione VARCHAR(500) DEFAULT '', attivo TINYINT(1) NOT NULL DEFAULT 1, creato_il DATETIME DEFAULT CURRENT_TIMESTAMP)",
            'uffici_ateneo_persone' => "CREATE TABLE uffici_ateneo_persone (id INT AUTO_INCREMENT PRIMARY KEY, ufficio_id INT NOT NULL, persona_id VARCHAR(80) NULL, email VARCHAR(150) NOT NULL DEFAULT '',
                nominativo VARCHAR(200) NOT NULL DEFAULT '', struttura VARCHAR(255) DEFAULT '', creato_il DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq (ufficio_id, email))",
            'tutorato_bandi' => 'CREATE TABLE tutorato_bandi (id INT AUTO_INCREMENT PRIMARY KEY, ufficio_ateneo_id INT NULL)',
            'personale_ateneo' => "CREATE TABLE personale_ateneo (id VARCHAR(80) PRIMARY KEY, nome VARCHAR(100) DEFAULT '', cognome VARCHAR(100) DEFAULT '', email VARCHAR(150) DEFAULT '', struttura VARCHAR(255) DEFAULT '')",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $c = new Container();
        $c->istanza(Container::class, $c);
        $c->istanza(Database::class, $this->db);
        $c->istanza(ClientApiAteneo::class, new AnagrafiClientApiFinto());
        $c->istanza(Sito::class, new Sito(dirname(__DIR__, 3), 'https://portale.test/eventi'));
        $this->c = $c;
        $this->db->esegui("INSERT INTO personale_ateneo (id, nome, cognome, email, struttura) VALUES ('r.zicarelli', 'Rossana', 'Zicarelli', 'R.Zicarelli@Unical.it', 'Area Risorse Umane'), ('senza.mail', 'Mario', 'Rossi', '', 'X')");
    }

    private function servizio(): ServizioUfficiAteneo
    {
        return $this->c->get(ServizioUfficiAteneo::class);
    }

    public function testCreaEModificaUnUfficio(): void
    {
        $s = $this->servizio();
        $this->assertSame("Scrivi il nome dell'ufficio.", $s->salva(0, '   ', '')[1]);
        [$id, $err] = $s->salva(0, '  Ufficio   pagamenti tutorato ', 'Paga i tutor');
        $this->assertNull($err);
        $this->assertSame('Ufficio pagamenti tutorato', $s->ufficio($id)['nome'], 'spazi in eccesso tolti');

        $this->assertSame([$id, null], $s->salva($id, 'Ufficio pagamenti', 'x', false));
        $u = $s->ufficio($id);
        $this->assertSame(['Ufficio pagamenti', '0'], [$u['nome'], $u['attivo']]);
        $this->assertCount(1, $s->elenco());
        $this->assertCount(0, $s->elenco(true), 'non attivo');
        $this->assertSame('Ufficio non trovato.', $s->salva(99, 'X', '')[1]);
    }

    public function testPersoneDallAnagrafeEAMano(): void
    {
        $s = $this->servizio();
        [$id] = $s->salva(0, 'Ufficio pagamenti', '');

        $this->assertNull($s->aggiungiDaAnagrafe($id, 'r.zicarelli'));
        $p = $s->persone($id);
        $this->assertCount(1, $p);
        $this->assertSame(['Zicarelli Rossana', 'r.zicarelli@unical.it', 'Area Risorse Umane'], [$p[0]['nominativo'], $p[0]['email'], $p[0]['struttura']]);
        $this->assertStringContainsString('già parte', (string) $s->aggiungiDaAnagrafe($id, 'r.zicarelli'));
        $this->assertStringContainsString('non ha un\'email', (string) $s->aggiungiDaAnagrafe($id, 'senza.mail'));
        $this->assertStringContainsString('non trovata', (string) $s->aggiungiDaAnagrafe($id, 'non.esiste'));

        $this->assertStringContainsString('nome ed email', (string) $s->aggiungiAMano($id, 'Paolo', 'non-email'));
        $this->assertNull($s->aggiungiAMano($id, 'Paolo Neri', 'P.Neri@unical.it'));
        $this->assertStringContainsString('già parte', (string) $s->aggiungiAMano($id, 'Paolo Neri', 'p.neri@unical.it'));
        $this->assertCount(2, $s->persone($id));

        $s->togliPersona((int) $s->persone($id)[0]['id']);
        $this->assertCount(1, $s->persone($id));
    }

    public function testUnaPersonaPuoStareInPiuUffici(): void
    {
        $s = $this->servizio();
        [$a] = $s->salva(0, 'Pagamenti', '');
        [$b] = $s->salva(0, 'Personale', '');
        [$c] = $s->salva(0, 'Chiuso', '', false);
        $s->aggiungiDaAnagrafe($a, 'r.zicarelli');
        $s->aggiungiDaAnagrafe($b, 'r.zicarelli');
        $s->aggiungiDaAnagrafe($c, 'r.zicarelli');
        $utente = ['id' => 7, 'email' => 'R.ZICARELLI@unical.it'];

        $this->assertSame(['Pagamenti', 'Personale'], array_column($s->ufficiDellUtente($utente), 'nome'), 'solo gli uffici attivi');
        $this->assertTrue($s->utenteMembro($utente, $a));
        $this->assertTrue($s->utenteMembro($utente, $b));
        $this->assertFalse($s->utenteMembro(['id' => 8, 'email' => 'altro@unical.it'], $a));
        $this->assertFalse($s->utenteMembro(['email' => 'r.zicarelli@unical.it'], $a), 'senza utente collegato');
        $this->assertFalse($s->utenteMembro(null, $a));
        $this->assertSame([], $s->ufficiDellUtente(null));
    }

    public function testEmailDelUfficioSenzaDoppioni(): void
    {
        $s = $this->servizio();
        [$id] = $s->salva(0, 'Pagamenti', '');
        $s->aggiungiDaAnagrafe($id, 'r.zicarelli');
        $s->aggiungiAMano($id, 'Paolo Neri', 'p.neri@unical.it');
        $e = $s->emailDelUfficio($id);
        sort($e);
        $this->assertSame(['p.neri@unical.it', 'r.zicarelli@unical.it'], $e);
    }

    public function testUnUfficioInUsoNonSiElimina(): void
    {
        $s = $this->servizio();
        [$id] = $s->salva(0, 'Pagamenti', '');
        $s->aggiungiDaAnagrafe($id, 'r.zicarelli');
        $this->db->esegui('INSERT INTO tutorato_bandi (ufficio_ateneo_id) VALUES (?)', [$id]);

        $this->assertStringContainsString('paga i tutor di 1 bando', (string) $s->elimina($id));
        $this->assertNotNull($s->ufficio($id));

        $this->db->esegui('UPDATE tutorato_bandi SET ufficio_ateneo_id = NULL');
        $this->assertNull($s->elimina($id));
        $this->assertNull($s->ufficio($id));
        $this->assertSame([], $s->persone($id), 'anche le persone vanno via');
        $this->assertSame('Ufficio non trovato.', $s->elimina($id));
    }
}
