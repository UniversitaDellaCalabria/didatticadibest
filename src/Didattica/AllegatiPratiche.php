<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Sito;
use App\Infrastructure\Storage\Upload;

/** Allegati delle pratiche: PDF, immagini e .p7m fino a 10 MB in una cartella bloccata al web (si scaricano solo da allegato_pratica.php). */
final class AllegatiPratiche
{
    public function __construct(private Upload $upload, private Sito $sito, private string $cartella = Costanti::DIR_PRATICHE)
    {
    }

    /** Cartella (relativa alla radice del sito, con «/» finale) dove stanno gli allegati. */
    public function cartella(): string
    {
        return $this->cartella;
    }

    /**
     * Allegato di una pratica (PDF o immagine, max 10 MB): percorso relativo o null.
     *
     * @param array<string, mixed> $file elemento di $_FILES
     */
    public function salva(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 10 * 1024 * 1024) {
            return null;
        }
        $dir = $this->sito->radice() . '/' . $this->cartella;
        $this->proteggi();
        $fn = $this->upload->salva($file, $dir, ['pdf', 'jpg', 'jpeg', 'png', 'p7m'], ['application/pdf', 'image/jpeg', 'image/png', 'application/pkcs7-mime', 'application/x-pkcs7-mime', 'application/octet-stream']);

        return $fn ? $this->cartella . $fn : null;
    }

    /** Salva un PDF già letto (es. un certificato caricato dall'ufficio) nella cartella degli allegati con un nome casuale. Ritorna il percorso, null se non riesce. */
    public function salvaPdf(string $contenuto): ?string
    {
        if (!str_starts_with($contenuto, '%PDF') || strlen($contenuto) > 10 * 1024 * 1024) {
            return null;
        }
        $this->proteggi();
        $nome = bin2hex(random_bytes(16)) . '.pdf';

        return @file_put_contents($this->sito->radice() . '/' . $this->cartella . $nome, $contenuto) === false ? null : $this->cartella . $nome;
    }

    /** Crea la cartella (se manca) con il file che la blocca al web. */
    public function proteggi(): void
    {
        $dir = $this->sito->radice() . '/' . $this->cartella;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '.htaccess')) {
            @file_put_contents($dir . '.htaccess', "# Allegati delle pratiche: si scaricano solo da allegato_pratica.php\nRequire all denied\n");
        }
    }
}
