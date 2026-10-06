<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Core\Database;
use App\Eventi\Righe;

/** Dati per le email a gestori, referenti e indirizzi aggiuntivi quando qualcuno prenota o annulla. */
final class NotificheRepository
{
    public function __construct(private Database $db)
    {
    }

    /** Indirizzi aggiuntivi dell'evento (testo libero, eventi.email_notifiche_extra). */
    public function emailExtraEvento(int $eventoId): string
    {
        $riga = $this->db->riga('SELECT email_notifiche_extra FROM eventi WHERE id = ? LIMIT 1', [$eventoId]);

        return $riga !== null ? (string) ($riga['email_notifiche_extra'] ?? '') : '';
    }

    /** Referenti del progetto (JSON di progetti_dettagli.referenti_json), '' se manca la scheda. */
    public function referentiJson(int $eventoId): string
    {
        $riga = $this->db->riga('SELECT referenti_json FROM progetti_dettagli WHERE evento_id = ? LIMIT 1', [$eventoId]);

        return $riga !== null ? (string) $riga['referenti_json'] : '';
    }

    /** @return array<string, string|null>|null la prenotazione con turno, evento e area */
    public function prenotazioneCompleta(int $prenotazioneId): ?array
    {
        return Righe::riga($this->db->riga(
            'SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine,
                                    e.id AS evento_id, e.titolo AS evento_titolo, e.luogo, e.pagina_id, pe.titolo AS area_titolo
                             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                             JOIN pagine_eventi pe ON e.pagina_id = pe.id WHERE pr.id = ? LIMIT 1',
            [$prenotazioneId]
        ));
    }

    /** @return array<string, string> nome_campo => etichetta dei campi del modulo dell'area o dell'evento, nell'ordine del form */
    public function etichetteCampi(int $paginaId, int $eventoId): array
    {
        $etichette = [];
        $righe = $this->db->righe(
            'SELECT nome_campo, etichetta FROM campi_form WHERE pagina_id = ? AND (evento_id IS NULL OR evento_id = ?) ORDER BY ordine ASC, id ASC',
            [$paginaId, $eventoId]
        );
        foreach ($righe as $cf) {
            $etichette[$cf['nome_campo']] = $cf['etichetta'];
        }

        return $etichette;
    }
}
