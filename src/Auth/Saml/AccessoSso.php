<?php

declare(strict_types=1);

namespace App\Auth\Saml;

use App\Auth\LogAccessiRepository;
use App\Auth\RuoloRepository;
use App\Auth\Sessione;
use App\Auth\UtenteRepository;

/**
 * Accesso con il Single Sign-On di Ateneo (SimpleSAMLphp, SP "default-sp"): accesso automatico di ogni pagina protetta
 * (sync_sso_user) e pagina di accesso esplicito (saml_login.php). Spostato da inc/base.php e saml_login.php SENZA cambiare
 * la logica: stesso ordine delle operazioni su SimpleSAML e sulla sessione PHP (non si può provare fuori dal server di Ateneo).
 * SimpleSAML può chiudere la sessione PHP del portale: per questo nome e ID si salvano prima e la sessione si riapre dopo.
 */
final class AccessoSso
{
    public function __construct(
        private UtenteRepository $utenti,
        private RuoloRepository $ruoli,
        private LogAccessiRepository $accessi,
        private CollegamentoAnagrafe $anagrafe,
        private Sessione $sessione,
    ) {
    }

    /**
     * Accesso automatico dalla sessione SSO di Ateneo (pagine protette). true se l'utente risulta collegato.
     * $uscito = l'utente aveva fatto "Esci" (cookie): niente accesso automatico, si rientra solo con "Accedi".
     */
    public function sincronizza(bool $uscito, string $percorsoSimpleSaml, string $ip, string $userAgent): bool
    {
        if (!empty($this->sessione->leggi('utente_id'))) {
            return true;
        }
        if ($uscito) {
            return false;
        }
        if (!file_exists($percorsoSimpleSaml)) {
            return false;
        }

        // Salviamo nome e ID sessione PRIMA di qualsiasi chiamata a SimpleSAML
        $nomeSessione = session_name();
        $idSessione = session_id();

        try {
            require_once $percorsoSimpleSaml;
            $as = new \SimpleSAML\Auth\Simple('default-sp');
            if (!$as->isAuthenticated()) {
                // SimpleSAML può aver chiuso la sessione anche solo leggendo lo stato
                if (session_status() !== PHP_SESSION_ACTIVE && $idSessione) {
                    session_name((string) $nomeSessione);
                    session_id($idSessione);
                    session_start();
                }

                return false;
            }

            $attributes = $as->getAttributes();
            $metaAccesso = AttributiSaml::metadatiAccesso($as, $attributes, $ip);

            // Ripristina la nostra sessione PHP (pattern LibreBooking adSAML::Cleanup)
            \SimpleSAML\Session::getSessionFromRequest()->cleanup();

            // cleanup() può chiudere la sessione: la riapriamo esplicitamente
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_name((string) $nomeSessione);
                session_id((string) $idSessione);
                session_start();
            }

            $cf = AttributiSaml::codiceFiscale($attributes, false);
            if (!$cf) {
                return false;
            }

            $uInfo = $this->utenti->perCodiceFiscaleConRuolo(strtoupper(trim($cf)));
            if ($uInfo === null) {
                return false;
            }

            $tipo = AttributiSaml::tipoUtente((string) ($uInfo['matricola_studente'] ?? ''), (string) ($uInfo['matricola_dipendente'] ?? ''));
            // Email mancante in DB: la recupera dall'IdP (mai inventare un indirizzo: le notifiche finirebbero nel vuoto)
            if (empty(trim((string) ($uInfo['email'] ?? '')))) {
                $samlEmail = AttributiSaml::email($attributes, $tipo);
                if ($samlEmail !== '') {
                    $this->utenti->impostaEmail((int) $uInfo['id'], $samlEmail);
                    $uInfo['email'] = $samlEmail;
                }
            }

            // Anagrafe di Ateneo: persona collegata per email, gruppo Docenti/PTA/Altro e abilitazioni in attesa
            $secAg = $this->anagrafe->collega((int) $uInfo['id'], AttributiSaml::email($attributes, $tipo));
            if ($secAg !== null) {
                $uInfo['ruoli_secondari'] = $secAg;
            }

            $this->apriSessione($uInfo, $metaAccesso);

            if (empty($this->sessione->leggi('accesso_sso_loggato'))) {
                $this->registraAccesso($uInfo, $ip, $userAgent);
                $this->sessione->scrivi('accesso_sso_loggato', 1);
            }

            return true;
        } catch (\Throwable $e) {
            // Errore SimpleSAML: logga ma NON distruggere la sessione,
            // che potrebbe contenere dati validi scritti da saml_login.php.
            error_log('[SSO] sync_sso_user exception: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Accesso esplicito (saml_login.php): requireAuth() rimanda all'IdP se serve; al ritorno crea o aggiorna l'utente
     * e apre la sessione del portale. Il cookie "uscito" va tolto dal chiamante ($dopoAccesso), prima della sessione.
     */
    public function accedi(string $percorsoSimpleSaml, string $ip, string $userAgent, callable $dopoAccesso): void
    {
        // I cinque ruoli di base, se mancano
        $this->ruoli->assicuraRuoliBase();

        if (!file_exists($percorsoSimpleSaml)) {
            return;
        }
        require_once $percorsoSimpleSaml;

        // Salviamo nome e ID della nostra sessione PRIMA che SimpleSAML possa chiuderla
        $nomeSessione = session_name();
        $idSessione = session_id();

        $as = new \SimpleSAML\Auth\Simple('default-sp');
        $as->requireAuth();

        // Attributi letti PRIMA di cleanup(), come nell'accesso automatico: dopo, la sessione SimpleSAML viene chiusa
        $attributes = $as->getAttributes();
        // Metodo di autenticazione (SPID con livello, CIE, credenziali di Ateneo): serve per le conferme con SPID/CIE (lettere di incarico)
        $metaAccesso = AttributiSaml::metadatiAccesso($as, $attributes, $ip);

        // Ripristina la nostra sessione PHP (pattern LibreBooking adSAML::Cleanup)
        \SimpleSAML\Session::getSessionFromRequest()->cleanup();

        // cleanup() può chiudere la sessione: la riapriamo esplicitamente
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name((string) $nomeSessione);
            session_id((string) $idSessione);
            session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
            session_start();
        }

        $cf = AttributiSaml::codiceFiscale($attributes, true);
        [$nome, $cognome] = AttributiSaml::nomeCognome($attributes);
        $matrStud = $attributes['matricola_studente'][0] ?? ($attributes['schacPersonalUniqueCode'][0] ?? '');
        $matrDip = $attributes['matricola_dipendente'][0] ?? '';
        // Studente → @studenti.unical.it, dipendente → @unical.it, SPID/CIE → email personale
        $email = AttributiSaml::email($attributes, AttributiSaml::tipoUtente((string) $matrStud, (string) $matrDip));

        if (!$cf) {
            return;
        }
        $cfPulito = strtoupper(trim($cf));
        $nomePulito = trim($nome);
        $cognomePulito = trim($cognome);
        $emailPulita = strtolower(trim($email));
        $matrStudPulita = trim((string) $matrStud);
        $matrDipPulita = trim((string) $matrDip);

        $uInfo = $this->utenti->perCodiceFiscaleConRuolo($cfPulito);
        if ($uInfo !== null) {
            $this->utenti->aggiornaAccessoSso((int) $uInfo['id'], $emailPulita, $nomePulito, $cognomePulito);
            // $uInfo è stato letto prima dell'UPDATE: allinea l'email così la sessione non resta con quella vecchia/vuota
            if ($emailPulita !== '' && (int) ($uInfo['email_personalizzata'] ?? 0) === 0) {
                $uInfo['email'] = $emailPulita;
            }
        } else {
            $ruolo = !empty($matrStudPulita) ? 3 : (!empty($matrDipPulita) ? 4 : 5);
            $matricola = !empty($matrStudPulita) ? $matrStudPulita : $matrDipPulita;
            $nuovoId = $this->utenti->creaDaSso($cfPulito, $nomePulito, $cognomePulito, $emailPulita, $matricola, $matrStudPulita, $matrDipPulita, $ruolo);
            $uInfo = $this->utenti->perIdConRuolo($nuovoId);
        }

        // Anagrafe di Ateneo: persona collegata per email, gruppo Docenti/PTA/Altro e abilitazioni in attesa
        $secAg = $this->anagrafe->collega((int) ($uInfo['id'] ?? 0), $emailPulita);
        if ($secAg !== null) {
            $uInfo['ruoli_secondari'] = $secAg;
        }

        $dopoAccesso(); // accesso esplicito: torna attivo l'accesso automatico SSO
        $this->apriSessione((array) $uInfo, $metaAccesso);

        if (empty($this->sessione->leggi('accesso_sso_loggato'))) {
            try {
                $this->registraAccesso((array) $uInfo, $ip, $userAgent);
                $this->sessione->scrivi('accesso_sso_loggato', 1);
            } catch (\Throwable $e) {
                error_log('[SSO] Errore log accesso: ' . $e->getMessage());
            }
        }

        session_write_close();
    }

    /**
     * @param array<string, mixed> $uInfo
     * @param array<string, mixed> $metaAccesso
     */
    private function apriSessione(array $uInfo, array $metaAccesso): void
    {
        // Nuovo id di sessione prima di scrivere l'utente: un cookie di sessione impostato in anticipo da altri non diventa valido
        $this->sessione->rigenera();
        $this->sessione->scrivi('utente_id', (int) $uInfo['id']);
        $this->sessione->scrivi('utente_cf', $uInfo['codice_fiscale']);
        $this->sessione->scrivi('utente_nome', trim($uInfo['nome'] . ' ' . $uInfo['cognome']));
        $this->sessione->scrivi('utente_email', $uInfo['email']);
        $this->sessione->scrivi('utente_ruolo_id', (int) $uInfo['ruolo_id']);
        $this->sessione->scrivi('utente_ruoli_secondari', $uInfo['ruoli_secondari'] ?? '');
        $this->sessione->scrivi('auth_meta', $metaAccesso);
    }

    /** @param array<string, mixed> $uInfo */
    private function registraAccesso(array $uInfo, string $ip, string $userAgent): void
    {
        $this->accessi->registra((int) $uInfo['id'], $uInfo['email'], $uInfo['nome'], $uInfo['cognome'], substr($ip, 0, 45), substr($userAgent, 0, 512), 'sso');
    }
}
