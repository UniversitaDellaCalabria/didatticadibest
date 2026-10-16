<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Database;
use App\Eventi\Righe;

/** Convenzioni e Allegati A compilati online dalle scuole (tabella convenzioni_compilate). */
final class ConvenzioneCompilataRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function perToken(string $token): ?array
    {
        return $this->db->riga('SELECT * FROM convenzioni_compilate WHERE token = ?', [$token]);
    }

    /**
     * L'ultima compilazione della prenotazione (una sola per prenotazione: se c'è già si riprende).
     *
     * @return array<string, string|null>|null
     */
    public function ultimaDellaPrenotazione(int $prenotazioneId): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM convenzioni_compilate WHERE prenotazione_id = ? ORDER BY id DESC LIMIT 1', [$prenotazioneId]));
    }

    /**
     * La compilazione che ha l'attività prenotata tra quelle dell'Allegato A (programma FSL: una compilazione per più prenotazioni).
     *
     * @return array<string, string|null>|null
     */
    public function cheContiene(int $prenotazioneId): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM convenzioni_compilate WHERE dati_json LIKE ? ORDER BY id DESC LIMIT 1', ['%"pr":' . $prenotazioneId . ',%']));
    }

    /**
     * Le compilazioni che riguardano le prenotazioni indicate (la prima prenotazione della compilazione oppure una di quelle dell'Allegato A), dalla più recente.
     *
     * @param list<int> $prenotazioniIds
     * @return list<array<string, string|null>>
     */
    public function perPrenotazioni(array $prenotazioniIds): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $prenotazioniIds)))), 0, 300);
        if (!$ids) {
            return [];
        }
        $where = ['prenotazione_id IN (' . implode(',', $ids) . ')'];
        $par = [];
        foreach ($ids as $id) {
            $where[] = 'dati_json LIKE ?';
            $par[] = '%"pr":' . $id . ',%';
        }

        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_compilate WHERE ' . implode(' OR ', $where) . ' ORDER BY id DESC LIMIT 50', $par));
    }

    /**
     * Apre la compilazione di una prenotazione con un link personale nuovo.
     *
     * @return array<string, string|null>|null la riga appena creata
     */
    public function crea(string $token, ?string $codiceScuola, int $prenotazioneId, string $email): ?array
    {
        $id = $this->db->inserisci('INSERT INTO convenzioni_compilate (token, scuola_codice, prenotazione_id, email) VALUES (?, ?, ?, ?)', [$token, $codiceScuola, $prenotazioneId, $email]);

        return Righe::riga($this->db->riga('SELECT * FROM convenzioni_compilate WHERE id = ?', [$id]));
    }

    public function salvaDati(int $id, string $datiJson, ?string $logo, string $email): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET dati_json = ?, logo = ?, email = ?, aggiornata_il = NOW() WHERE id = ?', [$datiJson, $logo, $email, $id]);
    }

    public function segnaScaricata(int $id): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET scaricata_il = NOW() WHERE id = ?', [$id]);
    }

    /** Protocollo assegnato dal Dipartimento: compare nei documenti Word generati. */
    public function salvaProtocollo(int $id, string $protocollo, ?string $data): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET protocollo = ?, protocollo_data = ? WHERE id = ?', [$protocollo, $data, $id]);
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM convenzioni_compilate WHERE id = ?', [$id]);
    }

    /** Convenzione firmata caricata dalla scuola (sostituisce la precedente): file, chi ha firmato e iter «caricata dalla scuola». */
    public function salvaFirmata(int $id, string $file, string $firmatario, ?string $allegato = null): void
    {
        // un nuovo caricamento riparte da capo: niente presa in carico, motivo di rifiuto o protocollo in entrata del file precedente; l'Allegato A resta se non se ne carica un altro
        $this->db->esegui("UPDATE convenzioni_compilate SET file_firmato = ?, firmatario = ?, firmata_il = NOW(), stato_iter = ?, in_carico_il = NULL, in_carico_da = '', nota_ufficio = '', protocollo_ingresso = '', protocollo_ingresso_data = NULL, file_allegato_scuola = COALESCE(?, file_allegato_scuola) WHERE id = ?", [$file, $firmatario, Costanti::ITER_CARICATA, $allegato, $id]);
    }

    /** L'ufficio ha registrato il protocollo in entrata della convenzione caricata dalla scuola: è presa in carico. */
    public function protocolloIngresso(int $id, string $numero, ?string $data, string $da): void
    {
        $this->db->esegui("UPDATE convenzioni_compilate SET stato_iter = ?, in_carico_il = NOW(), in_carico_da = ?, nota_ufficio = '', protocollo_ingresso = ?, protocollo_ingresso_data = ? WHERE id = ?", [Costanti::ITER_IN_CARICO, mb_substr($da, 0, 255), $numero, $data, $id]);
    }

    /** L'ufficio rifiuta il file con un motivo: la scuola potrà caricarne un altro. */
    public function rifiuta(int $id, string $nota): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET stato_iter = ?, nota_ufficio = ? WHERE id = ?', [Costanti::ITER_RIFIUTATA, mb_substr($nota, 0, 500), $id]);
    }

    /** @return array<string, mixed>|null */
    public function perTokenDirettore(string $token): ?array
    {
        return $token === '' ? null : $this->db->riga('SELECT * FROM convenzioni_compilate WHERE token_direttore = ?', [$token]);
    }

    /** Mandata al Direttore per la firma, con il suo link personale. */
    public function daFirmare(int $id, string $token): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET stato_iter = ?, token_direttore = ?, da_firmare_il = NOW() WHERE id = ?', [Costanti::ITER_DA_FIRMARE, $token, $id]);
    }

    /** Firma PAdES del Direttore registrata: il PDF firmato è pronto per il protocollo. */
    public function segnaFirmataDirettore(int $id, string $file, string $firmatario): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET stato_iter = ?, file_definitivo = ?, firmatario_direttore = ?, firmata_direttore_il = NOW() WHERE id = ?',
            [Costanti::ITER_FIRMATA, $file, mb_substr($firmatario, 0, 255), $id]);
    }
    /** Convenzione firmata dal Direttore, protocollata e registrata (riga di convenzioni_scuole): iter concluso. */
    public function segnaProtocollata(int $id, int $convenzioneId, string $protocollo, ?string $protocolloData): void
    {
        $this->db->esegui('UPDATE convenzioni_compilate SET stato_iter = ?, convenzione_id = ?, protocollo = ?, protocollo_data = ?, protocollata_il = NOW() WHERE id = ?',
            [Costanti::ITER_PROTOCOLLATA, $convenzioneId, $protocollo, $protocolloData, $id]);
    }

    /**
     * Email delle persone che ricevono l'avviso della convenzione caricata: amministratori, utenti abilitati a tutta la FSL o alle sole convenzioni e persone dell'«Ufficio convenzioni FSL».
     *
     * @return list<string>
     */
    public function emailAbilitati(): array
    {
        $righe = $this->db->righe("SELECT DISTINCT u.email FROM utenti u
            WHERE u.email <> '' AND (u.ruolo_id = 1 OR u.id IN (SELECT utente_id FROM abilitazioni_ambito WHERE tipo IN ('fsl', 'fsl_convenzioni')))
            UNION SELECT p.email FROM uffici_persone p JOIN uffici f ON f.id = p.ufficio_id WHERE f.chiave = 'convenzioni_fsl' AND p.email <> ''");

        return array_values(array_filter(array_map(static fn (array $r): string => trim((string) $r['email']), $righe), static fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));
    }

    /**
     * Le compilazioni dell'ultimo anno con i dati inseriti (pannello FSL).
     *
     * @return list<array<string, string|null>>
     */
    public function recenti(int $limite = 50): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_compilate WHERE dati_json IS NOT NULL AND aggiornata_il >= NOW() - INTERVAL 1 YEAR ORDER BY aggiornata_il DESC LIMIT ' . max(1, $limite)));
    }
}
