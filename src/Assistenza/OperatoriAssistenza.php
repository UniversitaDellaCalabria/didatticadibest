<?php

declare(strict_types=1);

namespace App\Assistenza;

use App\Core\Database;
use App\Uffici\UfficiRepository;

/**
 * Chi risponde alle chat: le persone dell'ufficio fisso «Assistenza», quelle degli uffici collegati a Didacticus (spunta in Gestione → Uffici) e gli
 * amministratori. Finché l'ufficio è vuoto gli avvisi vanno agli amministratori, così nessuna richiesta resta senza destinatario.
 *
 * Una chat passata da Didacticus a un ufficio collegato (conversazione con ufficio_id) la vedono solo le persone di quell'ufficio e gli amministratori;
 * le altre (ufficio_id vuoto) le vedono le persone dell'Assistenza e gli amministratori.
 */
final class OperatoriAssistenza
{
    public function __construct(private UfficiRepository $uffici, private Database $db)
    {
    }

    /** @param array<string, mixed>|null $utente riga dell'utente (id, email, ruolo_id e ruoli_secondari) */
    private static function eAmministratore(?array $utente): bool
    {
        return $utente !== null && ((int) ($utente['ruolo_id'] ?? 0) === 1 || in_array('1', array_filter(explode(',', (string) ($utente['ruoli_secondari'] ?? ''))), true));
    }

    /** @param array<string, mixed>|null $utente riga dell'utente (id, email, ruolo_id e ruoli_secondari) */
    public function eOperatore(?array $utente): bool
    {
        if (!$utente || empty($utente['id'])) {
            return false;
        }

        return self::eAmministratore($utente) || $this->ufficiVisibili($utente) !== [];
    }

    /**
     * Le chat che una persona può vedere: null = tutte (amministratori), altrimenti gli id degli uffici collegati di cui fa parte, con 0 per «Assistenza»
     * (le chat senza ufficio).
     *
     * @param array<string, mixed>|null $utente
     * @return list<int>|null
     */
    public function ufficiVisibili(?array $utente): ?array
    {
        if (!$utente || empty($utente['id'])) {
            return [];
        }
        if (self::eAmministratore($utente)) {
            return null;
        }
        $email = (string) ($utente['email'] ?? '');
        $out = [];
        $a = $this->uffici->perChiave(Costanti::UFFICIO);
        if ($a !== null && (int) $a['attivo'] === 1 && $this->uffici->membro((int) $a['id'], $email)) {
            $out[] = 0;
        }
        foreach ($this->ufficiCollegati() as $u) {
            if ($this->uffici->membro($u['id'], $email)) {
                $out[] = $u['id'];
            }
        }

        return $out;
    }

    /** Una persona (per id utente) può vedere le chat di quell'ufficio (null = Assistenza)? */
    public function puoVedere(int $utenteId, ?int $ufficioId): bool
    {
        $u = $this->db->riga('SELECT id, email, ruolo_id, ruoli_secondari FROM utenti WHERE id = ?', [$utenteId]);
        $v = $this->ufficiVisibili($u);

        return $v === null || in_array((int) $ufficioId, $v, true);
    }

