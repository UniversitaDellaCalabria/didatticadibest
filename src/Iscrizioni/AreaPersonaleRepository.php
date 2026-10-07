<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Core\Database;
use App\Eventi\Righe;

/**
 * Query dell'Area personale (area_personale.php): prenotazioni dell'utente, modifica, annullamento, messaggi alla segreteria,
 * posti offerti dalla lista d'attesa e riquadri di sintesi di altri moduli (aule, ricevimento, pratiche, tutorato).
 * Le persone si riconoscono per id utente o per email (già in minuscolo: $email).
 */
final class AreaPersonaleRepository
{
    public function __construct(private Database $db)
    {
    }

    // ---- elenco delle prenotazioni ----

    /** @return list<array<string, mixed>> tutte le prenotazioni dell'utente con turno, evento e area, dalla più recente */
    public function dellUtente(int $utenteId, string $email): array
    {
        return $this->db->righe(
            "SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.annullabile_fino,
           e.titolo as evento_titolo, e.luogo as evento_luogo, e.id as evento_id, e.locandina_path, e.abilita_presenze, e.tipo AS evento_tipo,
           pd.per_scuole, pd.attestati, pd.data_fine AS progetto_fine, pd.data_inizio, IFNULL(pd.convenzione, 0) AS fsl,
           pe.titolo as pagina_titolo, pe.colore_primario, pe.id as p_id
           FROM prenotazioni pr
           JOIN turni t ON pr.turno_id = t.id
           JOIN eventi e ON t.evento_id = e.id
           JOIN pagine_eventi pe ON e.pagina_id = pe.id
           LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
           WHERE (pr.utente_id = ? OR LOWER(pr.email) = ?)
           ORDER BY t.data_turno DESC, t.orario_inizio DESC",
            [$utenteId, $email]
        );
    }

    /**
     * @param list<int> $eventiIds
     * @return list<array<string, string|null>> turni ancora a venire di quegli eventi (senza data o dopo $adesso), in ordine di data
     */
    public function turniFuturi(array $eventiIds, string $adesso): array
    {
        return Righe::testo($this->db->righe(
            "SELECT id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, data_apertura, data_chiusura
                                    FROM turni
                                    WHERE evento_id IN (" . implode(',', $eventiIds) . ") AND (data_turno IS NULL OR CONCAT(data_turno, ' ', COALESCE(orario_inizio, '23:59:59')) > ?)
                                    ORDER BY (data_turno IS NULL), data_turno ASC, orario_inizio ASC, nome_turno ASC, id ASC",
            [$adesso]
        ));
    }

    /**
     * @param list<int> $turniIds
     * @return array<int, int> turno_id => posti confermati
     */
    public function postiConfermatiPerTurno(array $turniIds): array
    {
        $occupati = [];
        foreach ($this->db->righe(
            "SELECT turno_id, COALESCE(SUM(num_posti), 0) as tot
                                        FROM prenotazioni
                                        WHERE turno_id IN (" . implode(',', $turniIds) . ") AND stato = 'confermata'
                                        GROUP BY turno_id"
        ) as $o) {
            $occupati[(int) $o['turno_id']] = (int) $o['tot'];
        }

        return $occupati;
    }

    /** Il questionario di gradimento dell'evento è attivo. */
    public function sondaggioAttivo(int $eventoId): bool
    {
        return $this->db->riga('SELECT id FROM sondaggi WHERE evento_id = ? AND attivo = 1 LIMIT 1', [$eventoId]) !== null;
    }

    public function impostaTokenSondaggio(int $prenotazioneId, string $token): void
    {
        $this->db->esegui('UPDATE prenotazioni SET token_sondaggio = ? WHERE id = ?', [$token, $prenotazioneId]);
    }

    // ---- messaggi alla segreteria ----

    /** La prenotazione appartiene alla persona. */
    public function appartieneA(int $prenotazioneId, int $utenteId, string $email): bool
    {
        return $this->db->righe('SELECT id FROM prenotazioni WHERE id = ? AND (utente_id = ? OR LOWER(email) = ?)', [$prenotazioneId, $utenteId, $email]) !== [];
    }

    public function segnaLettiMessaggiAdmin(int $prenotazioneId): void
    {
        $this->db->esegui("UPDATE messaggi_prenotazioni SET letto = 1 WHERE prenotazione_id = ? AND mittente_tipo = 'admin'", [$prenotazioneId]);
    }

    public function inserisciMessaggioUtente(int $prenotazioneId, int $utenteId, string $messaggioHtml): void
    {
        $this->db->esegui("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, mittente_id, messaggio, letto) VALUES (?, 'utente', ?, ?, 0)", [$prenotazioneId, $utenteId, $messaggioHtml]);
    }

