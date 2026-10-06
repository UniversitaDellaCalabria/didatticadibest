<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Database;
use App\Eventi\Righe;

/** Query del pannello Formazione Scuola Lavoro (admin/fsl.php): riepilogo dell'anno scolastico, valutazioni, registro, iscrizioni da stipulare. */
final class PannelloRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Iscrizioni alle attività FSL che iniziano nell'anno scolastico (esclusi annullamenti, rifiuti, scadute).
     *
     * @return list<array<string, mixed>>
     */
    public function iscrizioniAnno(string $dal, string $al): array
    {
        return $this->db->righe(
            "SELECT pr.id, pr.stato, pr.presente, pr.scuola_codice, pr.convenzione, pr.dati_custom_json, pr.nome, pr.cognome, pr.email, pr.codice_prenotazione,
                    t.data_turno, t.nome_turno, e.id AS evento_id, e.titolo, e.tipo, pe.titolo AS area, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine,
                    (SELECT COUNT(*) FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id) AS n_elenco,
                    v.media AS val_media, v.id AS val_id, pr.valutazione_inviata
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id JOIN pagine_eventi pe ON e.pagina_id = pe.id
             JOIN progetti_dettagli pd ON pd.evento_id = e.id LEFT JOIN valutazioni_fsl v ON v.prenotazione_id = pr.id
             WHERE pd.convenzione = 1 AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'da_approvare', 'in_attesa', 'richiesta_conferma')
               AND COALESCE(IF(e.tipo = 'progetto', pd.data_inizio, t.data_turno), t.data_turno, pd.data_fine, DATE(pr.data_prenotazione)) BETWEEN ? AND ?
             ORDER BY e.titolo, t.data_turno",
            [$dal, $al]
        );
    }

    /**
     * Schede di valutazione dell'anno scolastico, dalla più recente.
     *
     * @return list<array<string, mixed>>
     */
    public function valutazioniAnno(string $dal, string $al): array
    {
        return $this->db->righe(
            "SELECT v.*, e.titolo, pr.codice_prenotazione FROM valutazioni_fsl v JOIN eventi e ON e.id = v.evento_id JOIN prenotazioni pr ON pr.id = v.prenotazione_id
             JOIN turni t ON pr.turno_id = t.id LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE COALESCE(IF(e.tipo = 'progetto', pd.data_inizio, t.data_turno), t.data_turno, pd.data_fine, DATE(v.created_at)) BETWEEN ? AND ? ORDER BY v.created_at DESC",
            [$dal, $al]
        );
    }

    /**
     * Registro delle convenzioni con il numero di iscrizioni della scuola (valori come testo, come li dava $conn->query()).
     *
     * @return list<array<string, string|null>>
     */
    public function registro(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT c.*, (SELECT COUNT(*) FROM prenotazioni p WHERE p.scuola_codice = c.scuola_codice AND IFNULL(p.stato, 'confermata') NOT IN ('annullata', 'rifiutata', 'scaduta')) AS n_iscr
             FROM convenzioni_scuole c ORDER BY c.scuola_codice, (c.scadenza IS NULL) DESC, c.scadenza DESC, c.id DESC"
        ));
    }

    /**
     * Iscrizioni non concluse da stipulare: segnate «da stipulare» (o dichiarate ma senza registro) e scuole scritte a mano nelle attività FSL.
     *
     * @return list<array<string, string|null>>
     */
    public function iscrizioniDaStipulare(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT pr.id, pr.scuola_codice, pr.nome, pr.cognome, pr.email, pr.codice_prenotazione, pr.stato, pr.dati_custom_json,
                    t.data_turno, pd.data_inizio AS pd_inizio, pd.data_fine AS pd_fine, e.titolo, e.pagina_id, pe.titolo AS area
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id JOIN pagine_eventi pe ON e.pagina_id = pe.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE e.archiviato = 0 AND IFNULL(pr.stato, 'confermata') IN ('confermata', 'in_attesa', 'da_approvare', 'richiesta_conferma')
               AND COALESCE(pd.data_fine, t.data_turno, CURDATE()) >= CURDATE()
               AND (pr.convenzione = 'no' OR (pd.convenzione = 1 AND pr.scuola_codice IS NULL AND IFNULL(pr.convenzione, '') <> 'ricevuta'))
             ORDER BY COALESCE(pd.data_inizio, t.data_turno), pr.data_prenotazione"
        ));
    }
}
