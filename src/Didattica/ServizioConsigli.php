<?php

declare(strict_types=1);

namespace App\Didattica;

use App\Anagrafi\ServizioPersone;

/**
 * Consigli dei corsi di studio: l'Ufficio didattico sceglie i referenti di ogni consiglio; i referenti inseriscono una volta sola
 * i componenti (docenti dall'anagrafe, rappresentanti scritti a mano).
 */
final class ServizioConsigli
{
    public function __construct(private ConsiglioRepository $consigli, private ServizioPersone $persone)
    {
    }

    /** @return array<int, array<string, string|null>> */
    public function tutti(bool $soloAttivi = false): array
    {
        return $this->consigli->tutti($soloAttivi);
    }

    /** @return array<string, mixed>|null */
    public function consiglio(int $id): ?array
    {
        return $this->consigli->perId($id);
    }

    /** @return list<array<string, mixed>> componenti (ordinati per qualifica come nel verbale) o referenti */
    public function persone(int $cid, string $ruolo = 'componente'): array
    {
        return $this->consigli->persone($cid, $ruolo);
    }

    /** @return array<string, mixed>|null una persona di un consiglio */
    public function persona(int $id): ?array
    {
        return $this->consigli->persona($id);
    }

    /**
     * Consigli di cui l'utente è referente (riconosciuto dall'email o dalla scheda dell'anagrafe): id.
     *
     * @param array<string, mixed>|null $u
     * @return list<int>
     */
    public function referenteDi(?array $u): array
    {
        if (!$u || empty($u['id'])) {
            return [];
        }
        $email = strtolower(trim((string) ($u['email'] ?? '')));
        $pid = (string) ($u['persona_id'] ?? '');
        $out = [];
        foreach ($this->consigli->referenti() as $x) {
            if (($email !== '' && strtolower((string) $x['email']) === $email) || ($pid !== '' && (string) $x['persona_id'] === $pid)) {
                $out[] = (int) $x['consiglio_id'];
            }
        }

        return array_values(array_unique($out));
    }

    /** Gruppo del verbale proposto dal ruolo dell'anagrafe («Professore Ordinario» → «Professori ordinari»). */
    public static function qualificaDaRuolo(string $ruolo): string
    {
        $r = mb_strtolower($ruolo);
        if (str_contains($r, 'ordinari')) {
            return 'Professori ordinari';
        }
        if (str_contains($r, 'associat')) {
            return 'Professori associati';
        }
        if (str_contains($r, 'ricercat')) {
            return 'Ricercatori';
        }
        if (str_contains($r, 'contratt')) {
            return 'Docenti a contratto';
        }
        if (str_contains($r, 'student')) {
            return 'Rappresentanti degli studenti';
        }

        return $ruolo !== '' ? 'Professori associati' : '';
    }

    /**
     * Referente o componente dall'anagrafe ($personaId) o scritto a mano ($nominativo, es. rappresentanti degli studenti).
     * Ritorna un messaggio di errore o null. Le persone già presenti non si duplicano.
     */
    public function aggiungiPersona(int $cid, string $ruolo, string $personaId, string $qualifica = '', string $nominativo = '', string $email = ''): ?string
    {
        if (!$this->consigli->perId($cid)) {
            return 'Consiglio non trovato.';
        }
        $ruolo = $ruolo === 'referente' ? 'referente' : 'componente';
        $pid = null;
        if ($personaId !== '') {
            $p = $this->persone->persona($personaId);
            if (!$p) {
                return "Persona non trovata nell'anagrafe di Ateneo.";
            }
            $pid = $personaId;
            $nominativo = trim($p['cognome'] . ' ' . $p['nome']);
            $email = strtolower(trim((string) $p['email']));
            if ($qualifica === '') {
                $qualifica = self::qualificaDaRuolo((string) $p['ruolo']);
            }
            if ($ruolo === 'referente' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return "La persona scelta non ha un'email nell'anagrafe: non potrebbe accedere.";
            }
        } else {
            $nominativo = mb_substr(trim($nominativo), 0, 200);
            $email = strtolower(trim($email));
            if ($nominativo === '') {
                return "Scrivi il nome o scegli dall'anagrafe.";
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return 'Email non valida.';
            }
            if ($ruolo === 'referente' && $email === '') {
                return "Per un referente serve l'email istituzionale (con quella entra nel pannello).";
            }
        }
        foreach ($this->consigli->persone($cid, $ruolo) as $x) {
            if (($pid && $x['persona_id'] === $pid) || (!$pid && mb_strtolower($x['nominativo']) === mb_strtolower($nominativo))) {
                return $ruolo === 'referente' ? 'È già referente di questo consiglio.' : 'È già tra i componenti.';
            }
        }
        $qualifica = mb_substr(trim($qualifica), 0, 150);

        return $this->consigli->aggiungiPersona($cid, $ruolo, $pid, $email, $nominativo, $qualifica, $this->consigli->ordineSuccessivo($cid)) ? null : 'Salvataggio non riuscito.';
    }

