<?php
// Prove del modulo Sedute (App\Didattica: consigli, sedute, presenze, decisioni, verbale, convocazioni, firma del verbale): le funzioni di
// inc/sedute.php e inc/didattica.php come facciate dei servizi, la cartella protetta dei verbali e i collegamenti nel container. Chiamato da
// esegui.php: stesse variabili ($conn, $q, $EMAIL) e funzioni (prova, sezione). I dati di prova (consiglio «Consiglio prova 81» e le sue sedute)
// vengono ripuliti alla fine.
sezione("Modulo Sedute: facciate di inc/, consigli, presenze, decisioni, convocazioni e verbale");

use App\Core\App as AppSed;
use App\Didattica\ServizioConsigli;
use App\Didattica\ServizioConvocazioni;
use App\Didattica\ServizioSedute;
use App\Didattica\TestiPratica;
use App\Didattica\VerbaleFirmato;

$c_sed = AppSed::per($conn);
$s_con = $c_sed->get(ServizioConsigli::class);
$s_sed = $c_sed->get(ServizioSedute::class);
$s_cvz = $c_sed->get(ServizioConvocazioni::class);
$s_ver = $c_sed->get(VerbaleFirmato::class);

prova($c_sed->get(\App\Iscritti\Pianificati::class) instanceof \App\Didattica\PianificatiDidattica && $s_ver instanceof VerbaleFirmato,
    "container: compiti pianificati e firma del verbale serviti da App\\Didattica");
prova(STATI_VERBALE === \App\Didattica\Costanti::STATI_VERBALE && STATI_PRESENZA === \App\Didattica\Costanti::STATI_PRESENZA
    && QUALIFICHE_CONSIGLIO === \App\Didattica\Costanti::QUALIFICHE_CONSIGLIO && ESITI_SEDUTA === \App\Didattica\Costanti::ESITI_SEDUTA && TESTO_CONVOCAZIONE === \App\Didattica\Costanti::TESTO_CONVOCAZIONE,
    "costanti globali di prima, con i valori di App\\Didattica\\Costanti");

// Funzioni pure: facciata e classe danno la stessa risposta
$mod_v = ['titolo' => 'Convalide', 'verbale_json' => json_encode(['sezione' => 'Convalide di esami', 'testo' => '{STUDENTE} (matr. {matricola}) chiede la convalida.', 'delibera' => 'Il Consiglio approva.'])];
$pr_v = ['nome' => 'Luca', 'cognome' => 'Rossi', 'matricola' => '8181', 'email' => 'luca81@example.org', 'codice' => 'PR-81', 'creata_il' => '2026-10-03 10:00:00', 'protocollo' => '', 'protocollo_data' => null,
         'risposte_json' => json_encode([['etichetta' => 'Corso di studio', 'tipo' => 'text', 'valore' => 'Biologia']]), 'delibera' => '', 'esito_seduta' => ''];
