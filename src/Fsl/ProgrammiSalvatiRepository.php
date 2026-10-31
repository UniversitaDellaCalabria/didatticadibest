<?php

declare(strict_types=1);

namespace App\Fsl;

use App\Core\Database;

/** Il programma FSL salvato per chi ha fatto l'accesso (tabella fsl_programmi): una riga per edizione (turno) scelta. */
final class ProgrammiSalvatiRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<int, array{evento_id: int, turno_id: int, custom: array<string, string>}> per turno_id, nell'ordine in cui sono state aggiunte */
    public function dellUtente(int $utenteId): array
    {
        $out = [];
        foreach ($this->db->righe('SELECT turno_id, evento_id, custom_json FROM fsl_programmi WHERE utente_id = ? ORDER BY id', [$utenteId]) as $r) {
            $custom = json_decode((string) $r['custom_json'], true);
            $out[(int) $r['turno_id']] = [
                'evento_id' => (int) $r['evento_id'], 'turno_id' => (int) $r['turno_id'],
                'custom' => array_map('strval', array_filter(is_array($custom) ? $custom : [], 'is_scalar')),
            ];
        }

        return $out;
    }

    /**
     * Salva il programma della persona com'è ora (le voci tolte spariscono, quelle nuove si aggiungono, le altre si aggiornano).
     *
     * @param array<int, array{evento_id: int, turno_id: int, custom: array<string, string>}> $voci
     */
    public function salva(int $utenteId, array $voci): void
    {
        $this->db->transazione(function () use ($utenteId, $voci): void {
            $turni = array_map('intval', array_keys($voci));
            if ($turni) {
                $this->db->esegui('DELETE FROM fsl_programmi WHERE utente_id = ? AND turno_id NOT IN (' . implode(',', $turni) . ')', [$utenteId]);
            } else {
                $this->db->esegui('DELETE FROM fsl_programmi WHERE utente_id = ?', [$utenteId]);
            }
            foreach ($voci as $v) {
                $this->db->esegui(
                    'INSERT INTO fsl_programmi (utente_id, turno_id, evento_id, custom_json) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE evento_id = VALUES(evento_id), custom_json = VALUES(custom_json)',
                    [$utenteId, (int) $v['turno_id'], (int) $v['evento_id'], json_encode($v['custom'], JSON_UNESCAPED_UNICODE)]
                );
            }
        });
    }
}
