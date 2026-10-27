<?php
// Prove dell'area personale per profilo (App\Portale\Area): chi è la persona (profili), i riquadri di sistema e quelli scritti dall'amministratore, l'ordine,
// l'interruttore. Chiamato da esegui.php. Dati di prova con id 995xx.
sezione("Area personale per profilo");

use App\Portale\Area\CatalogoRiquadri;
use App\Portale\Area\Profili;
use App\Portale\Area\ProfiliPersona;
use App\Portale\Area\RiquadriRepository;

$cont_ap = \App\Core\App::per($conn);
$rep_ap = $cont_ap->get(RiquadriRepository::class);
$quando_ap = '2026-10-27 12:00:00';

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// I profili e il catalogo
// ---------------------------------------------------------------------------------------------------------------------------------------------------
prova(Profili::da('esterno, studente,boh,studente,docente') === ['studente', 'docente', 'esterno'] && Profili::da('') === [] && Profili::inCsv(['amministratore', 'studente']) === 'studente,amministratore', "profili: solo quelli validi, senza ripetizioni e nell'ordine di sempre");
$cat_ap = CatalogoRiquadri::sistema();
$profili_ok = true; $ordini = [];
foreach ($cat_ap as $k => $v) {
    $profili_ok = $profili_ok && $v['profili'] !== [] && Profili::da(implode(',', $v['profili'])) === array_values(array_intersect(array_keys(Profili::NOMI), $v['profili']))
        && RiquadriRepository::iconaValida($v['icona']) && RiquadriRepository::coloreValido($v['colore']) && in_array($v['tipo'], [CatalogoRiquadri::SPECIALE, CatalogoRiquadri::SCHEDA], true)
        && ($v['tipo'] !== CatalogoRiquadri::SCHEDA || (RiquadriRepository::urlValido($v['link']) && RiquadriRepository::urlValido($v['link2']) && $v['testo'] !== ''));
    $ordini[] = $v['ordine'];
}
prova($profili_ok && count(array_unique($ordini)) === count($ordini), "catalogo: profili, icone, colori e collegamenti validi; ordini tutti diversi");
prova(in_array(Profili::AMMINISTRATORE, $cat_ap['da_fare']['profili'], true) && in_array(Profili::DOCENTE, $cat_ap['tutorato_registro']['profili'], true) && ($cat_ap['tutorato_pagamenti']['attivo'] ?? 1) === 0
    && str_contains($cat_ap['tirocini']['link2'], 'tsp.esse3.cineca.it'), "catalogo: l'amministratore ha tutto, il docente il tutorato, i pagamenti del tutorato spenti, i tirocini rimandano a TSP 2.0");

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// L'archivio: il seme e le modifiche dell'amministratore
// ---------------------------------------------------------------------------------------------------------------------------------------------------
$q("DELETE FROM area_riquadri WHERE chiave IS NULL OR chiave IN ('prova_ap')");
$tutti_ap = $rep_ap->tutti();
$chiavi_ap = array_column(array_filter($tutti_ap, fn($r) => $r['sistema']), 'chiave');
prova(array_diff(array_keys($cat_ap), $chiavi_ap) === [], "archivio: tutti i riquadri di sistema sono presenti dopo l'aggiornamento dello schema");
prova($rep_ap->semina($quando_ap) === 0, "archivio: riseminare non aggiunge né cambia niente");
$q("DELETE FROM area_riquadri WHERE chiave = 'guida'");
$q("UPDATE area_riquadri SET attivo = 0, profili = 'studente' WHERE chiave = 'assistenza'");
prova($rep_ap->semina($quando_ap) === 1, "archivio: un riquadro di sistema che manca si riaggiunge");
$ass = array_values(array_filter($rep_ap->tutti(), fn($r) => $r['chiave'] === 'assistenza'))[0];
prova(!$ass['attivo'] && $ass['profili'] === ['studente'], "archivio: un riquadro spento o cambiato dall'amministratore resta com'è");
$q("UPDATE area_riquadri SET attivo = 1, profili = '" . Profili::inCsv($cat_ap['assistenza']['profili']) . "' WHERE chiave = 'assistenza'");
$pag_ap = array_values(array_filter($rep_ap->tutti(), fn($r) => $r['chiave'] === 'tutorato_pagamenti'))[0];
prova(!$pag_ap['attivo'] && !in_array('tutorato_pagamenti', array_column($rep_ap->perProfili([Profili::PERSONALE, Profili::AMMINISTRATORE]), 'chiave'), true), "archivio: i pagamenti del tutorato sono spenti e non compaiono a nessuno");
$tir_ap = array_values(array_filter($rep_ap->tutti(), fn($r) => $r['chiave'] === 'tirocini'))[0];
prova($tir_ap['tipo'] === 'scheda' && $tir_ap['titolo'] === 'Tirocini' && $tir_ap['link'] === 'percorso_tirocini.php' && str_contains($tir_ap['link2'], 'tsp.esse3.cineca.it'), "archivio: la scheda Tirocini ha guida e collegamento alla piattaforma");