prova(verbale_modulo($mod_v) === TestiPratica::verbaleModulo($mod_v) && verbale_modulo($mod_v)['sezione'] === 'Convalide di esami' && decisione_modulo($mod_v) === TestiPratica::decisioneModulo($mod_v), "verbale_modulo() e decisione_modulo()");
prova(testo_segnaposti_pratica('{STUDENTE} {matricola} {Corso di studio}', $pr_v) === 'ROSSI LUCA 8181 Biologia' && testo_segnaposti_pratica('{STUDENTE}', $pr_v) === TestiPratica::segnaposti('{STUDENTE}', $pr_v), "segnaposti dei testi del verbale");
prova(riepilogo_presenze([['stato' => 'P'], ['stato' => 'P'], ['stato' => 'AG'], ['stato' => 'AI']]) === ['P' => 2, 'AG' => 1, 'AI' => 1] && riepilogo_presenze([]) === ServizioSedute::riepilogo([]), "riepilogo_presenze()");
$dec_v = ['tipo' => 'convalida', 'righe' => [['richiesto' => 'Analisi 1', 'ins' => 'Analisi', 'esito' => 'totale', 'cfu_ric' => '9', 'cfu_int' => '0', 'cfu' => '9', 'voto' => '27', 'ssd' => 'MAT/05', 'data' => '', 'ins_cfu' => '9']]];
prova(tabella_decisioni($dec_v) === TestiPratica::tabellaDecisioni($dec_v) && testo_decisioni(json_encode($dec_v)) === TestiPratica::testoDecisioni(json_encode($dec_v)) && str_contains(testo_decisioni(json_encode($dec_v)), 'Analisi'), "quadro delle decisioni: tabella e testo");
$post_v = ['d_richiesto' => ['Analisi 1', ''], 'd_ins' => ['', ''], 'd_cfu' => ['9', ''], 'd_esito' => ['totale', ''], 'd_voto' => ['27', '']];
prova(leggi_decisioni_post($conn, 'convalida', $post_v) === $c_sed->get(\App\Didattica\DecisioniSeduta::class)->leggiDalPost('convalida', $post_v) && count(leggi_decisioni_post($conn, 'convalida', $post_v)['righe']) === 1, "leggi_decisioni_post(): righe vuote saltate");
prova(testo_convocazione("Ciao {NOME}, il {DATA} alle {ORA} a {LUOGO}.\nGiustifica: {LINK_GIUSTIFICA}", ['organo' => 'X', 'data' => '2099-03-10', 'ora_inizio' => '10:00', 'luogo' => 'Aula', 'odg' => '', 'coordinatore' => ''], 'Anna', '') === 'Ciao Anna, il 10/03/2099 alle 10:00 a Aula.' . "\n"
    && testo_convocazione('a {LINK_GIUSTIFICA}', ['organo' => '', 'data' => null, 'ora_inizio' => '', 'luogo' => '', 'odg' => '', 'coordinatore' => ''], 'A', 'L') === 'a L', "testo_convocazione(): segnaposti");

// Dati di prova
$ripulisci_81 = function () use ($q, $conn) {
    foreach (["DELETE FROM pratiche_eventi WHERE pratica_id IN (SELECT id FROM pratiche WHERE codice = 'PR-PROVA81')", "DELETE FROM pratiche WHERE codice = 'PR-PROVA81'", "DELETE FROM didattica_moduli WHERE id = 9981",
              "DELETE FROM didattica_convocazioni WHERE seduta_id IN (SELECT id FROM didattica_sedute WHERE organo = 'Consiglio prova 81')", "DELETE FROM didattica_sedute_presenze WHERE seduta_id IN (SELECT id FROM didattica_sedute WHERE organo = 'Consiglio prova 81')",
              "DELETE FROM didattica_sedute WHERE organo = 'Consiglio prova 81'", "DELETE FROM didattica_consigli_persone WHERE consiglio_id IN (SELECT id FROM didattica_consigli WHERE nome = 'Consiglio prova 81')",
              "DELETE FROM didattica_consigli WHERE nome = 'Consiglio prova 81'"] as $sql) $q($sql);
};
$ripulisci_81();
$r81 = $s_con->salvaDaModulo(0, ['c_nome' => 'Consiglio prova 81', 'c_coordinatore' => 'Coord Ottantuno', 'c_segretario' => 'Segr Ottantuno', 'c_attivo' => '1']);
$cid81 = $r81['id'];
prova($cid81 > 0 && $r81['errore'] === null && consiglio_didattica($conn, $cid81)['nome'] === 'Consiglio prova 81' && consiglio_didattica($conn, $cid81) === $s_con->consiglio($cid81)
    && in_array($cid81, array_map('intval', array_column(consigli_didattica($conn), 'id')), true) && $s_con->salvaDaModulo(0, ['c_nome' => ' '])['errore'] === 'nome', "consigli: salvataggio, lettura e nome obbligatorio");
prova(aggiungi_persona_consiglio($conn, $cid81, 'componente', '', 'Professori ordinari', 'Verdi Ottantuno') === null && aggiungi_persona_consiglio($conn, $cid81, 'componente', '', '', 'Bianchi Ottantuno') === null
    && aggiungi_persona_consiglio($conn, $cid81, 'componente', '', '', 'verdi ottantuno') === 'È già tra i componenti.' && aggiungi_persona_consiglio($conn, $cid81, 'referente', '', '', 'Ref Ottantuno', 'ref81@example.org') === null
    && aggiungi_persona_consiglio($conn, $cid81, 'referente', '', '', 'Senza Email') !== null && count(persone_consiglio($conn, $cid81)) === 2 && persone_consiglio($conn, $cid81, 'referente')[0]['email'] === 'ref81@example.org', "consigli: componenti e referenti");
