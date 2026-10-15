<?php

declare(strict_types=1);

namespace App\Sistema\Backup;

use ZipArchive;

/**
 * Controlla che un backup sia leggibile e completo: un backup che non si riesce a riaprire è come non averlo. Non tocca il database né il sito:
 * legge solo i file del backup (lo ZIP dei file e l'esportazione SQL compressa).
 */
final class VerificaBackup
{
    /** L'ultima riga che scrive l'esportazione del database: se manca il file è stato interrotto. */
    public const FINE_ESPORTAZIONE = 'SET FOREIGN_KEY_CHECKS=1;';

    /**
     * Lo ZIP dei file del sito: si apre, la struttura è coerente e contiene almeno $attesi file.
     *
     * @return string|null un messaggio d'errore, null se è a posto
     */
    public function zip(string $file, int $attesi = 1): ?string
    {
        if (!extension_loaded('zip')) {
            return 'estensione PHP zip non attiva';
        }
        if (!is_file($file) || filesize($file) === 0) {
            return 'il file non esiste o è vuoto';
        }
        $z = new ZipArchive();
        $r = $z->open($file, ZipArchive::CHECKCONS);
        if ($r !== true) {
            return 'lo ZIP non si apre o è danneggiato (codice ' . (int) $r . ')';
        }
        $n = $z->numFiles;
        $conConfig = $z->locateName('config.php') !== false;
        $z->close();
        if ($n < $attesi) {
            return "lo ZIP contiene $n file invece di $attesi";
        }

        return $conConfig ? null : 'nello ZIP manca config.php';
    }

    /**
     * L'esportazione del database: si decomprime fino in fondo, finisce con la riga di chiusura e ha almeno $tabelleAttese tabelle.
     *
     * @return array{ok: bool, tabelle: int, righe: int, errore: string}
     */
    public function database(string $file, int $tabelleAttese = 1): array
    {
        $esito = ['ok' => false, 'tabelle' => 0, 'righe' => 0, 'errore' => ''];
        if (!is_file($file) || filesize($file) === 0) {
            $esito['errore'] = 'il file non esiste o è vuoto';

            return $esito;
        }
        $gz = @gzopen($file, 'rb');
        if (!$gz) {
            $esito['errore'] = 'il file non si apre come gzip';

            return $esito;
        }
        $ultima = '';
        while (($riga = gzgets($gz)) !== false) {
            if (str_starts_with($riga, 'CREATE TABLE')) {
                ++$esito['tabelle'];
            } elseif (str_starts_with($riga, 'INSERT INTO')) {
                ++$esito['righe'];
            }
            if (trim($riga) !== '') {
                $ultima = trim($riga);
            }
        }
        $integro = gzeof($gz);
        gzclose($gz);
        if (!$integro) {
            $esito['errore'] = 'il file gzip è troncato o danneggiato';
        } elseif ($ultima !== self::FINE_ESPORTAZIONE) {
            $esito['errore'] = 'l\'esportazione non è arrivata in fondo (manca la riga di chiusura)';
        } elseif ($esito['tabelle'] < $tabelleAttese) {
            $esito['errore'] = 'ci sono ' . $esito['tabelle'] . ' tabelle invece di almeno ' . $tabelleAttese;
        } else {
            $esito['ok'] = true;
        }

        return $esito;
    }
}
