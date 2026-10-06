<?php
// inc/attestati.php - Attestati singoli e di gruppo (classi), elenco degli studenti.
// La logica sta in src/Attestati/ (ServizioAttestati, ServizioCronAttestati, ElencoStudenti, ModelloElenco, DatiAttestato,
// Accesso, Vista\PaginaAttestati, Vista\Librerie, Vista\Qr): qui restano le facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

// =======================================================================
// ATTESTATI: singoli (una prenotazione = un attestato) e di gruppo (progetti per le scuole:
// un attestato per ogni studente dell'elenco inserito dal docente, inviati al docente).
// Ogni attestato ha un codice verificabile su verifica_attestato.php.
// =======================================================================
if (!function_exists('regola_attestato_evento')) {
    // Come si comporta l'attestato per l'evento della prenotazione:
    // 'evento'  = evento normale (regole di sempre)
    // 'no'      = progetto senza attestati
    // 'attendi' = progetto non ancora concluso (data di fine futura)
    // 'gruppo'  = progetto per le scuole o evento con attestati per la classe: attestati per gli studenti dell'elenco
    // 'singolo' = progetto generico concluso: attestato alla persona iscritta
    function regola_attestato_evento($conn, int $evento_id): string {
        return \App\Core\App::per($conn)->get(\App\Attestati\ServizioAttestati::class)->regola($evento_id)->value;
    }
}

if (!function_exists('get_partecipanti_prenotazione')) {
    // Elenco degli studenti di una prenotazione (ordine di inserimento)
    function get_partecipanti_prenotazione($conn, int $pr_id): array {
        return \App\Core\App::per($conn)->get(\App\Attestati\ServizioAttestati::class)->partecipanti($pr_id);
    }
}

if (!function_exists('nome_partecipante')) {
    function nome_partecipante(array $p): string {
        return \App\Attestati\ServizioAttestati::nomePartecipante($p);
    }
}

if (!function_exists('leggi_elenco_partecipanti')) {
    // Righe incollate da Excel o da un file CSV: "Cognome<TAB>Nome", "Cognome;Nome", "Cognome,Nome".
    // Una riga senza separatori è tenuta intera (es. "Rossi Mario"). L'intestazione "Cognome/Nome" è ignorata.
    // Ritorna [['cognome' => ..., 'nome' => ...], ...] senza righe vuote né doppioni.
    function leggi_elenco_partecipanti(string $testo, int $max = 500): array {
        return \App\Attestati\ElencoStudenti::daTesto($testo, $max);
    }
}

if (!function_exists('leggi_elenco_da_campi')) {
    // Elenco dai campi separati Cognome[] e Nome[] del modulo: righe vuote ignorate, doppioni tolti
    function leggi_elenco_da_campi(array $cognomi, array $nomi, int $max = 500): array {
        return \App\Attestati\ElencoStudenti::daCampi($cognomi, $nomi, $max);
    }
}

if (!function_exists('testo_da_file_elenco')) {
    // Testo "Cognome;Nome" da un file caricato: CSV/TXT (anche salvato da Excel) oppure XLSX (prime due colonne
    // del primo foglio; serve l'estensione zip di PHP). Ritorna il testo oppure null con $errore valorizzato.
    function testo_da_file_elenco(array $file, ?string &$errore = null): ?string {
        return \App\Attestati\ElencoStudenti::testoDaFile($file, $errore);
    }
}

if (!function_exists('invia_modello_elenco')) {
    // Scarica il modello da compilare (colonne Cognome, Nome): .xlsx se il server ha l'estensione zip, altrimenti .csv
    // (si apre comunque con Excel). $righe = eventuali nomi già inseriti. Termina lo script.
    function invia_modello_elenco(array $righe = [], string $nome_file = 'elenco_studenti'): void {
        while (ob_get_level() > 0) ob_end_clean();
        $modello = \App\Attestati\ModelloElenco::crea($righe, $nome_file);
        foreach ($modello['intestazioni'] as $intestazione) header($intestazione);
        echo $modello['contenuto'];
        exit;
    }
}

if (!function_exists('salva_elenco_partecipanti')) {
    // Sostituisce l'elenco degli studenti (usata dal docente prima dell'invio degli attestati).
    function salva_elenco_partecipanti($conn, int $pr_id, array $righe): void {
        \App\Core\App::per($conn)->get(\App\Attestati\ServizioAttestati::class)->salvaElenco($pr_id, $righe);
    }
}

if (!function_exists('max_partecipanti_prenotazione')) {
    // Quanti studenti può contenere l'elenco: il numero dichiarato nell'iscrizione, altrimenti il massimo del progetto
    function max_partecipanti_prenotazione(array $pren, ?array $dett): int {
        return \App\Core\App::get(\App\Attestati\ServizioAttestati::class)->maxPartecipanti($pren, $dett);
    }
}

if (!function_exists('nuovo_codice_attestato')) {
    function nuovo_codice_attestato(): string {
        return \App\Attestati\ServizioAttestati::nuovoCodice();
    }
}