    /** @return array<string, string|null>|null nome della persona ed evento della prenotazione (per avvisare i gestori) */
    public function personaEEvento(int $prenotazioneId): ?array
    {
        return Righe::riga($this->db->riga(
            'SELECT pr.nome, pr.cognome, e.id as evento_id, e.titolo as evento_titolo
                                    FROM prenotazioni pr
                                    JOIN turni t ON pr.turno_id = t.id
                                    JOIN eventi e ON t.evento_id = e.id
                                    WHERE pr.id = ? LIMIT 1',
            [$prenotazioneId]
        ));
    }

    // ---- modifica dei dati e cambio turno ----

    /** @return array<string, mixed>|null turno, posti e dati del modulo di una prenotazione della persona */
    public function perModifica(int $prenotazioneId, int $utenteId, string $email): ?array
    {
        return $this->db->riga(
            'SELECT turno_id, num_posti, dati_custom_json FROM prenotazioni WHERE id = ? AND (utente_id = ? OR (email IS NOT NULL AND LOWER(email) = ?))',
            [$prenotazioneId, $utenteId, $email]
        );
    }

    /** @return array<string, string|null>|null evento, limiti di partecipanti e termine di annullamento del turno */
    public function turnoEdEvento(int $turnoId): ?array
    {
        return Righe::riga($this->db->riga(
            'SELECT t.evento_id, t.min_partecipanti, t.max_partecipanti, t.annullabile_fino, e.tipo FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = ?',
            [$turnoId]
        ));
    }

    /** @return array<string, mixed>|null capienza e lista d'attesa del turno */
    public function capienzaTurno(int $turnoId): ?array
    {
        return $this->db->riga('SELECT max_posti, abilita_lista_attesa FROM turni WHERE id = ? LIMIT 1', [$turnoId]);
    }

    /** Posti confermati del turno, con blocco delle righe (solo dentro una transazione). */
    public function postiConfermatiBloccati(int $turnoId): int
    {
        return (int) $this->db->valore("SELECT COALESCE(SUM(num_posti), 0) as tot FROM prenotazioni WHERE turno_id = ? AND stato = 'confermata' FOR UPDATE", [$turnoId]);
    }

    /** Aggiorna i dati della prenotazione; con $nuovoStato cambia anche turno e stato. false se l'aggiornamento non riesce. */
    public function aggiorna(int $prenotazioneId, string $nome, string $cognome, string $email, string $matricola, ?string $datiCustomJson, ?int $nuovoTurnoId, ?string $nuovoStato): bool
    {
        if ($nuovoStato !== null) {
            return $this->db->esegui(
                'UPDATE prenotazioni SET turno_id = ?, stato = ?, nome = ?, cognome = ?, email = ?, matricola = ?, dati_custom_json = ? WHERE id = ?',
                [$nuovoTurnoId, $nuovoStato, $nome, $cognome, $email, $matricola, $datiCustomJson, $prenotazioneId]
            ) >= 0;
        }

        return $this->db->esegui(
            'UPDATE prenotazioni SET nome = ?, cognome = ?, email = ?, matricola = ?, dati_custom_json = ? WHERE id = ?',
            [$nome, $cognome, $email, $matricola, $datiCustomJson, $prenotazioneId]
        ) >= 0;
    }

    public function impostaScuolaPrenotazione(int $prenotazioneId, ?string $codice): void
    {
        $this->db->esegui('UPDATE prenotazioni SET scuola_codice = ? WHERE id = ?', [$codice, $prenotazioneId]);
    }

    public function impostaScuolaUtente(int $utenteId, ?string $codice): void
    {
        $this->db->esegui('UPDATE utenti SET scuola_codice = ? WHERE id = ?', [$codice, $utenteId]);
    }

    public function messaggioErrore(): string
    {
        return $this->db->ultimoErrore();
    }

    /** @return array<string, string|null>|null il primo in coda del turno (tutte le colonne) */
    public function primoInAttesa(int $turnoId): ?array
    {
        return Righe::riga($this->db->riga("SELECT * FROM prenotazioni WHERE turno_id = ? AND stato = 'in_attesa' ORDER BY data_prenotazione ASC, id ASC LIMIT 1", [$turnoId]));
    }

    /** Chi era in coda ottiene il posto: confermata, oppure da approvare se la scuola non ha ancora la convenzione. */
    public function promuoviDallaCoda(int $prenotazioneId): void
    {
        $this->db->esegui("UPDATE prenotazioni SET stato = IF(convenzione = 'no', 'da_approvare', 'confermata') WHERE id = ?", [$prenotazioneId]);
    }

    // ---- annullamento ----

    /** @return array<string, mixed>|null la prenotazione della persona con turno, termine di annullamento ed evento */
    public function perAnnullamento(int $prenotazioneId, int $utenteId, string $email): ?array
    {
        return $this->db->riga(
            'SELECT pr.*, t.id as turno_id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.annullabile_fino, e.titolo as evento_titolo, e.luogo, e.pagina_id, e.id as evento_id FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE pr.id = ? AND (pr.utente_id = ? OR LOWER(pr.email) = ?)',
            [$prenotazioneId, $utenteId, $email]
        );
    }

