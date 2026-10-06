<?php

declare(strict_types=1);

namespace App\Infrastructure\Documenti;

/** Manda al browser un file temporaneo come download e lo cancella (termina la richiesta). */
final class Download
{
    public static function invia(string $percorso, string $nome, string $tipo): never
    {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $tipo);
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.\- ]+/u', '_', $nome) . '"');
    header('Content-Length: ' . filesize($percorso));
    header('X-Content-Type-Options: nosniff');
    readfile($percorso);
    @unlink($percorso);
    exit;
}
}
