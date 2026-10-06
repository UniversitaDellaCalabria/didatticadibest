<?php

declare(strict_types=1);

namespace App\Eventi;

use App\Core\Database;
use RuntimeException;

/** Scheda di un progetto o di un evento (tabella progetti_dettagli): attestati, scuole, convenzione, corso, destinazione. */
final class ProgettoRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Opzioni scuole di un evento normale: attestati per gli studenti della classe, «Dedicato alle scuole» e attività FSL (convenzione).
     */
    public function salvaOpzioniScuole(int $eventoId, int $attestati, int $convenzione, int $dedicataScuole): void
    {
        $this->db->esegui(
            'INSERT INTO progetti_dettagli (evento_id, attestati, per_scuole, convenzione, dedicata_scuole, updated_at) VALUES (?, ?, 1, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE attestati = VALUES(attestati), per_scuole = 1, convenzione = VALUES(convenzione), dedicata_scuole = VALUES(dedicata_scuole), updated_at = NOW()',
            [$eventoId, $attestati, $convenzione, $dedicataScuole]
        );
    }

    /**
     * Scheda del progetto (nuova o aggiornata). Lancia RuntimeException se non riesce.
     *
     * @param array<string, mixed> $d struttura, data_inizio, data_fine, periodo_note, destinatari, modalita, ore_totali, incontri_previsti, min_studenti, max_studenti
     * @param array<string, mixed> $v referenti_json, info_json, moduli_json, obiettivi, conoscenze, competenze, per_scuole, attestati
     */
    public function salvaScheda(int $eventoId, array $d, array $v): void
    {
        $r = $this->db->esegui(
            'INSERT INTO progetti_dettagli (evento_id, struttura, data_inizio, data_fine, periodo_note, destinatari, modalita, ore_totali, incontri_previsti, min_studenti, max_studenti, referenti_json, info_extra_json, moduli_json, obiettivi, conoscenze, competenze, per_scuole, attestati, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE struttura=VALUES(struttura), data_inizio=VALUES(data_inizio), data_fine=VALUES(data_fine), periodo_note=VALUES(periodo_note),
                 destinatari=VALUES(destinatari), modalita=VALUES(modalita), ore_totali=VALUES(ore_totali), incontri_previsti=VALUES(incontri_previsti),
                 min_studenti=VALUES(min_studenti), max_studenti=VALUES(max_studenti),
                 referenti_json=VALUES(referenti_json), info_extra_json=VALUES(info_extra_json), moduli_json=VALUES(moduli_json),
                 obiettivi=VALUES(obiettivi), conoscenze=VALUES(conoscenze), competenze=VALUES(competenze),
                 per_scuole=VALUES(per_scuole), attestati=VALUES(attestati), updated_at=NOW()',
            [$eventoId, $d['struttura'], $d['data_inizio'], $d['data_fine'], $d['periodo_note'], $d['destinatari'], $d['modalita'],
                $d['ore_totali'], $d['incontri_previsti'], $d['min_studenti'], $d['max_studenti'], $v['referenti_json'], $v['info_json'], $v['moduli_json'],
                $v['obiettivi'], $v['conoscenze'], $v['competenze'], (int) $v['per_scuole'], (int) $v['attestati']]
        );
        if ($r < 0) {
            throw new RuntimeException($this->db->ultimoErrore());
        }
    }

    /** Corso di studio scelto dall'anagrafe (link alla pagina del corso nella scheda pubblica). */
    public function impostaCorso(int $eventoId, ?string $codice): void
    {
        $this->db->esegui('UPDATE progetti_dettagli SET corso_codice = ? WHERE evento_id = ?', [$codice, $eventoId]);
    }

    /** Rimando a un'altra pagina (slug di un'area o indirizzo http(s)). Lancia RuntimeException se non riesce. */
    public function impostaDestinazione(int $eventoId, ?string $destinazione): void
    {
        if ($this->db->esegui('UPDATE progetti_dettagli SET destinazione = ? WHERE evento_id = ?', [$destinazione, $eventoId]) < 0) {
            throw new RuntimeException($this->db->ultimoErrore());
        }
    }

    /** Attività di Formazione Scuola Lavoro: processo delle convenzioni. */
    public function impostaConvenzione(int $eventoId, int $convenzione): void
    {
        $this->db->esegui('UPDATE progetti_dettagli SET convenzione = ? WHERE evento_id = ?', [$convenzione, $eventoId]);
    }

    /** Studenti indicati nella prenotazione (scheda delle scuole assegnate). */
    public function contaStudenti(int $prenotazioneId): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) AS n FROM partecipanti_prenotazione WHERE prenotazione_id = ?', [$prenotazioneId]);
    }
}
