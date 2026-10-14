<?php

declare(strict_types=1);

namespace App\Risorse;

use App\Core\Database;
use App\Eventi\Righe;

/** Le scadenze delle risorse (risorse_scadenze): bollo, assicurazione, tagliando, revisione, tarature… */
final class ScadenzaRisorsaRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function elenco(int $risorsaId): array
    {
        return $this->db->righe('SELECT * FROM risorse_scadenze WHERE risorsa_id = ? ORDER BY scade_il, id', [$risorsaId]);
    }

    /** @return array<string, mixed>|null */
    public function perId(int $id): ?array
    {
        return $this->db->riga('SELECT * FROM risorse_scadenze WHERE id = ?', [$id]);
    }

    public function salva(int $risorsaId, int $id, string $nome, string $data, int $preavviso, string $note): int
    {
        if ($id) {
            // Se la data cambia gli avvisi ripartono
            $this->db->esegui('UPDATE risorse_scadenze SET nome = ?, scade_il = ?, preavviso_giorni = ?, note = ?, avviso_il = IF(scade_il = ?, avviso_il, NULL), avviso_scaduta_il = IF(scade_il = ?, avviso_scaduta_il, NULL) WHERE id = ? AND risorsa_id = ?',
                [$nome, $data, $preavviso, $note, $data, $data, $id, $risorsaId]);

            return $id;
        }

        return $this->db->inserisci('INSERT INTO risorse_scadenze (risorsa_id, nome, scade_il, preavviso_giorni, note) VALUES (?, ?, ?, ?, ?)', [$risorsaId, $nome, $data, $preavviso, $note]);
    }

    public function rinnova(int $id, string $nuovaData): void
    {
        $this->db->esegui('UPDATE risorse_scadenze SET scade_il = ?, avviso_il = NULL, avviso_scaduta_il = NULL WHERE id = ?', [$nuovaData, $id]);
    }

    public function elimina(int $id): void
    {
        $this->db->esegui('DELETE FROM risorse_scadenze WHERE id = ?', [$id]);
    }

    public function eliminaDellaRisorsa(int $risorsaId): void
    {
        $this->db->esegui('DELETE FROM risorse_scadenze WHERE risorsa_id = ?', [$risorsaId]);
    }

    /**
     * Scadenze da avvisare oggi: entro il preavviso e non ancora avvisate, oppure scadute e non ancora avvisate come scadute; con i dati della risorsa
     * per le email (pagina, indirizzi di notifica, colore).
     *
     * @return list<array<string, string|null>>
     */
    public function daAvvisare(string $oggi): array
    {
        return Righe::testo($this->db->righe(
            "SELECT s.*, r.nome AS risorsa_nome, r.pagina_id, r.email_notifiche, r.luogo, p.colore_primario FROM risorse_scadenze s JOIN risorse r ON r.id = s.risorsa_id JOIN pagine_eventi p ON p.id = r.pagina_id
             WHERE r.attiva = 1 AND ((s.scade_il < ? AND s.avviso_scaduta_il IS NULL) OR (s.scade_il >= ? AND s.scade_il <= DATE_ADD(?, INTERVAL s.preavviso_giorni DAY) AND s.avviso_il IS NULL))
             ORDER BY s.scade_il",
            [$oggi, $oggi, $oggi]
        ));
    }

    public function segnaAvviso(int $id, bool $scaduta): void
    {
        $this->db->esegui('UPDATE risorse_scadenze SET ' . ($scaduta ? 'avviso_scaduta_il = NOW(), avviso_il = COALESCE(avviso_il, NOW())' : 'avviso_il = NOW()') . ' WHERE id = ?', [$id]);
    }

    /**
     * Le scadenze di tutte le risorse indicate in scadenza o scadute, per il riquadro del responsabile.
     *
     * @param list<int> $risorseIds
     * @return list<array<string, mixed>>
     */
    public function dellaRisorseConAvviso(array $risorseIds, string $oggi): array
    {
        if (!$risorseIds) {
            return [];
        }

        return $this->db->righe(
            'SELECT s.*, r.nome AS risorsa_nome FROM risorse_scadenze s JOIN risorse r ON r.id = s.risorsa_id WHERE s.risorsa_id IN (' . implode(',', array_map('intval', $risorseIds)) . ')
             AND s.scade_il <= DATE_ADD(?, INTERVAL s.preavviso_giorni DAY) ORDER BY s.scade_il',
            [$oggi]
        );
    }
}
