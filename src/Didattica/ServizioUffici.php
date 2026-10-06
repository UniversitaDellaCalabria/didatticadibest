<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Anagrafi\ServizioPersone;
use App\Auth\Abilitazioni\ServizioAbilitazioni;
use App\Auth\ServizioUtenti;
use App\Core\Database;
use App\Eventi\Righe;

/** Ufficio didattico: operatori e chi li riconosce tra gli utenti, avvisi agli uffici e sportelli di ricevimento. */
final class ServizioUffici
{
    public function __construct(
        private Database $db,
        private UfficioRepository $uffici,
        private OperatoreRepository $operatori,
        private ServizioPersone $persone,
        private ServizioAbilitazioni $abilitazioni,
        private ServizioUtenti $utenti
    ) {
    }

    /**
     * Operatori dell'Ufficio didattico (facoltativo: solo chi ha quel compito); «_compiti» è l'elenco dei compiti.
     *
     * @return list<array<string, mixed>>
     */
    public function operatori(?string $compito = null): array
    {
        $out = [];
        foreach ($this->operatori->tutti() as $x) {
            $x['_compiti'] = array_filter(explode(',', (string) $x['compiti']));
            if ($compito === null || in_array($compito, $x['_compiti'], true)) {
                $out[] = $x;
            }
        }

        return $out;
    }

