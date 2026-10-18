<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Eventi\Righe;

/** Sedute dei consigli (didattica_sedute), le loro presenze (didattica_sedute_presenze) e le pratiche che portano. */
final class SedutaRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM didattica_sedute WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null la seduta il cui verbale ha questo link di firma (null se non c'è) */
    public function perTokenVerbale(string $token): ?array
    {
        $id = (int) $this->db->valore('SELECT id FROM didattica_sedute WHERE verbale_token = ?', [$token]);

        return $id ? $this->perId($id) : null;
    }

    /**
     * Sedute con il numero di pratiche, dalla più recente; per i referenti solo quelle dei loro consigli.
     *
     * @param list<int>|null $consigli null = tutte
     * @return list<array<string, string|null>>
     */
    public function elenco(?array $consigli = null): array
    {
        return Righe::testo($this->db->righe(
            'SELECT s.*, (SELECT COUNT(*) FROM pratiche p WHERE p.seduta_id = s.id) AS n_pratiche FROM didattica_sedute s'
            . " WHERE s.origine = 'portale'" . ($consigli !== null ? ' AND s.consiglio_id IN (' . implode(',', array_map('intval', $consigli ?: [0])) . ')' : '') . ' ORDER BY s.data DESC, s.id DESC'
        ));
    }

    /**
     * L'archivio dei verbali: sedute del portale e verbali vecchi importati, dal più recente. Filtri: consiglio, anno_accademico, q (testo: organo,
     * ordine del giorno, testi dei punti, testo dei PDF importati), tipo ('' tutti, portale, importato, firmato, incompleto = senza data o senza organo).
     *
     * @param array<string, mixed> $f
     * @param list<int>|null $consigli null = tutti (per i referenti solo i loro)
     * @return list<array<string, string|null>>
     */
    public function archivio(array $f, ?array $consigli, int $limite = 300): array
    {
        $dove = ['1'];
        $par = [];
        if ($consigli !== null) {
            $dove[] = 's.consiglio_id IN (' . implode(',', array_map('intval', $consigli ?: [0])) . ')';
        }
        if (!empty($f['consiglio'])) {
            $dove[] = 's.consiglio_id = ?';
            $par[] = (int) $f['consiglio'];
        }
        if (!empty($f['corso'])) {
            $dove[] = '(ao.corso = ? OR dc.nome = ?)';
            $par[] = (string) $f['corso'];
            $par[] = (string) $f['corso'];
        }
        if (!empty($f['organo_id'])) {
            $dove[] = 's.organo_id = ?';
            $par[] = (int) $f['organo_id'];
        }
        if (!empty($f['anno_accademico'])) {
            $dove[] = 's.anno_accademico = ?';
            $par[] = (string) $f['anno_accademico'];
        }
        $tipo = (string) ($f['tipo'] ?? '');
        if ($tipo === 'portale' || $tipo === 'importato') {
            $dove[] = 's.origine = ?';
            $par[] = $tipo;
        } elseif ($tipo === 'firmato') {
            $dove[] = "s.verbale_stato = 'firmato'";
        } elseif ($tipo === 'incompleto') {
            $dove[] = "(s.data IS NULL OR s.organo = '')";
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $l = '%' . addcslashes($q, '%_\\') . '%';
            $c = '%' . addcslashes(preg_replace('/\s+/u', '', $q) ?? '', '%_\\') . '%';
            $dove[] = "(s.organo LIKE ? OR s.odg LIKE ? OR s.nome_originale LIKE ? OR s.numero_verbale = ? OR s.testo_ricerca LIKE ? OR REPLACE(REPLACE(s.testo_ricerca, ' ', ''), CHAR(10), '') LIKE ?
                OR EXISTS (SELECT 1 FROM didattica_sedute_punti pt WHERE pt.seduta_id = s.id AND pt.testo LIKE ?))";
            array_push($par, $l, $l, $l, $q, $l, $c, $l);
        }

        return Righe::testo($this->db->righe(
            'SELECT s.id, s.organo, s.organo_id, s.da_cartella, s.allegati_json, s.data, s.anno_accademico, s.numero_verbale, s.origine, s.verbale_stato, s.verbale_pdf, s.nome_originale, s.consiglio_id, s.odg, s.pubblicabile, s.verbale_pubblico_pdf, COALESCE(NULLIF(ao.corso, \'\'), dc.nome, \'\') AS corso, (SELECT MIN(cc.id) FROM didattica_consigli cc WHERE cc.nome = ao.corso) AS corso_consiglio_id
             FROM didattica_sedute s LEFT JOIN archivio_organi ao ON ao.id = s.organo_id LEFT JOIN didattica_consigli dc ON dc.id = s.consiglio_id WHERE ' . implode(' AND ', $dove) . ' ORDER BY s.data IS NULL DESC, s.data DESC, s.id DESC LIMIT ' . (int) $limite,
            $par
        ));
    }

    /**
     * I verbali segnati pubblicabili (firmati o importati), dal più recente, con il nome del consiglio.
     *
     * @return list<array<string, mixed>>
     */
    public function pubblici(): array
    {
        return $this->db->righe(
            self::SQL_PUBBLICO . " WHERE s.pubblicabile = 1 AND (s.verbale_stato = 'firmato' OR s.origine = 'importato') AND s.verbale_pdf IS NOT NULL ORDER BY s.data DESC, s.id DESC"
        );
    }

    /** Un verbale con il suo corso di studi e il suo organo (per decidere se un accesso lo può vedere). @return array<string, mixed>|null */
    public function perIdPubblico(int $id): ?array
    {
        return $this->db->riga(self::SQL_PUBBLICO . ' WHERE s.id = ?', [$id]);
    }

    /** Verbale + consiglio + altro organo + corso di studi (quello dell'organo, o il consiglio) + il consiglio che dà il nome al corso dell'organo. */
    private const SQL_PUBBLICO = "SELECT s.*, c.nome AS consiglio_nome, ao.nome AS organo_nome, ao.tipo AS organo_tipo, COALESCE(NULLIF(ao.corso, ''), c.nome, '') AS corso,
        (SELECT MIN(cc.id) FROM didattica_consigli cc WHERE cc.nome = ao.corso) AS corso_consiglio_id
        FROM didattica_sedute s LEFT JOIN didattica_consigli c ON c.id = s.consiglio_id LEFT JOIN archivio_organi ao ON ao.id = s.organo_id";

    /** Segna il verbale come pubblicabile; $filePubblico = PDF con gli omissis (null = si pubblica il PDF firmato). */
    public function pubblica(int $id, ?string $filePubblico, string $da): void
    {
        $this->db->esegui('UPDATE didattica_sedute SET pubblicabile = 1, verbale_pubblico_pdf = ?, pubblicato_il = NOW(), pubblicato_da = ? WHERE id = ?', [$filePubblico, $da, $id]);
    }

    public function ritira(int $id): void
    {
        $this->db->esegui("UPDATE didattica_sedute SET pubblicabile = 0, verbale_pubblico_pdf = NULL, pubblicato_il = NULL, pubblicato_da = '' WHERE id = ?", [$id]);
    }

    /** @return array<string, mixed>|null una seduta (o verbale importato) con questa impronta del file */
    public function perImpronta(string $hash): ?array
    {
        return $this->db->riga('SELECT id, organo, data FROM didattica_sedute WHERE file_hash = ? LIMIT 1', [$hash]);
    }

    /**
     * Un verbale vecchio importato (PDF). Ritorna l'id.
     *
     * @param array<string, mixed> $f
     */
    public function inserisciImportato(array $f): int
    {
        return $this->db->inserisci(
            "INSERT INTO didattica_sedute (organo, anno_accademico, data, odg, consiglio_id, organo_id, numero_verbale, origine, verbale_pdf, nome_originale, file_hash, testo_ricerca, da_cartella, cartella_firma, allegati_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'importato', ?, ?, ?, ?, ?, ?, ?)",
            [$f['organo'], $f['anno_accademico'], $f['data'], $f['odg'] ?? '', $f['consiglio_id'], $f['organo_id'] ?? null, $f['numero_verbale'], $f['verbale_pdf'], $f['nome_originale'], $f['file_hash'], $f['testo_ricerca'], (int) ($f['da_cartella'] ?? 0), (string) ($f['cartella_firma'] ?? ''), $f['allegati_json'] ?? null]
        );
    }

    /**
     * I verbali letti dalla cartella dell'archivio, per percorso del file.
     *
     * @return array<string, array<string, mixed>>
     */
    public function daCartella(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT id, organo, consiglio_id, organo_id, verbale_pdf, cartella_firma, file_hash, allegati_json FROM didattica_sedute WHERE da_cartella = 1') as $r) {
            $out[(string) $r['verbale_pdf']] = $r;
        }

        return $out;
    }

    public function aggiornaAllegati(int $id, ?string $json): void
    {
        $this->db->esegui('UPDATE didattica_sedute SET allegati_json = ? WHERE id = ? AND da_cartella = 1', [$json, $id]);
    }

    public function testoRicerca(int $id): ?string
    {
        $t = $this->db->valore('SELECT testo_ricerca FROM didattica_sedute WHERE id = ?', [$id]);

        return $t === null ? null : (string) $t;
    }

    /** Il file di un verbale letto dalla cartella è stato cambiato o spostato: nuovi dati del file (e dell'organo, se cambia). @param array<string, mixed> $f */
    public function aggiornaDaCartella(int $id, array $f): void
    {
        $this->db->esegui(
            'UPDATE didattica_sedute SET verbale_pdf = ?, nome_originale = ?, file_hash = ?, testo_ricerca = ?, cartella_firma = ?, organo = ?, consiglio_id = ?, organo_id = ? WHERE id = ? AND da_cartella = 1',
            [$f['verbale_pdf'], $f['nome_originale'], $f['file_hash'], $f['testo_ricerca'], $f['cartella_firma'], $f['organo'], $f['consiglio_id'], $f['organo_id'], $id]
        );
    }

    /** Toglie dall'archivio i verbali della cartella i cui file non ci sono più. @param list<int> $ids */
    public function eliminaDaCartella(array $ids): int
    {
        return $ids ? $this->db->esegui('DELETE FROM didattica_sedute WHERE da_cartella = 1 AND id IN (' . implode(',', array_map('intval', $ids)) . ')') : 0;
    }

    /** @param array<string, mixed> $f organo, anno_accademico, data, odg, consiglio_id, numero_verbale */
    public function aggiornaImportato(int $id, array $f): void
    {
        $this->db->esegui(
            "UPDATE didattica_sedute SET organo = ?, anno_accademico = ?, data = ?, odg = ?, consiglio_id = ?, organo_id = ?, numero_verbale = ? WHERE id = ? AND origine = 'importato'",
            [$f['organo'], $f['anno_accademico'], $f['data'], $f['odg'], $f['consiglio_id'], $f['organo_id'] ?? null, $f['numero_verbale'], $id]
        );
    }

    public function sostituisciFileImportato(int $id, string $percorso, string $nomeOriginale, string $hash, string $testo): void
    {
        $this->db->esegui("UPDATE didattica_sedute SET verbale_pdf = ?, nome_originale = ?, file_hash = ?, testo_ricerca = ? WHERE id = ? AND origine = 'importato'", [$percorso, $nomeOriginale, $hash, $testo, $id]);
    }

    public function eliminaImportato(int $id): void
    {
        $this->db->esegui("DELETE FROM didattica_sedute WHERE id = ? AND origine = 'importato'", [$id]);
    }

    /** @return list<string> anni accademici presenti nell'archivio, dal più recente */
    public function anniAccademici(): array
    {
        return array_map('strval', array_column($this->db->righe("SELECT DISTINCT anno_accademico FROM didattica_sedute WHERE anno_accademico <> '' ORDER BY anno_accademico DESC"), 'anno_accademico'));
    }

    /**
     * Pratiche portate in seduta e ancora senza esito (non concluse), per consiglio.
     *
     * @return array<int, int> id consiglio => numero
     */
    public function praticheSenzaEsitoPerConsiglio(): array
    {
        $out = [];
        foreach ($this->db->righe("SELECT s.consiglio_id AS c, COUNT(*) AS n FROM pratiche p JOIN didattica_sedute s ON s.id = p.seduta_id
            WHERE s.consiglio_id IS NOT NULL AND p.esito_seduta = '' AND p.stato IN ('inviata', 'in_lavorazione', 'integrazione') GROUP BY s.consiglio_id") as $r) {
            $out[(int) $r['c']] = (int) $r['n'];
        }

        return $out;
    }

    /**
     * Id delle sedute dei consigli indicati.
     *
     * @param list<int> $consigli
     * @return list<string|null>
     */
    public function idDeiConsigli(array $consigli): array
    {
        return array_column(Righe::testo($this->db->righe('SELECT id FROM didattica_sedute WHERE consiglio_id IN (' . implode(',', array_map('intval', $consigli ?: [0])) . ')')), 'id');
    }

    /**
     * Crea (id = 0) o aggiorna una seduta. Ritorna l'id.
     *
     * @param array<string, mixed> $f organo, anno_accademico, data, ora_inizio, ora_fine, luogo, odg, presenze, segretario, coordinatore, consiglio_id, numero_verbale
     */
    public function salva(int $id, array $f): int
    {
        $v = [$f['organo'], $f['anno_accademico'], $f['data'], $f['ora_inizio'], $f['ora_fine'], $f['luogo'], $f['odg'], $f['presenze'], $f['segretario'], $f['coordinatore'], $f['consiglio_id'], (string) ($f['numero_verbale'] ?? '')];
        if ($id) {
            $this->db->esegui('UPDATE didattica_sedute SET organo=?, anno_accademico=?, data=?, ora_inizio=?, ora_fine=?, luogo=?, odg=?, presenze=?, segretario=?, coordinatore=?, consiglio_id=?, numero_verbale=? WHERE id=?', [...$v, $id]);

            return $id;
        }

        return $this->db->inserisci('INSERT INTO didattica_sedute (organo, anno_accademico, data, ora_inizio, ora_fine, luogo, odg, presenze, segretario, coordinatore, consiglio_id, numero_verbale) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $v);
    }

    /** Elimina la seduta con le sue presenze e convocazioni; le pratiche restano, senza seduta. */
    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM didattica_sedute_presenze WHERE seduta_id = ?', [$id]);
        $this->db->esegui('DELETE FROM didattica_convocazioni WHERE seduta_id = ?', [$id]);
        $this->db->esegui('UPDATE pratiche SET punto_id = NULL WHERE seduta_id = ?', [$id]);
        $this->db->esegui('DELETE FROM didattica_sedute_punti WHERE seduta_id = ?', [$id]);
        $this->db->esegui('UPDATE pratiche SET seduta_id = NULL WHERE seduta_id = ?', [$id]);
        $this->db->esegui('DELETE FROM didattica_sedute WHERE id = ?', [$id]);
    }

    /**
     * Presenze salvate della seduta.
     *
     * @return list<array<string, mixed>>
     */
    public function presenze(int $sedutaId): array
    {
        return $this->db->righe('SELECT * FROM didattica_sedute_presenze WHERE seduta_id = ? ORDER BY ordine, nominativo', [$sedutaId]);
    }

    /** Segna lo stato di un componente nella seduta (nome e qualifica restano nella seduta anche se il componente cambia dopo). */
    public function segnaPresenza(int $sedutaId, int $componenteId, string $nominativo, string $qualifica, int $ordine, string $stato): bool
    {
        return $this->db->esegui(
            'INSERT INTO didattica_sedute_presenze (seduta_id, componente_id, nominativo, qualifica, ordine, stato) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE stato = VALUES(stato), ordine = VALUES(ordine)',
            [$sedutaId, $componenteId, $nominativo, $qualifica, $ordine, $stato]
        ) >= 0;
    }

    /** Il componente giustifica l'assenza: «assente giustificato» (anche se non aveva ancora uno stato). */
    public function giustificaPresenza(int $sedutaId, int $componenteId, string $nominativo, string $qualifica): void
    {
        $ordine = (int) $this->db->valore('SELECT COUNT(*) FROM didattica_sedute_presenze WHERE seduta_id = ?', [$sedutaId]);
        $this->db->esegui(
            "INSERT INTO didattica_sedute_presenze (seduta_id, componente_id, nominativo, qualifica, ordine, stato) VALUES (?, ?, ?, ?, ?, 'AG') ON DUPLICATE KEY UPDATE stato = 'AG'",
            [$sedutaId, $componenteId, $nominativo, $qualifica, 1000 + $ordine]
        );
    }

    /** @return array<int, int> seduta => numero di presenze registrate */
    public function conteggioPresenze(): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT seduta_id, COUNT(*) n FROM didattica_sedute_presenze GROUP BY seduta_id') as $x) {
            $out[(int) $x['seduta_id']] = (int) $x['n'];
        }

        return $out;
    }

    /**
     * Id delle pratiche portate nella seduta.
     *
     * @return list<mixed>
     */
    public function idPratiche(int $sedutaId): array
    {
        return array_column($this->db->righe('SELECT id FROM pratiche WHERE seduta_id = ?', [$sedutaId]), 'id');
    }

    /**
     * Pratiche non ancora in nessuna seduta (e non chiuse né respinte), per portarle in seduta.
     *
     * @return list<array<string, string|null>>
     */
    public function pratichePortabili(): array
    {
        return Righe::testo($this->db->righe(
            "SELECT p.id, p.codice, p.cognome, p.nome, p.matricola, p.stato, p.risposte_json, m.titolo AS modulo_titolo FROM pratiche p JOIN didattica_moduli m ON m.id = p.modulo_id
             WHERE p.seduta_id IS NULL AND m.in_verbale = 1 AND p.stato NOT IN ('chiusa', 'respinta') ORDER BY m.titolo, p.cognome, p.nome"
        ));
    }

    /** Porta le pratiche nella seduta (0 = le toglie da qualunque seduta). */
    public function portaPratiche(array $ids, int $sedutaId): void
    {
        foreach ($ids as $id) {
            $this->db->esegui('UPDATE pratiche SET seduta_id = ? WHERE id = ?', [$sedutaId ?: null, $id]);
        }
    }
}
