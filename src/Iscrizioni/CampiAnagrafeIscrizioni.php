<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Core\Database;

/**
 * Campi «Scuola» e «Corso di studio» da inc/anagrafi.php: html_campo_scuola() segnala alla pagina che serve lo script
 * del campo (variabile globale letta dal piè di pagina), per questo passa da qui.
 */
final class CampiAnagrafeIscrizioni implements CampiAnagrafe
{
    public function __construct(private Database $db)
    {
    }

    public function campoScuola(string $campo, string $valore, string $codice): string
    {
        return html_campo_scuola($campo, $valore, $codice);
    }

    public function campoCorso(string $campo, string $valore, string $attr, string $classi, string $id): string
    {
        return html_campo_corso($this->db->mysqli(), $campo, $valore, $attr, $classi, $id);
    }
}
