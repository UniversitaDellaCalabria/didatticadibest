<?php
// inc/sedute.php - Sedute dei consigli, funzioni aggiunte dopo le presenze e le decisioni (inc/didattica.php):
// - convocazione per email (facoltativa): l'operatore o il referente personalizza oggetto e testo, ogni componente riceve il suo
//   link per giustificare l'assenza (giustifica.php), che diventa «assente giustificato» nelle presenze della seduta;
// - estratto del verbale per lo studente: PDF con la delibera e il quadro delle convalide, nella pratica quando si applicano gli esiti;
// - verbale firmato in PAdES: si carica il PDF del verbale, firma il segretario e poi il coordinatore (firma_verbale.php, firma remota
//   Aruba se configurata, altrimenti scarica, firma e ricarica); solleciti dal cron;
// - importazione dei componenti da un altro consiglio.
// La logica sta in src/Didattica/ (ServizioConvocazioni, VerbaleFirmato, EstrattoVerbale, ServizioConsigli): qui restano le facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('DIR_VERBALI')) define('DIR_VERBALI', \App\Didattica\Costanti::DIR_VERBALI);
if (!defined('STATI_VERBALE')) define('STATI_VERBALE', \App\Didattica\Costanti::STATI_VERBALE);
if (!defined('TESTO_CONVOCAZIONE')) define('TESTO_CONVOCAZIONE', \App\Didattica\Costanti::TESTO_CONVOCAZIONE);

if (!function_exists('presenze_registrate')) {
    // Presenze per il verbale e il riepilogo: se ne è stata salvata almeno una (anche una giustificazione arrivata con la
    // convocazione), valgono tutti i componenti, con quelli non segnati proposti presenti; altrimenti nessuna.
    function presenze_registrate($conn, array $s): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioSedute::class)->registrate($s);
    }
}

// ==============================================================================
// CONVOCAZIONE PER EMAIL E GIUSTIFICAZIONE DELL'ASSENZA
// ==============================================================================
if (!function_exists('testo_convocazione')) {
    // Segnaposti: {NOME} {ORGANO} {DATA} {ORA} {LUOGO} {ODG} {COORDINATORE} {LINK_GIUSTIFICA}
    function testo_convocazione(string $tpl, array $s, string $nome, string $link): string {
        return \App\Didattica\ServizioConvocazioni::testo($tpl, $s, $nome, $link);
    }
    // Invia la convocazione ai componenti con l'email. $con_link: ognuno riceve il link per giustificare l'assenza.
    // Ritorna [inviate, componenti senza email, errore]
    function invia_convocazione($conn, array $s, string $oggetto, string $testo, bool $con_link): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioConvocazioni::class)->invia($s, $oggetto, $testo, $con_link);
    }
    function convocazione_per_token($conn, string $tok): ?array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioConvocazioni::class)->perToken($tok);
    }
    // Il componente giustifica l'assenza dal link della convocazione: «assente giustificato» nelle presenze della seduta
    function giustifica_assenza($conn, string $tok, string $motivo): ?string {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioConvocazioni::class)->giustifica($tok, $motivo);
    }
    // Stato della convocazione per componente: [componente_id => riga]
    function convocazioni_seduta($conn, int $sid): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioConvocazioni::class)->dellaSeduta($sid);
    }
}

// ==============================================================================
// IMPORTA I COMPONENTI DA UN ALTRO CONSIGLIO
// ==============================================================================
if (!function_exists('importa_componenti_consiglio')) {
    // Copia i componenti di $da in $a (salta chi c'è già, per scheda dell'anagrafe o per nome). Ritorna quanti ne ha aggiunti.
    function importa_componenti_consiglio($conn, int $da, int $a): int {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioConsigli::class)->importaComponenti($da, $a);
    }
}

// ==============================================================================
// ESTRATTO DEL VERBALE PER LO STUDENTE (PDF)
// ==============================================================================
if (!function_exists('pdf_estratto_pratica')) {
    function pdf_estratto_pratica($conn, array $s, array $p): string {
        return \App\Core\App::per($conn)->get(\App\Didattica\EstrattoVerbale::class)->pdf($s, $p);
    }
}

// ==============================================================================
// VERBALE FIRMATO IN PADES: SEGRETARIO, POI COORDINATORE
// ==============================================================================
if (!function_exists('invia_verbale_alla_firma')) {
    function percorso_verbale_pdf(array $s): ?string {
        return \App\Core\App::get(\App\Didattica\VerbaleFirmato::class)->percorso($s);
    }
    // Carica il PDF del verbale e lo manda alla firma del segretario (poi del coordinatore)
    function invia_verbale_alla_firma($conn, int $sid, string $pdf, string $email_seg, string $email_coo): ?string {
        return \App\Core\App::per($conn)->get(\App\Didattica\VerbaleFirmato::class)->inviaAllaFirma($sid, $pdf, $email_seg, $email_coo);
    }
    function seduta_per_token_verbale($conn, string $tok): ?array {
        return \App\Core\App::per($conn)->get(\App\Didattica\VerbaleFirmato::class)->sedutaPerToken($tok);
    }
    // Chi deve firmare ora (email dell'accesso): segretario o coordinatore
    function firmatario_verbale(array $s, ?array $u): bool {
        return \App\Core\App::get(\App\Didattica\VerbaleFirmato::class)->firmatario($s, $u);
    }
    // Firma PAdES aggiunta al verbale (stesso PDF, revisione incrementale): passa al coordinatore, poi è firmato
    function registra_firma_verbale($conn, int $sid, string $pdf, string $come = 'caricamento'): ?string {
        return \App\Core\App::per($conn)->get(\App\Didattica\VerbaleFirmato::class)->registraFirma($sid, $pdf, $come);
    }
    // Cron: sollecito ogni FIRME_GIORNI_SOLLECITO giorni (al massimo 3) a chi deve firmare il verbale
    function solleciti_verbali($conn): int {
        return \App\Core\App::per($conn)->get(\App\Didattica\VerbaleFirmato::class)->solleciti();
    }
}

if (!function_exists('conserva_dati_sedute')) {
    // Conservazione (cron_background.php, CONSERVAZIONE_CONVOCAZIONI_MESI, predefinito 12): i link della convocazione,
    // le email e i motivi delle assenze delle sedute passate da più di $mesi mesi si cancellano (le presenze restano nel verbale)
    function conserva_dati_sedute($conn, int $mesi): int {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioConvocazioni::class)->conserva($mesi);
    }
}
