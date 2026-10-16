<?php

declare(strict_types=1);

namespace App\Ateneo;

use App\Core\Database;
use App\Eventi\Righe;

/** Uffici di Ateneo (tabella uffici, ambito «ateneo») e persone che ne fanno parte (uffici_persone). */
final class UfficioAteneoRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, string|null>|null */
    public function perId(int $id): ?array
    {
        return Righe::riga($this->db->riga("SELECT * FROM uffici WHERE id = ? AND ambito = 'ateneo'", [$id]));
    }

    /**
     * Tutti gli uffici con il numero di persone.
     *
     * @return list<array<string, string|null>>
     */
    public function tutti(bool $soloAttivi = false): array
    {
        return Righe::testo($this->db->righe(
            "SELECT u.*, (SELECT COUNT(*) FROM uffici_persone p WHERE p.ufficio_id = u.id) AS n_persone FROM uffici u WHERE u.ambito = 'ateneo'"
            . ($soloAttivi ? ' AND u.attivo = 1' : '') . ' ORDER BY u.nome'
        ));
    }

    public function salva(int $id, string $nome, string $descrizione, bool $attivo): int
    {
        if ($id > 0) {
            $this->db->esegui("UPDATE uffici SET nome = ?, descrizione = ?, attivo = ? WHERE id = ? AND ambito = 'ateneo'", [$nome, $descrizione, $attivo ? 1 : 0, $id]);

            return $id;
        }

        return $this->db->inserisci("INSERT INTO uffici (nome, descrizione, ambito, attivo) VALUES (?, ?, 'ateneo', ?)", [$nome, $descrizione, $attivo ? 1 : 0]);
    }

    public function elimina(int $id): void
    {
        $this->db->esegui("DELETE p FROM uffici_persone p JOIN uffici u ON u.id = p.ufficio_id WHERE p.ufficio_id = ? AND u.ambito = 'ateneo'", [$id]);
        $this->db->esegui("DELETE FROM uffici WHERE id = ? AND ambito = 'ateneo'", [$id]);
    }

    /** @return list<array<string, string|null>> */
    public function persone(int $ufficioId): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM uffici_persone WHERE ufficio_id = ? ORDER BY nominativo', [$ufficioId]));
    }

    /** Aggiunge la persona (la stessa email nello stesso ufficio una volta sola). Ritorna false se c'era già. */
    public function aggiungiPersona(int $ufficioId, ?string $personaId, string $email, string $nominativo, string $struttura): bool
    {
        if ((int) $this->db->valore('SELECT COUNT(*) FROM uffici_persone WHERE ufficio_id = ? AND email = ?', [$ufficioId, $email]) > 0) {
            return false;
        }

        return $this->db->inserisci('INSERT INTO uffici_persone (ufficio_id, persona_id, email, nominativo, struttura) VALUES (?, ?, ?, ?, ?)',
            [$ufficioId, $personaId, $email, $nominativo, $struttura]) > 0;
    }

    public function togliPersona(int $id): void
    {
        $this->db->esegui('DELETE FROM uffici_persone WHERE id = ?', [$id]);
    }

    public function membro(int $ufficioId, string $email): bool
    {
        return $email !== '' && (int) $this->db->valore('SELECT COUNT(*) FROM uffici_persone WHERE ufficio_id = ? AND email = ?', [$ufficioId, strtolower($email)]) > 0;
    }

    /**
     * Uffici (attivi) di Ateneo di cui la persona fa parte.
     *
     * @return list<array<string, string|null>>
     */
    public function dellaPersona(string $email): array
    {
        if ($email === '') {
            return [];
        }

        return Righe::testo($this->db->righe(
            "SELECT u.* FROM uffici u JOIN uffici_persone p ON p.ufficio_id = u.id WHERE p.email = ? AND u.attivo = 1 AND u.ambito = 'ateneo' ORDER BY u.nome",
            [strtolower($email)]
        ));
    }

    /** Quanti bandi di tutorato pagano con questo ufficio (non si elimina un ufficio in uso). */
    public function bandiCheLoUsano(int $ufficioId): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) FROM tutorato_bandi WHERE ufficio_ateneo_id = ?', [$ufficioId]);
    }
}