prova(consigli_referente($conn, ['id' => 7, 'email' => 'REF81@example.org']) === [$cid81] && consigli_referente($conn, ['id' => 7, 'email' => 'altro@example.org']) === [] && consigli_referente($conn, null) === [], "consigli_referente(): riconosciuto dall'email");

$sid81 = $s_sed->salva(0, ['organo' => 'Consiglio prova 81', 'anno_accademico' => '2026/2027', 'data' => '2099-03-10', 'ora_inizio' => '10:00', 'ora_fine' => '12:00', 'luogo' => 'Aula 81',
    'odg' => "Comunicazioni\n2) Pratiche studenti", 'presenze' => '', 'segretario' => 'Segr Ottantuno', 'coordinatore' => 'Coord Ottantuno', 'consiglio_id' => $cid81]);
$sed81 = seduta_didattica($conn, $sid81);
prova($sed81 === $s_sed->seduta($sid81) && etichetta_seduta($sed81) === '10/03/2099 – Consiglio prova 81' && presenze_registrate($conn, $sed81) === [], "sedute: salvataggio, lettura ed etichetta; nessuna presenza registrata");
$pres81 = presenze_seduta($conn, $sed81);
$ids81 = array_keys($pres81);
prova(count($pres81) === 2 && salva_presenze_seduta($conn, $sed81, [$ids81[0] => 'AG', $ids81[1] => 'XX']) === 2 && array_column(presenze_registrate($conn, $sed81), 'stato') === ['AG', 'P'] && riepilogo_presenze(presenze_registrate($conn, $sed81)) === ['P' => 1, 'AG' => 1, 'AI' => 0],
    "presenze: salvate per componente, stato sconosciuto ignorato");

// Convocazione e giustificazione
$EMAIL = [];
prova(invia_convocazione($conn, $sed81, 'Convocazione {ORGANO}', "Gentile {NOME},\n{LINK_GIUSTIFICA}", true) === [0, 2, null] && $EMAIL === [], "convocazione: i componenti senza email non ricevono nulla");
$q("UPDATE didattica_consigli_persone SET email = CONCAT('comp81_', id, '@example.org') WHERE consiglio_id = $cid81 AND ruolo = 'componente'");
$sed81 = seduta_didattica($conn, $sid81);
$r_inv = invia_convocazione($conn, $sed81, 'Convocazione {ORGANO}', "Gentile {NOME},\n{LINK_GIUSTIFICA}", true);
prova($r_inv === [2, 0, null] && count($EMAIL) === 2 && $EMAIL[0]['oggetto'] === 'Convocazione Consiglio prova 81' && str_contains($EMAIL[0]['corpo'], 'giustifica.php?t='), "convocazione: due email con il link personale");
$tok81 = (string)$conn->query("SELECT token FROM didattica_convocazioni WHERE seduta_id = $sid81 ORDER BY id LIMIT 1")->fetch_row()[0];
prova(convocazione_per_token($conn, $tok81)['seduta_id'] == $sid81 && convocazione_per_token($conn, 'xyz') === null && giustifica_assenza($conn, str_repeat('a', 40), 'x') === 'Il link non è valido.' && giustifica_assenza($conn, $tok81, ' In missione ') === null
    && count(convocazioni_seduta($conn, $sid81)) === 2 && $s_cvz->dellaSeduta($sid81) === convocazioni_seduta($conn, $sid81), "giustificazione dal link: presenza AG e motivo");
prova(importa_componenti_consiglio($conn, $cid81, $cid81) === 0 && conserva_dati_sedute($conn, 0) === 0, "importa_componenti_consiglio() e conserva_dati_sedute(): casi nulli");

