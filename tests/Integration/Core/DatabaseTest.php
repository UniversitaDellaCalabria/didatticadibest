<?php

declare(strict_types=1);

namespace Tests\Integration\Core;

use App\Core\Database;
use mysqli;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Database su MySQL/MariaDB vero (lo stesso usato da strumenti/prove/esegui.php):
 * PROVE_DB_HOST, PROVE_DB_USER, PROVE_DB_PASS (predefiniti 127.0.0.1, root, vuota). Senza database il test si salta.
 */
final class DatabaseTest extends TestCase
{
    private static ?mysqli $conn = null;
    private Database $db;

    public static function setUpBeforeClass(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = @new mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''));
        if ($conn->connect_error) {
            return;
        }
        $conn->query('CREATE DATABASE IF NOT EXISTS `' . self::nome() . '` CHARACTER SET utf8mb4');
        $conn->select_db(self::nome());
        $conn->set_charset('utf8mb4');
        self::$conn = $conn;
    }

    public static function tearDownAfterClass(): void
    {
        self::$conn?->query('DROP DATABASE IF EXISTS `' . self::nome() . '`');
    }

    private static function nome(): string
    {
        return (getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit') . '_db';
    }

    protected function setUp(): void
    {
        if (self::$conn === null) {
            self::markTestSkipped('Database di prova non raggiungibile');
        }
        self::$conn->query('DROP TABLE IF EXISTS prova');
        self::$conn->query('CREATE TABLE prova (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(50), n INT NULL) ENGINE=InnoDB');
        $this->db = Database::per(self::$conn);
    }

    public function testStessaConnessioneStessoOggetto(): void
    {
        self::assertSame($this->db, Database::per(self::$conn));
    }

    public function testInserisciRestituisceLIdAncheDopoUnUpdate(): void
    {
        $id = $this->db->inserisci('INSERT INTO prova (nome, n) VALUES (?, ?)', ['Anna', 3]);
        $this->db->esegui('UPDATE prova SET n = n + 1 WHERE id = ?', [$id]);

        self::assertGreaterThan(0, $id);
        self::assertSame(['id' => $id, 'nome' => 'Anna', 'n' => 4], $this->db->riga('SELECT * FROM prova WHERE id = ?', [$id]));
    }

    public function testRisultatiComeGliHelperProcedurali(): void
    {
        $this->db->esegui('INSERT INTO prova (nome, n) VALUES (?, ?), (?, ?)', ['a', 1, 'b', null]);

        self::assertCount(2, $this->db->righe('SELECT * FROM prova ORDER BY id'));
        self::assertSame(2, (int) $this->db->valore('SELECT COUNT(*) FROM prova'));
        self::assertNull($this->db->riga('SELECT * FROM prova WHERE nome = ?', ['zz']));
        self::assertSame(1, $this->db->esegui('UPDATE prova SET n = ? WHERE nome = ?', [5, 'b']));
        // Errori silenziosi, come prima: niente eccezioni nelle facciate
        self::assertSame([], $this->db->righe('SELECT * FROM tabella_inesistente'));
        self::assertSame(-1, $this->db->esegui('UPDATE tabella_inesistente SET x = 1'));
        self::assertNull($this->db->valore('SELECT nulla FROM tabella_inesistente'));
    }

    public function testTransazioneAnnullataSeIlLavoroFallisce(): void
    {
        try {
            $this->db->transazione(function (Database $db): void {
                $db->esegui('INSERT INTO prova (nome) VALUES (?)', ['annullata']);
                throw new RuntimeException('errore');
            });
        } catch (RuntimeException) {
        }
        $esito = $this->db->transazione(fn (Database $db): int => $db->inserisci('INSERT INTO prova (nome) VALUES (?)', ['tenuta']));

        self::assertSame(['tenuta'], array_column($this->db->righe('SELECT nome FROM prova'), 'nome'));
        self::assertGreaterThan(0, $esito);
    }
}