$chiavi_di = fn(array $profili) => array_column($rep_ap->perProfili($profili), 'chiave');
$stu_ap = $chiavi_di([Profili::STUDENTE]);
prova(in_array('tirocini', $stu_ap, true) && in_array('pratiche', $stu_ap, true) && in_array('tutorato_mio', $stu_ap, true) && !in_array('cruscotto_direttore', $stu_ap, true) && !in_array('ricevimento', $stu_ap, true), "riquadri: lo studente vede tirocini, pratiche e il suo tutorato, non il cruscotto del Direttore");
$doc_ap = $chiavi_di([Profili::DOCENTE]);
prova(in_array('tutorato_registro', $doc_ap, true) && in_array('ricevimento', $doc_ap, true) && in_array('tirocini', $doc_ap, true) && !in_array('tutorato_mio', $doc_ap, true), "riquadri: il docente vede il tutorato (registro, lettera e fine attività), il ricevimento e i tirocini");
prova(in_array('cruscotto_direttore', $chiavi_di([Profili::DIRETTORE]), true) && in_array('direttore_assente', $chiavi_di([Profili::DIRETTORE]), true), "riquadri: il Direttore vede cruscotto e «Direttore assente»");
$adm_ap = $chiavi_di([Profili::AMMINISTRATORE]);
prova(count(array_diff(array_keys(array_filter($cat_ap, fn($v) => ($v['attivo'] ?? 1) === 1)), $adm_ap)) === 0, "riquadri: l'amministratore vede tutti quelli accesi");
$multi_ap = $rep_ap->perProfili([Profili::STUDENTE, Profili::DOCENTE]);
prova(count(array_unique(array_column($multi_ap, 'chiave'))) === count($multi_ap) && array_column($multi_ap, 'ordine') === array_values(array_column($multi_ap, 'ordine')) && $multi_ap === array_values($multi_ap)
    && count($multi_ap) >= count($rep_ap->perProfili([Profili::STUDENTE])) && count($multi_ap) >= count($rep_ap->perProfili([Profili::DOCENTE])), "riquadri: con più profili si vede l'unione, senza doppioni");
$ord_ap = array_column($multi_ap, 'ordine'); $ord_ok = $ord_ap; sort($ord_ok);
prova($ord_ap === $ord_ok, "riquadri: sempre in ordine");

// Riquadri scritti dall'amministratore
[$id0, $e0] = $rep_ap->salva(0, ['titolo' => '  ', 'profili' => ['studente']], $quando_ap);
prova($id0 === 0 && $e0 !== null, "salva: il titolo ci vuole");
[$id0, $e0] = $rep_ap->salva(0, ['titolo' => 'X', 'link' => 'javascript:alert(1)', 'profili' => ['studente']], $quando_ap);
prova($id0 === 0 && str_contains((string) $e0, 'collegamento'), "salva: un collegamento strano si rifiuta");
[$id0, $e0] = $rep_ap->salva(0, ['titolo' => str_repeat('x', 121)], $quando_ap);
prova($id0 === 0 && $e0 !== null, "salva: titolo troppo lungo rifiutato");
[$idc, $ec] = $rep_ap->salva(0, ['titolo' => 'Borse di studio', 'testo' => "Bando in scadenza\nPrepara l'ISEE", 'icona' => 'fa-graduation-cap', 'colore' => '#aa3300', 'link' => 'https://www.unical.it/borse',
    'etichetta_link' => 'Il bando', 'link2' => 'pratiche.php?x=1', 'etichetta_link2' => '', 'profili' => ['studente', 'boh', 'esterno'], 'attivo' => 1], $quando_ap);
