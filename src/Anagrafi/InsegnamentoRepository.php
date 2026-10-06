<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Core\Database;

/** Insegnamenti dei corsi del proprio dipartimento (tabella insegnamenti, dalle API activities). */
final class InsegnamentoRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<string, mixed> $i riga con le colonne di insegnamenti */
    public function salva(array $i): void
    {
        $this->db->esegui(
            'INSERT INTO insegnamenti (id, codice, nome, cds_cod, cds_nome, anno_corso, anno_accademico, coorte, semestre, ssd_cod, ssd, lingua, docente, docente_id, partizione, padre_id, dipartimento_cod, cfu, presente, aggiornato_il)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                              ON DUPLICATE KEY UPDATE codice=VALUES(codice), cfu=VALUES(cfu), nome=VALUES(nome), cds_cod=VALUES(cds_cod), cds_nome=VALUES(cds_nome), anno_corso=VALUES(anno_corso),
                                anno_accademico=VALUES(anno_accademico), coorte=VALUES(coorte), semestre=VALUES(semestre), ssd_cod=VALUES(ssd_cod), ssd=VALUES(ssd), lingua=VALUES(lingua), docente=VALUES(docente),
                                docente_id=VALUES(docente_id), partizione=VALUES(partizione), padre_id=VALUES(padre_id), dipartimento_cod=VALUES(dipartimento_cod), presente=1, aggiornato_il=NOW()',
            [$i['id'], $i['codice'], $i['nome'], $i['cds_cod'], $i['cds_nome'], $i['anno_corso'], $i['anno_accademico'], $i['coorte'], $i['semestre'], $i['ssd_cod'], $i['ssd'],
             $i['lingua'], $i['docente'], $i['docente_id'], $i['partizione'], $i['padre_id'], $i['dipartimento_cod'], $i['cfu']]
        );
    }

    /**
     * Non più presenti negli anni scaricati; quelli di anni accademici precedenti si conservano un anno.
     *
     * @param list<int> $visti
     */
    public function segnaNonPresentiEPulisci(string $dipartimento, int $annoAccademico, array $visti): void
    {
        $this->db->esegui(
            'UPDATE insegnamenti SET presente = 0 WHERE dipartimento_cod = ? AND anno_accademico >= ?' . ($visti ? ' AND id NOT IN (' . implode(',', array_fill(0, count($visti), '?')) . ')' : ''),
            array_merge([$dipartimento, $annoAccademico], array_map('intval', $visti))
        );
        $this->db->esegui('DELETE FROM insegnamenti WHERE anno_accademico < ?', [$annoAccademico - 1]);
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM insegnamenti WHERE id = ?', [$id]);
    }

    public function contaPresenti(int $anno): int
    {
        return (int) ($this->db->valore('SELECT COUNT(*) n FROM insegnamenti WHERE presente = 1 AND anno_accademico = ?', [$anno]) ?? 0);
    }

    public function annoPiuRecente(): int
    {
        return (int) ($this->db->valore('SELECT MAX(anno_accademico) a FROM insegnamenti WHERE presente = 1') ?? 0);
    }

    /** @return list<int> anni accademici presenti, in ordine */
    public function anniPresenti(): array
    {
        return array_map(static fn (array $r): int => (int) $r['a'], $this->db->righe('SELECT DISTINCT anno_accademico a FROM insegnamenti WHERE presente = 1 ORDER BY a'));
    }

    /** @return list<array<string, mixed>> insegnamenti presenti di un anno, per corso */
    public function dellAnno(int $anno): array
    {
        return $this->db->righe('SELECT * FROM insegnamenti WHERE presente = 1 AND anno_accademico = ? ORDER BY cds_nome, anno_corso, nome, partizione', [$anno]);
    }

    /**
     * Insegnamenti per il pannello (al massimo 600) con le attività collegate non archiviate.
     *
     * @return list<array<string, mixed>>
     */
    public function elenco(int $anno, string $cds, int $annoCorso, string $semestre, string $cerca): array
    {
        $where = ['presente = 1', 'anno_accademico = ?'];
        $par = [$anno];
        if ($cds !== '') {
            $where[] = 'cds_cod = ?';
            $par[] = $cds;
        }
        if ($annoCorso > 0) {
            $where[] = 'anno_corso = ?';
            $par[] = $annoCorso;
        }
        if ($semestre !== '') {
            $where[] = 'semestre = ?';
            $par[] = $semestre;
        }
        if ($cerca !== '') {
            $where[] = '(nome LIKE ? OR docente LIKE ? OR codice LIKE ? OR ssd_cod LIKE ?)';
            $lk = '%' . addcslashes($cerca, '%_\\') . '%';
            array_push($par, $lk, $lk, $lk, $lk);
        }

        return $this->db->righe(
            'SELECT i.*, (SELECT COUNT(*) FROM progetti_dettagli d JOIN eventi e ON e.id = d.evento_id WHERE d.insegnamento_id = i.id AND e.archiviato = 0) AS n_att
                            FROM insegnamenti i WHERE ' . implode(' AND ', $where) . ' ORDER BY cds_nome, anno_corso, nome, partizione LIMIT 600',
            $par
        );
    }

    /** @return list<array<string, mixed>> corsi con insegnamenti in quell'anno (cds_cod, cds_nome, n) */
    public function corsiDellAnno(int $anno): array
    {
        return $this->db->righe('SELECT cds_cod, cds_nome, COUNT(*) n FROM insegnamenti WHERE presente = 1 AND anno_accademico = ? GROUP BY cds_cod, cds_nome ORDER BY cds_nome', [$anno]);
    }

    /** @return list<string> semestri presenti */
    public function semestri(): array
    {
        return array_map(static fn (array $r): string => (string) $r['semestre'], $this->db->righe("SELECT DISTINCT semestre FROM insegnamenti WHERE presente = 1 AND semestre <> '' ORDER BY semestre"));
    }

    /** @return list<array<string, mixed>> insegnamenti di un corso e di una coorte (ripiego del catalogo di Ateneo) */
    public function perCorsoECoorte(string $cds, int $coorte): array
    {
        return $this->db->righe('SELECT id, nome, codice, cfu, ssd_cod, ssd, anno_corso, partizione, semestre FROM insegnamenti WHERE cds_cod = ? AND coorte = ? ORDER BY anno_corso, nome, partizione', [$cds, $coorte]);
    }
}
