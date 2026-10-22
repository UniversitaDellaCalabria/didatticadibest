<?php

declare(strict_types=1);

namespace App\Assistenza;

use App\Core\Database;
use App\Uffici\UfficiRepository;

/**
 * Chi risponde alle chat: le persone dell'ufficio fisso «Assistenza» (anagrafe degli uffici) e gli amministratori. Finché l'ufficio è vuoto gli avvisi
 * vanno agli amministratori, così nessuna richiesta resta senza destinatario.
 */
final class OperatoriAssistenza
{
    public function __construct(private UfficiRepository $uffici, private Database $db)
    {
    }

    /** @param array<string, mixed>|null $utente riga dell'utente (id, email, ruolo_id e ruoli_secondari) */
    public function eOperatore(?array $utente): bool
    {
        if (!$utente || empty($utente['id'])) {
            return false;
        }
        if ((int) ($utente['ruolo_id'] ?? 0) === 1 || in_array('1', array_filter(explode(',', (string) ($utente['ruoli_secondari'] ?? ''))), true)) {
            return true;
        }
        $u = $this->uffici->perChiave(Costanti::UFFICIO);

        return $u !== null && (int) $u['attivo'] === 1 && $this->uffici->membro((int) $u['id'], (string) ($utente['email'] ?? ''));
    }

    /**
     * Gli indirizzi a cui avvisare di una nuova richiesta.
     *
     * @return list<string>
     */
    public function emailAvvisi(): array
    {
        $u = $this->uffici->perChiave(Costanti::UFFICIO);
        $e = $u !== null && (int) $u['attivo'] === 1 ? $this->uffici->emailDelUfficio((int) $u['id']) : [];
        if ($e) {
            return $e;
        }
        $out = [];
        foreach ($this->db->righe("SELECT email FROM utenti WHERE ruolo_id = 1 AND email IS NOT NULL AND email <> ''") as $r) {
            if (filter_var((string) $r['email'], FILTER_VALIDATE_EMAIL)) {
                $out[strtolower((string) $r['email'])] = (string) $r['email'];
            }
        }

        return array_values($out);
    }

    /** L'email di un operatore (per avvisarlo di un messaggio nella sua chat), '' se non c'è. */
    public function emailDi(int $utenteId): string
    {
        $e = (string) $this->db->valore('SELECT email FROM utenti WHERE id = ?', [$utenteId]);

        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
    }

    /**
     * Le persone che possono ricevere una chat passata da un collega: [id utente => nome], dalle persone dell'ufficio che hanno un utente nel portale.
     *
     * @return array<int, string>
     */
    public function colleghi(): array
    {
        $out = [];
        $u = $this->uffici->perChiave(Costanti::UFFICIO);
        if ($u === null) {
            return $out;
        }
        foreach ($this->uffici->persone((int) $u['id']) as $p) {
            $id = (int) $this->db->valore('SELECT id FROM utenti WHERE LOWER(email) = ? LIMIT 1', [strtolower((string) $p['email'])]);
            if ($id > 0) {
                $out[$id] = (string) ($p['nominativo'] ?: $p['email']);
            }
        }

        return $out;
    }
}
