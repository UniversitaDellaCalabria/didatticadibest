<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Database;
use App\Eventi\Righe;

/** Autorizzazione della scuola (PDF) e promemoria sui documenti di una prenotazione FSL (colonne di prenotazioni). */
final class DocumentiClasseRepository
{
    public function __construct(private Database $db)
    {
    }

    /** Studenti in elenco (anche quelli esclusi dall'attestato: l'elenco serve a sapere chi partecipa). */
    public function contaStudenti(int $prenotazioneId): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) AS n FROM partecipanti_prenotazione WHERE prenotazione_id = ?', [$prenotazioneId]);
    }

    /**
     * @return array{file: string, nome: string}|null l'autorizzazione caricata, null se non c'è
     */
    public function autorizzazione(int $prenotazioneId): ?array
    {
        $r = $this->db->riga('SELECT autorizzazione_file, autorizzazione_nome FROM prenotazioni WHERE id = ?', [$prenotazioneId]);
        if (!$r || empty($r['autorizzazione_file'])) {
            return null;
        }

        return ['file' => (string) $r['autorizzazione_file'], 'nome' => (string) ($r['autorizzazione_nome'] ?? '')];
    }

    public function salvaAutorizzazione(int $prenotazioneId, string $file, string $nome): void
    {
        $this->db->esegui('UPDATE prenotazioni SET autorizzazione_file = ?, autorizzazione_nome = ?, autorizzazione_il = NOW() WHERE id = ?', [$file, mb_substr($nome, 0, 255), $prenotazioneId]);
    }

    public function togliAutorizzazione(int $prenotazioneId): void
    {
        $this->db->esegui('UPDATE prenotazioni SET autorizzazione_file = NULL, autorizzazione_nome = NULL, autorizzazione_il = NULL WHERE id = ?', [$prenotazioneId]);
    }

    /**
     * Prenotazioni FSL di classe, attive e con l'attività non ancora iniziata, a cui manca l'elenco o l'autorizzazione.
     * Il calendario degli avvisi lo decide DocumentiClasse::prossimoAvviso().
     *
     * @return list<array<string, string|null>>
     */
    public function incomplete(): array
    {
        $stati = "'" . implode("','", DocumentiClasse::STATI_ATTIVI) . "'";

        return Righe::testo($this->db->righe(
            "SELECT pr.id, pr.nome, pr.cognome, pr.email, pr.codice_prenotazione, pr.turno_id, pr.stato, pr.data_prenotazione, pr.autorizzazione_file,
                    pr.doc_promemoria, pr.doc_promemoria_il, pr.doc_ultimo_avviso, pr.num_posti,
                    t.nome_turno, t.data_turno, e.titolo, e.tipo AS evento_tipo, e.pagina_id, pd.data_inizio, pd.data_fine, pd.per_scuole, 1 AS fsl,
                    (SELECT COUNT(*) FROM partecipanti_prenotazione pp WHERE pp.prenotazione_id = pr.id) AS studenti
             FROM prenotazioni pr
             JOIN turni t ON pr.turno_id = t.id
             JOIN eventi e ON t.evento_id = e.id
             JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE e.archiviato = 0 AND pd.convenzione = 1 AND (IFNULL(e.tipo, 'evento') <> 'progetto' OR pd.per_scuole = 1)
               AND IFNULL(pr.stato, 'confermata') IN ($stati)
               AND COALESCE(t.data_turno, pd.data_inizio) >= CURDATE()
               AND (pr.autorizzazione_file IS NULL OR pr.autorizzazione_file = ''
                    OR NOT EXISTS (SELECT 1 FROM partecipanti_prenotazione pp2 WHERE pp2.prenotazione_id = pr.id))
             ORDER BY COALESCE(t.data_turno, pd.data_inizio), pr.id"
        ));
    }

    /**
     * La prenotazione con quel codice, con i dati che servono a sapere se vale per lei la consegna dei documenti.
     *
     * @return array<string, string|null>|null
     */
    public function perCodice(string $codice): ?array
    {
        return Righe::riga($this->db->riga(
            "SELECT pr.id, pr.stato, pr.data_prenotazione, pr.autorizzazione_file, t.data_turno, e.tipo AS evento_tipo, pd.data_inizio, pd.per_scuole, IFNULL(pd.convenzione, 0) AS fsl
             FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
             LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id
             WHERE pr.codice_prenotazione = ? LIMIT 1",
            [$codice]
        ));
    }

    public function contaPromemoria(int $prenotazioneId): void
    {
        $this->db->esegui('UPDATE prenotazioni SET doc_promemoria = doc_promemoria + 1, doc_promemoria_il = NOW() WHERE id = ?', [$prenotazioneId]);
    }

    public function segnaUltimoAvviso(int $prenotazioneId): void
    {
        $this->db->esegui('UPDATE prenotazioni SET doc_ultimo_avviso = 1, doc_promemoria_il = NOW() WHERE id = ?', [$prenotazioneId]);
    }
}
