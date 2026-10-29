<?php

declare(strict_types=1);

namespace App\Uffici;

use App\Anagrafi\ServizioPersone;
use App\Core\Database;
use App\Didattica\Costanti as CostantiDidattica;
use App\Didattica\ServizioUffici as ServizioUfficiDidattica;

/**
 * Gli uffici del Dipartimento e di Ateneo: li crea l'amministratore (Gestione del portale → Uffici) e vi inserisce le persone, sempre scelte
 * dall'anagrafe di Ateneo. Da qui pratiche, tutorato, prenotazioni e convenzioni prendono i destinatari. Le regole di sicurezza (uffici fissi,
 * cosa impedisce l'eliminazione) stanno in questa classe.
 */
final class ServizioUffici
{
    /** Ruoli proposti nella Direzione (il Direttore e il vice hanno un significato per il portale). */
    public const RUOLI_DIREZIONE = [Direzione::DIRETTORE, Direzione::VICE];

    public function __construct(
        private UfficiRepository $uffici,
        private ServizioPersone $persone,
        private ServizioUfficiDidattica $didattica,
        private Database $db
    ) {
    }

    /** @return list<array<string, string|null>> */
    public function elenco(?string $ambito = null, bool $soloAttivi = false): array
    {
        return $this->uffici->tutti($ambito, $soloAttivi);
    }

    /** @return array<string, string|null>|null */
    public function ufficio(int $id): ?array
    {
        return $this->uffici->perId($id);
    }

    /** @return array<string, string|null>|null */
    public function perChiave(string $chiave): ?array
    {
        return $this->uffici->perChiave($chiave);
    }

    /** @return list<array<string, string|null>> */
    public function persone(int $ufficioId): array
    {
        return $this->uffici->persone($ufficioId);
    }

    public static function fisso(array $u): bool
    {
        return in_array((string) ($u['chiave'] ?? ''), UfficiRepository::CHIAVI_FISSE, true);
    }

    /** L'ufficio ha un ruolo nel flusso delle pratiche (tipo, smista, segue i corsi): chi ci entra lavora le pratiche. */
    public static function nelFlusso(array $u): bool
    {
        return ($u['ambito'] ?? '') === UfficiRepository::DIPARTIMENTO && !self::fisso($u)
            && ((string) ($u['tipo'] ?? '') !== '' || (int) ($u['smista'] ?? 0) === 1 || (int) ($u['segue_corsi'] ?? 0) === 1 || (string) ($u['chiave'] ?? '') !== '');
    }

    /**
     * Crea (id = 0) o modifica un ufficio. Ritorna [id, errore].
     *
     * @return array{0: int, 1: string|null}
     */
    /**
     * Collega l'ufficio a Didacticus: le chat che l'assistente non riesce a chiudere, sugli argomenti scelti, arrivano alle sue persone. Vale solo per gli uffici
     * del Dipartimento che non sono fissi (l'Assistenza è già la destinazione di partenza).
     *
     * @param list<string> $argomenti chiavi degli argomenti dell'assistenza
     */
    public function collegaDidacticus(int $id, bool $collegato, array $argomenti): ?string
    {
        $u = $this->uffici->perId($id);
        if (!$u) {
            return 'Ufficio non trovato.';
        }
        if (self::fisso($u) || (string) $u['ambito'] !== UfficiRepository::DIPARTIMENTO) {
            return null;
        }
        $validi = array_keys(\App\Assistenza\Costanti::argomenti());
        $scelti = array_values(array_unique(array_filter($argomenti, fn($a) => is_string($a) && in_array($a, $validi, true))));
        $this->uffici->impostaDidacticus($id, $collegato, implode(',', $scelti));

        return null;
    }

