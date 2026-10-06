<?php
// inc/convalide.php - Pratiche che passano dal protocollo: il primo modulo è la «Richiesta di convalida/riconoscimento esami».
// - Domanda in PDF/A-2b generata all'invio, pronta da protocollare: logo del Dipartimento, testo «Il/La sottoscritto/a… CHIEDE…»,
//   tabelle degli esami, dichiarazioni, metadati visibili (riquadro finale) e invisibili (XMP e informazioni del documento).
// - Flusso: Ufficio protocollo (scarica, protocolla nel sistema di Ateneo, registra numero e data) → ufficio del corso di studio
//   della pratica, scelto in automatico dal consiglio che ha quel corso → convalide del referente (richiesto → convalidato con un
//   insegnamento dell'anagrafe, anche inserimento nel piano come a scelta) → seduta e verbale → invio alla segreteria studenti
//   scelta dal referente, con il verbale; lo studente riceve la sua parte del verbale (estratto) quando si applicano gli esiti.
// - Uffici con un tipo (didattica_uffici.tipo): 'protocollo', 'cdl' (con il consiglio dei corsi), 'segreteria'.
// La logica sta in src/Didattica/ (ServizioFlussoConvalide, DomandaPdfa, EditorDecisioni): qui restano le facciate.
// Caricato da functions.php (nell'ordine indicato lì): non includerlo da solo.

if (!defined('TIPI_UFFICIO')) define('TIPI_UFFICIO', \App\Didattica\Costanti::TIPI_UFFICIO);
// Nomi brevi degli uffici dei cinque consigli di partenza (per ordine del consiglio); per gli altri «Ufficio del <consiglio>»
if (!defined('NOMI_UFFICI_CONSIGLI')) define('NOMI_UFFICI_CONSIGLI', \App\Didattica\Costanti::NOMI_UFFICI_CONSIGLI);
if (!defined('TESTO_DICHIARAZIONE_DPR')) define('TESTO_DICHIARAZIONE_DPR', \App\Didattica\Costanti::TESTO_DICHIARAZIONE_DPR);

// ==============================================================================
// UFFICI DEL FLUSSO
// ==============================================================================
if (!function_exists('uffici_di_tipo')) {
    function uffici_di_tipo($conn, string $tipo): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioFlussoConvalide::class)->ufficiDiTipo($tipo);
    }
    // Corso di studio indicato nella pratica (prima risposta di tipo «Corso di studio»)
    function corso_pratica(array $p): string {
        return \App\Didattica\ServizioFlussoConvalide::corsoPratica($p);
    }
    // Ufficio di corso di studio a cui va una pratica del corso $corso: quello del consiglio che ha il corso tra i suoi
    function ufficio_del_corso($conn, string $corso): ?int {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioFlussoConvalide::class)->ufficioDelCorso($corso);
    }
}

// ==============================================================================
// PARTENZA: UFFICI, UFFICIO PROTOCOLLO E MODULO DI CONVALIDA (dallo schema, una volta)
// ==============================================================================
if (!function_exists('prepara_flusso_convalide')) {
    function prepara_flusso_convalide($conn): void {
        \App\Core\App::per($conn)->get(\App\Didattica\ServizioFlussoConvalide::class)->preparaFlusso();
    }

}

// ==============================================================================
// DOMANDA IN PDF/A
// ==============================================================================
if (!function_exists('config_domanda')) {
    // Impostazioni della domanda del modulo: pdf (sì/no), bollo (chi protocolla verifica il pagamento della marca da bollo
    // prima di trasmettere la pratica), oggetto, destinatario, testo dopo «CHIEDE»
    function config_domanda(?array $m): array {
        return \App\Core\App::get(\App\Didattica\DomandaPdfa::class)->config($m);
    }
    // Frasi per il piano di studi: insegnamenti da inserire come a scelta (ed eventuale insegnamento da eliminare).
    // $righe: righe delle decisioni (convalidato in 'ins') o delle richieste dello studente; $chi: soggetto della frase
    function frasi_piano(array $righe, string $chi = 'Lo/La studente/ssa chiede'): array {
        return \App\Core\App::get(\App\Didattica\DomandaPdfa::class)->frasiPiano($righe, $chi);
    }
    function frasi_piano_decisioni(?array $dec): array {
        return \App\Core\App::get(\App\Didattica\DomandaPdfa::class)->frasiPianoDecisioni($dec);
    }
}

