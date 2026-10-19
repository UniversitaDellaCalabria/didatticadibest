<?php

declare(strict_types=1);

namespace App\Uffici;

use App\Core\Database;
use App\Eventi\Righe;

/**
 * Uffici del Dipartimento e di Ateneo (tabella uffici) e le persone che ne fanno parte (uffici_persone): un'unica anagrafe degli uffici, da cui
 * pratiche, tutorato, prenotazioni e convenzioni prendono i destinatari. Le persone sono sempre quelle dell'anagrafe di Ateneo.
 */
final class UfficiRepository
{
    public const DIPARTIMENTO = 'dipartimento';
    public const ATENEO = 'ateneo';
    /** Uffici fissi del Dipartimento: servono al portale, non si eliminano. Le loro persone non sono «operatori» della Didattica (senza op_id). */
    public const CHIAVI_FISSE = ['direzione', 'segreteria_direzione', 'convenzioni_fsl', 'verbali_scarico'];

    public function __construct(private Database $db)
    {
    }

    /**
     * Gli uffici con il numero di persone, per ambito (tutti se null), in ordine.
     *
     * @return list<array<string, string|null>>
     */
    public function tutti(?string $ambito = null, bool $soloAttivi = false): array
    {
        $dove = [];
        $par = [];
        if ($ambito !== null) {
            $dove[] = 'u.ambito = ?';
            $par[] = $ambito;
        }
        if ($soloAttivi) {
            $dove[] = 'u.attivo = 1';
        }

        return Righe::testo($this->db->righe(
            'SELECT u.*, (SELECT COUNT(*) FROM uffici_persone p WHERE p.ufficio_id = u.id) AS n_persone FROM uffici u'
            . ($dove ? ' WHERE ' . implode(' AND ', $dove) : '') . ' ORDER BY u.ambito, u.ordine, u.nome',
            $par
        ));
    }

    /** @return array<string, string|null>|null */
    public function perId(int $id): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM uffici WHERE id = ?', [$id]));
    }

    /** @return array<string, string|null>|null */
    public function perChiave(string $chiave): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM uffici WHERE chiave = ? ORDER BY id LIMIT 1', [$chiave]));
    }

    /**
     * Crea (id = 0) o modifica un ufficio: ritorna l'id. La chiave non cambia con le modifiche.
     */
    public function salva(int $id, string $ambito, string $nome, string $descrizione, bool $attivo, int $ordine, string $tipo, ?int $consiglioId, bool $smista, bool $segueCorsi): int
    {
        if ($id > 0) {
            $this->db->esegui(
                'UPDATE uffici SET nome = ?, descrizione = ?, attivo = ?, ordine = ?, tipo = ?, consiglio_id = ?, smista = ?, segue_corsi = ? WHERE id = ?',
                [$nome, $descrizione, $attivo ? 1 : 0, $ordine, $tipo, $consiglioId, $smista ? 1 : 0, $segueCorsi ? 1 : 0, $id]
            );

            return $id;
        }

        return $this->db->inserisci(
            'INSERT INTO uffici (nome, descrizione, ambito, attivo, ordine, tipo, consiglio_id, smista, segue_corsi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$nome, $descrizione, $ambito, $attivo ? 1 : 0, $ordine, $tipo, $consiglioId, $smista ? 1 : 0, $segueCorsi ? 1 : 0]
        );
    }

    /** Elimina l'ufficio con le sue persone (i controlli su cosa lo usa sono del servizio). */
    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM uffici_persone WHERE ufficio_id = ?', [$id]);
        $this->db->esegui('DELETE FROM uffici WHERE id = ?', [$id]);
    }

    /**
     * Le persone dell'ufficio (prima chi ha un ruolo di rilievo non conta: ordine alfabetico).
     *
     * @return list<array<string, string|null>>
     */
    public function persone(int $ufficioId): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM uffici_persone WHERE ufficio_id = ? ORDER BY nominativo', [$ufficioId]));
    }

    /** @return array<string, string|null>|null */
    public function persona(int $id): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM uffici_persone WHERE id = ?', [$id]));
    }

    /**
     * Aggiunge la persona all'ufficio. Ritorna false se c'è già. Negli uffici del Dipartimento (non della Direzione) la persona è un «operatore»:
     * ha un op_id stabile, lo stesso in tutti i suoi uffici (è il numero che le pratiche conservano in assegnata_a).
     */
    public function aggiungiPersona(int $ufficioId, ?string $personaId, string $email, string $nominativo, string $struttura, string $ruolo, string $compiti, ?string $corsiJson): bool
    {
        $email = strtolower(trim($email));
        if ((int) $this->db->valore('SELECT COUNT(*) FROM uffici_persone WHERE ufficio_id = ? AND email = ?', [$ufficioId, $email]) > 0) {
            return false;
        }
        $u = $this->perId($ufficioId);
        $operatore = $u && $u['ambito'] === self::DIPARTIMENTO && !in_array((string) $u['chiave'], self::CHIAVI_FISSE, true);
        $op = $operatore ? (int) ($this->db->valore('SELECT MIN(op_id) FROM uffici_persone WHERE email = ? AND op_id IS NOT NULL', [$email]) ?? 0) : 0;
        $nuovo = $this->db->inserisci(
            'INSERT INTO uffici_persone (ufficio_id, persona_id, email, nominativo, struttura, ruolo, compiti, corsi, op_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$ufficioId, $personaId, $email, $nominativo, $struttura, $ruolo, $compiti, $corsiJson, $op ?: null]
        );
        if ($nuovo > 0 && $operatore && $op === 0) {
            $this->db->esegui('UPDATE uffici_persone SET op_id = id WHERE id = ?', [$nuovo]);
        }

        return $nuovo > 0;
    }

    public function aggiornaPersona(int $id, string $ruolo, string $compiti, ?string $corsiJson): void
    {
        $this->db->esegui('UPDATE uffici_persone SET ruolo = ?, compiti = ?, corsi = ? WHERE id = ?', [$ruolo, $compiti, $corsiJson, $id]);
    }

    public function togliPersona(int $id): void
    {
        $this->db->esegui('DELETE FROM uffici_persone WHERE id = ?', [$id]);
    }

    /**
     * Email valide delle persone dell'ufficio (per gli avvisi), senza doppioni.
     *
     * @return list<string>
     */
    public function emailDelUfficio(int $ufficioId): array
    {
        $e = [];
        foreach ($this->persone($ufficioId) as $p) {
            if (filter_var((string) $p['email'], FILTER_VALIDATE_EMAIL)) {
                $e[strtolower((string) $p['email'])] = (string) $p['email'];
            }
        }

        return array_values($e);
    }

    public function membro(int $ufficioId, string $email): bool
    {
        return $email !== '' && (int) $this->db->valore('SELECT COUNT(*) FROM uffici_persone WHERE ufficio_id = ? AND email = ?', [$ufficioId, strtolower(trim($email))]) > 0;
    }

    /**
     * Uffici (attivi) di cui la persona fa parte.
     *
     * @return list<array<string, string|null>>
     */
    public function dellaPersona(string $email, ?string $ambito = null): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return [];
        }

        return Righe::testo($this->db->righe(
            'SELECT u.* FROM uffici u JOIN uffici_persone p ON p.ufficio_id = u.id WHERE p.email = ? AND u.attivo = 1' . ($ambito !== null ? ' AND u.ambito = ?' : '') . ' ORDER BY u.ordine, u.nome',
            $ambito !== null ? [$email, $ambito] : [$email]
        ));
    }
}
