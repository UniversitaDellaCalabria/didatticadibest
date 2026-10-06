<?php

declare(strict_types=1);

namespace App\Iscrizioni;

use App\Core\Database;
use App\Eventi\Righe;

/** Query delle liste d'attesa: coda di un turno, offerta del posto, scadenze. Gli orari arrivano dal chiamante (ora del portale). */
final class ListaAttesaRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, string|null>|null capienza e inizio del turno */
    public function turno(int $turnoId): ?array
    {
        return Righe::riga($this->db->riga('SELECT max_posti, data_turno, orario_inizio FROM turni WHERE id = ? LIMIT 1', [$turnoId]));
    }

    /** @return array<string, string|null>|null il primo in coda (per data di prenotazione, a parità per id) con il titolo dell'evento */
    public function primoInAttesa(int $turnoId): ?array
    {
        return Righe::riga($this->db->riga(
            "SELECT p.*, e.titolo as evento_titolo FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE p.turno_id = ? AND p.stato = 'in_attesa' ORDER BY p.data_prenotazione ASC, p.id ASC LIMIT 1",
            [$turnoId]
        ));
    }

    /** Offre il posto: stato «richiesta_conferma» con la scadenza per confermare. true se la riga è cambiata. */
    public function offriPosto(int $prenotazioneId, string $scadenza): bool
    {
        return $this->db->esegui("UPDATE prenotazioni SET stato = 'richiesta_conferma', scadenza_conferma = ? WHERE id = ?", [$scadenza, $prenotazioneId]) > 0;
    }

    /** @return list<array<string, string|null>> posti offerti la cui scadenza è passata */
    public function offerteScadute(string $adesso): array
    {
        return Righe::testo($this->db->righe("SELECT id, turno_id FROM prenotazioni WHERE stato = 'richiesta_conferma' AND scadenza_conferma < ?", [$adesso]));
    }

    public function segnaScaduta(int $prenotazioneId): void
    {
        $this->db->esegui("UPDATE prenotazioni SET stato = 'scaduta' WHERE id = ?", [$prenotazioneId]);
    }

    /** @return list<array<string, string|null>> turni che iniziano tra $adesso e $limite */
    public function turniInPartenza(string $adesso, string $limite): array
    {
        return Righe::testo($this->db->righe(
            "SELECT id FROM turni WHERE CONCAT(data_turno, ' ', orario_inizio) <= ? AND CONCAT(data_turno, ' ', orario_inizio) > ?",
            [$limite, $adesso]
        ));
    }

    /** Chiude definitivamente la coda del turno: attese e posti offerti diventano «scaduta». */
    public function chiudiCoda(int $turnoId): void
    {
        $this->db->esegui("UPDATE prenotazioni SET stato = 'scaduta' WHERE turno_id = ? AND stato IN ('in_attesa', 'richiesta_conferma')", [$turnoId]);
    }
}
