<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Database;
use App\Eventi\Righe;

/** Le prenotazioni viste dalla Formazione Scuola Lavoro: stato della convenzione, attività della scuola, invito alla valutazione. */
final class PrenotazioneFslRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Prenotazione con turno, evento, periodo dell'attività e impostazioni dell'area (modelli e PEC della convenzione).
     *
     * @return array<string, string|null>|null
     */
    public function dati(int $id): ?array
    {
        return Righe::riga($this->db->riga(
            "SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.richiede_approvazione, t.evento_id,
                    e.titolo AS evento_titolo, e.pagina_id, pe.conv_url_modello, pe.conv_url_allegato, pe.conv_pec,
                    pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine, IFNULL(pd.convenzione, 0) AS fsl
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             JOIN pagine_eventi pe ON e.pagina_id = pe.id LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE pr.id = ? LIMIT 1",
            [$id]
        ));
    }

    /**
     * Descrizione e tipo dell'evento (testi come li dava $conn->query()); [] se l'evento non c'è.
     *
     * @return array<string, string|null>
     */
    public function descrizioneEvento(int $eventoId): array
    {
        return Righe::riga($this->db->riga('SELECT descrizione, descrizione_breve, tipo FROM eventi WHERE id = ?', [$eventoId])) ?? [];
    }

    /**
     * Prenotazione (id, scuola, email) con il codice, se l'attività è FSL e la prenotazione non è annullata, rifiutata o scaduta.
     *
     * @return array<string, mixed>|null
     */
    public function perCodiceConvenzione(string $codice): ?array
    {
        return $this->db->riga(
            "SELECT pr.id, pr.scuola_codice, pr.email FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id
             WHERE pr.codice_prenotazione = ? AND pd.convenzione = 1 AND IFNULL(pr.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta') LIMIT 1",
            [$codice]
        );
    }

    /**
     * Prenotazioni della scuola in attesa della convenzione (o dichiarata) con il periodo dell'attività.
     *
     * @return list<array<string, mixed>>
     */
    public function inAttesaDellaScuola(string $codice): array
    {
        return $this->db->righe(
            "SELECT pr.id, t.data_turno, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id LEFT JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id
             WHERE pr.scuola_codice = ? AND pr.convenzione IN ('no', 'si')
               AND IFNULL(pr.stato, 'confermata') IN ('da_approvare', 'confermata', 'in_attesa', 'richiesta_conferma')",
            [$codice]
        );
    }

    public function segnaConvenzioneRicevuta(int $id): void
    {
        $this->db->esegui("UPDATE prenotazioni SET convenzione = 'ricevuta' WHERE id = ?", [$id]);
    }

    /** Da «da approvare» a «confermata» (solo se lo è ancora): true se la prenotazione è cambiata. */
    public function confermaSeDaApprovare(int $id): bool
    {
        return $this->db->esegui("UPDATE prenotazioni SET stato = 'confermata' WHERE id = ? AND stato = 'da_approvare'", [$id]) > 0;
    }

    /**
     * Iscrizioni da verificare con il registro: attività FSL non concluse e, in qualsiasi attività, quelle a cui è stata chiesta
     * la convenzione (es. da Iscrizioni in OpenLab).
     *
     * @return list<array<string, string|null>>
     */
    public function daVerificare(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT pr.id, pr.scuola_codice, pr.convenzione, t.data_turno, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine, IFNULL(pd.convenzione, 0) AS fsl
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE (pd.convenzione = 1 OR pr.convenzione IN ('no', 'si')) AND e.archiviato = 0
               AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'da_approvare', 'in_attesa', 'richiesta_conferma')
               AND COALESCE(pd.data_fine, t.data_turno, CURDATE()) >= CURDATE()"
        ));
    }

    /** «Da stipulare», senza promemoria automatici finché il gestore non invia la richiesta (conv_promemoria = 3). */
    public function segnaDaStipulare(int $id): void
    {
        $this->db->esegui("UPDATE prenotazioni SET convenzione = 'no', conv_promemoria = 3 WHERE id = ?", [$id]);
    }

    /**
     * Prenotazioni FSL di una scuola da mettere nella convenzione online: quella indicata e, se la scuola è dell'anagrafe,
     * tutte le sue prenotazioni FSL attive non concluse.
     *
     * @return list<int>
     */
    public function idPrenotateDallaScuola(int $prenotazioneId, string $codiceScuola): array
    {
        $anagrafe = (bool) preg_match('/^[A-Z0-9]{10}$/', $codiceScuola);
        $per = $anagrafe ? '(pr.id = ? OR pr.scuola_codice = ?)' : 'pr.id = ?';
        $parametri = $anagrafe ? [$prenotazioneId, $codiceScuola, $prenotazioneId] : [$prenotazioneId, $prenotazioneId];
        $righe = $this->db->righe(
            "SELECT pr.id FROM prenotazioni pr JOIN turni t ON t.id = pr.turno_id JOIN progetti_dettagli pd ON pd.evento_id = t.evento_id
             WHERE $per AND pd.convenzione = 1 AND IFNULL(pr.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')
               AND (pr.id = ? OR COALESCE(pd.data_fine, t.data_turno, CURDATE()) >= CURDATE() - INTERVAL 30 DAY) ORDER BY t.data_turno, pr.id",
            $parametri
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $righe);
    }

    /**
     * Attività FSL ancora prenotabili (con il primo turno futuro), per l'Allegato A.
     *
     * @return list<array<string, string|null>>
     */
    public function attivitaPrenotabili(int $limite = 60): array
    {
        return Righe::testo($this->db->righe(
            "SELECT e.id AS evento_id, e.titolo AS evento_titolo, e.tipo, e.pagina_id, pe.slug, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine,
                    MIN(t.data_turno) AS data_turno
             FROM eventi e JOIN progetti_dettagli pd ON pd.evento_id = e.id JOIN pagine_eventi pe ON pe.id = e.pagina_id LEFT JOIN turni t ON t.evento_id = e.id AND t.data_turno >= CURDATE()
             WHERE pd.convenzione = 1 AND IFNULL(pe.visibile, 1) = 1 AND (t.id IS NOT NULL OR IFNULL(pd.data_fine, CURDATE()) >= CURDATE())
             GROUP BY e.id ORDER BY MIN(t.data_turno), e.titolo LIMIT " . max(1, $limite)
        ));
    }

    /**
     * Prenotazione (con attività, periodo e scuola) dal link personale della scheda di valutazione.
     *
     * @return array<string, mixed>|null
     */
    public function perValutazione(string $token): ?array
    {
        return $this->db->riga(
            'SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.id AS evento_id, e.titolo AS evento_titolo, e.pagina_id,
                    pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine, v.id AS valutazione_id
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id LEFT JOIN valutazioni_fsl v ON v.prenotazione_id = pr.id
             WHERE pr.valutazione_token = ? LIMIT 1',
            [$token]
        );
    }

    public function impostaTokenValutazione(int $id, string $token): void
    {
        $this->db->esegui('UPDATE prenotazioni SET valutazione_token = ? WHERE id = ?', [$token, $id]);
    }

    /** L'invito (o il promemoria) alla scheda è partito. */
    public function segnaInvitoValutazione(int $id, bool $promemoria): void
    {
        $this->db->esegui('UPDATE prenotazioni SET ' . ($promemoria ? 'valutazione_promemoria = 1' : 'valutazione_inviata = NOW()') . ' WHERE id = ?', [$id]);
    }
}
