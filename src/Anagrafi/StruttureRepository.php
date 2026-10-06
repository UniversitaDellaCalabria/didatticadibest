<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Core\Database;

/** Strutture di Ateneo da sincronizzare (tabella anagrafe_strutture: il DiBEST di partenza, se ne possono aggiungere altre). */
final class StruttureRepository
{
    public function __construct(private Database $db)
    {
    }

    /** Ora del database: serve a capire chi non è stato aggiornato dalla sincronizzazione. */
    public function adesso(): ?string
    {
        $n = $this->db->valore('SELECT NOW() AS n');

        return $n === null ? null : (string) $n;
    }

    /** @return list<string> codici in ordine di inserimento, o solo quello richiesto */
    public function codici(?string $solo = null): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT codice FROM anagrafe_strutture ORDER BY aggiunta_il') as $r) {
            if ($solo === null || $r['codice'] === $solo) {
                $out[] = (string) $r['codice'];
            }
        }

        return $out;
    }

    /** Codice della prima struttura (il proprio dipartimento): ('' se non ce ne sono) */
    public function prima(): string
    {
        return (string) ($this->db->valore('SELECT codice FROM anagrafe_strutture ORDER BY aggiunta_il, codice LIMIT 1') ?? '');
    }

    /** @return list<array<string, mixed>> */
    public function tutte(): array
    {
        return $this->db->righe('SELECT * FROM anagrafe_strutture ORDER BY aggiunta_il');
    }

    public function aggiungi(string $codice, string $nome): void
    {
        $this->db->esegui('INSERT IGNORE INTO anagrafe_strutture (codice, nome) VALUES (?, ?)', [$codice, $nome]);
    }

    public function elimina(string $codice): void
    {
        $this->db->esegui('DELETE FROM anagrafe_strutture WHERE codice = ?', [$codice]);
    }

    public function segnaErrore(string $codice, string $esito): void
    {
        $this->db->esegui('UPDATE anagrafe_strutture SET esito = ? WHERE codice = ?', [$esito, $codice]);
    }

    public function segnaAggiornata(string $codice, int $persone, int $corsi, string $esito, string $nome): void
    {
        $this->db->esegui(
            "UPDATE anagrafe_strutture SET persone = ?, corsi = ?, ultima_sync = NOW(), esito = ?, nome = IF(? <> '', ?, nome) WHERE codice = ?",
            [$persone, $corsi, $esito, $nome, $nome, $codice]
        );
    }
}