if (!function_exists('url_verifica_attestato')) {
    function url_verifica_attestato(string $codice): string {
        return \App\Core\App::get(\App\Attestati\ServizioAttestati::class)->urlVerifica($codice);
    }
}

if (!function_exists('prenotazione_per_attestati')) {
    // Prenotazione con evento, area, portale e scheda del progetto: i dati che servono agli attestati
    function prenotazione_per_attestati($conn, int $pr_id): ?array {
        return \App\Core\App::per($conn)->get(\App\Attestati\ServizioAttestati::class)->prenotazione($pr_id);
    }
}

if (!function_exists('dati_attestato')) {
    // Campi dell'attestato a partire da una prenotazione (prenotazione_per_attestati o get_attestato).
    // Nei progetti: ore totali e periodo del progetto; negli eventi: data e durata del turno.
    function dati_attestato(array $p, string $nome_completo, string $codice, string $matricola = ''): array {
        return \App\Attestati\DatiAttestato::crea($p, $nome_completo, $codice, $matricola);
    }
}

if (!function_exists('url_vendor')) {
    // Librerie e caratteri salvati sul server in assets/vendor (stessa struttura dei CDN): aprendo le pagine
    // il browser non si collega a servizi di terzi (privacy: niente IP a Google Fonts o ai CDN).
    // Percorso dalla radice del sito, valido sia dalle pagine pubbliche sia da /admin.
    function url_vendor(string $percorso): string {
        return \App\Attestati\Vista\Librerie::urlVendor(url_base_sito(), $percorso);
    }
}

if (!function_exists('script_libreria')) {
    // Librerie JavaScript salvate sul server (assets/js): QR e scanner funzionano anche se il CDN non risponde.
    // Se il file locale mancasse (es. non caricato sul server) si ripiega sul CDN, in modo sincrono
    // (document.write subito dopo lo script locale): il codice che segue trova la libreria come prima.
    function script_libreria(string $nome): string {
        return \App\Attestati\Vista\Librerie::scriptLibreria(url_base_sito(), $nome);
    }
}

if (!function_exists('qr_html')) {
    // QR disegnato nella pagina (SVG, libreria qrcode-generator): nessun servizio esterno riceve il contenuto.
    // $stile imposta la larghezza (il QR è quadrato); lo script si aggiunge da solo una volta per pagina.
    function qr_html(string $dati, string $stile = 'width:150px', string $alt = 'QR code', string $classi = ''): string {
        static $qr = null;
        $qr ??= new \App\Attestati\Vista\Qr(url_base_sito());
        return $qr->html($dati, $stile, $alt, $classi);
    }
}

if (!function_exists('slug_file')) {
    // Testo adatto a un nome di file: minuscole, senza accenti né apostrofi, parole separate da _
    function slug_file(string $s): string {
        return \App\Attestati\NomeFile::slug($s);
    }
}

if (!function_exists('pagina_attestati')) {
    // Documento HTML stampabile con uno o più attestati (uno per pagina A4 orizzontale).
    // Ogni attestato riporta il codice di verifica e il QR che apre verifica_attestato.php.
    function pagina_attestati(array $lista, string $titolo_doc): string {
        return \App\Attestati\Vista\PaginaAttestati::html($lista, $titolo_doc, url_base_sito());
    }
}

if (!function_exists('assegna_codici_partecipanti')) {
    // Codice di verifica per gli studenti che non l'hanno ancora (assegnato una volta, non cambia più)
    function assegna_codici_partecipanti($conn, int $pr_id): void {
        \App\Core\App::per($conn)->get(\App\Attestati\ServizioAttestati::class)->assegnaCodici($pr_id);
    }
}

if (!function_exists('invia_attestati_gruppo')) {
    // Progetti per le scuole ed eventi con attestati per la classe: genera i codici degli studenti e manda
    // a chi ha prenotato il link agli attestati. Condizioni: prenotazione confermata e presente, almeno uno
    // studente non escluso, attività conclusa (progetto: data di fine; evento: giorno del turno), oppure
    // $forza dal pulsante "Invia attestati ora" dell'admin. Ritorna true o il motivo.
    function invia_attestati_gruppo($conn, int $pr_id, bool $forza = false) {
        return \App\Core\App::per($conn)->get(\App\Attestati\ServizioAttestati::class)->inviaGruppo($pr_id, $forza);
    }
}

if (!function_exists('puo_vedere_prenotazione')) {
    // L'utente loggato è il titolare della prenotazione, un amministratore o un gestore
    function puo_vedere_prenotazione(array $p): bool {
        return \App\Attestati\Accesso::puoVedere(
            $p,
            (int)($_SESSION['utente_id'] ?? 0),
            (int)($_SESSION['utente_ruolo_id'] ?? 5),
            isset($_SESSION['utente_ruoli_secondari']) ? (string)$_SESSION['utente_ruoli_secondari'] : null,
            isset($_SESSION['utente_email']) ? (string)$_SESSION['utente_email'] : null
        );
    }
}