// Decisioni, esiti, estratto e verbale
$q("INSERT INTO didattica_moduli (id, titolo, categoria, tipo, campi_json, destinatari, attivo, giorni_promemoria, aggiornato_il, verbale_json) VALUES (9981, 'Modulo prova 81', 'Prova', 'online', '[]', 'tutti', 1, 7, NOW(), '"
    . $conn->real_escape_string($mod_v['verbale_json']) . "')");
$q("INSERT INTO pratiche (modulo_id, codice, nome, cognome, email, matricola, risposte_json, stato, seduta_id) VALUES (9981, 'PR-PROVA81', 'Luca', 'Rossi', 'luca81@example.org', '8181', '[]', 'inviata', $sid81)");
$pid81 = (int)$conn->query("SELECT id FROM pratiche WHERE codice = 'PR-PROVA81'")->fetch_row()[0];
prova(salva_decisioni_seduta($conn, $pid81, 'approvata', $dec_v, ' Ok ') && ($pr = $conn->query("SELECT esito_seduta, delibera FROM pratiche WHERE id = $pid81")->fetch_row()) && $pr === ['approvata', 'Ok'], "salva_decisioni_seduta(): esito e delibera");
$pp81 = pratiche_per_esportazione($conn, [$pid81, 0]);
prova(count($pp81) === 1 && $pp81[0]['modulo_titolo'] === 'Modulo prova 81' && str_starts_with(pdf_estratto_pratica($conn, $sed81, $pp81[0]), '%PDF'), "pratiche per l'esportazione ed estratto in PDF");
$docx81 = genera_verbale_pratiche($conn, $sed81, $pp81);
$xlsx81 = genera_excel_pratiche($conn, $pp81);
prova(is_string($docx81) && str_starts_with((string)@file_get_contents($docx81), 'PK') && is_string($xlsx81) && str_starts_with((string)@file_get_contents($xlsx81), 'PK'), "verbale in Word ed elenco in Excel");
@unlink((string)$docx81);
@unlink((string)$xlsx81);
$EMAIL = [];
prova(applica_esiti_seduta($conn, $sed81, 1, 'Prove') === [1, 0, 0] && $conn->query("SELECT stato FROM pratiche WHERE id = $pid81")->fetch_row()[0] === 'accolta' && count($EMAIL) >= 1 && applica_esiti_seduta($conn, $sed81, 1) === [0, 0, 0], "applica_esiti_seduta(): pratica accolta una sola volta");

// Verbale firmato: invio alla firma, link, firmatario e solleciti
prova(invia_verbale_alla_firma($conn, 99999, '%PDF-1.4', 'a@example.org', 'b@example.org') === 'Seduta non trovata.' && invia_verbale_alla_firma($conn, $sid81, 'boh', 'a@example.org', 'b@example.org') !== null
    && invia_verbale_alla_firma($conn, $sid81, '%PDF-1.4 prova', 'seg81@example.org', 'coo81@example.org') === null, "verbale: invio alla firma");
$sed81 = seduta_didattica($conn, $sid81);
$pdf81 = percorso_verbale_pdf($sed81);
prova($sed81['verbale_stato'] === 'segretario' && $pdf81 !== null && is_file($pdf81) && seduta_per_token_verbale($conn, $sed81['verbale_token'])['id'] == $sid81 && firmatario_verbale($sed81, ['email' => 'SEG81@example.org']) && !firmatario_verbale($sed81, ['email' => 'coo81@example.org'])
    && $s_ver->percorso($sed81) === $pdf81 && percorso_verbale_pdf(['verbale_pdf' => '../.env']) === null, "verbale: stato, percorso protetto e firmatario");
$q("UPDATE didattica_sedute SET verbale_inviato_il = NOW() - INTERVAL 30 DAY WHERE id = $sid81");
$EMAIL = [];
prova(solleciti_verbali($conn) >= 1 && count($EMAIL) >= 1 && $EMAIL[0]['a'] === 'seg81@example.org', "verbale: sollecito della firma del segretario");
prova(is_file(RADICE_SITO . '/' . DIR_VERBALI . '.htaccess'), "cartella dei verbali protetta (.htaccess)");

if ($pdf81) @unlink($pdf81);
$ripulisci_81();
