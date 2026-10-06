<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Core\Database;

/** Campi del modulo di iscrizione che l'anagrafe dei progetti garantisce (tabella campi_form, gestita dal Form Builder). */
final class CampiProgettoRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Il modulo di iscrizione dei progetti per le scuole chiede il numero di partecipanti: campo dell'area, creato se manca,
     * mostrato SOLO nei progetti dedicati alle scuole. Gli altri campi si gestiscono dal Form Builder.
     */
    public function assicuraPartecipanti(int $paginaId, string $nomeCampo): void
    {
        if ($this->db->riga('SELECT 1 FROM campi_form WHERE pagina_id = ? AND nome_campo = ? LIMIT 1', [$paginaId, $nomeCampo]) !== null) {
            return;
        }
        $this->db->esegui(
            "INSERT INTO campi_form (pagina_id, evento_id, nome_campo, etichetta, tipo_campo, opzioni_select, obbligatorio, ordine) VALUES (?, NULL, ?, 'Numero di partecipanti', 'number', '', 1, -20)",
            [$paginaId, $nomeCampo]
        );
    }
}
