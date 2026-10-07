<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Database;
use App\Eventi\Righe;

/** Convenzioni e Allegati A compilati online dalle scuole (tabella convenzioni_compilate). */
final class ConvenzioneCompilataRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function perToken(string $token): ?array
    {
        return $this->db->riga('SELECT * FROM convenzioni_compilate WHERE token = ?', [$token]);
    }

    /**
     * L'ultima compilazione della prenotazione (una sola per prenotazione: se c'è già si riprende).
     *
     * @return array<string, string|null>|null
     */
    public function ultimaDellaPrenotazione(int $prenotazioneId): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM convenzioni_compilate WHERE prenotazione_id = ? ORDER BY id DESC LIMIT 1', [$prenotazioneId]));
    }

    /**
     * La compilazione che ha l'attività prenotata tra quelle dell'Allegato A (programma FSL: una compilazione per più prenotazioni).
     *
     * @return array<string, string|null>|null
     */
    public function cheContiene(int $prenotazioneId): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM convenzioni_compilate WHERE dati_json LIKE ? ORDER BY id DESC LIMIT 1', ['%"pr":' . $prenotazioneId . ',%']));
    }

    /**
     * Le compilazioni che riguardano le prenotazioni indicate (la prima prenotazione della compilazione oppure una di quelle dell'Allegato A), dalla più recente.
     *
     * @param list<int> $prenotazioniIds
     * @return list<array<string, string|null>>
     */
    public function perPrenotazioni(array $prenotazioniIds): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $prenotazioniIds)))), 0, 300);
        if (!$ids) {
            return [];
        }
        $where = ['prenotazione_id IN (' . implode(',', $ids) . ')'];
        $par = [];
        foreach ($ids as $id) {
            $where[] = 'dati_json LIKE ?';
            $par[] = '%"pr":' . $id . ',%';
        }

        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_compilate WHERE ' . implode(' OR ', $where) . ' ORDER BY id DESC LIMIT 50', $par));
    }

    /**
     * Apre la compilazione di una prenotazione con un link personale nuovo.
     *
     * @return array<string, string|null>|null la riga appena creata
     */
    public function crea(string $token, ?string $codiceScuola, int $prenotazioneId, string $email): ?array
    {
        $id = $this->db->inserisci('INSERT INTO convenzioni_compilate (token, scuola_codice, prenotazione_id, email) VALUES (?, ?, ?, ?)', [$token, $codiceScuola, $prenotazioneId, $email]);

        return Righe::riga($this->db->riga('SELECT * FROM convenzioni_compilate WHERE id = ?', [$id]));
    }

    public function salvaDati(int $id, string $datiJson, ?string $logo, string $email): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET dati_json = ?, logo = ?, email = ?, aggiornata_il = NOW() WHERE id = ?', [$datiJson, $logo, $email, $id]);
    }

    public function segnaScaricata(int $id): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET scaricata_il = NOW() WHERE id = ?', [$id]);
    }

    /** Protocollo assegnato dal Dipartimento: compare nei documenti Word generati. */
    public function salvaProtocollo(int $id, string $protocollo, ?string $data): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET protocollo = ?, protocollo_data = ? WHERE id = ?', [$protocollo, $data, $id]);
    }

    /**
     * Le compilazioni dell'ultimo anno con i dati inseriti (pannello FSL).
     *
     * @return list<array<string, string|null>>
     */
    public function recenti(int $limite = 50): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_compilate WHERE dati_json IS NOT NULL AND aggiornata_il >= NOW() - INTERVAL 1 YEAR ORDER BY aggiornata_il DESC LIMIT ' . max(1, $limite)));
    }
}
