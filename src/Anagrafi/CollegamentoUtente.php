<?php

declare(strict_types=1);

namespace App\Anagrafi;

use App\Auth\Saml\CollegamentoAnagrafe;
use Throwable;

/**
 * Al login: collega l'utente alla persona dell'anagrafe con la stessa email (quella dell'SSO o del profilo), aggiorna il gruppo
 * Docenti / PTA / Altro tra i gruppi secondari e attiva le abilitazioni in attesa per quell'email.
 * Spostato da collega_utente_anagrafe() e scollega_utenti_senza_persona() di inc/anagrafi.php.
 */
final class CollegamentoUtente implements CollegamentoAnagrafe
{
    /** @var array<string, int>|null id dei gruppi Docenti / PTA / Altro (tabella ruoli), per chiave */
    private ?array $idsGruppi = null;

    public function __construct(
        private CollegamentiRepository $collegamenti,
        private PersonaRepository $persone,
        private PermessiGestori $permessi,
        private RegistroOperazioni $registro,
    ) {
    }

    /** I gruppi secondari aggiornati (per la sessione) o null se l'utente non esiste. */
    public function collega(int $utenteId, string $emailSso): ?string
    {
        try {
            $u = $this->collegamenti->utente($utenteId);
            if (!$u) {
                return null;
            }
            $emailList = array_values(array_unique(array_filter(
                [strtolower(trim($emailSso)), strtolower(trim((string) $u['email']))],
                static fn (string $e): bool => (bool) filter_var($e, FILTER_VALIDATE_EMAIL)
            )));
            $persona = null;
            foreach ($emailList as $e) {
                if ($persona = $this->persone->perEmail($e)) {
                    break;
                }
            }
            $pid = $persona['id'] ?? null;
            if ($pid !== ($u['persona_id'] ?? null)) {
                $this->collegamenti->collegaPersona($utenteId, $pid === null ? null : (string) $pid);
            }
            // Gruppo automatico: tolti quelli dell'anagrafe, aggiunto quello attuale
            $idsG = $this->idsGruppi();
            $sec = array_values(array_filter(explode(',', (string) $u['ruoli_secondari']), static fn (string $x): bool => $x !== '' && !in_array((int) $x, $idsG, true)));
            if ($persona && isset($idsG[$persona['gruppo']])) {
                $sec[] = (string) $idsG[$persona['gruppo']];
            }
            $secStr = implode(',', array_unique($sec));
            if ($secStr !== (string) $u['ruoli_secondari']) {
                $this->collegamenti->salvaRuoliSecondari($utenteId, $secStr);
            }
            // Abilitazioni date prima del primo accesso
            foreach ($emailList as $e) {
                foreach ($this->collegamenti->abilitazioniInAttesa($e) as $a) {
                    $this->permessi->applica($utenteId, (string) ($a['ambito'] ?? 'area'), (int) $a['pagina_id'], array_values(array_filter(array_map('intval', explode(',', (string) $a['eventi_ids'])))), (int) ($a['creata_da'] ?? 0));
                    $this->collegamenti->eliminaAbilitazioneInAttesa((int) $a['id']);
                    $this->registro->registra('Attivata abilitazione in attesa', ['Utente' => $utenteId, 'Email' => $e, 'Area' => (int) $a['pagina_id']]);
                }
            }

            return $secStr;
        } catch (Throwable $e) {
            error_log('[anagrafe] collega_utente_anagrafe: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Utenti collegati a una persona tolta dall'anagrafe: collegamento azzerato. Il confronto si fa in PHP
     * (le tabelle possono avere collation diverse e MySQL rifiuterebbe il confronto diretto).
     */
    public function scollegaSenzaPersona(): void
    {
        $ids = $this->persone->ids();
        foreach ($this->collegamenti->utentiCollegati() as $u) {
            if (!isset($ids[(string) $u['persona_id']])) {
                $this->collegamenti->collegaPersona((int) $u['id'], null);
            }
        }
    }

    /** @return array<string, int> id dei gruppi (tabella ruoli) Docenti / PTA / Altro, per chiave */
    public function idsGruppi(): array
    {
        if ($this->idsGruppi !== null) {
            return $this->idsGruppi;
        }
        $ids = [];
        foreach ($this->collegamenti->ruoliPerNome() as $nome => $id) {
            $k = array_search($nome, Anagrafe::GRUPPI_PERSONALE, true);
            if ($k !== false) {
                $ids[$k] = $id;
            }
        }

        return $this->idsGruppi = $ids;
    }
}