$c_ap = $rep_ap->perId($idc);
prova($ec === null && $idc > 0 && $c_ap['tipo'] === 'personalizzato' && !$c_ap['sistema'] && $c_ap['profili'] === ['studente', 'esterno'] && $c_ap['link'] === 'https://www.unical.it/borse'
    && $c_ap['link2'] === 'pratiche.php?x=1' && $c_ap['etichetta_link2'] === '', "salva: nuovo riquadro con profili validi e collegamenti (l'etichetta vuota resta vuota)", json_encode($c_ap));
prova($c_ap['ordine'] > $cat_ap['guida']['ordine'] && in_array('Borse di studio', array_column($rep_ap->perProfili([Profili::STUDENTE]), 'titolo'), true) && !in_array('Borse di studio', array_column($rep_ap->perProfili([Profili::DOCENTE]), 'titolo'), true), "salva: il nuovo riquadro va in fondo e lo vedono solo i suoi profili");
[$u1, $eu] = $rep_ap->salva($idc, ['titolo' => 'Borse e premi', 'testo' => 'Nuovo testo', 'icona' => 'non-valida', 'colore' => 'rosso', 'profili' => ['docente'], 'attivo' => 0], $quando_ap);
$c2_ap = $rep_ap->perId($idc);
prova($eu === null && $c2_ap['titolo'] === 'Borse e premi' && $c2_ap['icona'] === 'fa-circle-info' && $c2_ap['colore'] === '#0056B3' && $c2_ap['profili'] === ['docente'] && !$c2_ap['attivo']
    && $rep_ap->perProfili([Profili::DOCENTE]) === array_values(array_filter($rep_ap->perProfili([Profili::DOCENTE]), fn($r) => $r['id'] !== $idc)), "salva: modifica; icona e colore non validi tornano a quelli di serie; spento non si vede");
// Riquadri di sistema: speciale = solo profili, ordine, interruttore e titolo; scheda = anche testi
$id_cr = (int) array_values(array_filter($rep_ap->tutti(), fn($r) => $r['chiave'] === 'cruscotto_direttore'))[0]['id'];
$rep_ap->salva($id_cr, ['titolo' => '', 'testo' => 'non deve restare', 'link' => 'altro.php', 'profili' => ['direttore'], 'attivo' => 1, 'ordine' => 21], $quando_ap);
$cr = $rep_ap->perId($id_cr);
prova($cr['titolo'] === 'Cruscotto del Direttore' && $cr['testo'] === '' && $cr['link'] === '' && $cr['profili'] === ['direttore'] && $cr['ordine'] === 21 && $cr['sistema'], "salva: di un riquadro «speciale» cambiano profili e ordine, non i testi; il titolo vuoto torna quello di serie");
$rep_ap->salva($id_cr, ['titolo' => '', 'profili' => $cat_ap['cruscotto_direttore']['profili'], 'attivo' => 1, 'ordine' => 20], $quando_ap);
$id_ti = (int) $tir_ap['id'];
[, $et] = $rep_ap->salva($id_ti, ['titolo' => 'Tirocini e stage', 'testo' => 'Testo cambiato', 'icona' => 'fa-briefcase', 'colore' => '#0e7490', 'link' => 'percorso_tirocini.php', 'etichetta_link' => 'La guida',
    'link2' => 'https://unical.tsp.esse3.cineca.it', 'etichetta_link2' => 'Piattaforma TSP 2.0', 'profili' => ['studente', 'docente'], 'attivo' => 1], $quando_ap);
