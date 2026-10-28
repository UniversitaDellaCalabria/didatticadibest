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

    /**
     * I corsi di laurea seguiti dalle persone dell'ufficio Assistenza (come sono scritti nell'anagrafe degli uffici): servono a chiedere «a quale corso sei iscritto?».
     * Vuoto se nessuno ha indicato i corsi: in quel caso Didacticus non fa la domanda e avvisa tutti.
     *
     * @return list<string>
     */
    public function corsiSeguiti(): array
    {
        $out = [];
        foreach ($this->personeUfficio() as $p) {
            foreach ((array) json_decode((string) ($p['corsi'] ?? ''), true) as $c) {
                if (is_string($c) && trim($c) !== '') {
                    $out[trim($c)] = true;
                }
            }
        }
        $corsi = array_keys($out);
        sort($corsi, SORT_NATURAL | SORT_FLAG_CASE);

        return $corsi;
    }

    /**
     * Gli indirizzi degli operatori che seguono un corso di laurea; se nessuno lo segue (o il corso non è indicato) quelli di tutti gli operatori.
     *
     * @return list<string>
     */
    public function emailPerCorso(string $corso): array
    {
        $corso = trim($corso);
        $out = [];
        if ($corso !== '') {
            foreach ($this->personeUfficio() as $p) {
                $seguiti = array_map('trim', array_filter((array) json_decode((string) ($p['corsi'] ?? ''), true), 'is_string'));
                if (in_array($corso, $seguiti, true) && filter_var((string) $p['email'], FILTER_VALIDATE_EMAIL)) {
                    $out[strtolower((string) $p['email'])] = (string) $p['email'];
                }
            }
        }

        return $out ? array_values($out) : $this->emailAvvisi();
    }

    /** @return list<array<string, string|null>> */
    private function personeUfficio(): array
    {
        $u = $this->uffici->perChiave(Costanti::UFFICIO);

        return $u !== null && (int) $u['attivo'] === 1 ? $this->uffici->persone((int) $u['id']) : [];
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
