<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Core\Database;
use App\Eventi\Righe;

/** Query dei campi personalizzati dei moduli di iscrizione (tabella campi_form): elenco, inserimento, modifica, ordine, eliminazione. */
final class FormBuilderRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Evento di un campo dell'area (evento_id null se vale per tutti gli eventi); null se il campo non è di quell'area.
     *
     * @return array<string, mixed>|null
     */
    public function campoDellArea(int $campoId, int $paginaId): ?array
    {
        return $this->db->riga('SELECT evento_id FROM campi_form WHERE id = ? AND pagina_id = ? LIMIT 1', [$campoId, $paginaId]);
    }

    public function impostaOrdine(int $campoId, int $ordine): void
    {
        $this->db->esegui('UPDATE campi_form SET ordine = ? WHERE id = ?', [$ordine, $campoId]);
    }

    /** Ordine attuale del campo (null se non esiste). */
    public function ordine(int $campoId): ?int
    {
        $r = $this->db->riga('SELECT ordine FROM campi_form WHERE id = ? LIMIT 1', [$campoId]);

        return $r === null ? null : (int) $r['ordine'];
    }

    /** Aggiunge un campo all'area ($eventoId null = per tutti gli eventi dell'area). */
    public function inserisci(int $paginaId, ?int $eventoId, string $nomeCampo, string $etichetta, string $tipo, string $opzioni, int $obbligatorio, int $ordine, ?string $condizioneJson): void
    {
        $this->db->esegui(
            'INSERT INTO campi_form (pagina_id, evento_id, nome_campo, etichetta, tipo_campo, opzioni_select, obbligatorio, ordine, condizione_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$paginaId, $eventoId, $nomeCampo, $etichetta, $tipo, $opzioni, $obbligatorio, $ordine, $condizioneJson]
        );
    }

    public function aggiorna(int $campoId, ?int $eventoId, string $etichetta, string $tipo, string $opzioni, int $obbligatorio, ?string $condizioneJson): void
    {
        $this->db->esegui(
            'UPDATE campi_form SET evento_id=?, etichetta=?, tipo_campo=?, opzioni_select=?, obbligatorio=?, condizione_json=? WHERE id=?',
            [$eventoId, $etichetta, $tipo, $opzioni, $obbligatorio, $condizioneJson, $campoId]
        );
    }

    public function elimina(int $campoId): void
    {
        $this->db->esegui('DELETE FROM campi_form WHERE id = ?', [$campoId]);
    }

    /**
     * Eventi non archiviati dell'area (per la scelta dell'evento di destinazione).
     *
     * @return list<array<string, string|null>>
     */
    public function eventi(int $paginaId): array
    {
        return Righe::testo($this->db->righe('SELECT id, titolo FROM eventi e WHERE pagina_id = ? AND archiviato = 0 ORDER BY ordine ASC, id DESC', [$paginaId]));
    }

    /**
     * Campi dell'area (suoi o dei suoi eventi), con il titolo dell'evento di destinazione.
     *
     * @return list<array<string, string|null>>
     */
    public function campi(int $paginaId): array
    {
        return Righe::testo($this->db->righe(
            "SELECT cf.*, COALESCE(e.titolo, '') AS evento_titolo FROM campi_form cf LEFT JOIN eventi e ON cf.evento_id = e.id WHERE cf.pagina_id = ? OR (cf.evento_id > 0 AND e.pagina_id = ?) ORDER BY cf.ordine ASC, cf.id ASC",
            [$paginaId, $paginaId]
        ));
    }
}
