<?php
// inc/tutorato_registro.php - Tutorato dopo la firma della lettera di incarico:
// - registro delle attività: il tutor segna giorno, ore e attività (registro_tutorato.php), il docente responsabile approva o respinge;
// - fine attività: il tutor dichiara concluse le attività, il docente conferma e il portale prepara la «dichiarazione di fine
//   attività» (modello del Dipartimento) con le ore approvate; il docente la firma in PAdES (firma_incarico.php, come la lettera),
//   poi l'operatore riceve l'avviso «attività completate» con il riepilogo e il PDF e registra il protocollo;
// - promemoria (cron): al tutor per aggiornare il registro e, verso la fine del periodo, per chiuderlo; al docente per le ore
//   da approvare; solleciti per le firme ferme (docente, direttore, dichiarazione di fine attività) ogni FIRME_GIORNI_SOLLECITO giorni.
// La logica sta in src/Tutorato/ (ServizioRegistroTutorato, DocumentiIncarico): qui restano le facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('STATI_REGISTRO')) define('STATI_REGISTRO', \App\Tutorato\Costanti::STATI_REGISTRO);
if (!defined('STATI_FINE_ATTIVITA')) define('STATI_FINE_ATTIVITA', \App\Tutorato\Costanti::STATI_FINE_ATTIVITA);

if (!function_exists('registro_incarico')) {
    // Righe del registro della lettera, in ordine di data
    function registro_incarico($conn, int $id): array {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->righe($id);
    }
}

if (!function_exists('ore_registro')) {
    // Ore per stato: ['inviata' => …, 'approvata' => …, 'respinta' => …, 'totale' => inviate + approvate]
    function ore_registro(array $righe): array {
        return \App\Tutorato\ServizioRegistroTutorato::ore($righe);
    }
}

if (!function_exists('ore_testo')) {
    function ore_testo($v): string {
        return \App\Tutorato\DatiLettera::oreTesto($v);
    }
}

if (!function_exists('registro_aperto')) {
    // Il registro si compila dopo la firma del direttore e finché il docente non conferma la fine delle attività
    function registro_aperto(array $i): bool {
        return \App\Tutorato\ServizioRegistroTutorato::aperto($i);
    }
}

if (!function_exists('incarichi_registro')) {
    // Incarichi visibili nel registro: come tutor (codice fiscale dell'accesso) o come docente responsabile
    function incarichi_registro($conn, ?array $u): array {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->incarichiRegistro($u);
    }
}

if (!function_exists('aggiungi_registro')) {
    // Il tutor segna un giorno di attività. Ritorna un errore o null.
    function aggiungi_registro($conn, int $id, string $data, $ore, string $attivita): ?string {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->aggiungi($id, $data, $ore, $attivita);
    }
}

if (!function_exists('togli_registro')) {
    function togli_registro($conn, int $id, int $riga): ?string {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->togli($id, $riga);
    }
}

if (!function_exists('decidi_registro')) {
    // Il docente approva o respinge righe del registro ($righe = id; vuoto = tutte quelle da approvare)
    function decidi_registro($conn, int $id, string $esito, array $righe = [], string $nota = ''): int {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->decidi($id, $esito, $righe, $nota);
    }
}

if (!function_exists('richiedi_fine_attivita')) {
    // Il tutor dichiara concluse le attività: il docente riceve l'email per approvare le ore e confermare
    function richiedi_fine_attivita($conn, int $id): ?string {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->richiediFine($id);
    }
}

if (!function_exists('dati_fine_attivita')) {
    // Dati della dichiarazione di fine attività (modello del Dipartimento)
    function dati_fine_attivita(array $i, ?float $ore = null): array {
        return \App\Core\App::get(\App\Tutorato\DocumentiIncarico::class)->datiFine($i, $ore);
    }
}

if (!function_exists('pdf_fine_attivita')) {
    // Dichiarazione di fine attività in PDF (con il riepilogo del registro). Ritorna [contenuto PDF, segnaposti della firma].
    function pdf_fine_attivita(array $i, array $registro, ?float $ore = null): array {
        return \App\Core\App::get(\App\Tutorato\DocumentiIncarico::class)->pdfFine($i, $registro, $ore);
    }
}

if (!function_exists('conferma_fine_attivita')) {
    // Il docente conferma la fine: ore approvate, dichiarazione in PDF da firmare (link firma_incarico.php?t=token_fine)
    function conferma_fine_attivita($conn, int $id, string $autore = ''): array {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->confermaFine($id, $autore);
    }
}

if (!function_exists('pdf_fine_corrente')) {
    function pdf_fine_corrente(array $i): ?string {
        return \App\Core\App::get(\App\Tutorato\ArchivioIncarichi::class)->fineCorrente($i);
    }
}

if (!function_exists('registra_firma_fine')) {
    // Firma PAdES del docente sulla dichiarazione di fine attività: avviso «attività completate» all'operatore (con il PDF) e al tutor
    function registra_firma_fine($conn, int $id, string $pdf, string $come = 'caricamento'): ?string {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->registraFirmaFine($id, $pdf, $come);
    }
}

if (!function_exists('protocolla_fine_attivita')) {
    function protocolla_fine_attivita($conn, int $id, string $prot, string $autore = ''): ?string {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->protocollaFine($id, $prot, $autore);
    }
}

if (!function_exists('salva_dati_fine_attivita')) {
    // Dati che servono dopo l'invio della lettera: insegnamento e corso del docente, titolo, periodo (per i promemoria)
    function salva_dati_fine_attivita($conn, int $id, array $d): ?string {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->salvaDatiFine($id, $d);
    }
}

if (!function_exists('promemoria_tutorato')) {
    // Cron giornaliero (admin/cron_reminders.php): promemoria del registro e solleciti delle firme ferme. Ritorna le email inviate.
    function promemoria_tutorato($conn): int {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->promemoria();
    }
}

if (!function_exists('conserva_dati_tutorato')) {
    // Conservazione (cron_background.php, CONSERVAZIONE_INCARICHI_MESI nel .env, 0 = mai; durata da concordare con il DPO):
    // le lettere protocollate (con la fine attività protocollata, se c'è) o annullate da più di $mesi mesi perdono i dati personali
    // (nascita, residenza, contatti, metadati dell'accesso SPID/CIE), i PDF e il registro. Restano codice, nome e cognome,
    // bando, ore, compenso, protocolli e storico: gli originali firmati sono nel protocollo di Ateneo.
    function conserva_dati_tutorato($conn, int $mesi): int {
        return \App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class)->conserva($mesi);
    }
}
