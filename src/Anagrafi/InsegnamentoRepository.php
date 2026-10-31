<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Core\Database;

/** Insegnamenti dei corsi del proprio dipartimento (tabella insegnamenti, dalle API activities). */
final class InsegnamentoRepository
{
    /** Primo anno accademico (2026/2027) di cui l'archivio conserva tutti gli insegnamenti di Ateneo */
    public const PRIMO_ANNO_ARCHIVIO = 2026;

    public function __construct(private Database $db)
    {
    }

    /**
     * Condizione SQL sui moduli degli integrati: 'semplici' = insegnamenti e moduli, senza le righe degli integrati (come prima);
     * 'scelta' = insegnamenti singoli e integrati, senza i loro moduli (si sceglie l'integrato); 'tutti' = tutto.
     */
    private static function filtroLivello(string $livello): string
    {
        return match ($livello) {
            'scelta' => ' AND (e_integrato = 1 OR padre_id IS NULL)',
            'tutti' => '',
            default => ' AND e_integrato = 0',
        };
    }

    /** Codice del proprio dipartimento (la prima struttura dell'anagrafe), null se l'anagrafe è vuota: per gli elenchi che non devono mostrare tutto l'Ateneo */
    public function dipartimentoProprio(): ?string
    {
        $c = (string) ($this->db->valore('SELECT codice FROM anagrafe_strutture ORDER BY aggiunta_il, codice LIMIT 1') ?? '');

        return $c !== '' ? $c : null;
    }

    /** @param array<string, mixed> $i riga con le colonne di insegnamenti (i CFU che l'elenco non dà restano quelli già scaricati dal dettaglio) */
    public function salva(array $i): void
    {
        $this->db->esegui(
            'INSERT INTO insegnamenti (id, codice, nome, cds_cod, cds_nome, anno_corso, anno_accademico, coorte, semestre, ssd_cod, ssd, lingua, docente, docente_id, partizione, padre_id, padre_nome, dipartimento_cod, dipartimento, cfu, link, presente, aggiornato_il)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                              ON DUPLICATE KEY UPDATE codice=VALUES(codice), cfu=COALESCE(VALUES(cfu), cfu), nome=VALUES(nome), cds_cod=VALUES(cds_cod), cds_nome=VALUES(cds_nome), anno_corso=VALUES(anno_corso),
                                anno_accademico=VALUES(anno_accademico), coorte=VALUES(coorte), semestre=VALUES(semestre), ssd_cod=VALUES(ssd_cod), ssd=VALUES(ssd), lingua=VALUES(lingua), docente=VALUES(docente),
                                docente_id=VALUES(docente_id), partizione=VALUES(partizione), padre_id=VALUES(padre_id), padre_nome=VALUES(padre_nome), dipartimento_cod=VALUES(dipartimento_cod),
                                dipartimento=VALUES(dipartimento), link=VALUES(link), presente=1, aggiornato_il=NOW()',
            [$i['id'], $i['codice'], $i['nome'], $i['cds_cod'], $i['cds_nome'], $i['anno_corso'], $i['anno_accademico'], $i['coorte'], $i['semestre'], $i['ssd_cod'], $i['ssd'],
             $i['lingua'], $i['docente'], $i['docente_id'], $i['partizione'], $i['padre_id'], $i['padre_nome'] ?? '', $i['dipartimento_cod'], $i['dipartimento'] ?? '', $i['cfu'], $i['link'] ?? '']
        );
    }

    /**
     * Non più presenti negli anni scaricati (di un dipartimento o, senza, di tutto l'Ateneo). Gli anni accademici dal 2026/2027 si
     * conservano sempre (archivio); quelli precedenti solo per un anno.
     *
     * @param list<int> $visti
     */
    public function segnaNonPresentiEPulisci(?string $dipartimento, int $annoAccademico, array $visti): void
    {
        $this->db->esegui(
            'UPDATE insegnamenti SET presente = 0 WHERE ' . ($dipartimento !== null ? 'dipartimento_cod = ? AND ' : '') . 'anno_accademico >= ?' . ($visti ? ' AND id NOT IN (' . implode(',', array_fill(0, count($visti), '?')) . ')' : ''),
            array_merge($dipartimento !== null ? [$dipartimento] : [], [$annoAccademico], array_map('intval', $visti))
        );
        $this->db->esegui('DELETE FROM insegnamenti WHERE anno_accademico < ?', [min($annoAccademico, self::PRIMO_ANNO_ARCHIVIO) - 1]);
    }

