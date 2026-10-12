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
    public function salva(string $personaId, string $email, string $nominativo, string $ruolo, string $compiti, ?int $ufficioId, ?string $corsiJson, ?string $ufficiJson = null): bool
    {
        return $this->db->esegui(
            'INSERT INTO ufficio_didattica (persona_id, email, nominativo, ruolo, compiti, ufficio_id, corsi, uffici_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE persona_id = VALUES(persona_id), nominativo = VALUES(nominativo), ruolo = VALUES(ruolo), compiti = VALUES(compiti), ufficio_id = VALUES(ufficio_id), corsi = VALUES(corsi), uffici_json = VALUES(uffici_json)',
            [$personaId, $email, $nominativo, $ruolo, $compiti, $ufficioId, $corsiJson, $ufficiJson]
        ) >= 0;
    }

    /** Persone dell'Ufficio didattico che stanno nell'ufficio indicato (anche se non è il loro ufficio principale). */
    public function contaDelloUfficio(int $ufficioId): int
    {
        return count(array_filter($this->tutti(), fn (array $o): bool => in_array($ufficioId, self::uffici($o), true)));
    }

    /**
     * Tutti gli uffici di un operatore: quello principale (ufficio_id) per primo, poi gli altri.
     *
     * @param array<string, mixed> $o riga di ufficio_didattica
     * @return list<int>
     */
    public static function uffici(array $o): array
    {
        $ids = [];
        if (!empty($o['ufficio_id'])) {
            $ids[] = (int) $o['ufficio_id'];
        }
        foreach (json_decode((string) ($o['uffici_json'] ?? ''), true) ?: [] as $x) {
            if ((int) $x > 0) {
                $ids[] = (int) $x;
            }
        }

        return array_values(array_unique($ids));
    }

    public function togli(int $id): void
    {
        $this->db->esegui('DELETE FROM ufficio_didattica WHERE id = ?', [$id]);
    }
}
