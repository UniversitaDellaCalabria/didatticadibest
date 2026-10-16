<?php

declare(strict_types=1);

namespace App\Uffici;

use App\Core\Database;
use App\Core\Orologio;

/**
 * La Direzione del Dipartimento: il Direttore e il vice direttore sono persone dell'ufficio «direzione» (Gestione del portale → Uffici), scelte
 * dall'anagrafe di Ateneo. Hanno gli stessi permessi (cruscotto, firme), ma i link di firma e gli avvisi vanno al Direttore finché non è attivo
 * l'interruttore «Direttore assente»: allora passano al vice, fino a quando l'interruttore si spegne (o passa la data di rientro, se indicata).
 * Lo possono attivare il Direttore, il vice, la segreteria di direzione e gli amministratori.
 */
final class Direzione
{
    public const DIRETTORE = 'Direttore';
    public const VICE = 'Vice direttore';

    public function __construct(private Database $db, private UfficiRepository $uffici, private Orologio $orologio)
    {
    }

    /** @return array<string, string|null>|null */
    public function ufficio(): ?array
    {
        return $this->uffici->perChiave('direzione');
    }

    /** @return array<string, string|null>|null */
    public function direttore(): ?array
    {
        return $this->conRuolo(false);
    }

    /** @return array<string, string|null>|null */
    public function vice(): ?array
    {
        return $this->conRuolo(true);
    }

    /**
     * Lo stato dell'interruttore «Direttore assente». «attiva» tiene conto della data di rientro: passata quella, l'assenza non vale più.
     *
     * @return array{attiva: bool, impostata: bool, fino: ?string, da: string, il: ?string}
     */
    public function assenza(): array
    {
        $r = $this->db->riga('SELECT direttore_assente, direttore_assente_fino, direttore_assente_da, direttore_assente_il FROM impostazioni_sistema WHERE id = 1') ?: [];
        $impostata = (int) ($r['direttore_assente'] ?? 0) === 1;
        $fino = !empty($r['direttore_assente_fino']) ? (string) $r['direttore_assente_fino'] : null;
        $attiva = $impostata && ($fino === null || $fino >= $this->orologio->adesso()->format('Y-m-d'));

        return ['attiva' => $attiva, 'impostata' => $impostata, 'fino' => $fino, 'da' => (string) ($r['direttore_assente_da'] ?? ''), 'il' => !empty($r['direttore_assente_il']) ? (string) $r['direttore_assente_il'] : null];
    }

    /** Chi ha in carico firme, link e avvisi ora: il vice se il Direttore è assente (e c'è un vice), altrimenti il Direttore. @return array<string, string|null>|null */
    public function inCarica(): ?array
    {
        $vice = $this->assenza()['attiva'] ? $this->vice() : null;

        return $vice ?? $this->direttore();
    }

    /**
     * Chi firma, nella forma che usano convenzioni e pratiche: nome, email e codice fiscale (quello dell'utente collegato con la stessa email: serve al controllo del certificato).
     *
     * @return array{nome: string, email: string, cf: string}
     */
    public function chiFirma(): array
    {
        $p = $this->inCarica();
        if (!$p) {
            return ['nome' => '', 'email' => '', 'cf' => ''];
        }
        $cf = (string) ($this->db->valore("SELECT codice_fiscale FROM utenti WHERE LOWER(email) = ? AND COALESCE(codice_fiscale, '') <> '' LIMIT 1", [strtolower((string) $p['email'])]) ?? '');

        return ['nome' => (string) $p['nominativo'], 'email' => (string) $p['email'], 'cf' => strtoupper($cf)];
    }

    /**
     * L'utente è il Direttore o il vice (stessi permessi, anche se il vice non è «in carica»). Per il cruscotto.
     *
     * @param array<string, mixed>|null $utente
     */
    public function direttoreOVice(?array $utente): bool
    {
        $email = strtolower(trim((string) ($utente['email'] ?? '')));
        if ($email === '') {
            return false;
        }
        foreach ([$this->direttore(), $this->vice()] as $p) {
            if ($p && strtolower((string) $p['email']) === $email) {
                return true;
            }
        }

        return false;
    }

    /**
     * Può firmare ora: la persona in carica, riconosciuta dall'email o dal codice fiscale.
     *
     * @param array<string, mixed>|null $utente
     */
    public function puoFirmare(?array $utente): bool
    {
        if (!$utente || empty($utente['id'])) {
            return false;
        }
        $d = $this->chiFirma();
        $email = strtolower(trim((string) ($utente['email'] ?? '')));
        $cf = strtoupper(trim((string) ($utente['codice_fiscale'] ?? '')));

        return ($email !== '' && $email === strtolower($d['email'])) || ($cf !== '' && $d['cf'] !== '' && $cf === $d['cf']);
    }

    /**
     * Chi può attivare o spegnere «Direttore assente»: il Direttore, il vice, la segreteria di direzione e gli amministratori.
     *
     * @param array<string, mixed>|null $utente
     */
    public function puoImpostareAssenza(?array $utente): bool
    {
        if (!$utente || empty($utente['id'])) {
            return false;
        }
        if ((int) ($utente['ruolo_id'] ?? 0) === 1 || in_array('1', explode(',', (string) ($utente['ruoli_secondari'] ?? '')), true)) {
            return true;
        }
        if ($this->direttoreOVice($utente)) {
            return true;
        }
        $seg = $this->uffici->perChiave('segreteria_direzione');

        return $seg !== null && $this->uffici->membro((int) $seg['id'], (string) ($utente['email'] ?? ''));
    }

    /**
     * Attiva o spegne «Direttore assente». $fino = data di rientro (facoltativa), $da = chi lo ha fatto. Ritorna l'errore o null.
     */
    public function imposta(bool $attiva, ?string $fino, string $da): ?string
    {
        if ($attiva && !$this->vice()) {
            return 'Non c\'è un vice direttore: aggiungilo prima alla Direzione (Gestione del portale → Uffici) con il ruolo «Vice direttore».';
        }
        $fino = $fino !== null && $fino !== '' ? $fino : null;
        if ($fino !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fino) || $fino < $this->orologio->adesso()->format('Y-m-d'))) {
            return 'La data di rientro non è valida (deve essere oggi o dopo).';
        }
        $this->db->esegui(
            'UPDATE impostazioni_sistema SET direttore_assente = ?, direttore_assente_fino = ?, direttore_assente_da = ?, direttore_assente_il = NOW() WHERE id = 1',
            [$attiva ? 1 : 0, $attiva ? $fino : null, mb_substr($da, 0, 150)]
        );

        return null;
    }

    /** @return array<string, string|null>|null */
    private function conRuolo(bool $vice): ?array
    {
        $u = $this->ufficio();
        if (!$u) {
            return null;
        }
        foreach ($this->uffici->persone((int) $u['id']) as $p) {
            $ruolo = mb_strtolower(trim((string) $p['ruolo']));
            if ($vice ? str_starts_with($ruolo, 'vice') : $ruolo === 'direttore') {
                return $p;
            }
        }

        return null;
    }
}
