<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Core\Database;
use App\Eventi\Righe;

/**
 * Query della tabella prenotazioni usate dalla prenotazione pubblica, dalla ricevuta, dal check-in e dai vincoli per area.
 * I conteggi dei posti sono quelli di getPostiOccupati() (config.php): confermata, posto offerto e da approvare occupano un posto.
 */
final class PrenotazioneRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Posti occupati di un turno (somma di num_posti delle prenotazioni che tengono un posto; stato NULL = confermata).
     * $blocca = true SOLO dentro una transazione già aperta: blocca le righe del turno fino al commit, così due prenotazioni
     * concorrenti vengono serializzate invece di leggere lo stesso conteggio «vecchio» (anti-overbooking).
     */
    public function postiOccupati(int $turnoId, bool $blocca = false): int
    {
        $blocco = $blocca ? ' FOR UPDATE' : '';

        return (int) $this->db->valore(
            "SELECT COALESCE(SUM(num_posti), 0) as totale FROM prenotazioni
                         WHERE turno_id = ?
                           AND IFNULL(stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')" . $blocco,
            [$turnoId]
        );
    }

    // ---- ricevuta e letture di un turno ----

    /** @return array<string, mixed>|null prenotazione con turno, evento, area e dati del portale per la ricevuta (per id se > 0, altrimenti per codice) */
    public function ricevuta(string $codice, int $id): ?array
    {
        $select = "SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine,
               e.titolo as evento_titolo, e.luogo as evento_luogo,
               pe.titolo as pagina_titolo, pe.colore_primario,
               cp.logo_path, cp.nome_portale, cp.sottotitolo_portale,
               COALESCE(NULLIF(pr.matricola,''), u.matricola_studente, u.matricola_dipendente, u.matricola, '') as matricola_effettiva
            FROM prenotazioni pr
            JOIN turni t ON pr.turno_id = t.id
            JOIN eventi e ON t.evento_id = e.id
            LEFT JOIN pagine_eventi pe ON e.pagina_id = pe.id
            LEFT JOIN utenti u ON pr.utente_id = u.id
            JOIN configurazione_portale cp ON cp.id = 1
            WHERE ";
        if ($id > 0) {
            return $this->db->riga($select . 'pr.id = ? LIMIT 1', [$id]);
        }

        return $this->db->riga($select . 'pr.codice_prenotazione = ? LIMIT 1', [$codice]);
    }

    /** @return array<string, mixed>|null il turno con titolo, descrizione e luogo dell'evento */
    public function turnoConEvento(int $turnoId): ?array
    {
        return $this->db->riga('SELECT t.*, e.titolo as evento_titolo, e.descrizione, e.luogo FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = ? LIMIT 1', [$turnoId]);
    }

    // ---- vincolo di iscrizione per area ----

    /**
     * La prenotazione attiva (non in lista d'attesa) che blocca una nuova iscrizione nell'ambito $condizioneAmbito
     * (condizione SQL con alias t ed e: la costruisce ServizioVincoli::condizioneAmbito, senza input dell'utente).
     *
     * @return array<string, mixed>|null
     */
    public function iscrizioneVincolata(string $condizioneAmbito, string $email, int $utenteId, string $matricola): ?array
    {
        return $this->db->riga(
            "SELECT pr.id, pr.codice_prenotazione, e.titolo AS evento_titolo
             FROM prenotazioni pr
             JOIN turni t  ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE $condizioneAmbito
               AND pr.stato NOT IN ('annullata', 'rifiutata', 'scaduta', 'in_attesa', 'richiesta_conferma')
               AND (LOWER(pr.email) = ? OR (? > 0 AND pr.utente_id = ?) OR (? != '' AND pr.matricola = ?))
             LIMIT 1",
            [strtolower($email), $utenteId, $utenteId, $matricola, $matricola]
        );
    }

    /** @return list<array<string, mixed>> (evento_id, turno_id, stato) delle prenotazioni attive dell'utente nell'area */
    public function iscrizioniAttiveNellArea(int $paginaId, int $utenteId, string $email): array
    {
        return $this->db->righe(
            "SELECT t.evento_id, pr.turno_id, pr.stato
             FROM prenotazioni pr
             JOIN turni t  ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE e.pagina_id = ?
               AND pr.stato NOT IN ('annullata', 'rifiutata', 'scaduta')
               AND (pr.utente_id = ? OR (? != '' AND LOWER(pr.email) = LOWER(?)))",
            [$paginaId, $utenteId, $email, $email]
        );
    }

    /** @return array<string, string|null>|null la prenotazione con evento, area e limite iscrizioni (per decadi_attese_vincolate) */
    public function perVincolo(int $prenotazioneId): ?array
    {
        return Righe::riga($this->db->riga(
            "SELECT pr.stato, pr.turno_id, pr.email, pr.utente_id, pr.matricola, pr.nome, t.evento_id, e.pagina_id,
                    e.titolo AS evento_titolo, pe.limite_iscrizioni
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id
             WHERE pr.id = ? LIMIT 1",
            [$prenotazioneId]
        ));
    }

    /** @return list<array<string, mixed>> richieste in sospeso della stessa persona nell'ambito (attese, posti offerti, da approvare) */
    public function richiesteInSospeso(string $condizioneAmbito, int $prenotazioneId, string $email, int $utenteId, string $matricola): array
    {
        return $this->db->righe(
            "SELECT pr.id, pr.turno_id, pr.stato, e.titolo AS evento_titolo, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine
             FROM prenotazioni pr
             JOIN turni t  ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE $condizioneAmbito
               AND pr.id != ?
               AND pr.stato IN ('in_attesa', 'richiesta_conferma', 'da_approvare')
               AND (LOWER(pr.email) = ? OR (? > 0 AND pr.utente_id = ?) OR (? != '' AND pr.matricola = ?))",
            [$prenotazioneId, $email, $utenteId, $utenteId, $matricola, $matricola]
        );
    }

    /** Annulla la richiesta solo se è ancora nello stato letto (non annulla una riga cambiata nel frattempo): true se l'ha annullata. */
    public function annullaSeNelloStato(int $prenotazioneId, string $stato): bool
    {
        return $this->db->esegui("UPDATE prenotazioni SET stato = 'annullata' WHERE id = ? AND stato = ?", [$prenotazioneId, $stato]) === 1;
    }

    // ---- prenotazione pubblica ----

    /** @return array<string, mixed>|null il turno se appartiene all'area e l'evento non è archiviato (id e tipo dell'evento) */
    public function eventoDelTurnoNellArea(int $turnoId, int $paginaId): ?array
    {
        return Righe::riga($this->db->riga(
            'SELECT e.id, e.tipo FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = ? AND e.pagina_id = ? AND e.archiviato = 0 LIMIT 1',
            [$turnoId, $paginaId]
        ));
    }

    /** Esiste già una prenotazione sul turno con la stessa email o la stessa matricola. */
    public function esisteDuplicata(int $turnoId, string $email, string $matricola): bool
    {
        return $this->db->righe(
            "SELECT id FROM prenotazioni WHERE turno_id = ? AND (email = ? OR (matricola != '' AND matricola = ?))",
            [$turnoId, $email, $matricola]
        ) !== [];
    }

    /** @return array<string, mixed>|null il turno con i dati dell'evento che servono a prenotare */
    public function turnoPerPrenotare(int $turnoId): ?array
    {
        return $this->db->riga(
            'SELECT t.*, e.titolo as evento_titolo, e.luogo, e.pagina_id, e.ruolo_accesso_id, e.richiede_prenotazione, e.tipo AS evento_tipo FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = ? LIMIT 1',
            [$turnoId]
        );
    }

    /** Progetti: la persona (utente o email) partecipa già a un'altra edizione dello stesso progetto, anche in lista d'attesa. */
    /** Il progetto consente di prenotare più edizioni (progetti_dettagli.piu_edizioni). */
    public function piuEdizioniConsentite(int $eventoId): bool
    {
        return (int) ($this->db->valore('SELECT piu_edizioni FROM progetti_dettagli WHERE evento_id = ?', [$eventoId]) ?? 0) === 1;
    }

    public function partecipaAdAltraEdizione(int $eventoId, int $turnoId, int $utenteId, string $email): bool
    {
        return $this->db->righe(
            "SELECT 1 FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id
                                       WHERE t.evento_id = ? AND pr.turno_id <> ? AND IFNULL(pr.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')
                                         AND ((? > 0 AND pr.utente_id = ?) OR LOWER(pr.email) = ?) LIMIT 1",
            [$eventoId, $turnoId, $utenteId, $utenteId, $email]
        ) !== [];
    }

    /** Blocco per persona e area (GET_LOCK, attesa massima 10 secondi): true se ottenuto. Si rilascia con rilasciaBlocco() o alla chiusura della connessione. */
    public function ottieniBlocco(string $nome): bool
    {
        return (int) ($this->db->valore('SELECT GET_LOCK(?, 10)', [$nome]) ?? 0) === 1;
    }

    public function rilasciaBlocco(string $nome): void
    {
        $this->db->valore('SELECT RELEASE_LOCK(?)', [$nome]);
    }

    /**
     * Inserisce la prenotazione; ritorna l'id della riga creata, 0 se l'inserimento non riesce.
     */
    public function inserisci(int $turnoId, ?int $utenteId, string $codice, string $stato, int $numPosti, string $nome, string $cognome, string $email, string $matricola, ?string $datiCustomJson): int
    {
        return $this->db->inserisci(
            'INSERT INTO prenotazioni (turno_id, utente_id, codice_prenotazione, stato, num_posti, nome, cognome, email, matricola, dati_custom_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$turnoId, $utenteId, $codice, $stato, $numPosti, $nome, $cognome, $email, $matricola, $datiCustomJson]
        );
    }

    public function impostaConvenzione(int $prenotazioneId, string $convenzione): void
    {
        $this->db->esegui('UPDATE prenotazioni SET convenzione = ? WHERE id = ?', [$convenzione, $prenotazioneId]);
    }

    public function impostaScuolaPrenotazione(int $prenotazioneId, ?string $codiceScuola): void
    {
        $this->db->esegui('UPDATE prenotazioni SET scuola_codice = ? WHERE id = ?', [$codiceScuola, $prenotazioneId]);
    }

    /** La scuola scelta diventa la proposta della prossima prenotazione del docente (profilo utente). */
    public function impostaScuolaUtente(int $utenteId, ?string $codiceScuola): void
    {
        $this->db->esegui('UPDATE utenti SET scuola_codice = ? WHERE id = ?', [$codiceScuola, $utenteId]);
    }

    // ---- check-in ----

    /** @return array<string, mixed>|null il turno del QR (id e token) con titolo e luogo dell'evento */
    public function turnoPerCheckin(int $turnoId, string $token): ?array
    {
        return $this->db->riga(
            'SELECT t.*, e.titolo as evento_titolo, e.luogo FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = ? AND t.token_checkin = ? LIMIT 1',
            [$turnoId, $token]
        );
    }

    /** @return array<string, mixed>|null la prenotazione dell'utente sul turno (id, stato, presente) */
    public function dellUtenteSulTurno(int $turnoId, int $utenteId): ?array
    {
        return $this->db->riga('SELECT id, stato, presente FROM prenotazioni WHERE turno_id = ? AND utente_id = ? LIMIT 1', [$turnoId, $utenteId]);
    }

    public function registraPresenza(int $prenotazioneId): void
    {
        $this->db->esegui('UPDATE prenotazioni SET presente = 1, data_presenza = NOW() WHERE id = ?', [$prenotazioneId]);
    }
}