    /**
     * Gli uffici collegati a Didacticus (spuntati in Gestione → Uffici), con gli argomenti di cui si occupano.
     *
     * @return list<array{id: int, nome: string, argomenti: list<string>, cdl: bool}> cdl = ufficio di un corso di studio (legato a un consiglio)
     */
    public function ufficiCollegati(): array
    {
        $out = [];
        foreach ($this->db->righe("SELECT id, nome, tipo, didacticus_argomenti FROM uffici WHERE didacticus = 1 AND attivo = 1 AND ambito = 'dipartimento' ORDER BY ordine, nome") as $r) {
            $out[] = ['id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'argomenti' => array_values(array_filter(explode(',', (string) $r['didacticus_argomenti']))), 'cdl' => (string) $r['tipo'] === 'cdl'];
        }

        return $out;
    }

    /**
     * I corsi di laurea coperti dagli uffici di corso di studio collegati a Didacticus (i corsi del consiglio a cui ogni ufficio è legato).
     *
     * @return list<string>
     */
    public function corsiDegliUffici(): array
    {
        $out = [];
        foreach ($this->consigliDegliUffici() as $c) {
            foreach ($c['corsi'] as $nome) {
                $out[$nome] = true;
            }
        }
        $corsi = array_keys($out);
        sort($corsi, SORT_NATURAL | SORT_FLAG_CASE);

        return $corsi;
    }

    /** L'ufficio di corso di studio collegato a Didacticus che segue quel corso di laurea (stessa regola delle pratiche), null se non c'è. */
    public function ufficioPerCorso(string $corso): ?int
    {
        $corso = mb_strtolower(trim($corso));
        if ($corso === '') {
            return null;
        }
        $uffici = $this->consigliDegliUffici();
        foreach ($uffici as $c) {
            if (in_array($corso, array_map('mb_strtolower', $c['corsi']), true)) {
                return $c['ufficio'];
            }
        }
        foreach ($uffici as $c) { // consigli senza i corsi indicati: il corso citato nel nome del consiglio
            if (!$c['corsi'] && preg_match('/' . preg_quote($corso, '/') . '(?![\p{L}])/u', mb_strtolower($c['nome']))) {
                return $c['ufficio'];
            }
        }

        return null;
    }

    /** @return list<array{ufficio: int, nome: string, corsi: list<string>}> */
    private function consigliDegliUffici(): array
    {
        $out = [];
        foreach ($this->db->righe("SELECT u.id, c.nome, c.corsi FROM uffici u JOIN didattica_consigli c ON c.id = u.consiglio_id WHERE u.didacticus = 1 AND u.attivo = 1 AND u.tipo = 'cdl' AND u.ambito = 'dipartimento' ORDER BY u.ordine, u.nome") as $r) {
            $corsi = array_values(array_filter(array_map(fn($x) => is_string($x) ? trim($x) : '', (array) json_decode((string) $r['corsi'], true))));
            $out[] = ['ufficio' => (int) $r['id'], 'nome' => (string) $r['nome'], 'corsi' => $corsi];
        }

        return $out;
    }

    public function nomeUfficio(?int $ufficioId): string
    {
        return $ufficioId ? (string) ($this->uffici->perId($ufficioId)['nome'] ?? '') : '';
    }

    /**
     * Gli indirizzi a cui avvisare di una nuova richiesta (dell'ufficio indicato, altrimenti dell'Assistenza).
     *
     * @return list<string>
     */
    public function emailAvvisi(?int $ufficioId = null): array
    {
        if ($ufficioId) {
            $e = $this->uffici->emailDelUfficio($ufficioId);
            if ($e) {
                return $e;
            }
        }
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
     * I corsi di laurea seguiti dalle persone dell'ufficio (come sono scritti nell'anagrafe degli uffici; Assistenza se non è indicato un ufficio): servono a
     * chiedere «a quale corso sei iscritto?». Vuoto se nessuno ha indicato i corsi: in quel caso Didacticus non fa la domanda e avvisa tutti.
     *
     * @return list<string>
     */
    public function corsiSeguiti(?int $ufficioId = null): array
    {
        $out = [];
        foreach ($this->personeUfficio($ufficioId) as $p) {
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
     * Gli indirizzi delle persone dell'ufficio che seguono un corso di laurea; se nessuno lo segue (o il corso non è indicato) quelli di tutto l'ufficio.
     *
     * @return list<string>
     */
    public function emailPerCorso(string $corso, ?int $ufficioId = null): array
    {
        $corso = trim($corso);
        $out = [];
        if ($corso !== '') {
            foreach ($this->personeUfficio($ufficioId) as $p) {
                $seguiti = array_map('trim', array_filter((array) json_decode((string) ($p['corsi'] ?? ''), true), 'is_string'));
                if (in_array($corso, $seguiti, true) && filter_var((string) $p['email'], FILTER_VALIDATE_EMAIL)) {
                    $out[strtolower((string) $p['email'])] = (string) $p['email'];
                }
            }
        }

        return $out ? array_values($out) : $this->emailAvvisi($ufficioId);
    }

    /** @return list<array<string, string|null>> */
    private function personeUfficio(?int $ufficioId = null): array
    {
        if ($ufficioId) {
            $u = $this->uffici->perId($ufficioId);

            return $u !== null && (int) $u['attivo'] === 1 ? $this->uffici->persone($ufficioId) : [];
        }
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
     * Le persone che possono ricevere una chat passata da un collega: [id utente => nome], dalle persone dell'ufficio (Assistenza se non è indicato) che hanno un
     * utente nel portale.
     *
     * @return array<int, string>
     */
    public function colleghi(?int $ufficioId = null): array
    {
        $out = [];
        foreach ($this->personeUfficio($ufficioId) as $p) {
            $id = (int) $this->db->valore('SELECT id FROM utenti WHERE LOWER(email) = ? LIMIT 1', [strtolower((string) $p['email'])]);
            if ($id > 0) {
                $out[$id] = (string) ($p['nominativo'] ?: $p['email']);
            }
        }

        return $out;
    }
}
