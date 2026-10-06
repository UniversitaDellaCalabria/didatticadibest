<?php

declare(strict_types=1);

namespace App\Sistema\Backup;

use ZipArchive;

/** Cifratura delle copie del database inviate per email: spostata da cifra_zip_aes() e cifra_openssl_aes() di inc/sistema.php. */
final class Cifratura
{
    /**
     * ZIP con il file cifrato AES-256 (si apre con 7-Zip/WinRAR). Ritorna false con il motivo in $errore:
     * alcuni PHP hanno ZipArchive::EM_AES_256 ma una libreria libzip compilata senza cifratura.
     *
     * @param-out string $errore
     */
    public function zipAes(string $src, string $dest, string $password, ?string &$errore = null): bool
    {
        $errore = '';
        if (!extension_loaded('zip') || !defined('ZipArchive::EM_AES_256')) {
            $errore = 'estensione zip senza cifratura AES';

            return false;
        }
        $z = new ZipArchive();
        if (($r = $z->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE)) !== true) {
            $errore = "apertura non riuscita (codice $r)";

            return false;
        }
        $nome = basename($src);
        if (!$z->addFile($src, $nome)) {
            $errore = 'aggiunta del file: ' . $z->getStatusString();
            @$z->close();

            return false;
        }
        if (!$z->setEncryptionName($nome, ZipArchive::EM_AES_256, $password)) {
            $errore = 'la libreria zip del server non supporta la cifratura (' . $z->getStatusString() . ')';
            @$z->close();

            return false;
        }
        if (!$z->close()) {
            $errore = 'chiusura: ' . $z->getStatusString();

            return false;
        }

        return is_file($dest) && filesize($dest) > 0;
    }

    /**
     * File cifrato AES-256-CBC nello stesso formato di "openssl enc -aes-256-cbc -pbkdf2 -iter 100000 -md sha256":
     * "Salted__" + sale di 8 byte + dati; chiave e IV dalla password con PBKDF2-SHA256. Si apre con
     * openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -md sha256 -in FILE.enc -out FILE (openssl c'è in Git Bash).
     *
     * @param-out string $errore
     */
    public function opensslAes(string $src, string $dest, string $password, ?string &$errore = null): bool
    {
        $errore = '';
        if (!function_exists('openssl_encrypt')) {
            $errore = 'estensione openssl non attiva';

            return false;
        }
        $dati = @file_get_contents($src);
        if ($dati === false) {
            $errore = 'lettura del file non riuscita';

            return false;
        }
        $sale = random_bytes(8);
        $km = hash_pbkdf2('sha256', $password, $sale, 100000, 48, true);
        $cif = openssl_encrypt($dati, 'aes-256-cbc', substr($km, 0, 32), OPENSSL_RAW_DATA, substr($km, 32, 16));
        if ($cif === false) {
            $errore = 'cifratura non riuscita: ' . (openssl_error_string() ?: 'errore sconosciuto');

            return false;
        }
        if (@file_put_contents($dest, 'Salted__' . $sale . $cif) === false) {
            $errore = 'scrittura del file non riuscita';

            return false;
        }

        return true;
    }
}
