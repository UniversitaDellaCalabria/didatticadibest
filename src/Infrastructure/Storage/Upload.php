<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

/**
 * Salvataggio sicuro dei file caricati: estensione e tipo MIME reale (finfo) ammessi, nome casuale.
 * Spostato da inc/sistema.php (secure_upload resta come facciata).
 */
final class Upload
{
    /**
     * @param array{error?: int, name?: string, tmp_name?: string} $file elemento di $_FILES
     * @param list<string> $estensioni
     * @param list<string> $mime
     * @return string|null nome del file salvato nella cartella, null se non valido
     */
    public function salva(array $file, string $cartella, array $estensioni, array $mime): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, $estensioni, true)) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $tipo = finfo_file($finfo, (string) ($file['tmp_name'] ?? ''));
        finfo_close($finfo);
        if (!in_array($tipo, $mime, true)) {
            return null;
        }
        if (!file_exists($cartella)) {
            mkdir($cartella, 0755, true);
        }
        $nome = bin2hex(random_bytes(16)) . '.' . $ext;

        return move_uploaded_file((string) ($file['tmp_name'] ?? ''), $cartella . $nome) ? $nome : null;
    }

    /** Crea la cartella (se manca) con un .htaccess che la blocca al web: i file si scaricano solo dalle pagine del portale. */
    public function cartellaProtetta(string $cartella, string $motivo): void
    {
        if (!is_dir($cartella)) {
            @mkdir($cartella, 0755, true);
        }
        if (!is_file($cartella . '.htaccess')) {
            @file_put_contents($cartella . '.htaccess', "# $motivo\nRequire all denied\n");
        }
    }
}