    /**
     * L'utente è un operatore dell'Ufficio didattico (riconosciuto dall'email o dalla scheda dell'anagrafe).
     *
     * @param array<string, mixed>|null $u
     */
    public function utenteOperatore(?array $u, ?string $compito = null): bool
    {
        if (!$u || empty($u['id'])) {
            return false;
        }
        $email = strtolower(trim((string) ($u['email'] ?? '')));
        $pid = (string) ($u['persona_id'] ?? '');
        foreach ($this->operatori($compito) as $o) {
            if (($email !== '' && strtolower((string) $o['email']) === $email) || ($pid !== '' && (string) $o['persona_id'] === $pid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Amministratori, abilitati al modulo Didattica e operatori dell'Ufficio didattico.
     *
     * @param array<string, mixed>|null $u
     */
    public function gestisce(?array $u): bool
    {
        if (!$u || empty($u['id'])) {
            return false;
        }
        $sec = explode(',', (string) ($u['ruoli_secondari'] ?? ''));

        return (int) ($u['ruolo_id'] ?? 5) === 1 || in_array('1', $sec, true) || $this->abilitazioni->haModulo((int) $u['id'], 'didattica') || $this->utenteOperatore($u);
    }

    /**
     * Aggiunge (o aggiorna) un operatore scelto dall'anagrafe di Ateneo. Ritorna un messaggio di errore o null.
     * $ufficioId: ufficio dell'Ufficio didattico; $corsi: corsi di studio seguiti (uffici che seguono i corsi).
     *
     * @param list<string> $compiti
     * @param list<string> $corsi
     */
    public function aggiungi(string $personaId, string $ruolo, array $compiti, int $ufficioId = 0, array $corsi = []): ?string
    {
        $p = $this->persone->persona($personaId);
        if (!$p) {
            return "Persona non trovata nell'anagrafe di Ateneo.";
        }
        $email = strtolower(trim((string) $p['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return "La persona scelta non ha un'email nell'anagrafe.";
        }
        $nom = trim($p['cognome'] . ' ' . $p['nome']);
        $comp = implode(',', array_values(array_intersect(array_keys(Costanti::COMPITI_UFFICIO), $compiti)));
        $ruolo = mb_substr(trim($ruolo), 0, 100);
        $uffId = $this->uffici->idDa($ufficioId);
        $corsiJson = $corsi ? (string) json_encode(array_values(array_unique(array_filter(array_map('trim', $corsi)))), JSON_UNESCAPED_UNICODE) : null;

        return $this->operatori->salva($personaId, $email, $nom, $ruolo, $comp, $uffId, $corsiJson) ? null : 'Salvataggio non riuscito.';
    }

    /**
     * Riga dell'operatore per id, oppure (con $u) l'operatore corrispondente all'utente.
     *
     * @param array<string, mixed>|null $u
     * @return array<string, mixed>|null
     */
    public function operatore(int $id = 0, ?array $u = null): ?array
    {
        foreach ($this->operatori() as $o) {
            if ($id && (int) $o['id'] === $id) {
                return $o;
            }
            if ($u && ((strtolower((string) $o['email']) === strtolower(trim((string) ($u['email'] ?? ''))) && $o['email'] !== '') || ((string) ($u['persona_id'] ?? '') !== '' && (string) $o['persona_id'] === (string) $u['persona_id']))) {
                return $o;
            }
        }

        return null;
    }

    /** @param array<string, mixed>|null $o */
    public function etichetta(?array $o): string
    {
        return $o ? $o['nominativo'] . (($n = $this->nomeUfficio($o)) !== '' ? ' · ' . $n : '') : '';
    }

    /** @param array<string, mixed>|null $o */
    public function nomeUfficio(?array $o): string
    {
        return ($o && !empty($o['ufficio_id'])) ? (string) ($this->uffici->tutti()[(int) $o['ufficio_id']]['nome'] ?? '') : '';
    }

    /**
     * Chi firma le azioni dell'ufficio: «Cognome Nome · Ufficio» se è un operatore, altrimenti nome e «Ufficio didattico».
     *
     * @param array<string, mixed>|null $u
     */
    public function nomeAutore(?array $u): string
    {
        $o = $u ? $this->operatore(0, $u) : null;

        return $o ? $this->etichetta($o) : trim(($u['nome'] ?? '') . ' ' . ($u['cognome'] ?? '')) . ' · Ufficio didattico';
    }

    /**
     * Email di chi riceve gli avvisi: quelle del modulo, altrimenti gli operatori con quel compito, altrimenti gli amministratori.
     *
     * @return list<string>
     */
    public function emailUfficio(string $emailModulo = '', string $compito = 'pratiche'): array
    {
        $e = array_filter(array_map('trim', preg_split('/[,;\s]+/', $emailModulo) ?: []), fn (string $x): bool => (bool) filter_var($x, FILTER_VALIDATE_EMAIL));
        if (!$e) {
            $e = array_column($this->operatori($compito), 'email');
        }
        if (!$e) {
            $e = $this->utenti->emailAmministratori();
        }

        return array_values(array_unique($e));
    }

    /**
     * Sportelli di ricevimento che l'utente gestisce: il proprio (docente, dall'anagrafe) e quelli dell'Ufficio didattico se ne è operatore.
     *
     * @param array<string, mixed>|null $u
     * @return list<array<string, mixed>>
     */
    public function sportelliUtente(?array $u): array
    {
        if (!$u || empty($u['id'])) {
            return [];
        }
        $pid = (string) ($u['persona_id'] ?? '');
        $uff = $this->utenteOperatore($u, 'ricevimento') || $this->utenteOperatore($u, null) && !$this->operatori('ricevimento');
        $where = [];
        $par = [];
        if ($pid !== '') {
            $where[] = 'r.persona_id = ?';
            $par[] = $pid;
        }
        if ($uff) {
            $where[] = "r.ufficio = 'didattica'";
        }
        if (!$where) {
            return [];
        }

        return $this->db->righe('SELECT r.*, p.titolo AS area_titolo, p.slug AS area_slug FROM risorse r JOIN pagine_eventi p ON p.id = r.pagina_id WHERE ' . implode(' OR ', $where) . ' ORDER BY r.ufficio DESC, r.nome', $par);
    }

    /** @return list<array<string, string|null>> */
    public function sportelli(bool $soloAttivi = false): array
    {
        return Righe::testo($this->db->righe("SELECT r.*, p.titolo AS area_titolo, p.slug AS area_slug FROM risorse r JOIN pagine_eventi p ON p.id = r.pagina_id WHERE r.ufficio = 'didattica'" . ($soloAttivi ? ' AND r.attiva = 1' : '') . ' ORDER BY r.nome'));
    }

    /** Sportello di ricevimento dell'Ufficio didattico in un'area di Prenotazioni e risorse. Ritorna l'id. */
    public function creaSportello(int $paginaId, string $nome, string $luogo): int
    {
        $email = implode(', ', $this->emailUfficio('', 'ricevimento'));
        $nome = mb_substr(trim($nome) ?: 'Ufficio didattico – ricevimento studenti', 0, 150);
        $luogo = mb_substr(trim($luogo), 0, 255);

        return $this->db->inserisci(
            "INSERT INTO risorse (pagina_id, nome, tipo, luogo, referente, email_notifiche, durata_slot, max_slot, anticipo_ore, max_giorni, accesso, approvazione, chiede_motivo, attiva, ufficio)
             VALUES (?, ?, 'sportello', ?, 'Ufficio didattico', ?, 15, 1, 12, 30, 'tutti', 0, 1, 1, 'didattica')",
            [$paginaId, $nome, $luogo, $email]
        );
    }

    /**
     * Crea o modifica un ufficio. Il tipo è quello del flusso delle pratiche (protocollo, ufficio di corso, segreteria); un ufficio
     * di corso di studio segue sempre i corsi.
     */
    public function salvaUfficio(int $id, string $nome, string $descrizione, bool $smista, bool $segueCorsi, int $ordine, string $tipo, ?int $consiglioId): void
    {
        $this->uffici->salva($id, $nome, $descrizione, $smista ? 1 : 0, $segueCorsi ? 1 : 0, $ordine, $tipo, $consiglioId);
    }

    /**
     * Cosa tiene occupato un ufficio: persone che ci lavorano e moduli che lo hanno nell'iter (con quelle non si può eliminare).
     *
     * @return array{operatori: int, moduli: int}
     */
    public function usoUfficio(int $id): array
    {
        $inIter = 0;
        foreach (Righe::testo($this->db->righe('SELECT iter_json FROM didattica_moduli WHERE iter_json IS NOT NULL')) as $mi) {
            foreach (json_decode((string) $mi['iter_json'], true) ?: [] as $x) {
                if ($this->uffici->idDa($x) === $id) {
                    $inIter++;
                }
            }
        }

        return ['operatori' => $this->operatori->contaDelloUfficio($id), 'moduli' => $inIter];
    }

    public function eliminaUfficio(int $id): void
    {
        $this->uffici->elimina($id);
    }

    public function togliOperatore(int $id): void
    {
        $this->operatori->togli($id);
    }
}