    public function salva(int $id, string $ambito, string $nome, string $descrizione, bool $attivo, int $ordine = 0, string $tipo = '', ?int $consiglioId = null, bool $smista = false, bool $segueCorsi = false): array
    {
        $nome = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $nome)), 0, 150);
        if ($nome === '') {
            return [$id, "Scrivi il nome dell'ufficio."];
        }
        $esistente = $id > 0 ? $this->uffici->perId($id) : null;
        if ($id > 0 && !$esistente) {
            return [$id, 'Ufficio non trovato.'];
        }
        $ambito = $esistente ? (string) $esistente['ambito'] : (in_array($ambito, [UfficiRepository::DIPARTIMENTO, UfficiRepository::ATENEO], true) ? $ambito : UfficiRepository::DIPARTIMENTO);
        if ($esistente && self::fisso($esistente)) {
            $attivo = true;
            $tipo = '';
            $consiglioId = null;
            $smista = $segueCorsi = false;
        } elseif ($ambito === UfficiRepository::ATENEO) {
            $tipo = '';
            $consiglioId = null;
            $smista = $segueCorsi = false;
        } else {
            $tipo = isset(CostantiDidattica::TIPI_UFFICIO[$tipo]) ? $tipo : '';
            if ($tipo === 'cdl') {
                $segueCorsi = true;
                if (!$consiglioId || !(int) $this->db->valore('SELECT COUNT(*) FROM didattica_consigli WHERE id = ?', [$consiglioId])) {
                    return [$id, 'Un ufficio di corso di studio segue i corsi di un consiglio: scegli il consiglio.'];
                }
            } else {
                $consiglioId = null;
            }
        }

        return [$this->uffici->salva($id, $ambito, $nome, mb_substr(trim($descrizione), 0, 500), $attivo, $ordine, $tipo, $consiglioId, $smista, $segueCorsi), null];
    }

    /**
     * Cosa tiene occupato l'ufficio, per dirlo a chi vuole eliminarlo.
     *
     * @return array{moduli: int, pratiche: int, bandi: int, risorse: int}
     */
    public function uso(int $id): array
    {
        return [
            'moduli' => $this->didattica->usoUfficio($id)['moduli'],
            'pratiche' => (int) $this->db->valore("SELECT COUNT(*) FROM pratiche WHERE ufficio_id = ? AND stato NOT IN ('bozza', 'accolta', 'respinta', 'chiusa')", [$id]),
            'bandi' => (int) $this->db->valore('SELECT COUNT(*) FROM tutorato_bandi WHERE ufficio_ateneo_id = ?', [$id]),
            'risorse' => (int) $this->db->valore('SELECT COUNT(*) FROM risorse_uffici WHERE ufficio_id = ?', [$id]),
        ];
    }

    /** Elimina un ufficio con le sue persone, se non è fisso e nulla lo usa. Ritorna l'errore o null. */
    public function elimina(int $id): ?string
    {
        $u = $this->uffici->perId($id);
        if (!$u) {
            return 'Ufficio non trovato.';
        }
        if (self::fisso($u)) {
            return "Quest'ufficio è fisso (serve al portale): si possono cambiare le persone, non eliminarlo.";
        }
        $uso = $this->uso($id);
        $motivi = [];
        if ($uso['moduli']) {
            $motivi[] = "è nell'iter di {$uso['moduli']} " . ($uso['moduli'] === 1 ? 'modulo' : 'moduli');
        }
        if ($uso['pratiche']) {
            $motivi[] = "ha {$uso['pratiche']} " . ($uso['pratiche'] === 1 ? 'pratica' : 'pratiche') . ' in corso';
        }
        if ($uso['bandi']) {
            $motivi[] = "paga i tutor di {$uso['bandi']} " . ($uso['bandi'] === 1 ? 'bando' : 'bandi');
        }
        if ($uso['risorse']) {
            $motivi[] = "è responsabile di {$uso['risorse']} " . ($uso['risorse'] === 1 ? 'risorsa' : 'risorse');
        }
        if ($motivi) {
            return "L'ufficio non si può eliminare: " . implode(', ', $motivi) . '. Cambia prima dove è usato (oppure disattivalo).';
        }
        $this->uffici->elimina($id);

        return null;
    }

    /**
     * Compiti proposti a chi entra in un ufficio: nei flussi delle pratiche si lavorano pratiche, sedute e ricevimento; negli altri nessuno
     * (essere in un ufficio non apre il pannello Didattica).
     *
     * @param array<string, mixed> $u
     * @return list<string>
     */
    public static function compitiPredefiniti(array $u): array
    {
        return self::nelFlusso($u) ? ['pratiche', 'sedute', 'ricevimento'] : [];
    }

    /**
     * Aggiunge una persona scelta dall'anagrafe di Ateneo. Ritorna l'errore o null.
     *
     * @param list<string>|null $compiti null = quelli proposti per quell'ufficio
     * @param list<string> $corsi corsi di studio seguiti (uffici che seguono i corsi)
     */
    public function aggiungiDaAnagrafe(int $ufficioId, string $personaId, string $ruolo = '', ?array $compiti = null, array $corsi = []): ?string
    {
        $u = $this->uffici->perId($ufficioId);
        if (!$u) {
            return 'Ufficio non trovato.';
        }
        $p = $this->persone->persona($personaId);
        if (!$p) {
            return "Persona non trovata nell'anagrafe di Ateneo: le persone si scelgono sempre dall'anagrafe.";
        }
        $email = strtolower(trim((string) $p['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return "La persona scelta non ha un'email nell'anagrafe di Ateneo: aggiornala dall'anagrafe prima di inserirla.";
        }
        if (self::fisso($u) && (string) $u['chiave'] === 'direzione' && $ruolo !== '' && in_array($ruolo, self::RUOLI_DIREZIONE, true)) {
            foreach ($this->uffici->persone($ufficioId) as $x) {
                if (mb_strtolower((string) $x['ruolo']) === mb_strtolower($ruolo) && strtolower((string) $x['email']) !== $email) {
                    return "C'è già un «{$ruolo}» nella Direzione: toglilo prima (c'è un solo " . mb_strtolower($ruolo) . ').';
                }
            }
        }
        $comp = $this->compiti($compiti ?? self::compitiPredefiniti($u));
        $ok = $this->uffici->aggiungiPersona($ufficioId, $personaId, $email, trim($p['cognome'] . ' ' . $p['nome']), mb_substr(trim((string) ($p['struttura'] ?? '')), 0, 255),
            mb_substr(trim($ruolo), 0, 100), $comp, $this->corsiJson($corsi));

        return $ok ? null : 'La persona fa già parte di questo ufficio.';
    }

    /** Cambia ruolo, compiti e corsi seguiti di una persona dell'ufficio. Ritorna l'errore o null. @param list<string> $compiti @param list<string> $corsi */
    public function aggiornaPersona(int $id, string $ruolo, array $compiti, array $corsi): ?string
    {
        $r = $this->uffici->persona($id);
        if (!$r) {
            return 'Persona non trovata.';
        }
        $u = $this->uffici->perId((int) $r['ufficio_id']);
        $ruolo = mb_substr(trim($ruolo), 0, 100);
        if ($u && (string) $u['chiave'] === 'direzione' && in_array($ruolo, self::RUOLI_DIREZIONE, true)) {
            foreach ($this->uffici->persone((int) $u['id']) as $x) {
                if ((int) $x['id'] !== $id && mb_strtolower((string) $x['ruolo']) === mb_strtolower($ruolo)) {
                    return "C'è già un «{$ruolo}» nella Direzione: c'è un solo " . mb_strtolower($ruolo) . '.';
                }
            }
        }
        $this->uffici->aggiornaPersona($id, $ruolo, $u && self::fisso($u) ? '' : $this->compiti($compiti), $this->corsiJson($corsi));

        return null;
    }

    public function togliPersona(int $id): void
    {
        $this->uffici->togliPersona($id);
    }

    /**
     * Email delle persone dell'ufficio (indicato per id o per chiave), per gli avvisi. Vuoto se l'ufficio non esiste.
     *
     * @return list<string>
     */
    public function emailDelUfficio(int|string $ufficio): array
    {
        $u = is_int($ufficio) ? $this->uffici->perId($ufficio) : $this->uffici->perChiave($ufficio);

        return $u ? $this->uffici->emailDelUfficio((int) $u['id']) : [];
    }

    /**
     * L'utente collegato fa parte dell'ufficio (per id o chiave): riconosciuto dall'email.
     *
     * @param array<string, mixed>|null $utente
     */
    public function utenteMembro(?array $utente, int|string $ufficio): bool
    {
        $u = is_int($ufficio) ? $this->uffici->perId($ufficio) : $this->uffici->perChiave($ufficio);

        return $u !== null && $utente && !empty($utente['id']) && $this->uffici->membro((int) $u['id'], (string) ($utente['email'] ?? ''));
    }

    /** @param list<string> $compiti */
    private function compiti(array $compiti): string
    {
        return implode(',', array_values(array_intersect(array_keys(CostantiDidattica::COMPITI_UFFICIO), $compiti)));
    }

    /** @param list<string> $corsi */
    private function corsiJson(array $corsi): ?string
    {
        $c = array_values(array_unique(array_filter(array_map('trim', $corsi))));

        return $c ? (string) json_encode($c, JSON_UNESCAPED_UNICODE) : null;
    }
}
