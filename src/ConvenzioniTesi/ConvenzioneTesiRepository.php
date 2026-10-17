<?php

declare(strict_types=1);

namespace App\ConvenzioniTesi;

use App\Core\Database;
use App\Eventi\Righe;

/** Convenzioni per la tesi con enti e aziende (tabella convenzioni_tesi): solo lettura e scrittura dei dati, nessuna regola. */
final class ConvenzioneTesiRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, string|null>|null */
    public function perId(int $id): ?array
    {
        return Righe::riga($this->db->riga('SELECT * FROM convenzioni_tesi WHERE id = ?', [$id]));
    }

    /** @return array<string, string|null>|null */
    public function perToken(string $token): ?array
    {
        return $token === '' ? null : Righe::riga($this->db->riga('SELECT * FROM convenzioni_tesi WHERE token = ?', [$token]));
    }

    /** @return array<string, string|null>|null */
    public function perTokenDirettore(string $token): ?array
    {
        return $token === '' ? null : Righe::riga($this->db->riga('SELECT * FROM convenzioni_tesi WHERE token_direttore = ?', [$token]));
    }

    /** Una convenzione ancora aperta (non stipulata) per la stessa email: serve a non aprirne due quando l'azienda richiede più volte il link. @return array<string, string|null>|null */
    public function apertaPerEmail(string $email): ?array
    {
        return Righe::riga($this->db->riga("SELECT * FROM convenzioni_tesi WHERE LOWER(email) = ? AND stato <> ? ORDER BY id DESC LIMIT 1", [strtolower(trim($email)), Costanti::PROTOCOLLATA]));
    }

    public function crea(string $email, string $azienda, string $origine, string $invitataDa): int
    {
        return $this->db->inserisci(
            'INSERT INTO convenzioni_tesi (token, stato, origine, azienda, email, invitata_da, aggiornata_il) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [bin2hex(random_bytes(20)), Costanti::INVITATA, $origine, mb_substr($azienda, 0, 200), mb_substr(strtolower(trim($email)), 0, 255), mb_substr($invitataDa, 0, 150)]
        );
    }

    /** I dati dell'azienda (compilazione o aggiornamento). */
    public function salvaDati(int $id, string $azienda, string $email, string $datiJson, bool $consensoElenco, string $nota): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET azienda = ?, email = ?, dati_json = ?, consenso_elenco = ?, nota_azienda = ?, aggiornata_il = NOW() WHERE id = ?',
            [mb_substr($azienda, 0, 200), mb_substr(strtolower(trim($email)), 0, 255), $datiJson, $consensoElenco ? 1 : 0, mb_substr($nota, 0, 1000), $id]);
    }

    public function impostaStato(int $id, string $stato): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET stato = ?, aggiornata_il = NOW() WHERE id = ?', [$stato, $id]);
    }

    public function salvaModifiche(int $id, string $json, string $stato): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET modifiche_json = ?, stato = ?, aggiornata_il = NOW() WHERE id = ?', [$json, $stato, $id]);
    }

    public function salvaFirmata(int $id, string $percorso, string $firmatario): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET file_firmato = ?, firmatario = ?, firmata_il = NOW(), stato = ?, nota_ufficio = \'\', aggiornata_il = NOW() WHERE id = ?',
            [$percorso, mb_substr($firmatario, 0, 255), Costanti::CARICATA, $id]);
    }

    public function protocolloIngresso(int $id, string $numero, ?string $data, string $autore): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET protocollo_ingresso = ?, protocollo_ingresso_data = ?, in_carico_il = NOW(), in_carico_da = ?, stato = ?, aggiornata_il = NOW() WHERE id = ?',
            [$numero, $data, mb_substr($autore, 0, 255), Costanti::IN_CARICO, $id]);
    }

    public function daFirmare(int $id, string $token): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET token_direttore = ?, da_firmare_il = NOW(), stato = ?, aggiornata_il = NOW() WHERE id = ?', [$token, Costanti::DA_FIRMARE, $id]);
    }

    public function segnaFirmataDirettore(int $id, string $percorso, string $chi): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET file_definitivo = ?, firmatario_direttore = ?, firmata_direttore_il = NOW(), stato = ?, aggiornata_il = NOW() WHERE id = ?',
            [$percorso, mb_substr($chi, 0, 255), Costanti::FIRMATA, $id]);
    }

    public function rifiuta(int $id, string $motivo): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET stato = ?, nota_ufficio = ?, token_direttore = NULL, da_firmare_il = NULL, aggiornata_il = NOW() WHERE id = ?',
            [Costanti::RIFIUTATA, mb_substr($motivo, 0, 500), $id]);
    }

    public function segnaProtocollata(int $id, string $protocollo, ?string $data, string $dal, string $al, ?string $fileProtocollato): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET stato = ?, protocollo = ?, protocollo_data = ?, valida_dal = ?, valida_al = ?, file_protocollato = COALESCE(?, file_protocollato), protocollata_il = NOW(), avviso_scadenza = 0, aggiornata_il = NOW() WHERE id = ?',
            [Costanti::PROTOCOLLATA, $protocollo, $data, $dal, $al, $fileProtocollato, $id]);
    }

    /** Una convenzione già in essere inserita a mano nell'archivio (senza passare dall'iter). */
    public function creaDaArchivio(string $azienda, string $email, string $datiJson, bool $consenso, string $protocollo, ?string $dataProt, string $dal, string $al, ?string $file, string $autore): int
    {
        return $this->db->inserisci(
            'INSERT INTO convenzioni_tesi (token, stato, origine, azienda, email, dati_json, consenso_elenco, protocollo, protocollo_data, valida_dal, valida_al, file_protocollato, protocollata_il, invitata_da, aggiornata_il)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())',
            [bin2hex(random_bytes(20)), Costanti::PROTOCOLLATA, Costanti::ORIGINE_ARCHIVIO, mb_substr($azienda, 0, 200), mb_substr(strtolower(trim($email)), 0, 255), $datiJson, $consenso ? 1 : 0,
                $protocollo, $dataProt, $dal, $al, $file, mb_substr($autore, 0, 150)]
        );
    }

    public function aggiornaArchivio(int $id, string $azienda, string $datiJson, bool $consenso, string $protocollo, ?string $dataProt, string $dal, string $al): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET azienda = ?, dati_json = ?, consenso_elenco = ?, protocollo = ?, protocollo_data = ?, valida_dal = ?, valida_al = ?, avviso_scadenza = 0, aggiornata_il = NOW() WHERE id = ?',
            [mb_substr($azienda, 0, 200), $datiJson, $consenso ? 1 : 0, $protocollo, $dataProt, $dal, $al, $id]);
    }

    public function impostaFileProtocollato(int $id, string $percorso): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET file_protocollato = ?, aggiornata_il = NOW() WHERE id = ?', [$percorso, $id]);
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM convenzioni_tesi WHERE id = ?', [$id]);
    }

    /**
     * Le convenzioni in uno degli stati indicati, dalla più recente.
     *
     * @param list<string> $stati
     * @return list<array<string, string|null>>
     */
    public function inStati(array $stati): array
    {
        if (!$stati) {
            return [];
        }

        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_tesi WHERE stato IN (' . implode(',', array_fill(0, count($stati), '?')) . ') ORDER BY aggiornata_il DESC, id DESC', array_values($stati)));
    }

    /** @return array<string, int> quante convenzioni per stato */
    public function conteggi(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT stato, COUNT(*) n FROM convenzioni_tesi GROUP BY stato') as $r) {
            $out[(string) $r['stato']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * L'archivio: le convenzioni stipulate, con cerca nel nome e filtro per validità.
     *
     * @return list<array<string, string|null>>
     */
    public function archivio(string $cerca, string $oggi, string $vista = 'tutte'): array
    {
        $where = ['stato = ?'];
        $par = [Costanti::PROTOCOLLATA];
        if (trim($cerca) !== '') {
            $where[] = '(azienda LIKE ? OR protocollo LIKE ? OR dati_json LIKE ?)';
            $like = '%' . trim($cerca) . '%';
            array_push($par, $like, $like, $like);
        }
        if ($vista === 'valide') {
            $where[] = '(valida_al IS NULL OR valida_al >= ?)';
            $par[] = $oggi;
        } elseif ($vista === 'scadute') {
            $where[] = 'valida_al < ?';
            $par[] = $oggi;
        }

        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_tesi WHERE ' . implode(' AND ', $where) . ' ORDER BY azienda, valida_al DESC', $par));
    }

    /**
     * L'elenco pubblico: stipulate, valide oggi e con il consenso dell'azienda a comparire.
     *
     * @return list<array<string, string|null>>
     */
    public function elencoPubblico(string $oggi): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_tesi WHERE stato = ? AND consenso_elenco = 1 AND valida_al >= ? ORDER BY azienda', [Costanti::PROTOCOLLATA, $oggi]));
    }

    /**
     * Convenzioni valide che scadono entro i giorni indicati e di cui non si è ancora avvisato.
     *
     * @return list<array<string, string|null>>
     */
    public function inScadenza(string $oggi, string $entro): array
    {
        return Righe::testo($this->db->righe('SELECT * FROM convenzioni_tesi WHERE stato = ? AND avviso_scadenza = 0 AND valida_al >= ? AND valida_al <= ? ORDER BY valida_al', [Costanti::PROTOCOLLATA, $oggi, $entro]));
    }

    public function segnaAvvisoScadenza(int $id): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET avviso_scadenza = 1 WHERE id = ?', [$id]);
    }

    /**
     * Compilazioni ferme da tempo che aspettano l'azienda (per il promemoria), non ricordate negli ultimi giorni e create dopo la data indicata (oltre non si insiste).
     *
     * @param list<string> $stati
     * @return list<array<string, string|null>>
     */
    public function ferme(array $stati, string $prima, string $promemoriaPrima, string $creataDopo): array
    {
        if (!$stati) {
            return [];
        }

        return Righe::testo($this->db->righe(
            'SELECT * FROM convenzioni_tesi WHERE stato IN (' . implode(',', array_fill(0, count($stati), '?')) . ') AND aggiornata_il <= ? AND (promemoria_il IS NULL OR promemoria_il <= ?) AND creata_il >= ? ORDER BY id',
            array_merge(array_values($stati), [$prima, $promemoriaPrima, $creataDopo])
        ));
    }

    public function segnaPromemoria(int $id): void
    {
        $this->db->esegui('UPDATE convenzioni_tesi SET promemoria_il = NOW() WHERE id = ?', [$id]);
    }
}