    /** La riga resta nel DB per storico e verifiche: cambia solo lo stato. */
    public function annulla(int $prenotazioneId): void
    {
        $this->db->esegui("UPDATE prenotazioni SET stato = 'annullata' WHERE id = ?", [$prenotazioneId]);
    }

    // ---- posto offerto dalla lista d'attesa ----

    /** @return array<string, mixed>|null l'offerta di posto con blocco della riga (solo dentro una transazione) */
    public function offertaBloccata(int $prenotazioneId, int $utenteId, string $email): ?array
    {
        return $this->db->riga(
            'SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo AS evento_titolo, e.luogo
                               FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                               WHERE pr.id = ? AND (pr.utente_id = ? OR LOWER(pr.email) = ?) FOR UPDATE',
            [$prenotazioneId, $utenteId, $email]
        );
    }

    /** Cambia lo stato; false se l'aggiornamento non riesce. */
    public function impostaStato(int $prenotazioneId, string $stato): bool
    {
        return $this->db->esegui('UPDATE prenotazioni SET stato = ? WHERE id = ?', [$stato, $prenotazioneId]) >= 0;
    }

    /** @return array<string, string|null> la riga di pagine_eventi dell'area del turno ([] se manca) */
    public function areaDelTurno(int $turnoId): array
    {
        return Righe::riga($this->db->riga('SELECT pe.* FROM pagine_eventi pe JOIN eventi e ON e.pagina_id = pe.id JOIN turni t ON t.evento_id = e.id WHERE t.id = ?', [$turnoId])) ?? [];
    }

    /** @return array<string, mixed>|null il posto offerto, per il riquadro «Si è liberato un posto per te» */
    public function offertaPerRiquadro(int $prenotazioneId, int $utenteId, string $email): ?array
    {
        return $this->db->riga(
            'SELECT pr.id, pr.stato, pr.scadenza_conferma, pr.num_posti, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo AS evento_titolo, e.luogo
                               FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                               WHERE pr.id = ? AND (pr.utente_id = ? OR LOWER(pr.email) = ?)',
            [$prenotazioneId, $utenteId, $email]
        );
    }

    // ---- riquadri di sintesi di altri moduli (tabelle di Risorse, Didattica e Tutorato) ----

    /** @return list<array<string, string|null>> aule, laboratori e appuntamenti prenotati dall'utente e ancora da vivere (errori di tabella ignorati) */
    public function risorsePrenotate(int $utenteId): array
    {
        return Righe::testo($this->db->righe(
            "SELECT pr.*, r.nome AS risorsa_nome, r.luogo, p.titolo AS area_titolo, p.slug AS area_slug, p.colore_primario
                           FROM prenotazioni_risorse pr JOIN risorse r ON r.id = pr.risorsa_id JOIN pagine_eventi p ON p.id = r.pagina_id
                           WHERE pr.utente_id = ? AND pr.stato IN ('confermata', 'da_approvare') AND pr.fine >= NOW() ORDER BY pr.inizio LIMIT 50",
            [$utenteId]
        ));
    }

    /**
     * @param list<int> $risorseIds
     * @return list<array<string, string|null>> per ogni sportello: appuntamenti in programma e da approvare
     */
    public function riepilogoSportelli(array $risorseIds): array
    {
        return Righe::testo($this->db->righe(
            "SELECT r.id, (SELECT COUNT(*) FROM prenotazioni_risorse pr WHERE pr.risorsa_id = r.id AND pr.stato IN ('confermata', 'da_approvare') AND pr.fine >= NOW()) AS n,
                                         (SELECT COUNT(*) FROM prenotazioni_risorse pr WHERE pr.risorsa_id = r.id AND pr.stato = 'da_approvare' AND pr.fine >= NOW()) AS da_appr
                                  FROM risorse r WHERE r.id IN (" . implode(',', $risorseIds) . ')'
        ));
    }

    /** @return array<string, string|null>|null numero di pratiche dell'utente, con integrazione richiesta e ancora aperte */
    public function riepilogoPratiche(int $utenteId): ?array
    {
        return Righe::riga($this->db->riga(
            "SELECT COUNT(*) AS n, SUM(stato = 'integrazione') AS integr, SUM(stato NOT IN ('accolta', 'respinta', 'chiusa')) AS aperte FROM pratiche WHERE utente_id = ?",
            [$utenteId]
        ));
    }

    /** Voci del registro di tutorato già inviate dal tutor all'incarico. */
    public function registriTutoratoInviati(int $incaricoId): int
    {
        return (int) $this->db->valore("SELECT COUNT(*) FROM tutorato_registro WHERE incarico_id = ? AND stato = 'inviata'", [$incaricoId]);
    }
}
