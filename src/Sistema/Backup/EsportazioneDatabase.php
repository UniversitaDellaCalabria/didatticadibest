<?php

declare(strict_types=1);

namespace App\Sistema\Backup;

use App\Core\Database;

/**
 * Esportazione completa del database in un file SQL compresso (backups/backup_DB_<data>.sql.gz), scritta a blocchi:
 * le tabelle grandi non vengono caricate in memoria. Spostata da admin/cron_backup.php.
 * Usa la connessione sottostante perché la lettura a flusso (MYSQLI_USE_RESULT) non passa dalle query preparate.
 */
final class EsportazioneDatabase
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Scrive il file. Ritorna null se non si riesce a crearlo, altrimenti [numero di tabelle, numero di righe];
     * il chiamante controlla poi che il file esista e non sia vuoto.
     *
     * @return array{0: int, 1: int}|null
     */
    public function esporta(string $file): ?array
    {
        $gz = @gzopen($file, 'wb6');
        if (!$gz) {
            return null;
        }
        $conn = $this->db->mysqli();
        gzwrite($gz, "-- Backup del database Didattica DiBEST\n-- Generato il " . date('Y-m-d H:i:s') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $tabelle = [];
        $r = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($r instanceof \mysqli_result && $t = $r->fetch_row()) {
            $tabelle[] = $t[0];
        }
        $nRighe = 0;
        foreach ($tabelle as $tab) {
            $rc = $conn->query("SHOW CREATE TABLE `$tab`");
            $crea = $rc instanceof \mysqli_result ? ($rc->fetch_row()[1] ?? '') : '';
            gzwrite($gz, "DROP TABLE IF EXISTS `$tab`;\n$crea;\n\n");
            // Lettura a flusso: le tabelle grandi non vengono caricate tutte in memoria
            $res = $conn->query("SELECT * FROM `$tab`", MYSQLI_USE_RESULT);
            if (!$res instanceof \mysqli_result) {
                continue;
            }
            while ($row = $res->fetch_row()) {
                $valori = array_map(static fn ($v): string => $v === null ? 'NULL' : "'" . $conn->real_escape_string((string) $v) . "'", $row);
                gzwrite($gz, "INSERT INTO `$tab` VALUES(" . implode(',', $valori) . ");\n");
                $nRighe++;
            }
            $res->free();
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);

        return [count($tabelle), $nRighe];
    }
}
