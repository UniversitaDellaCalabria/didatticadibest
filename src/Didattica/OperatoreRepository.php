<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Eventi\Righe;

/** Operatori dell'Ufficio didattico (ufficio_didattica): chi segue pratiche, sedute, ricevimento e bandi. */
final class OperatoreRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, string|null>> tutti, in ordine di nominativo */
    public function tutti(): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM ufficio_didattica ORDER BY nominativo'));
    }

    /**
     * Aggiunge un operatore o, se l'email c'è già, ne aggiorna i dati. False se il salvataggio non riesce.
     */
    public function salva(string $personaId, string $email, string $nominativo, string $ruolo, string $compiti, ?int $ufficioId, ?string $corsiJson): bool
    {
        return $this->db->esegui(
            'INSERT INTO ufficio_didattica (persona_id, email, nominativo, ruolo, compiti, ufficio_id, corsi) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE persona_id = VALUES(persona_id), nominativo = VALUES(nominativo), ruolo = VALUES(ruolo), compiti = VALUES(compiti), ufficio_id = VALUES(ufficio_id), corsi = VALUES(corsi)',
            [$personaId, $email, $nominativo, $ruolo, $compiti, $ufficioId, $corsiJson]
        ) >= 0;
    }

    /** Persone dell'Ufficio didattico che stanno nell'ufficio indicato. */
    public function contaDelloUfficio(int $ufficioId): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM ufficio_didattica WHERE ufficio_id = ?', [$ufficioId]);
    }

    public function togli(int $id): void
    {
        $this->db->esegui('DELETE FROM ufficio_didattica WHERE id = ?', [$id]);
    }
}
