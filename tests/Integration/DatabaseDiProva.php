<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use mysqli;
use PHPUnit\Framework\TestCase;

/**
 * Base dei test che usano MySQL/MariaDB: database usa e getta eventi_prova_unit (PROVE_DB_HOST/USER/PASS come
 * strumenti/prove/esegui.php). Ogni classe di test crea le sue tabelle; senza database i test si saltano.
 */
abstract class DatabaseDiProva extends TestCase
{
    protected static ?mysqli $conn = null;
    protected Database $db;

    public static function setUpBeforeClass(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = @new mysqli(getenv('PROVE_DB_HOST') ?: '127.0.0.1', getenv('PROVE_DB_USER') ?: 'root', (string) (getenv('PROVE_DB_PASS') ?: ''));
        if ($conn->connect_error) {
            self::$conn = null;

            return;
        }
        $nome = getenv('PROVE_DB_UNIT') ?: 'eventi_prova_unit';
        $conn->query("CREATE DATABASE IF NOT EXISTS `$nome` CHARACTER SET utf8mb4");
        $conn->select_db($nome);
        $conn->set_charset('utf8mb4');
        self::$conn = $conn;
    }

    protected function setUp(): void
    {
        if (self::$conn === null) {
            self::markTestSkipped('Database di prova non raggiungibile');
        }
        $this->db = Database::per(self::$conn);
        foreach ($this->tabelle() as $nome => $sql) {
            self::$conn->query("DROP TABLE IF EXISTS `$nome`");
            self::$conn->query($sql);
        }
    }

    /** @return array<string, string> nome tabella => CREATE TABLE */
    abstract protected function tabelle(): array;
}
