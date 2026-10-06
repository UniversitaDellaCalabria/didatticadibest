<?php
// inc/prenotazioni.php - Modulo di prenotazione: controllo anti-robot, termini di annullamento, referenti, campi e studenti.
// Anti-robot, termini, campi e limiti stanno in src/Iscrizioni/; i referenti in src/Eventi/: qui restano le facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// ── CAPTCHA delle prenotazioni pubbliche (chi prenota senza accesso) ──────────
// Domanda semplice (una somma) generata dal server: niente servizi esterni, niente cookie di terzi.
// Una domanda per pagina (vale per tutte le finestre di prenotazione della pagina), risposta in sessione,
// valida una sola volta; si tengono le ultime 10 pagine aperte (più schede del browser).
// La logica sta in src/Iscrizioni/ (CaptchaPrenotazione): qui restano le facciate.
if (!function_exists('captcha_prenotazione')) {
    function captcha_prenotazione(): array {
        return \App\Core\App::get(\App\Iscrizioni\CaptchaPrenotazione::class)->domanda();
    }
}

if (!function_exists('captcha_verifica')) {
    // Ritorna null se la risposta è giusta, altrimenti il messaggio da mostrare.
    // Rifiuta anche i moduli inviati in meno di 3 secondi (tipico dei programmi automatici).
    function captcha_verifica(string $id, string $risposta): ?string {
        return \App\Core\App::get(\App\Iscrizioni\CaptchaPrenotazione::class)->verifica($id, $risposta);
    }
}

if (!function_exists('annullamento_scaduto')) {
    // Il turno ha una scadenza per annullare/cambiare turno ed è passata
    function annullamento_scaduto(?array $turno): bool {
        return \App\Iscrizioni\ServizioAreaPersonale::annullamentoScaduto($turno);
    }
}

if (!function_exists('leggi_referenti_post')) {
    // Referenti dal form (ref_ruolo[], ref_nome[], ref_email[], ref_tel[], ref_link[], ref_notifiche[]) di progetti ed eventi:
    // righe con almeno nome o email, email/telefono/link non validi scartati (le email scartate finiscono in $email_scartate).
    function leggi_referenti_post(?array &$email_scartate = null): array {
        $email_scartate = [];
        $anagrafe = isset($GLOBALS['conn']) ? \App\Core\App::per($GLOBALS['conn'])->get(\App\Eventi\Anagrafe::class) : null;
        return \App\Eventi\ReferentiForm::daPost($_POST, $anagrafe, $email_scartate);
    }
}

if (!function_exists('salva_referenti_evento')) {
    // Referenti di un evento normale: stessi dati dei progetti, salvati nella scheda (progetti_dettagli.referenti_json)
    function salva_referenti_evento($conn, int $ev_id, array $referenti): void {
        \App\Core\App::per($conn)->get(\App\Eventi\ServizioEventi::class)->salvaReferenti($ev_id, $referenti);
    }
}

if (!function_exists('salva_corso_evento')) {
    // Corso di laurea / struttura di un evento normale (campi struttura e corso_codice del modulo), nella scheda progetti_dettagli
    // Insegnamento dell'anagrafe collegato all'attività (aree "Gruppi degli insegnamenti"); 0 = nessuno
    function salva_insegnamento_evento($conn, int $ev_id): void {
        \App\Core\App::per($conn)->get(\App\Eventi\ServizioEventi::class)->salvaInsegnamento($ev_id, $_POST);
    }

    function salva_corso_evento($conn, int $ev_id): void {
        \App\Core\App::per($conn)->get(\App\Eventi\ServizioEventi::class)->salvaCorso($ev_id, $_POST);
    }
}

if (!function_exists('html_campi_form_admin')) {
    // Campi del Form Builder per l'evento indicato, da usare nell'admin (prenotazione manuale e modifica).
    // $valori = dati_custom_json già salvati. Condizioni "mostra se" ignorate: in admin si vede tutto, niente obbligatori.
    // Gli allegati non si caricano da qui: si mostrano i link a quelli esistenti.
    function html_campi_form_admin($conn, int $evento_id, array $valori = [], string $pref = 'cf', int $turno_id = 0): string {
        return \App\Core\App::per($conn)->get(\App\Iscrizioni\ServizioCampiForm::class)->htmlAdmin($evento_id, $valori, $pref, $turno_id);
    }
}

if (!function_exists('limiti_partecipanti')) {
    // Minimo e massimo di partecipanti per iscrizione: quelli dell'edizione (turno) se indicati,
    // altrimenti quelli generali del progetto. max = null se non c'è un massimo.
    function limiti_partecipanti(?array $d, ?array $turno = null): array {
        return \App\Iscrizioni\LimitiPartecipanti::calcola($d, $turno);
    }
}

if (!function_exists('testo_limiti_partecipanti')) {
    // "da 15 a 30", "almeno 15", "fino a 30" o '' se non ci sono limiti
    function testo_limiti_partecipanti(?int $min, ?int $max): string {
        return \App\Iscrizioni\LimitiPartecipanti::testo($min, $max);
    }
}

if (!function_exists('valida_partecipanti_progetto')) {
    // Progetti per le scuole: numero di partecipanti obbligatorio, intero, dentro i limiti del progetto.
    // Ritorna null se va bene (o se il progetto non è per le scuole), altrimenti il messaggio di errore.
    function valida_partecipanti_progetto(array $custom, ?array $d, ?array $turno = null): ?string {
        return \App\Iscrizioni\LimitiPartecipanti::valida($custom, $d, $turno);
    }
}