    /**
     * Riga dell'integrato (id del padre nelle API) con i dati ricavati dai suoi moduli.
     *
     * @param array<string, mixed> $i riga con le colonne di insegnamenti
     */
    public function salvaIntegrato(array $i): void
    {
        $this->db->esegui(
            'INSERT INTO insegnamenti (id, codice, nome, cds_cod, cds_nome, anno_corso, anno_accademico, coorte, semestre, ssd_cod, ssd, lingua, docente, docente_id, partizione, padre_id, padre_nome, dipartimento_cod, dipartimento, link, e_integrato, presente, aggiornato_il)
                              VALUES (?, \'\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'\', \'\', \'\', NULL, \'\', ?, ?, \'\', 1, 1, NOW())
                              ON DUPLICATE KEY UPDATE nome=VALUES(nome), cds_cod=VALUES(cds_cod), cds_nome=VALUES(cds_nome), anno_corso=VALUES(anno_corso), anno_accademico=VALUES(anno_accademico), coorte=VALUES(coorte),
                                semestre=VALUES(semestre), ssd_cod=VALUES(ssd_cod), ssd=VALUES(ssd), lingua=VALUES(lingua), dipartimento_cod=VALUES(dipartimento_cod), dipartimento=VALUES(dipartimento), e_integrato=1, presente=1, aggiornato_il=NOW()',
            [$i['id'], $i['nome'], $i['cds_cod'], $i['cds_nome'], $i['anno_corso'], $i['anno_accademico'], $i['coorte'], $i['semestre'], $i['ssd_cod'], $i['ssd'], $i['lingua'], $i['dipartimento_cod'], $i['dipartimento']]
        );
    }

    /** CFU degli integrati = somma dei CFU dei moduli (vuoti finché manca quello di un modulo) */
    public function ricalcolaCfuIntegrati(): void
    {
        $this->db->esegui('UPDATE insegnamenti p SET p.cfu = (SELECT IF(COUNT(m.cfu) = COUNT(*), SUM(m.cfu), NULL) FROM (SELECT padre_id, cfu FROM insegnamenti WHERE padre_id IS NOT NULL AND presente = 1) m WHERE m.padre_id = p.id) WHERE p.e_integrato = 1');
    }

    /** @return list<int> id degli insegnamenti i cui CFU non sono ancora stati scaricati dal dettaglio (o da più di 90 giorni) */
    public function daCompletare(int $max): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->righe(
            'SELECT id FROM insegnamenti WHERE presente = 1 AND e_integrato = 0 AND (dettaglio_il IS NULL OR dettaglio_il < NOW() - INTERVAL 90 DAY) ORDER BY anno_accademico, id LIMIT ' . max(1, $max)
        ));
    }

    public function quantiDaCompletare(): int
    {
        return (int) ($this->db->valore('SELECT COUNT(*) n FROM insegnamenti WHERE presente = 1 AND e_integrato = 0 AND (dettaglio_il IS NULL OR dettaglio_il < NOW() - INTERVAL 90 DAY)') ?? 0);
    }

    /** Segna scaricato il dettaglio di un insegnamento, con i CFU se indicati */
    public function salvaDettaglio(int $id, ?float $cfu): void
    {
        $this->db->esegui('UPDATE insegnamenti SET cfu = COALESCE(?, cfu), dettaglio_il = NOW() WHERE id = ?', [$cfu, $id]);
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM insegnamenti WHERE id = ?', [$id]);
    }

    /** $dip: solo i corsi di quel dipartimento, null = tutto l'Ateneo */
    public function contaPresenti(int $anno, ?string $dip = null): int
    {
        return (int) ($this->db->valore('SELECT COUNT(*) n FROM insegnamenti WHERE presente = 1 AND e_integrato = 0 AND anno_accademico = ?' . ($dip !== null ? ' AND (dipartimento_cod = ? OR dipartimento_cod = \'\')' : ''), $dip !== null ? [$anno, $dip] : [$anno]) ?? 0);
    }

    public function annoPiuRecente(?string $dip = null): int
    {
        return (int) ($this->db->valore('SELECT MAX(anno_accademico) a FROM insegnamenti WHERE presente = 1' . ($dip !== null ? ' AND (dipartimento_cod = ? OR dipartimento_cod = \'\')' : ''), $dip !== null ? [$dip] : []) ?? 0);
    }

    /** @return list<int> anni accademici presenti, in ordine */
    public function anniPresenti(): array
    {
        return array_map(static fn (array $r): int => (int) $r['a'], $this->db->righe('SELECT DISTINCT anno_accademico a FROM insegnamenti WHERE presente = 1 ORDER BY a'));
    }

    /** @return list<array<string, mixed>> insegnamenti presenti di un anno, per corso ($dip: solo di un dipartimento, null = tutto l'Ateneo) */
    public function dellAnno(int $anno, ?string $dip = null, string $livello = 'semplici'): array
    {
        return $this->db->righe('SELECT * FROM insegnamenti WHERE presente = 1 AND anno_accademico = ?' . ($dip !== null ? ' AND (dipartimento_cod = ? OR dipartimento_cod = \'\')' : '') . self::filtroLivello($livello) . ' ORDER BY cds_nome, anno_corso, nome, partizione', $dip !== null ? [$anno, $dip] : [$anno]);
    }

    /**
     * Insegnamenti per il pannello (al massimo 3000) con le attività collegate non archiviate. $dip: solo di un dipartimento, null = tutto l'Ateneo.
     *
     * @return list<array<string, mixed>>
     */
    public function elenco(int $anno, string $cds, int $annoCorso, string $semestre, string $cerca, ?string $dip = null, string $livello = 'semplici'): array
    {
        $where = ['presente = 1', 'anno_accademico = ?', ltrim(self::filtroLivello($livello), ' AND') ?: '1 = 1'];
        $par = [$anno];
        if ($dip !== null) {
            $where[] = "(dipartimento_cod = ? OR dipartimento_cod = '')";
            $par[] = $dip;
        }
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
            $where[] = '(nome LIKE ? OR docente LIKE ? OR codice LIKE ? OR ssd_cod LIKE ? OR cds_nome LIKE ? OR cds_cod LIKE ? OR padre_nome LIKE ?)';
            $lk = '%' . addcslashes($cerca, '%_\\') . '%';
            array_push($par, $lk, $lk, $lk, $lk, $lk, $lk, $lk);
        }

        return $this->db->righe(
            'SELECT i.*, (SELECT COUNT(*) FROM progetti_dettagli d JOIN eventi e ON e.id = d.evento_id WHERE d.insegnamento_id = i.id AND e.archiviato = 0) AS n_att
                            FROM insegnamenti i WHERE ' . implode(' AND ', $where) . ' ORDER BY cds_nome, anno_corso, COALESCE(NULLIF(padre_nome, \'\'), nome), e_integrato DESC, nome, partizione LIMIT 3000',
            $par
        );
    }

    /** @return list<array<string, mixed>> corsi con insegnamenti in quell'anno (cds_cod, cds_nome, n) */
    public function corsiDellAnno(int $anno, ?string $dip = null): array
    {
        return $this->db->righe('SELECT cds_cod, cds_nome, COUNT(*) n FROM insegnamenti WHERE presente = 1 AND anno_accademico = ?' . ($dip !== null ? ' AND (dipartimento_cod = ? OR dipartimento_cod = \'\')' : '') . ' GROUP BY cds_cod, cds_nome ORDER BY cds_nome', $dip !== null ? [$anno, $dip] : [$anno]);
    }

    /** @return list<array<string, mixed>> dipartimenti con insegnamenti in quell'anno (dipartimento_cod, dipartimento, n) */
    public function dipartimentiDellAnno(int $anno): array
    {
        return $this->db->righe("SELECT dipartimento_cod, MAX(dipartimento) dipartimento, COUNT(*) n FROM insegnamenti WHERE presente = 1 AND anno_accademico = ? AND dipartimento_cod <> '' GROUP BY dipartimento_cod ORDER BY dipartimento", [$anno]);
    }

    /** @return list<string> semestri presenti */
    public function semestri(): array
    {
        return array_map(static fn (array $r): string => (string) $r['semestre'], $this->db->righe("SELECT DISTINCT semestre FROM insegnamenti WHERE presente = 1 AND semestre <> '' ORDER BY semestre"));
    }

    /** @return list<array<string, mixed>> insegnamenti di un corso e di una coorte (ripiego del catalogo di Ateneo) */
    public function perCorsoECoorte(string $cds, int $coorte): array
    {
        return $this->db->righe('SELECT id, nome, codice, cfu, ssd_cod, ssd, anno_corso, partizione, semestre, padre_id, padre_nome, link, docente FROM insegnamenti WHERE cds_cod = ? AND coorte = ? AND e_integrato = 0 ORDER BY anno_corso, nome, partizione', [$cds, $coorte]);
    }
}
