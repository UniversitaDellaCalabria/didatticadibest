<?php

declare(strict_types=1);

namespace App\Auth;

use App\Infrastructure\Audit\AuditLog;

/**
 * «Entra come…»: l'amministratore vede il portale come lo vede un'altra persona (studente, docente, scuola, azienda, Direttore…) per dare indicazioni precise.
 *
 * Come funziona: le chiavi di identità della sessione (utente_id, ruolo, nome, email…) prendono i valori della persona scelta, letti dal database; quelli veri
 * dell'amministratore restano in $_SESSION['simula']['origine'] e tornano con «Torna a te». Il resto del portale non sa niente: legge sempre le stesse chiavi.
 * La simulazione è in SOLA LETTURA (la guardia inc/simulazione_guardia.php, caricata da config.php, blocca ogni richiesta che modifica qualcosa), dura al massimo
 * un'ora, non si può fare verso un altro amministratore né da dentro un'altra simulazione, e inizio e fine sono nel registro delle operazioni.
 */
final class SimulazioneAccesso
{
    public const CHIAVE = 'simula';
    public const DURATA_SECONDI = 3600;
    /** Chiavi di identità che prendono i valori della persona simulata. */
    public const CHIAVI_IDENTITA = ['utente_id', 'utente_cf', 'utente_nome', 'utente_email', 'utente_ruolo_id', 'utente_ruoli_secondari', 'auth_meta'];

    public function __construct(private Sessione $sessione, private UtenteRepository $utenti, private AuditLog $registro)
    {
    }

    /** Si sta guardando il portale come un'altra persona (e la simulazione non è scaduta). */
    public function attiva(): bool
    {
        $s = $this->sessione->leggi(self::CHIAVE);

        return is_array($s) && !empty($s['origine']) && (int) ($s['fino'] ?? 0) >= time();
    }

    /** @return array{target_id: int, nome: string, profili: string, fino: int}|null */
    public function stato(): ?array
    {
        $s = $this->sessione->leggi(self::CHIAVE);

        return $this->attiva() ? ['target_id' => (int) $s['target_id'], 'nome' => (string) $s['nome'], 'profili' => (string) ($s['profili'] ?? ''), 'fino' => (int) $s['fino']] : null;
    }

    /** L'amministratore vero della sessione (anche durante una simulazione). */
    public function idAmministratore(): int
    {
        $s = $this->sessione->leggi(self::CHIAVE);

        return is_array($s) && !empty($s['origine']) ? (int) ($s['origine']['utente_id'] ?? 0) : (int) $this->sessione->leggi('utente_id');
    }

    /** @param array<string, mixed> $utente riga di utenti */
    public static function eAmministratore(array $utente): bool
    {
        return (int) ($utente['ruolo_id'] ?? 0) === 1 || in_array('1', array_map('trim', explode(',', (string) ($utente['ruoli_secondari'] ?? ''))), true);
    }

    /** Può simulare solo un amministratore vero, che non sta già simulando. */
    public function puoSimulare(): bool
    {
        if ($this->sessione->leggi(self::CHIAVE) !== null) {
            return false;
        }
        $ruoli = array_map('trim', explode(',', (string) ($this->sessione->leggi('utente_ruoli_secondari') ?? '')));

        return (int) $this->sessione->leggi('utente_id') > 0 && ((int) $this->sessione->leggi('utente_ruolo_id') === 1 || in_array('1', $ruoli, true));
    }

    /**
     * Comincia a vedere il portale come la persona scelta.
     *
     * @param string $profili i profili della persona (testo per la barra)
     * @return string|null il motivo per cui non si può, null se è partita
     */
    public function avvia(int $targetId, string $profili, string $ip): ?string
    {
        if (!$this->puoSimulare()) {
            return 'Solo un amministratore può vedere il portale come un\'altra persona, e non da dentro un\'altra simulazione.';
        }
        $adminId = (int) $this->sessione->leggi('utente_id');
        $t = $this->utenti->perId($targetId);
        if ($t === null || $targetId === $adminId) {
            return 'Persona non trovata.';
        }
        if (self::eAmministratore($t)) {
            return 'Un altro amministratore non si può simulare.';
        }
        $origine = [];
        foreach (self::CHIAVI_IDENTITA as $k) {
            $origine[$k] = $this->sessione->leggi($k);
        }
        $nome = trim((string) ($t['nome'] ?? '') . ' ' . (string) ($t['cognome'] ?? ''));
        $this->registro->registra($adminId, 'Simulazione di accesso: inizio', ['Come' => $nome, 'Utente' => $targetId, 'Profili' => $profili], $ip);
        $this->sessione->scrivi(self::CHIAVE, ['origine' => $origine, 'target_id' => $targetId, 'nome' => $nome, 'profili' => $profili, 'fino' => time() + self::DURATA_SECONDI]);
        $this->sessione->scrivi('utente_id', $targetId);
        $this->sessione->scrivi('utente_cf', (string) ($t['codice_fiscale'] ?? ''));
        $this->sessione->scrivi('utente_nome', $nome);
        $this->sessione->scrivi('utente_email', (string) ($t['email'] ?? ''));
        $this->sessione->scrivi('utente_ruolo_id', (int) ($t['ruolo_id'] ?? 5));
        $this->sessione->scrivi('utente_ruoli_secondari', (string) ($t['ruoli_secondari'] ?? ''));
        $this->sessione->togli('auth_meta');

        return null;
    }

    /** «Torna a te»: rimette l'identità vera. */
    public function termina(string $ip): void
    {
        $s = $this->sessione->leggi(self::CHIAVE);
        if (!is_array($s)) {
            return;
        }
        foreach ((array) ($s['origine'] ?? []) as $k => $v) {
            $v === null ? $this->sessione->togli((string) $k) : $this->sessione->scrivi((string) $k, $v);
        }
        $this->sessione->togli(self::CHIAVE);
        $adminId = (int) $this->sessione->leggi('utente_id');
        $this->registro->registra($adminId, 'Simulazione di accesso: fine', ['Come' => (string) ($s['nome'] ?? ''), 'Utente' => (int) ($s['target_id'] ?? 0)], $ip);
    }
}
