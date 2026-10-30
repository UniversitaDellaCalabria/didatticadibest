<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

/**
 * Un gruppo di persone che non viene dall'anagrafe di Ateneo (Scuole, Aziende): una riga di `ruoli`, come Docenti e PTA, aggiunta ai gruppi secondari della persona
 * (utenti.ruoli_secondari). Il gruppo è la conseguenza di un dato vero (la scuola indicata, la convenzione di tesi) ma l'amministratore può darlo o toglierlo
 * a mano da Utenti.
 */
abstract class GruppoPersone
{
    public function __construct(protected Database $db)
    {
    }

    /** Il nome del gruppo in `ruoli`. */
    abstract public function nome(): string;

    /** L'id del gruppo; lo crea se manca. */
    public function id(): int
    {
        $id = (int) ($this->db->valore('SELECT id FROM ruoli WHERE nome = ? LIMIT 1', [$this->nome()]) ?? 0);
        if ($id === 0) {
            $this->db->esegui('INSERT INTO ruoli (nome) VALUES (?)', [$this->nome()]);
            $id = (int) ($this->db->valore('SELECT id FROM ruoli WHERE nome = ? LIMIT 1', [$this->nome()]) ?? 0);
        }

        return $id;
    }

    /** Aggiunge il gruppo alla persona (se c'è già non cambia niente). */
    public function assegna(int $utenteId): void
    {
        $id = $this->id();
        if ($id === 0) {
            return;
        }
        $this->db->esegui(
            "UPDATE utenti SET ruoli_secondari = TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(ruoli_secondari, ''), ?)) WHERE id = ? AND FIND_IN_SET(?, IFNULL(ruoli_secondari, '')) = 0",
            [(string) $id, $utenteId, (string) $id]
        );
    }

    /** Toglie il gruppo alla persona. */
    public function togli(int $utenteId): void
    {
        $id = $this->id();
        if ($id === 0) {
            return;
        }
        $nuovi = array_values(array_filter(
            explode(',', (string) ($this->db->valore('SELECT ruoli_secondari FROM utenti WHERE id = ?', [$utenteId]) ?? '')),
            static fn (string $x): bool => $x !== '' && (int) $x !== $id
        ));
        $this->db->esegui('UPDATE utenti SET ruoli_secondari = ? WHERE id = ?', [implode(',', $nuovi), $utenteId]);
    }

    /** Dà il gruppo a tutte le persone che già ne hanno diritto (una volta sola, dalla migrazione, per lo storico). @return int quante persone sono state aggiornate */
    abstract public function allineaTutti(): int;

    /** Dà il gruppo alle persone scelte da una condizione SQL su `utenti` (senza parametri dall'esterno). */
    protected function assegnaOve(string $condizione): int
    {
        $id = $this->id();
        if ($id === 0) {
            return 0;
        }

        return $this->db->esegui(
            "UPDATE utenti SET ruoli_secondari = TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(ruoli_secondari, ''), ?)) WHERE ($condizione) AND FIND_IN_SET(?, IFNULL(ruoli_secondari, '')) = 0",
            [(string) $id, (string) $id]
        );
    }
}
