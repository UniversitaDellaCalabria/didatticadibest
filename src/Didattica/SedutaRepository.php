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
            . ($consigli !== null ? ' WHERE s.consiglio_id IN (' . implode(',', array_map('intval', $consigli ?: [0])) . ')' : '') . ' ORDER BY s.data DESC, s.id DESC'
        ));
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
     * @param array<string, mixed> $f organo, anno_accademico, data, ora_inizio, ora_fine, luogo, odg, presenze, segretario, coordinatore, consiglio_id
     */
    public function salva(int $id, array $f): int
    {
        $v = [$f['organo'], $f['anno_accademico'], $f['data'], $f['ora_inizio'], $f['ora_fine'], $f['luogo'], $f['odg'], $f['presenze'], $f['segretario'], $f['coordinatore'], $f['consiglio_id']];
        if ($id) {
            $this->db->esegui('UPDATE didattica_sedute SET organo=?, anno_accademico=?, data=?, ora_inizio=?, ora_fine=?, luogo=?, odg=?, presenze=?, segretario=?, coordinatore=?, consiglio_id=? WHERE id=?', [...$v, $id]);

            return $id;
        }

        return $this->db->inserisci('INSERT INTO didattica_sedute (organo, anno_accademico, data, ora_inizio, ora_fine, luogo, odg, presenze, segretario, coordinatore, consiglio_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $v);
    }

    /** Elimina la seduta con le sue presenze e convocazioni; le pratiche restano, senza seduta. */
    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM didattica_sedute_presenze WHERE seduta_id = ?', [$id]);
        $this->db->esegui('DELETE FROM didattica_convocazioni WHERE seduta_id = ?', [$id]);
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
             WHERE p.seduta_id IS NULL AND p.stato NOT IN ('chiusa', 'respinta') ORDER BY m.titolo, p.cognome, p.nome"
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
