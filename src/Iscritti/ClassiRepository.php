<?php

declare(strict_types=1);

namespace App\Iscritti;

use App\Core\Database;
use App\Eventi\Righe;

/** Query delle iscrizioni di classe (progetti per le scuole, eventi con attestati): elenco delle classi e degli studenti inseriti. */
final class ClassiRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Iscrizioni di classe dell'area (progetti per le scuole ed eventi con attestati per la classe), con il numero di studenti in elenco.
     *
     * @param string $rbac filtro dei permessi del gestore (AND e.id IN …)
     * @return list<array<string, string|null>>
     */
    public function dellArea(int $paginaId, string $rbac): array
    {
        return Righe::testo($this->db->righe(
            "SELECT pr.id, pr.nome, pr.cognome, pr.email, pr.stato, pr.presente, pr.attestato_inviato, pr.dati_custom_json,
                    t.nome_turno, t.data_turno, e.id AS evento_id, e.titolo AS evento_titolo, e.tipo, d.data_fine, d.attestati,
                    (SELECT COUNT(*) FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id) AS n_studenti
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN progetti_dettagli d ON d.evento_id = e.id
             WHERE e.pagina_id = ? AND e.archiviato = 0 $rbac
               AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'richiesta_conferma', 'da_approvare')
               AND ((e.tipo = 'progetto' AND IFNULL(d.per_scuole, 1) = 1) OR (IFNULL(e.tipo, 'evento') <> 'progetto' AND d.attestati = 1))
             ORDER BY e.titolo ASC, t.id ASC, pr.id ASC",
            [$paginaId]
        ));
    }

    public function aggiungiStudente(int $prenotazioneId, string $cognome, string $nome, int $ordine): void
    {
        $this->db->esegui('INSERT INTO partecipanti_prenotazione (prenotazione_id, cognome, nome, ordine) VALUES (?, ?, ?, ?)', [$prenotazioneId, $cognome, $nome, $ordine]);
    }

    /** Studente escluso (assente: niente attestato) o riammesso. */
    public function impostaEscluso(int $studenteId, int $prenotazioneId, int $escluso): void
    {
        $this->db->esegui('UPDATE partecipanti_prenotazione SET escluso = ? WHERE id = ? AND prenotazione_id = ?', [$escluso, $studenteId, $prenotazioneId]);
    }

    public function eliminaStudente(int $studenteId, int $prenotazioneId): void
    {
        $this->db->esegui('DELETE FROM partecipanti_prenotazione WHERE id = ? AND prenotazione_id = ?', [$studenteId, $prenotazioneId]);
    }

    public function svuota(int $prenotazioneId): void
    {
        $this->db->esegui('DELETE FROM partecipanti_prenotazione WHERE prenotazione_id = ?', [$prenotazioneId]);
    }

    /** Segna presente la classe (l'ora resta quella già registrata, se c'è). */
    public function segnaPresente(int $prenotazioneId): void
    {
        $this->db->esegui('UPDATE prenotazioni SET presente = 1, data_presenza = COALESCE(data_presenza, NOW()) WHERE id = ?', [$prenotazioneId]);
    }
}
