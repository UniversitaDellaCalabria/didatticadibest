<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Core\Database;
use App\Eventi\Righe;

/** Query dello scanner del check-in: turni con presenze attive, iscritti confermati, ricerca del biglietto. */
final class ScannerRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Turni con check-in attivo dell'area, visibili al gestore ($rbac = AND e.id IN …), per id.
     *
     * @return array<int, array<string, string|null>>
     */
    public function turni(int $paginaId, string $rbac): array
    {
        $out = [];
        foreach (Righe::testo($this->db->righe(
            "SELECT t.id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo AS evento_titolo
             FROM turni t JOIN eventi e ON t.evento_id = e.id
             WHERE e.pagina_id = ? AND e.archiviato = 0 AND IFNULL(e.abilita_presenze, 1) = 1 $rbac
             ORDER BY (t.data_turno IS NULL), t.data_turno ASC, t.orario_inizio ASC, e.titolo ASC, t.id ASC",
            [$paginaId]
        )) as $r) {
            $out[(int) $r['id']] = $r;
        }

        return $out;
    }

    /**
     * Prenotazioni confermate del turno, in ordine alfabetico.
     *
     * @return list<array<string, string|null>>
     */
    public function confermatiDelTurno(int $turnoId): array
    {
        return Righe::testo($this->db->righe(
            "SELECT id, nome, cognome, codice_prenotazione, num_posti, presente, data_presenza
             FROM prenotazioni WHERE turno_id = ? AND IFNULL(stato, 'confermata') = 'confermata'
             ORDER BY cognome ASC, nome ASC, id ASC",
            [$turnoId]
        ));
    }

    /**
     * Biglietto per codice (maiuscolo) con turno, evento e area.
     *
     * @return array<string, mixed>|null
     */
    public function perCodice(string $codiceMaiuscolo): ?array
    {
        return $this->db->riga(
            'SELECT pr.*, t.id AS turno_id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo AS evento_titolo, e.pagina_id
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             WHERE UPPER(pr.codice_prenotazione) = ? LIMIT 1',
            [$codiceMaiuscolo]
        );
    }

    /**
     * Biglietto per id della prenotazione, con turno, evento e area.
     *
     * @return array<string, mixed>|null
     */
    public function perId(int $prenotazioneId): ?array
    {
        return $this->db->riga(
            'SELECT pr.*, t.id AS turno_id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo AS evento_titolo, e.pagina_id
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             WHERE pr.id = ? LIMIT 1',
            [$prenotazioneId]
        );
    }
}
