<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Core\Database;
use App\Eventi\Righe;

/**
 * Operatori della Didattica: le persone degli uffici del Dipartimento (uffici_persone con op_id) che hanno almeno un compito (pratiche, sedute,
 * ricevimento, bandi). Una persona in più uffici è un solo operatore (stesso op_id, quello di pratiche.assegnata_a): il primo ufficio è quello principale.
 * Le persone della Direzione e della segreteria di direzione (senza op_id) non sono operatori. Gli uffici si gestiscono da Gestione del portale → Uffici.
 */
final class OperatoreRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Tutti gli operatori, in ordine di nominativo, con la forma di sempre: id (op_id), persona_id, email, nominativo, ruolo,
     * compiti, ufficio_id (principale), corsi, uffici_json (gli altri uffici).
     *
     * @return list<array<string, string|null>>
     */
    public function tutti(): array
    {
        $per = [];
        foreach (Righe::testo($this->db->righe(
            "SELECT p.* FROM uffici_persone p JOIN uffici u ON u.id = p.ufficio_id WHERE u.ambito = 'dipartimento' AND p.op_id IS NOT NULL ORDER BY p.id"
        )) as $r) {
            $k = strtolower((string) $r['email']);
            if (!isset($per[$k])) {
                $per[$k] = ['id' => $r['op_id'], 'persona_id' => $r['persona_id'], 'email' => $r['email'], 'nominativo' => $r['nominativo'], 'ruolo' => (string) $r['ruolo'],
                    'compiti' => [], 'ufficio_id' => $r['ufficio_id'], 'altri' => [], 'corsi' => [], 'creato_il' => $r['creato_il']];
            } elseif ($r['ufficio_id'] !== $per[$k]['ufficio_id'] && !in_array($r['ufficio_id'], $per[$k]['altri'], true)) {
                $per[$k]['altri'][] = $r['ufficio_id'];
            }
            if ($per[$k]['ruolo'] === '' && (string) $r['ruolo'] !== '') {
                $per[$k]['ruolo'] = (string) $r['ruolo'];
            }
            foreach (array_filter(explode(',', (string) $r['compiti'])) as $c) {
                if (!in_array($c, $per[$k]['compiti'], true)) {
                    $per[$k]['compiti'][] = $c;
                }
            }
            foreach (json_decode((string) ($r['corsi'] ?? ''), true) ?: [] as $c) {
                if (!in_array($c, $per[$k]['corsi'], true)) {
                    $per[$k]['corsi'][] = $c;
                }
            }
        }
        $out = [];
        foreach ($per as $o) {
            if (!$o['compiti']) {
                continue;
            }
            $out[] = [
                'id' => $o['id'], 'persona_id' => $o['persona_id'], 'email' => $o['email'], 'nominativo' => $o['nominativo'], 'ruolo' => $o['ruolo'],
                'compiti' => implode(',', $o['compiti']), 'ufficio_id' => $o['ufficio_id'],
                'corsi' => $o['corsi'] ? (string) json_encode($o['corsi'], JSON_UNESCAPED_UNICODE) : null,
                'uffici_json' => $o['altri'] ? (string) json_encode(array_map('intval', $o['altri'])) : null, 'creato_il' => $o['creato_il'],
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp(mb_strtolower((string) $a['nominativo']), mb_strtolower((string) $b['nominativo'])));

        return $out;
    }

    /**
     * Aggiunge un operatore o, se l'email c'è già, ne aggiorna i dati (uffici, ruolo, compiti, corsi). False se il salvataggio non riesce.
     */
    public function salva(string $personaId, string $email, string $nominativo, string $ruolo, string $compiti, ?int $ufficioId, ?string $corsiJson, ?string $ufficiJson = null): bool
    {
        $ids = $ufficioId ? [$ufficioId] : [];
        foreach (json_decode((string) $ufficiJson, true) ?: [] as $x) {
            if ((int) $x > 0 && !in_array((int) $x, $ids, true)) {
                $ids[] = (int) $x;
            }
        }
        // Senza un ufficio la persona va nella segreteria didattica (un operatore sta sempre in un ufficio)
        $ids = $ids ?: array_filter([(int) ($this->db->valore("SELECT id FROM uffici WHERE ambito = 'dipartimento' AND chiave = 'operatore' LIMIT 1")
            ?? $this->db->valore("SELECT id FROM uffici WHERE ambito = 'dipartimento' AND COALESCE(chiave, '') NOT IN ('direzione', 'segreteria_direzione') ORDER BY ordine, id LIMIT 1") ?? 0)]);
        $op = (int) ($this->db->valore('SELECT MIN(op_id) FROM uffici_persone WHERE email = ? AND op_id IS NOT NULL', [strtolower($email)]) ?? 0);
        $this->liberaOperatore($email);
        foreach ($ids as $uid) {
            $nuovo = $this->db->inserisci(
                'INSERT INTO uffici_persone (ufficio_id, persona_id, email, nominativo, ruolo, compiti, corsi, op_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$uid, $personaId, strtolower($email), $nominativo, $ruolo, $compiti, $corsiJson, $op ?: null]
            );
            if ($op === 0 && $nuovo > 0) {
                $op = $nuovo;
                $this->db->esegui('UPDATE uffici_persone SET op_id = id WHERE id = ?', [$nuovo]);
            }
        }

        return true;
    }

    /** Persone dell'Ufficio didattico che stanno nell'ufficio indicato (anche se non è il loro ufficio principale). */
    public function contaDelloUfficio(int $ufficioId): int
    {
        return count(array_filter($this->tutti(), fn (array $o): bool => in_array($ufficioId, self::uffici($o), true)));
    }

    /**
     * Tutti gli uffici di un operatore: quello principale (ufficio_id) per primo, poi gli altri.
     *
     * @param array<string, mixed> $o riga dell'operatore
     * @return list<int>
     */
    public static function uffici(array $o): array
    {
        $ids = [];
        if (!empty($o['ufficio_id'])) {
            $ids[] = (int) $o['ufficio_id'];
        }
        foreach (json_decode((string) ($o['uffici_json'] ?? ''), true) ?: [] as $x) {
            if ((int) $x > 0) {
                $ids[] = (int) $x;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Toglie l'operatore da tutti i suoi uffici (la persona resta in quelli della Direzione, se c'è). $id è l'op_id di tutti(). */
    public function togli(int $id): void
    {
        $this->db->esegui('DELETE FROM uffici_persone WHERE op_id = ?', [$id]);
    }

    /** Toglie dagli uffici del Dipartimento le appartenenze «da operatore» della persona (non quelle della Direzione). */
    private function liberaOperatore(string $email): void
    {
        $this->db->esegui('DELETE FROM uffici_persone WHERE email = ? AND op_id IS NOT NULL', [strtolower($email)]);
    }
}