    /**
     * Copia i componenti di $da in $a (salta chi c'è già, per scheda dell'anagrafe o per nome). Ritorna quanti ne ha aggiunti.
     */
    public function importaComponenti(int $da, int $a): int
    {
        if ($da === $a || !$this->consigli->perId($da) || !$this->consigli->perId($a)) {
            return 0;
        }
        $gia = $this->consigli->persone($a);
        $pid = array_filter(array_column($gia, 'persona_id'));
        $nomi = array_map('mb_strtolower', array_column($gia, 'nominativo'));
        $ordine = $this->consigli->ultimoOrdine($a);
        $n = 0;
        foreach ($this->consigli->persone($da) as $x) {
            if (($x['persona_id'] && in_array($x['persona_id'], $pid, true)) || in_array(mb_strtolower($x['nominativo']), $nomi, true)) {
                continue;
            }
            $this->consigli->aggiungiPersona($a, 'componente', $x['persona_id'] ?: null, (string) $x['email'], (string) $x['nominativo'], (string) $x['qualifica'], ++$ordine);
            $n++;
        }

        return $n;
    }

    /**
     * Salva il consiglio dal modulo del pannello (id = 0: nuovo). Ritorna ['id' => …, 'nome' => …, 'errore' => 'nome'|null].
     *
     * @param array<string, mixed> $post
     * @return array{id: int, nome: string, errore: ?string}
     */
    public function salvaDaModulo(int $cid, array $post): array
    {
        $f = [];
        foreach (['nome' => 500, 'coordinatore' => 200, 'segretario' => 200, 'luogo' => 255, 'odg' => 5000] as $k => $max) {
            $f[$k] = mb_substr(trim((string) ($post['c_' . $k] ?? '')), 0, $max);
        }
        $corsi = (string) json_encode(array_values(array_filter(array_map('trim', (array) ($post['c_corsi'] ?? [])))), JSON_UNESCAPED_UNICODE);
        $attivo = isset($post['c_attivo']) ? 1 : 0;
        $ordine = (int) ($post['c_ordine'] ?? 0);
        if ($f['nome'] === '') {
            return ['id' => $cid, 'nome' => '', 'errore' => 'nome'];
        }

        return ['id' => $this->consigli->salva($cid, $f, $corsi, $attivo, $ordine), 'nome' => $f['nome'], 'errore' => null];
    }

    public function togliPersona(int $id): void
    {
        $this->consigli->eliminaPersona($id);
    }

    /**
     * Gruppi e ordine dei componenti del consiglio.
     *
     * @param array<int|string, mixed> $qualifiche id componente => gruppo
     * @param array<int|string, mixed> $ordini id componente => posto
     */
    public function salvaQualifiche(int $cid, array $qualifiche, array $ordini): void
    {
        foreach ($qualifiche as $pid => $q) {
            $q = mb_substr(trim((string) $q), 0, 150);
            $o = (int) ($ordini[$pid] ?? 0);
            $this->consigli->aggiornaQualifica($cid, (int) $pid, $q, $o);
        }
    }
}
