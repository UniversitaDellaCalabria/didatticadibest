<?php

declare(strict_types=1);

namespace App\Tutorato;

use App\Core\Database;

/** Registro delle attività del tutor (tabella tutorato_registro). */
final class RegistroRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Righe del registro della lettera, in ordine di data.
     *
     * @return list<array<string, mixed>>
     */
    public function righe(int $incaricoId): array
    {
        return $this->db->righe('SELECT * FROM tutorato_registro WHERE incarico_id = ? ORDER BY data, id', [$incaricoId]);
    }

    public function aggiungi(int $incaricoId, string $data, float $ore, string $attivita): void
    {
        $this->db->esegui('INSERT INTO tutorato_registro (incarico_id, data, ore, attivita) VALUES (?, ?, ?, ?)', [$incaricoId, $data, $ore, $attivita]);
    }

    /** Toglie una riga non ancora decisa; true se l'ha tolta. */
    public function togliSeDaApprovare(int $incaricoId, int $riga): bool
    {
        return $this->db->esegui("DELETE FROM tutorato_registro WHERE id = ? AND incarico_id = ? AND stato = 'inviata'", [$riga, $incaricoId]) > 0;
    }

    /** Il docente decide una riga: ritorna le righe cambiate (0 se non è cambiato nulla o la query non riesce). */
    public function decidi(int $riga, string $esito, string $nota): int
    {
        return max(0, $this->db->esegui('UPDATE tutorato_registro SET stato = ?, nota_docente = ?, decisa_il = NOW() WHERE id = ?', [$esito, $nota, $riga]));
    }

    /** Righe da approvare da più di 7 giorni. */
    public function contaDaApprovareVecchie(int $incaricoId): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) FROM tutorato_registro WHERE incarico_id = ? AND stato = 'inviata' AND creata_il < NOW() - INTERVAL 7 DAY", [$incaricoId]);
    }

    public function eliminaDellaLettera(int $incaricoId): void
    {
        $this->db->esegui('DELETE FROM tutorato_registro WHERE incarico_id = ?', [$incaricoId]);
    }
}
