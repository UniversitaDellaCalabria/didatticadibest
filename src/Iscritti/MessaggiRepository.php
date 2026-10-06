<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Core\Database;
use App\Eventi\Righe;

/** Query dei messaggi tra iscritti e segreteria (tabella messaggi_prenotazioni): inbox, chat, lettura. */
final class MessaggiRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Conversazioni dell'area, con prima quelle da leggere, come get_inbox_conversazioni(). $rbac = filtro dei permessi (AND e.id IN …).
     *
     * @return list<array<string, string|null>>
     */
    public function conversazioni(int $paginaId, string $rbac = ''): array
    {
        return Righe::testo($this->db->righe(
            "SELECT p.id as prenotazione_id, p.codice_prenotazione, p.nome, p.cognome, p.email,
               e.titolo as evento_titolo, e.pagina_id,
               MAX(m.data_invio) as ultimo_messaggio_data,
               COUNT(m.id) as totale_messaggi,
               SUM(CASE WHEN m.letto = 0 AND m.mittente_tipo = 'utente' THEN 1 ELSE 0 END) as messaggi_da_leggere
             FROM messaggi_prenotazioni m
             JOIN prenotazioni p ON m.prenotazione_id = p.id
             JOIN turni t ON p.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             WHERE e.pagina_id = ? $rbac
             GROUP BY p.id
             ORDER BY messaggi_da_leggere DESC, ultimo_messaggio_data DESC",
            [$paginaId]
        ));
    }

    /**
     * Messaggi di più prenotazioni, raggruppati per id della prenotazione, come get_messaggi_per_prenotazioni().
     *
     * @param list<int|string> $prenotazioniIds
     * @return array<int, list<array<string, string|null>>>
     */
    public function perPrenotazioni(array $prenotazioniIds): array
    {
        if (empty($prenotazioniIds)) {
            return [];
        }
        $ids = array_map('intval', $prenotazioniIds);
        $segnaposto = implode(',', array_fill(0, count($ids), '?'));
        $messaggi = [];
        foreach (Righe::testo($this->db->righe("SELECT * FROM messaggi_prenotazioni WHERE prenotazione_id IN ($segnaposto) ORDER BY data_invio ASC", $ids)) as $m) {
            $messaggi[(int) $m['prenotazione_id']][] = $m;
        }

        return $messaggi;
    }

    /**
     * La chat di una prenotazione, dal più vecchio.
     *
     * @return list<array<string, string|null>>
     */
    public function chat(int $prenotazioneId): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM messaggi_prenotazioni WHERE prenotazione_id = ? ORDER BY data_invio ASC', [$prenotazioneId]));
    }

    /** Segna come letti i messaggi dell'utente di quella prenotazione. */
    public function segnaLettiDellUtente(int $prenotazioneId): void
    {
        $this->db->esegui("UPDATE messaggi_prenotazioni SET letto = 1 WHERE prenotazione_id = ? AND mittente_tipo = 'utente'", [$prenotazioneId]);
    }

    /** Salva la risposta della segreteria (dall'inbox è già letta). */
    public function inserisciDellAdmin(int $prenotazioneId, int $adminId, string $messaggio, bool $letto): void
    {
        if ($letto) {
            $this->db->esegui("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, mittente_id, messaggio, letto) VALUES (?, 'admin', ?, ?, 1)", [$prenotazioneId, $adminId, $messaggio]);

            return;
        }
        $this->db->esegui("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, mittente_id, messaggio) VALUES (?, 'admin', ?, ?)", [$prenotazioneId, $adminId, $messaggio]);
    }

    /** Nome e cognome dell'operatore che risponde (null se l'utente non esiste). */
    public function nomeOperatore(int $utenteId): ?string
    {
        $op = $this->db->riga('SELECT nome, cognome FROM utenti WHERE id = ? LIMIT 1', [$utenteId]);

        return $op ? trim($op['nome'] . ' ' . $op['cognome']) : null;
    }
}