if (!function_exists('genera_domanda_pratica')) {
    // Genera (o rigenera) la domanda in PDF/A e la mette nella pratica (visibile allo studente). Ritorna il percorso o null.
    function genera_domanda_pratica($conn, int $id, int $uid = 0, string $autore_nome = ''): ?string {
        return \App\Core\App::per($conn)->get(\App\Didattica\DomandaPdfa::class)->genera($id, $uid, $autore_nome);
    }
    // Evento con il file della domanda (per il link di scaricamento)
    function evento_domanda_pratica($conn, array $p): ?int {
        return \App\Core\App::per($conn)->get(\App\Didattica\DomandaPdfa::class)->eventoDomanda($p);
    }
}

// ==============================================================================
// FLUSSO: PROTOCOLLO → UFFICIO DEL CORSO → SEGRETERIA STUDENTI
// ==============================================================================
if (!function_exists('registra_protocollo_pratica')) {
    // L'Ufficio protocollo registra numero e data e, se il modulo la richiede, spunta la marca da bollo pagata; con protocollo
    // (e bollo) la pratica passa da sola all'ufficio del corso di studio (passo «@cdl» dell'iter). Senza bollo resta al protocollo
    // e si trasmette quando lo si spunta (numero e data già registrati restano). Ritorna [errore|null, messaggio]
    function registra_protocollo_pratica($conn, int $id, string $numero, string $data, int $uid, string $autore_nome = '', bool $bollo = false): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioFlussoConvalide::class)->registraProtocollo($id, $numero, $data, $uid, $autore_nome, $bollo);
    }
}

if (!function_exists('invia_pratiche_segreteria')) {
    // Il referente manda alla segreteria studenti le pratiche lavorate (esaminate in seduta, con esito): nella pratica vanno
    // l'estratto del verbale (se non c'è già) e il verbale della seduta (firmato, se c'è); una email riepiloga le pratiche.
    // Ritorna [inviate, [motivi delle escluse]]
    function invia_pratiche_segreteria($conn, array $ids, int $uff_id, int $uid, string $autore_nome = ''): array {
        return \App\Core\App::per($conn)->get(\App\Didattica\ServizioFlussoConvalide::class)->inviaPraticheSegreteria($ids, $uff_id, $uid, $autore_nome);
    }
}

if (!function_exists('html_editor_decisioni')) {
    // Tabella delle decisioni (convalide o piano) da compilare: nella pratica (referente) e in seduta. $ins_dip: anagrafe del Dipartimento
    function html_editor_decisioni(string $tipo_d, array $dec, array $ins_dip): string {
        return \App\Core\App::get(\App\Didattica\EditorDecisioni::class)->editor($tipo_d, $dec, $ins_dip);
    }
    // Elenco dell'anagrafe per i campi «convalidato con» e lo script delle tabelle (una volta per pagina)
    function html_supporto_decisioni(array $ins_dip): string {
        return \App\Core\App::get(\App\Didattica\EditorDecisioni::class)->supporto($ins_dip);
    }
}

if (!function_exists('valore_iniziale_campo')) {
    // Valore proposto al primo caricamento del modulo, dai dati dell'accesso (codice fiscale, matricola, cellulare)
    function valore_iniziale_campo(array $c, ?array $u): string {
        return \App\Didattica\EditorDecisioni::valoreIniziale($c, $u);
    }
}
