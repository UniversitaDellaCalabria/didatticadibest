<?php
// inc/tutorato.php - Tutorato: lettere di incarico dei vincitori dei bandi di tutorato.
// L'operatore (Ufficio didattico, compito "Bandi") inserisce il bando (decreto del bando e della commissione, direttore
// dall'anagrafe) e per ogni vincitore dati anagrafici, attività, ore, periodo, compenso e docente responsabile
// (dall'anagrafe o scritto a mano). I dati precompilano il modello Word del Dipartimento e la lettera in PDF.
// Iter (le email partono solo quando la lettera passa alla persona successiva): studente (SPID/CIE) → docente (PAdES) →
// direttore (PAdES) → operatore (protocollo). Solo PAdES: le firme stanno dentro il PDF (/ByteRange) e ogni firma si aggiunge
// alla precedente senza toccarla; i .p7m (CAdES) sono rifiutati. I PDF stanno in uploads/incarichi/ (bloccata al web).
// La logica sta in src/Tutorato/ (ServizioIncarichi, ServizioRegistroTutorato, DocumentiIncarico, FirmaRemotaAruba, ...): qui restano le facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('STATI_INCARICO')) define('STATI_INCARICO', \App\Tutorato\Costanti::STATI_INCARICO);
if (!defined('DIR_INCARICHI')) define('DIR_INCARICHI', \App\Tutorato\Costanti::DIR_INCARICHI);
if (!defined('MODELLO_LETTERA_INCARICO')) define('MODELLO_LETTERA_INCARICO', \App\Tutorato\Costanti::MODELLO_LETTERA);
if (!defined('LOGO_LETTERA_INCARICO')) define('LOGO_LETTERA_INCARICO', \App\Tutorato\Costanti::LOGO_LETTERA);
if (!defined('METODI_ACCESSO')) define('METODI_ACCESSO', \App\Tutorato\Costanti::METODI_ACCESSO);

if (!function_exists('bando_tutorato')) {
    function bando_tutorato($conn, int $id): ?array {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->bando($id);
    }
}

if (!function_exists('bandi_tutorato')) {
    function bandi_tutorato($conn): array {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->bandiElenco();
    }
}

if (!function_exists('incarico_tutorato')) {
    // Lettera con i dati del bando (decreti, direttore, operatore)
    function incarico_tutorato($conn, int $id): ?array {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->incarico($id);
    }
}

if (!function_exists('incarico_per_token')) {
    // Lettera dal link personale (studente, docente o direttore)
    function incarico_per_token($conn, string $ruolo, string $token): ?array {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->incaricoPerToken($ruolo, $token);
    }
}

if (!function_exists('decreto_testo')) {
    // "123/2026 del 15/09/2026"
    function decreto_testo(?string $num, ?string $data): string {
        return \App\Tutorato\DatiLettera::decretoTesto($num, $data);
    }
}

if (!function_exists('salva_bando_tutorato')) {
    // Bando: titolo, a.a., decreti, direttore (dall'anagrafe o a mano), operatore che riceve le lettere firmate. Ritorna [id, errore].
    function salva_bando_tutorato($conn, array $d, int $uid): array {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->salvaBando($d, $uid);
    }
}

if (!function_exists('salva_incarico_tutorato')) {
    // Lettera di un vincitore (solo in bozza: dopo l'invio i dati non cambiano). Docente dall'anagrafe ($d['docente_persona_id'])
    // o scritto a mano (nome, cognome, email, codice fiscale). Ritorna [id, errore].
    function salva_incarico_tutorato($conn, array $d): array {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->salva($d);
    }
}

if (!function_exists('numero_italiano')) {
    // "1.250,50", "1250.5", "€ 300" → numero (null se non è un numero)
    function numero_italiano($v): ?float {
        return \App\Tutorato\DatiLettera::numeroItaliano($v);
    }
}

if (!function_exists('dati_lettera_incarico')) {
    // Valori della lettera (segnaposti del modello Word e testo del PDF)
    function dati_lettera_incarico(array $i): array {
        return \App\Core\App::get(\App\Tutorato\DatiLettera::class)->valori($i);
    }
}

if (!function_exists('docx_lettera_incarico')) {
    // Word precompilato dal modello del Dipartimento (modelli_documenti/lettera_incarico_tutorato.docx): percorso temporaneo o null
    function docx_lettera_incarico(array $i): ?string {
        return \App\Core\App::get(\App\Tutorato\DocumentiIncarico::class)->docx($i);
    }
}

if (!function_exists('pdf_lettera_incarico')) {
    // Lettera in PDF (stesso testo del modello Word). $firma = conferma dello studente con SPID/CIE (riquadro e firma per accettazione).
    // Ritorna [contenuto PDF, segnaposti delle firme].
    function pdf_lettera_incarico(array $i, ?array $firma = null): array {
        return \App\Core\App::get(\App\Tutorato\DocumentiIncarico::class)->pdfLettera($i, $firma);
    }
}

if (!function_exists('impronta_dati_incarico')) {
    // Impronta dei dati della lettera (cosa ha confermato lo studente): cambia se cambia un solo dato
    function impronta_dati_incarico(array $i): string {
        return \App\Core\App::get(\App\Tutorato\DatiLettera::class)->impronta($i);
    }
}

if (!function_exists('pdf_corrente_incarico')) {
    function pdf_corrente_incarico(array $i): ?string {
        return \App\Core\App::get(\App\Tutorato\ArchivioIncarichi::class)->pdfCorrente($i);
    }
}

