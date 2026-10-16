<?php

declare(strict_types=1);

namespace App\Ateneo;

use App\Anagrafi\ServizioPersone;

/**
 * Uffici di Ateneo (tabella uffici, ambito «ateneo»): li crea l'amministratore da Gestione del portale → Uffici e associa le persone scelte dall'anagrafe di Ateneo (mai a mano).
 * Le persone entrano nel portale con le credenziali Unical e vedono le pratiche che gli uffici ricevono (es. pagamenti del tutorato):
 * si riconoscono dall'email, come gli operatori dell'Ufficio didattico. Una persona può far parte di più uffici.
 */
final class ServizioUfficiAteneo
{
    public function __construct(private UfficioAteneoRepository $uffici, private ServizioPersone $persone)
    {
    }

    /** @return list<array<string, string|null>> */
    public function elenco(bool $soloAttivi = false): array
    {
        return $this->uffici->tutti($soloAttivi);
    }

    /** @return array<string, string|null>|null */
    public function ufficio(int $id): ?array
    {
        return $this->uffici->perId($id);
    }

    /** @return list<array<string, string|null>> */
    public function persone(int $ufficioId): array
    {
        return $this->uffici->persone($ufficioId);
    }

    /**
     * Crea (id = 0) o modifica un ufficio. Ritorna [id, errore].
     *
     * @return array{0: int, 1: string|null}
     */
    public function salva(int $id, string $nome, string $descrizione, bool $attivo = true): array
    {
        $nome = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $nome)), 0, 150);
        if ($nome === '') {
            return [$id, "Scrivi il nome dell'ufficio."];
        }
        if ($id > 0 && !$this->uffici->perId($id)) {
            return [$id, 'Ufficio non trovato.'];
        }

        return [$this->uffici->salva($id, $nome, mb_substr(trim($descrizione), 0, 500), $attivo), null];
    }

    /** Elimina un ufficio con le sue persone, se nessun bando lo usa. Ritorna l'errore o null. */
    public function elimina(int $id): ?string
    {
        if (!$this->uffici->perId($id)) {
            return 'Ufficio non trovato.';
        }
        if (($n = $this->uffici->bandiCheLoUsano($id)) > 0) {
            return "L'ufficio paga i tutor di $n " . ($n === 1 ? 'bando' : 'bandi') . ": cambia l'ufficio del bando prima di eliminarlo (oppure disattivalo).";
        }
        $this->uffici->elimina($id);

        return null;
    }

    /** Aggiunge una persona dall'anagrafe di Ateneo. Ritorna l'errore o null. */
    public function aggiungiDaAnagrafe(int $ufficioId, string $personaId): ?string
    {
        if (!$this->uffici->perId($ufficioId)) {
            return 'Ufficio non trovato.';
        }
        $p = $this->persone->persona($personaId);
        if (!$p) {
            return "Persona non trovata nell'anagrafe di Ateneo.";
        }
        $email = strtolower(trim((string) $p['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return "La persona scelta non ha un'email nell'anagrafe: aggiungila a mano scrivendo nome ed email.";
        }
        $ok = $this->uffici->aggiungiPersona($ufficioId, $personaId, $email, trim($p['cognome'] . ' ' . $p['nome']), mb_substr(trim((string) ($p['struttura'] ?? '')), 0, 255));

        return $ok ? null : 'La persona fa già parte di questo ufficio.';
    }

    public function togliPersona(int $id): void
    {
        $this->uffici->togliPersona($id);
    }

    /**
     * La persona (utente collegato) fa parte dell'ufficio: riconosciuta dall'email.
     *
     * @param array<string, mixed>|null $utente
     */
    public function utenteMembro(?array $utente, int $ufficioId): bool
    {
        return $utente && !empty($utente['id']) && $this->uffici->membro($ufficioId, strtolower(trim((string) ($utente['email'] ?? ''))));
    }

    /**
     * Uffici attivi della persona collegata.
     *
     * @param array<string, mixed>|null $utente
     * @return list<array<string, string|null>>
     */
    public function ufficiDellUtente(?array $utente): array
    {
        return $utente && !empty($utente['id']) ? $this->uffici->dellaPersona((string) ($utente['email'] ?? '')) : [];
    }

    /**
     * Email delle persone dell'ufficio (per gli avvisi).
     *
     * @return list<string>
     */
    public function emailDelUfficio(int $ufficioId): array
    {
        $e = [];
        foreach ($this->uffici->persone($ufficioId) as $p) {
            if (filter_var((string) $p['email'], FILTER_VALIDATE_EMAIL)) {
                $e[strtolower((string) $p['email'])] = (string) $p['email'];
            }
        }

        return array_values($e);
    }
}
