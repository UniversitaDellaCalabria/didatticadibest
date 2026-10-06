<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Database;

/** Schede di valutazione della struttura ospitante compilate dalle scuole (tabella valutazioni_fsl). */
final class ValutazioneRepository
{
    public function __construct(private Database $db)
    {
    }

    /** Salva la scheda (una sola per prenotazione): righe inserite, 0 se esiste già, -1 se la query non riesce. */
    public function inserisci(int $prenotazioneId, int $eventoId, ?string $codiceScuola, string $compilataDa, string $risposteJson, float $media, string $ripeterebbe): int
    {
        return $this->db->esegui(
            'INSERT IGNORE INTO valutazioni_fsl (prenotazione_id, evento_id, scuola_codice, compilata_da, risposte_json, media, ripeterebbe) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$prenotazioneId, $eventoId, $codiceScuola, $compilataDa, $risposteJson, $media, $ripeterebbe]
        );
    }
}