if (!function_exists('nome_file_incarico')) {
    // Nome del file da scaricare: LETTERA_INCARICO_COGNOME_NOME[_firmata].pdf
    function nome_file_incarico(array $i, string $suffisso = ''): string {
        return \App\Tutorato\DatiLettera::nomeFile($i, $suffisso);
    }
}

if (!function_exists('invia_incarico_studente')) {
    // Passo 1: la lettera va allo studente (email con il link personale). Ritorna un errore o null.
    function invia_incarico_studente($conn, int $id, string $autore = ''): ?string {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->invia($id, $autore);
    }
}

if (!function_exists('accesso_valido_incarico')) {
    // Lo studente può confermare solo se è proprio lui (codice fiscale dell'accesso = codice fiscale della lettera) e, se richiesto, con SPID o CIE.
    // Ritorna un errore o null.
    function accesso_valido_incarico(array $i, ?array $utente, array $meta): ?string {
        return \App\Core\App::get(\App\Tutorato\ServizioIncarichi::class)->accessoValido($i, $utente, $meta);
    }
}

if (!function_exists('conferma_incarico_studente')) {
    // Passo 2: lo studente conferma. Si genera il PDF definitivo con i dati dell'autenticazione e la lettera va al docente.
    function conferma_incarico_studente($conn, int $id, array $utente, array $meta): ?string {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->conferma($id, $utente, $meta);
    }
}

if (!function_exists('segnala_errore_incarico')) {
    // Lo studente segnala un errore nei dati: l'operatore riceve un'email (la lettera resta da confermare)
    function segnala_errore_incarico($conn, int $id, string $testo): ?string {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->segnala($id, $testo);
    }
}

if (!function_exists('analizza_firma_pdf')) {
    // Verifica crittografica dell'ultima firma del PDF (integrità del documento firmato) e dati del firmatario dal certificato:
    // ['integra' => true|false|null (null = controllo non disponibile), 'nome' => CN, 'cf' => codice fiscale (serialNumber TINIT-…), 'emittente' => …]
    function analizza_firma_pdf(string $pdf): array {
        return \App\Core\App::get(\App\Tutorato\VerificaPdfFirmato::class)->analizza($pdf);
    }
}

if (!function_exists('verifica_pdf_firmato')) {
    // Controlla il PDF firmato rispetto alla versione precedente (PAdES che aggiunge una firma senza modificare il documento).
    // Ritorna [errore | null, dati della firma].
    function verifica_pdf_firmato(string $prima, string $dopo, string $cf_atteso = ''): array {
        return \App\Core\App::get(\App\Tutorato\VerificaPdfFirmato::class)->verifica($prima, $dopo, $cf_atteso);
    }
}

if (!function_exists('registra_firma_incarico')) {
    // Passo 3 o 4: firma PAdES del docente (stato 'confermata') o del direttore (stato 'firmata_docente'). $pdf = file firmato.
    function registra_firma_incarico($conn, int $id, string $ruolo, string $pdf, string $come = 'caricamento'): ?string {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->registraFirma($id, $ruolo, $pdf, $come);
    }
}

if (!function_exists('protocolla_incarico')) {
    // Ultimo passo: l'operatore registra il protocollo. $invia_copia = la lettera protocollata va anche allo studente.
    function protocolla_incarico($conn, int $id, string $prot, ?string $data, bool $invia_copia, string $autore = ''): ?string {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->protocolla($id, $prot, $data, $invia_copia, $autore);
    }
}

if (!function_exists('annulla_incarico')) {
    function annulla_incarico($conn, int $id, string $motivo, string $autore = ''): ?string {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->annulla($id, $motivo, $autore);
    }
}

if (!function_exists('duplica_incarico')) {
    // Copia di una lettera (es. dopo un errore segnalato): nuova bozza con gli stessi dati
    function duplica_incarico($conn, int $id): int {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioIncarichi::class)->duplica($id);
    }
}

if (!function_exists('firmatario_incarico')) {
    // Chi deve firmare con il link: il docente o il direttore riconosciuti dall'accesso (email, codice fiscale o scheda dell'anagrafe)
    function firmatario_incarico(array $i, string $ruolo, ?array $u): bool {
        return \App\Core\App::get(\App\Tutorato\ServizioIncarichi::class)->firmatario($i, $ruolo, $u);
    }
}

if (!function_exists('firma_remota_disponibile')) {
    // Firma remota Aruba (ArubaSignService, ARSS) – PAdES: configurazione nel .env (ARUBA_ARSS_URL, ARUBA_ARSS_DOMINIO, ARUBA_ARSS_CERTID)
    function firma_remota_disponibile(): bool {
        return \App\Core\App::get(\App\Tutorato\FirmaRemota::class)->disponibile();
    }
}

if (!function_exists('firma_remota_aruba')) {
    // Firma PAdES (pdfsignatureV2, profilo PADESBES) del PDF con la firma remota Aruba. $aspetto = segnaposto della firma visibile
    // (pagina, x, y, l, a in punti). Ritorna [PDF firmato, null] oppure [null, messaggio di errore].
    function firma_remota_aruba(string $pdf, string $utente, string $password, string $otp, ?array $aspetto = null, string $motivo = ''): array {
        return \App\Core\App::get(\App\Tutorato\FirmaRemota::class)->firma($pdf, $utente, $password, $otp, $aspetto, $motivo);
    }
}

if (!function_exists('badge_stato_incarico')) {
    function badge_stato_incarico(string $stato): string {
        return \App\Tutorato\Vista\StatiIncarico::badge($stato);
    }
}

if (!function_exists('passi_incarico')) {
    // Passi della lettera per le pagine: [nome, fatto?, attuale?]
    function passi_incarico(array $i): array {
        return \App\Tutorato\Vista\StatiIncarico::passi($i);
    }
}
