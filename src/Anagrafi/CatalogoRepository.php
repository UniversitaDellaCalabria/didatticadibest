<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Core\Database;

/**
 * Catalogo di Ateneo per i moduli della Didattica: corsi di studio di tutti i dipartimenti (ateneo_cds) e insegnamenti
 * di un corso per anno di offerta (ateneo_insegnamenti, con la data dell'ultimo scaricamento in ateneo_insegnamenti_scaricati).
 * Le tabelle possono mancare (installazioni vecchie): le letture danno risultati vuoti, come prima.
 */
final class CatalogoRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<string, mixed> $c codice, anno, nome, tipo, tipo_descrizione, dipartimento_cod, dipartimento */
    public function salvaCorso(array $c): bool
    {
        return $this->db->esegui(
            'INSERT INTO ateneo_cds (codice, anno, nome, tipo, tipo_descrizione, dipartimento_cod, dipartimento, aggiornato_il) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                              ON DUPLICATE KEY UPDATE nome = VALUES(nome), tipo = VALUES(tipo), tipo_descrizione = VALUES(tipo_descrizione), dipartimento_cod = VALUES(dipartimento_cod), dipartimento = VALUES(dipartimento), aggiornato_il = NOW()',
            [$c['codice'], (int) $c['anno'], $c['nome'], $c['tipo'], $c['tipo_descrizione'], $c['dipartimento_cod'], $c['dipartimento']]
        ) >= 0;
    }

    /** @return list<array<string, mixed>> tipi presenti nel catalogo (tipo, tipo_descrizione) */
    public function tipi(): array
    {
        return $this->db->righe("SELECT DISTINCT tipo, tipo_descrizione FROM ateneo_cds WHERE tipo <> '' ORDER BY tipo");
    }

    /** @return list<array<string, mixed>> corsi di un tipo offerti in un anno accademico (codice, nome, dipartimento) */
    public function corsiDelTipoNell(string $tipo, int $anno): array
    {
        return $this->db->righe('SELECT codice, nome, dipartimento FROM ateneo_cds WHERE tipo = ? AND anno = ? ORDER BY nome, codice', [$tipo, $anno]);
    }

    /** @return list<array<string, mixed>> corsi di un tipo con tutti gli anni di offerta (anni = elenco separato da virgole, dal più recente) */
    public function corsiDelTipo(string $tipo): array
    {
        return $this->db->righe('SELECT codice, nome, dipartimento, GROUP_CONCAT(anno ORDER BY anno DESC) AS anni FROM ateneo_cds WHERE tipo = ? GROUP BY codice, nome, dipartimento ORDER BY nome', [$tipo]);
    }

    /** @return list<int> anni di offerta dei corsi di un tipo, dal più recente */
    public function anni(string $tipo): array
    {
        return array_map(static fn (array $r): int => (int) $r['anno'], $this->db->righe('SELECT DISTINCT anno FROM ateneo_cds WHERE tipo = ? ORDER BY anno DESC', [$tipo]));
    }

    /** @return array<string, mixed>|null codice, nome, tipo e dipartimento dell'offerta più recente */
    public function corso(string $codice): ?array
    {
        return $this->db->riga('SELECT codice, nome, tipo, dipartimento FROM ateneo_cds WHERE codice = ? ORDER BY anno DESC LIMIT 1', [$codice]);
    }

    public function scaricatoIl(string $cds, int $coorte): ?string
    {
        $d = $this->db->valore('SELECT scaricato_il FROM ateneo_insegnamenti_scaricati WHERE cds_cod = ? AND coorte = ?', [$cds, $coorte]);

        return $d === null ? null : (string) $d;
    }

    /** @return list<array<string, mixed>> */
    public function insegnamenti(string $cds, int $coorte): array
    {
        return $this->db->righe('SELECT id, nome, codice, cfu, ssd_cod, ssd, anno_corso, partizione, semestre FROM ateneo_insegnamenti WHERE cds_cod = ? AND coorte = ? ORDER BY anno_corso, nome, partizione', [$cds, $coorte]);
    }

    /** @param array<string, mixed> $i id, cds_cod, coorte, anno_corso, codice, nome, cfu, ssd_cod, ssd, partizione, semestre, docente */
    public function salvaInsegnamento(array $i): bool
    {
        return $this->db->esegui(
            'INSERT INTO ateneo_insegnamenti (id, cds_cod, coorte, anno_corso, codice, nome, cfu, ssd_cod, ssd, partizione, semestre, docente) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                              ON DUPLICATE KEY UPDATE cds_cod = VALUES(cds_cod), coorte = VALUES(coorte), anno_corso = VALUES(anno_corso), codice = VALUES(codice), nome = VALUES(nome), cfu = VALUES(cfu),
                                ssd_cod = VALUES(ssd_cod), ssd = VALUES(ssd), partizione = VALUES(partizione), semestre = VALUES(semestre), docente = VALUES(docente)',
            [$i['id'], $i['cds_cod'], $i['coorte'], $i['anno_corso'], $i['codice'], $i['nome'], $i['cfu'], $i['ssd_cod'], $i['ssd'], $i['partizione'], $i['semestre'], $i['docente']]
        ) >= 0;
    }

    public function segnaScaricato(string $cds, int $coorte, int $n): void
    {
        $this->db->esegui('INSERT INTO ateneo_insegnamenti_scaricati (cds_cod, coorte, n, scaricato_il) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE n = VALUES(n), scaricato_il = NOW()', [$cds, $coorte, $n]);
    }
}
