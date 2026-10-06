<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Core\Database;

/** Corsi di studio dei dipartimenti dell'anagrafe (tabella corsi_studio). */
final class CorsoRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<string, mixed> $c codice, nome, tipo, tipo_descrizione, classe, anno, lingua, durata, dipartimento_cod, visibile, regdid_id */
    public function salvaDaSincronizzazione(array $c): void
    {
        $this->db->esegui(
            'INSERT INTO corsi_studio (codice, nome, tipo, tipo_descrizione, classe, anno, lingua, durata, dipartimento_cod, visibile, regdid_id, presente, aggiornato_il)
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                                         ON DUPLICATE KEY UPDATE nome=VALUES(nome), tipo=VALUES(tipo), tipo_descrizione=VALUES(tipo_descrizione), classe=VALUES(classe),
                                           anno=VALUES(anno), lingua=VALUES(lingua), durata=VALUES(durata), dipartimento_cod=VALUES(dipartimento_cod), regdid_id=VALUES(regdid_id), presente=1, aggiornato_il=NOW()',
            [$c['codice'], $c['nome'], $c['tipo'], $c['tipo_descrizione'], $c['classe'], $c['anno'], $c['lingua'], $c['durata'], $c['dipartimento_cod'], (int) $c['visibile'], $c['regdid_id']]
        );
    }

    /**
     * I corsi del dipartimento non arrivati nell'ultimo aggiornamento restano ma non risultano più presenti.
     *
     * @param list<string> $codici
     */
    public function segnaNonPresenti(string $dipartimento, array $codici): void
    {
        $this->db->esegui(
            'UPDATE corsi_studio SET presente = 0 WHERE dipartimento_cod = ?' . ($codici ? ' AND codice NOT IN (' . implode(',', array_fill(0, count($codici), '?')) . ')' : ''),
            array_merge([$dipartimento], $codici)
        );
    }

    public function eliminaDelDipartimento(string $dipartimento): void
    {
        $this->db->esegui('DELETE FROM corsi_studio WHERE dipartimento_cod = ?', [$dipartimento]);
    }

    /** @return list<array<string, mixed>> corsi proposti nei campi dei moduli (visibili e presenti), per nome */
    public function visibili(): array
    {
        return $this->db->righe('SELECT codice, nome, tipo, tipo_descrizione FROM corsi_studio WHERE visibile = 1 AND presente = 1 ORDER BY nome');
    }

    public function contaVisibili(): int
    {
        return (int) $this->db->valore('SELECT COUNT(*) n FROM corsi_studio WHERE presente = 1 AND visibile = 1');
    }

    /** @return array<string, mixed>|null */
    public function perCodice(string $codice): ?array
    {
        return $this->db->riga('SELECT * FROM corsi_studio WHERE codice = ? LIMIT 1', [$codice]);
    }

    public function impostaVisibile(string $codice, bool $visibile): void
    {
        $this->db->esegui('UPDATE corsi_studio SET visibile = ? WHERE codice = ?', [$visibile ? 1 : 0, $codice]);
    }

    /** ID del regolamento didattico, se ancora mancante (serve per il link alla pagina del corso). */
    public function completaRegdid(string $codice, int $regdid): void
    {
        $this->db->esegui('UPDATE corsi_studio SET regdid_id = ? WHERE codice = ? AND (regdid_id IS NULL OR regdid_id = 0)', [$regdid, $codice]);
    }

    /** @return list<array<string, mixed>> corsi presenti con il nome della struttura, per il pannello */
    public function presentiConStruttura(): array
    {
        return $this->db->righe('SELECT c.*, s.nome AS dipartimento FROM corsi_studio c LEFT JOIN anagrafe_strutture s ON s.codice = c.dipartimento_cod WHERE c.presente = 1 ORDER BY c.tipo_descrizione, c.nome, c.anno DESC');
    }

    /** @return list<array<string, mixed>> codice, nome e anno dei corsi presenti di un tipo (ripiego del catalogo) */
    public function presentiDiTipo(string $tipo): array
    {
        return $this->db->righe('SELECT codice, nome, anno FROM corsi_studio WHERE presente = 1 AND tipo = ? ORDER BY nome', [$tipo]);
    }

    /** @return list<array<string, mixed>> tipi di corso presenti (ripiego del catalogo) */
    public function tipiPresenti(): array
    {
        return $this->db->righe("SELECT DISTINCT tipo, tipo_descrizione FROM corsi_studio WHERE presente = 1 AND tipo <> '' ORDER BY tipo");
    }
}