prova($et === null && $rep_ap->perId($id_ti)['testo'] === 'Testo cambiato' && $rep_ap->perId($id_ti)['profili'] === ['studente', 'docente'], "salva: di una scheda di sistema cambiano anche testo e collegamenti");
$rep_ap->salva($id_ti, ['titolo' => $tir_ap['titolo'], 'testo' => $tir_ap['testo'], 'icona' => $tir_ap['icona'], 'colore' => $tir_ap['colore'], 'link' => $tir_ap['link'], 'etichetta_link' => $tir_ap['etichetta_link'],
    'link2' => $tir_ap['link2'], 'etichetta_link2' => $tir_ap['etichetta_link2'], 'profili' => $tir_ap['profili'], 'attivo' => 1], $quando_ap);
prova($rep_ap->elimina($id_ti) === false && $rep_ap->perId($id_ti) !== null && $rep_ap->elimina($idc) === true && $rep_ap->perId($idc) === null, "elimina: i riquadri di sistema non si eliminano (si spengono), quelli scritti dall'amministratore sì");
prova($rep_ap->salva(999999, ['titolo' => 'x'], $quando_ap)[1] === 'Riquadro non trovato.', "salva: riquadro che non esiste");

// Ordine
$el0 = $rep_ap->perProfilo(Profili::STUDENTE);
$prima = array_column($el0, 'chiave');
$rep_ap->sposta((int) $el0[1]['id'], -1, Profili::STUDENTE, $quando_ap);
$dopo = array_column($rep_ap->perProfilo(Profili::STUDENTE), 'chiave');
prova($dopo[0] === $prima[1] && $dopo[1] === $prima[0] && array_slice($dopo, 2) === array_slice($prima, 2), "ordine: «su» scambia con il riquadro sopra");
$rep_ap->sposta((int) $rep_ap->perProfilo(Profili::STUDENTE)[0]['id'], -1, Profili::STUDENTE, $quando_ap);
prova(array_column($rep_ap->perProfilo(Profili::STUDENTE), 'chiave') === $dopo, "ordine: il primo non va più su");
$ult = $rep_ap->perProfilo(Profili::STUDENTE); $rep_ap->sposta((int) end($ult)['id'], 1, Profili::STUDENTE, $quando_ap);
prova(array_column($rep_ap->perProfilo(Profili::STUDENTE), 'chiave') === $dopo, "ordine: l'ultimo non va più giù");
$q("UPDATE area_riquadri SET ordine = 500 WHERE chiave IN ('da_fare', 'assistenza')"); // due riquadri con lo stesso numero: si distanziano
$el1 = $rep_ap->perProfilo(Profili::STUDENTE);
$pos_a = array_search('da_fare', array_column($el1, 'chiave'), true);
$rep_ap->sposta((int) $el1[$pos_a]['id'], 1, Profili::STUDENTE, $quando_ap);
$ordini2 = array_column($rep_ap->perProfilo(Profili::STUDENTE), 'ordine');
prova(count($ordini2) === count(array_unique($ordini2)), "ordine: due riquadri con lo stesso numero si distanziano");
foreach ($cat_ap as $k => $v) $q("UPDATE area_riquadri SET ordine = " . (int) $v['ordine'] . " WHERE chiave = '$k'");

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Chi è la persona
// ---------------------------------------------------------------------------------------------------------------------------------------------------
foreach (["DELETE FROM utenti WHERE id BETWEEN 99501 AND 99509", "DELETE FROM uffici_persone WHERE email LIKE '%-ap995@example.org'", "DELETE FROM personale_ateneo WHERE id = 'prova.ap995'"] as $sql) $q($sql);
$id_doc_ruolo = (int) $db_oop->valore("SELECT id FROM ruoli WHERE nome = ?", [\App\Anagrafi\Anagrafe::GRUPPI_PERSONALE['docenti']]);
$q("INSERT INTO personale_ateneo (id, cognome, nome, email, ruolo, ruolo_cod, gruppo, docente, attivo) VALUES ('prova.ap995', 'Pta', 'Prova', 'pta-ap995@example.org', 'Tecnico', 'ND', 'pta', 0, 1)");
$q("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari, matricola_studente, persona_id) VALUES
    (99501, 'Stu', 'Dente', 'stu-ap995@example.org', 3, '', NULL, NULL), (99502, 'Os', 'Pite', 'osp-ap995@example.org', 5, '', NULL, NULL),
    (99503, 'Ad', 'Min', 'adm-ap995@example.org', 1, '', NULL, NULL), (99504, 'Ges', 'Tore', 'ges-ap995@example.org', 2, '', NULL, NULL),
    (99505, 'Do', 'Cente', 'doc-ap995@example.org', 5, '$id_doc_ruolo', NULL, NULL), (99506, 'Pt', 'Ta', 'pta-ap995@example.org', 5, '', NULL, 'prova.ap995'),
    (99507, 'Dir', 'Ettore', 'dir-ap995@example.org', 5, '', NULL, NULL), (99508, 'Ex', 'Matricola', 'mat-ap995@example.org', 5, '', 'S123456', NULL),
    (99509, 'Uff', 'Icio', 'uff-ap995@example.org', 5, '', NULL, NULL)");
$dir_ap = $cont_ap->get(\App\Uffici\Direzione::class)->ufficio();
if ($dir_ap) $q("INSERT INTO uffici_persone (ufficio_id, email, nominativo, ruolo, compiti) VALUES (" . (int) $dir_ap['id'] . ", 'dir-ap995@example.org', 'Dir Ettore', 'Direttore', '')");
$uff_ap = (int) $db_oop->valore("SELECT id FROM uffici WHERE chiave = 'assistenza'");
$q("INSERT INTO uffici_persone (ufficio_id, email, nominativo, ruolo, compiti) VALUES ($uff_ap, 'uff-ap995@example.org', 'Uff Icio', '', '')");
$pp_ap = $cont_ap->get(ProfiliPersona::class);
$di_ap = fn(int $id) => $pp_ap->di($cont_ap->get(\App\Auth\UtenteRepository::class)->perId($id));
prova($di_ap(99501) === ['studente'] && $di_ap(99508) === ['studente'], "profili della persona: studente dal ruolo o dalla matricola");
prova($di_ap(99502) === ['esterno'] && $pp_ap->di(null) === ['esterno'] && $pp_ap->di(['id' => 0]) === ['esterno'], "profili della persona: chi non rientra in nessun profilo è esterno");
prova($di_ap(99503) === ['amministratore'] && $di_ap(99504) === ['gestore'], "profili della persona: amministratore e gestore dal ruolo");
prova($di_ap(99505) === ['docente'], "profili della persona: docente dal gruppo «Docenti»", json_encode($di_ap(99505)));
prova($di_ap(99506) === ['personale'], "profili della persona: personale dall'anagrafe di Ateneo", json_encode($di_ap(99506)));
prova($dir_ap ? $di_ap(99507) === ['direttore'] : true, "profili della persona: il Direttore dalla Direzione (non conta come personale)", json_encode($di_ap(99507)));
prova($di_ap(99509) === ['personale'], "profili della persona: chi fa parte di un ufficio è personale");
$q("UPDATE utenti SET ruoli_secondari = '$id_doc_ruolo,3' WHERE id = 99501");
prova($di_ap(99501) === ['studente', 'docente'], "profili della persona: più profili, nell'ordine di sempre");
foreach (["DELETE FROM utenti WHERE id BETWEEN 99501 AND 99509", "DELETE FROM uffici_persone WHERE email LIKE '%-ap995@example.org'", "DELETE FROM personale_ateneo WHERE id = 'prova.ap995'"] as $sql) $q($sql);

// ---- La pagina: ogni riquadro «speciale» ha il suo file, la pagina li include e tiene fuori i pagamenti del tutorato ----
sezione("Area personale — pagina");
$senza_file_ap = [];
foreach (CatalogoRiquadri::sistema() as $chiave_ap => $v_ap) {
    if ($v_ap['tipo'] === CatalogoRiquadri::SPECIALE && !is_file(__DIR__ . '/../../../inc/area/' . $chiave_ap . '.php')) $senza_file_ap[] = $chiave_ap;
}
prova($senza_file_ap === [], "ogni riquadro speciale del catalogo ha il suo file in inc/area", implode(', ', $senza_file_ap));
prova(is_file(__DIR__ . '/../../../inc/area/_generico.php'), "c'è il disegno dei riquadri scritti dall'amministratore");
$pag_ap = (string) file_get_contents(__DIR__ . '/../../../area_personale.php');
prova(str_contains($pag_ap, 'perProfili($profili_mostrati)') && str_contains($pag_ap, "inc/area/' . \$riq['chiave']"), "la pagina legge i riquadri del profilo e li include in ordine");
require_once __DIR__ . '/../../../inc/area/_tutorato_tappe.php';
$inc_ap = ['id' => 0, 'stato' => 'firmata', 'fine_stato' => '', 'pagamento_stato' => '', 'confermata_il' => '2026-10-01 10:00:00', 'protocollo' => '', 'ore_approvate' => null,
    'fine_firmata_il' => null, 'pagamento_passato_il' => null, 'pagamento_data' => null, 'iter' => 'portale'];
$t_spenti_ap = area_tappe_tutorato($conn, $inc_ap, false);
$t_accesi_ap = area_tappe_tutorato($conn, $inc_ap, true);
prova(count($t_spenti_ap['tappe']) === 4 && count($t_accesi_ap['tappe']) === 6, "«Il mio tutorato» nasconde le tappe dei pagamenti finché l'ufficio pagamenti è spento", count($t_spenti_ap['tappe']) . '/' . count($t_accesi_ap['tappe']));
prova($t_spenti_ap['prima'] === 2 && $t_spenti_ap['firmata'], "«Il mio tutorato»: la prima tappa da fare è il registro, con la lettera già firmata", json_encode($t_spenti_ap['prima']));
$cruscotto_ap = (string) file_get_contents(__DIR__ . '/../../../area_personale.php');
prova(str_contains($cruscotto_ap, '_tessera.php') && str_contains($cruscotto_ap, "'Location: miei_eventi.php?'"), "il cruscotto usa le tessere e rimanda i vecchi indirizzi degli eventi a miei_eventi.php");
foreach (['miei_eventi.php', 'profilo.php', 'il_mio_tutorato.php'] as $pg_ap) prova(is_file(__DIR__ . '/../../../' . $pg_ap), "la pagina $pg_ap esiste");
prova(!str_contains($cruscotto_ap, 'name="aggiorna_email"') && !str_contains($cruscotto_ap, 'pills-tab'), "il cruscotto non contiene più i tab degli eventi né il modulo del profilo");
$riq_t_ap = $rep_ap->tutti();
$pag_riq_ap = array_values(array_filter($riq_t_ap, fn($r) => $r['chiave'] === 'tutorato_pagamenti'));
prova($pag_riq_ap && !$pag_riq_ap[0]['attivo'], "i pagamenti del tutorato partono spenti");
$doc_ap = array_filter($rep_ap->perProfili(['docente']), fn($r) => in_array($r['chiave'], ['tutorato_registro', 'tirocini'], true));
prova(count($doc_ap) === 2, "il docente vede il registro del tutorato (lettera di incarico e fine attività) e i tirocini");
$amm_ap = array_column($rep_ap->perProfili(['amministratore']), 'chiave');
$tutte_ap = array_keys(array_filter(CatalogoRiquadri::sistema(), fn($v) => ($v['attivo'] ?? 1) === 1));
prova(array_diff($tutte_ap, $amm_ap) === [], "l'amministratore vede tutti i riquadri accesi", implode(', ', array_diff($tutte_ap, $amm_ap)));
$tir_pag_ap = (string) file_get_contents(__DIR__ . '/../../../percorso_tirocini.php');
prova(substr_count($tir_pag_ap, 'unical.tsp.esse3.cineca.it') >= 2, "la guida dei tirocini rimanda alla piattaforma TSP 2.0");
