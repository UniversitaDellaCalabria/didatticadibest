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
prova(in_array(Profili::AMMINISTRATORE, $cat_ap['da_fare']['profili'], true) && in_array(Profili::DOCENTE, $cat_ap['approvazioni_docente']['profili'], true) && ($cat_ap['tutorato_pagamenti']['attivo'] ?? 1) === 0
    && str_contains($cat_ap['tirocini']['link2'], 'tsp.esse3.cineca.it'), "catalogo: l'amministratore ha tutto, il docente le approvazioni, i pagamenti del tutorato spenti, i tirocini rimandano a TSP 2.0");

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
prova(in_array('approvazioni_docente', $doc_ap, true) && !in_array('tutorato_registro', $doc_ap, true) && !in_array('firme', $doc_ap, true) && in_array('ricevimento', $doc_ap, true) && in_array('tirocini', $doc_ap, true) && in_array('tutorato_mio', $doc_ap, true), "riquadri: il docente vede «Le mie approvazioni» (firme, conferme e registri dei tutor al posto delle due tessere), il ricevimento, i tirocini e il suo tutorato");
foreach ([Profili::PERSONALE, Profili::SCUOLA, Profili::AZIENDA, Profili::ESTERNO, Profili::GESTORE, Profili::DIRETTORE] as $pf_t_) {
    $k_t_ = $chiavi_di([$pf_t_]);
    prova(in_array('tutorato_mio', $k_t_, true) && in_array('tutorato_registro', $k_t_, true), "riquadri: il profilo «{$pf_t_}» ha le tessere del tutorato (compaiono solo con un incarico)");
}
// Migrazione v109: i profili di partenza vecchi passano a tutti, quelli cambiati dall'amministratore restano come sono
$q("UPDATE area_riquadri SET profili = 'studente,amministratore' WHERE chiave = 'tutorato_mio'");
$q("UPDATE area_riquadri SET profili = 'docente,direttore' WHERE chiave = 'tutorato_registro'");
$mig_t = new \App\Core\Schema\Migrazioni(\App\Core\App::per($conn)->get(\App\Core\Database::class), sys_get_temp_dir());
(new ReflectionMethod($mig_t, 'datiDiPartenza'))->invoke($mig_t);
$mig_t_ = array_column($rep_ap->tutti(), 'profili', 'chiave');
prova(in_array(Profili::PERSONALE, $mig_t_['tutorato_mio'], true) && $mig_t_['tutorato_registro'] === [Profili::DOCENTE, Profili::DIRETTORE], "migrazione v109: tessera con i profili di partenza passa a tutti, quella cambiata dall'amministratore non si tocca");
$q("UPDATE area_riquadri SET profili = '" . Profili::inCsv(array_map('strval', $cat_ap['tutorato_registro']['profili'])) . "' WHERE chiave = 'tutorato_registro'");
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
// Il gruppo «Scuole» e il profilo «Scuola»
$gs_ap = new \App\Auth\GruppoScuole($db_oop);
$id_gs = $gs_ap->id();
prova($id_gs > 0 && $gs_ap->id() === $id_gs && (int) $db_oop->valore("SELECT COUNT(*) FROM ruoli WHERE nome = 'Scuole'") === 1, "gruppo Scuole: esiste una volta sola");
$q("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari, scuola_codice) VALUES (99510, 'Doc', 'Scuola', 'sc-ap995@example.org', 5, '', 'CSTF00003C'), (99511, 'Vec', 'Chio', 'vec-ap995@example.org', 5, '7', 'CSTF00003C'), (99512, 'Sen', 'Za', 'sen-ap995@example.org', 5, '', NULL)");
$rs_ap = fn(int $id) => (string) $db_oop->valore('SELECT ruoli_secondari FROM utenti WHERE id = ?', [$id]);
prova($di_ap(99510) === ['scuola'] && $di_ap(99512) === ['esterno'], "profili della persona: chi ha indicato una scuola è «scuola», chi non ha niente resta esterno", json_encode($di_ap(99510)));
$n_gs = $gs_ap->allineaTutti();
prova($n_gs >= 2 && $rs_ap(99510) === (string) $id_gs && $rs_ap(99511) === '7,' . $id_gs && $rs_ap(99512) === '' && $gs_ap->allineaTutti() === 0, "gruppo Scuole: lo storico lo riceve (senza toccare gli altri gruppi) e rifarlo non cambia niente", $rs_ap(99510) . ' | ' . $rs_ap(99511));
$gs_ap->assegna(99512); $gs_ap->assegna(99512);
prova($rs_ap(99512) === (string) $id_gs && $di_ap(99512) === ['scuola'], "gruppo Scuole: dato a mano una volta sola, basta per il profilo", $rs_ap(99512));
$gs_ap->allinea(99511, null);
prova($rs_ap(99511) === '7', "gruppo Scuole: tolta la scuola, il gruppo va via e gli altri restano", $rs_ap(99511));
$cont_ap->get(\App\Iscrizioni\PrenotazioneRepository::class)->impostaScuolaUtente(99511, 'CSTF00003C');
prova($rs_ap(99511) === '7,' . $id_gs, "gruppo Scuole: quando il docente sceglie la scuola il gruppo arriva da solo", $rs_ap(99511));
$riq_sc = array_column($rep_ap->perProfili(['scuola']), 'chiave');
prova(in_array('da_fare', $riq_sc, true) && in_array('assistenza', $riq_sc, true) && !in_array('tirocini', $riq_sc, true), "profilo Scuola: vede i riquadri di tutti (da fare, assistenza) ma non i tirocini universitari (TSP 2.0)", implode(',', $riq_sc));
// Migrazione v110: la scheda Tirocini perde la Scuola solo se i profili sono ancora quelli di partenza
$mig_s4 = new \App\Core\Schema\Migrazioni(\App\Core\App::per($conn)->get(\App\Core\Database::class), sys_get_temp_dir());
$q("UPDATE area_riquadri SET profili = 'studente,docente,esterno,amministratore,scuola,azienda' WHERE chiave = 'tirocini'"); // come sui siti già in uso: scuola e azienda aggiunte in coda dalla v104
(new ReflectionMethod($mig_s4, 'datiDiPartenza'))->invoke($mig_s4);
$pr_s4 = fn() => array_column($rep_ap->tutti(), 'profili', 'chiave')['tirocini'];
prova(!in_array('scuola', $pr_s4(), true) && in_array('esterno', $pr_s4(), true) && in_array('azienda', $pr_s4(), true), "migrazione v110: tirocini perde la Scuola e resta a studenti, docenti, aziende, esterni e amministratori");
$q("UPDATE area_riquadri SET profili = 'scuola,studente' WHERE chiave = 'tirocini'");
(new ReflectionMethod($mig_s4, 'datiDiPartenza'))->invoke($mig_s4);
prova($pr_s4() === ['scuola', 'studente'] || $pr_s4() === ['studente', 'scuola'], "migrazione v110: se l'amministratore aveva scelto i profili, non si toccano");
$q("UPDATE area_riquadri SET profili = '" . \App\Portale\Area\Profili::inCsv(array_map('strval', $cat_ap['tirocini']['profili'])) . "' WHERE chiave = 'tirocini'");
// Migrazione v111: «Da firmare» e il registro del tutorato perdono il Docente solo se i profili sono ancora quelli di partenza
$mig_d11 = new \App\Core\Schema\Migrazioni(\App\Core\App::per($conn)->get(\App\Core\Database::class), sys_get_temp_dir());
$tutti_d11 = 'studente,docente,direttore,personale,gestore,amministratore,scuola,azienda,esterno';
$q("UPDATE area_riquadri SET profili = '$tutti_d11' WHERE chiave IN ('firme', 'tutorato_registro')");
(new ReflectionMethod($mig_d11, 'datiDiPartenza'))->invoke($mig_d11);
$pr_d11 = fn(string $k) => array_column($rep_ap->tutti(), 'profili', 'chiave')[$k];
prova(!in_array('docente', $pr_d11('firme'), true) && !in_array('docente', $pr_d11('tutorato_registro'), true) && in_array('direttore', $pr_d11('firme'), true) && in_array('studente', $pr_d11('tutorato_registro'), true), "migrazione v111: «Da firmare» e registro del tutorato perdono il Docente e restano agli altri profili");
$q("UPDATE area_riquadri SET profili = 'docente,studente' WHERE chiave = 'firme'");
(new ReflectionMethod($mig_d11, 'datiDiPartenza'))->invoke($mig_d11);
prova($pr_d11('firme') === ['docente', 'studente'] || $pr_d11('firme') === ['studente', 'docente'], "migrazione v111: se l'amministratore aveva scelto i profili, non si toccano");
foreach (['firme', 'tutorato_registro'] as $k_d11) {
    $q("UPDATE area_riquadri SET profili = '" . \App\Portale\Area\Profili::inCsv(array_map('strval', $cat_ap[$k_d11]['profili'])) . "' WHERE chiave = '$k_d11'");
}
// Il gruppo «Aziende» e il profilo «Azienda»: dall'email di una convenzione di tesi o dal gruppo dato a mano
$ga_ap = new \App\Auth\GruppoAziende($db_oop);
$id_ga = $ga_ap->id();
$q("INSERT INTO utenti (id, nome, cognome, email, ruolo_id, ruoli_secondari) VALUES (99513, 'Az', 'Ienda', 'Rossi@Azienda-AP995.example.org', 5, ''), (99514, 'Ma', 'Nuale', 'man-ap995@example.org', 5, '')");
$q("INSERT INTO convenzioni_tesi (token, azienda, email) VALUES ('tok-ap995', 'Azienda Prova Srl', 'rossi@azienda-ap995.example.org')");
prova($id_ga > 0 && $id_ga !== $id_gs && $ga_ap->haConvenzione('ROSSI@azienda-ap995.example.org') && !$ga_ap->haConvenzione('') && !$ga_ap->haConvenzione('altro@example.org'), "gruppo Aziende: l'email di una convenzione di tesi si riconosce (maiuscole a parte)");
prova($di_ap(99513) === ['azienda'] && $di_ap(99514) === ['esterno'], "profili della persona: l'email di una convenzione fa «azienda», gli altri restano ospiti", json_encode($di_ap(99513)));
$rs_ap = fn(int $id) => (string) $db_oop->valore('SELECT ruoli_secondari FROM utenti WHERE id = ?', [$id]);
prova($ga_ap->allineaTutti() === 1 && $rs_ap(99513) === (string) $id_ga && $rs_ap(99514) === '' && $ga_ap->allineaTutti() === 0, "gruppo Aziende: lo storico lo riceve una volta sola", $rs_ap(99513));
$ga_ap->assegna(99514);
prova($di_ap(99514) === ['azienda'], "gruppo Aziende: dato a mano basta per il profilo");
$ga_ap->togli(99514); $ga_ap->togli(99514);
prova($rs_ap(99514) === '' && $di_ap(99514) === ['esterno'], "gruppo Aziende: tolto a mano torna ospite");
$riq_az = array_column($rep_ap->perProfili(['azienda']), 'chiave');
prova(in_array('da_fare', $riq_az, true) && in_array('assistenza', $riq_az, true) && in_array('tirocini', $riq_az, true), "profilo Azienda: vede i riquadri di tutti e i tirocini", implode(',', $riq_az));
$q("DELETE FROM convenzioni_tesi WHERE token = 'tok-ap995'");
foreach (["DELETE FROM utenti WHERE id BETWEEN 99501 AND 99514", "DELETE FROM uffici_persone WHERE email LIKE '%-ap995@example.org'", "DELETE FROM personale_ateneo WHERE id = 'prova.ap995'"] as $sql) $q($sql);

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
// Il pulsante sul passo che tocca al tutor (e solo lì)
$az_ap = fn(array $m) => area_tappe_tutorato($conn, $m + $inc_ap, false)['azione'];
prova($az_ap([])[0] === 'Apri il registro delle attività' && $az_ap(['id' => 77])[1] === 'registro_tutorato.php?id=77', "tutor: con la lettera firmata il pulsante apre il registro");
prova($az_ap(['data_fine' => '2020-01-31'])[0] === 'Dichiara la fine delle attività', "tutor: a periodo concluso il pulsante è «Dichiara la fine delle attività»");
prova($az_ap(['data_fine' => '2999-01-31'])[0] === 'Apri il registro delle attività', "tutor: a periodo non concluso resta «Apri il registro»");
$ac_ap = $az_ap(['stato' => 'inviata', 'confermata_il' => null, 'token_studente' => 'tokacc77']);
prova(is_array($ac_ap) && str_contains($ac_ap[0], "l'incarico") && (str_ends_with($ac_ap[1], 'incarico.php?t=tokacc77') || str_ends_with($ac_ap[1], 'dichiarazione_tutorato.php?t=tokacc77')), "tutor: lettera inviata, il pulsante porta ad accettare l'incarico (con la dichiarazione se richiesta)", json_encode($ac_ap));
prova($az_ap(['stato' => 'inviata', 'confermata_il' => null, 'token_studente' => '']) === null, "tutor: senza il link personale non c'è pulsante");
prova($az_ap(['stato' => 'confermata']) === null && $az_ap(['stato' => 'firmata_docente']) === null, "tutor: se la lettera aspetta il docente o il Direttore non c'è pulsante");
prova($az_ap(['fine_stato' => 'richiesta']) === null, "tutor: fine attività già dichiarata, aspetta il docente: nessun pulsante");
require_once __DIR__ . '/../../../inc/area/_tessera.php';
ob_start();
area_tessera('fa-user-graduate', '#0f766e', 'Il mio tutorato', 'Prossimo passo', 'il_mio_tutorato.php', 1, 'da fare', [[$ac_ap[0], $ac_ap[1], false, true]]);
$html_tt = ob_get_clean();
prova(str_contains($html_tt, 'ha-novita') && str_contains($html_tt, '1 da fare') && str_contains($html_tt, 'btn-danger') && str_contains($html_tt, 'tokacc77'), "tessera del tutor: numero rosso «da fare» e pulsante del passo");
$cruscotto_ap = (string) file_get_contents(__DIR__ . '/../../../area_personale.php');
prova(str_contains($cruscotto_ap, '_tessera.php') && str_contains($cruscotto_ap, "'Location: miei_eventi.php?'"), "il cruscotto usa le tessere e rimanda i vecchi indirizzi degli eventi a miei_eventi.php");
foreach (['miei_eventi.php', 'profilo.php', 'il_mio_tutorato.php'] as $pg_ap) prova(is_file(__DIR__ . '/../../../' . $pg_ap), "la pagina $pg_ap esiste");
prova(!str_contains($cruscotto_ap, 'name="aggiorna_email"') && !str_contains($cruscotto_ap, 'pills-tab'), "il cruscotto non contiene più i tab degli eventi né il modulo del profilo");
$riq_t_ap = $rep_ap->tutti();
$pag_riq_ap = array_values(array_filter($riq_t_ap, fn($r) => $r['chiave'] === 'tutorato_pagamenti'));
prova($pag_riq_ap && !$pag_riq_ap[0]['attivo'], "i pagamenti del tutorato partono spenti");
$doc_ap = array_column(array_filter($rep_ap->perProfili(['docente']), fn($r) => in_array($r['chiave'], ['tutorato_registro', 'firme', 'approvazioni_docente', 'tirocini'], true)), 'chiave');
prova(count($doc_ap) === 2 && in_array('approvazioni_docente', $doc_ap, true) && in_array('tirocini', $doc_ap, true), "il docente vede le approvazioni e i tirocini, non più le due tessere sovrapposte");
$amm_ap = array_column($rep_ap->perProfili(['amministratore']), 'chiave');
$tutte_ap = array_keys(array_filter(CatalogoRiquadri::sistema(), fn($v) => ($v['attivo'] ?? 1) === 1));
prova(array_diff($tutte_ap, $amm_ap) === [], "l'amministratore vede tutti i riquadri accesi", implode(', ', array_diff($tutte_ap, $amm_ap)));
$tir_pag_ap = (string) file_get_contents(__DIR__ . '/../../../percorso_tirocini.php');
prova(substr_count($tir_pag_ap, 'unical.tsp.esse3.cineca.it') >= 2, "la guida dei tirocini rimanda alla piattaforma TSP 2.0");

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// «Il mio lavoro»: le postazioni di chi gestisce qualcosa (regole senza database)
// ---------------------------------------------------------------------------------------------------------------------------------------------------
use App\Portale\Area\PostazioniLavoro;

prova(PostazioniLavoro::elenco([], []) === [], "il mio lavoro: senza permessi non c'è nessuna postazione (il riquadro non compare)");
$lav_op = PostazioniLavoro::elenco(['didattica_tutto' => true, 'didattica' => true, 'conv_tesi' => true], ['smistare' => 3, 'smistare_giorni' => 9, 'protocollare' => 2, 'verbali' => 1, 'conv_tesi' => 4]);
prova(array_column($lav_op, 'chiave') === ['pratiche', 'protocollo', 'sedute', 'convenzioni_tesi'], "il mio lavoro: l'operatore dell'ufficio didattico vede pratiche, protocollo, sedute e convenzioni di tesi, nell'ordine", implode(',', array_column($lav_op, 'chiave')));
prova($lav_op[0]['novita'] === 3 && $lav_op[0]['altri'][0][3] === true && $lav_op[1]['novita'] === 2 && $lav_op[2]['novita'] === 1 && $lav_op[3]['novita'] === 4, "il mio lavoro: i numeri arrivano sulle tessere e la pratica ferma da 9 giorni è in evidenza");
$lav_ref = PostazioniLavoro::elenco(['didattica' => true], ['verbali' => 2]);
prova(count($lav_ref) === 1 && $lav_ref[0]['chiave'] === 'sedute' && $lav_ref[0]['titolo'] === 'Le sedute dei miei consigli', "il mio lavoro: il referente di un consiglio vede solo le sue sedute");
$lav_fermo = PostazioniLavoro::elenco(['didattica_tutto' => true], ['smistare' => 1, 'smistare_giorni' => 2]);
prova(count($lav_fermo) === 1 && $lav_fermo[0]['altri'][0][3] === false, "il mio lavoro: niente protocollo se non c'è nulla da protocollare e nessun avviso per una pratica arrivata da poco");
$lav_adm = PostazioniLavoro::elenco(array_fill_keys(PostazioniLavoro::PERMESSI, true), ['protocollare' => 1]);
prova(count($lav_adm) === 8 && end($lav_adm)['titolo'] === 'Gestione del portale' && count(array_unique(array_column($lav_adm, 'chiave'))) === count($lav_adm), "il mio lavoro: l'amministratore ha tutte le postazioni, una volta sola");
$lav_ok = true;
foreach ($lav_adm as $v_l) $lav_ok = $lav_ok && is_file(__DIR__ . '/../../../' . strtok($v_l['href'], '?')) && RiquadriRepository::iconaValida($v_l['icona']) && RiquadriRepository::coloreValido($v_l['colore'])
    && array_reduce($v_l['altri'], fn($c, $a) => $c && is_file(__DIR__ . '/../../../' . strtok($a[1], '?')), true);
prova($lav_ok, "il mio lavoro: ogni tessera e ogni pulsante portano a una pagina che esiste");
prova(in_array('lavoro', array_keys(CatalogoRiquadri::sistema()), true) && !in_array(Profili::STUDENTE, CatalogoRiquadri::sistema()['lavoro']['profili'], true), "il mio lavoro: è un riquadro di sistema e non lo vedono gli studenti");
$dopo_lav = (string) file_get_contents(__DIR__ . '/../../../admin/admin_header.php');
prova(str_contains($dopo_lav, '../area_personale.php'), "il pannello ha il pulsante per tornare all'area personale");

// Tutte le azioni sulle tessere e le tessere delle aree (eventi, iscritti, presenze)
$az_ap = array_column($lav_adm[0]['altri'], 0);
prova(in_array('Seguite da me', $az_ap, true) && in_array('Moduli e documenti', $az_ap, true) && in_array('Ricevimento', $az_ap, true) && in_array('Statistiche', $az_ap, true), "il mio lavoro: la tessera delle pratiche ha tutte le azioni (assegnate, seguite, moduli, ricevimento, statistiche)");
$ar_ap = PostazioniLavoro::elenco(['aree' => true], [], [['id' => 7, 'titolo' => 'OpenLab', 'colore' => '#0056B3', 'calendario' => false, 'intera' => true, 'crea_eventi' => true, 'crea_progetti' => true, 'eventi' => 3, 'progetti' => 1, 'iscritti' => 40, 'messaggi' => 2, 'da_approvare' => 5]]);
$et_ap = array_column($ar_ap[0]['altri'] ?? [], 0);
prova(count($ar_ap) === 1 && $ar_ap[0]['chiave'] === 'area_7' && $ar_ap[0]['novita'] === 7 && $ar_ap[0]['testo'] === '3 eventi · 1 progetti · 40 iscritti', "il mio lavoro: il gestore vede una tessera per area con eventi, iscritti e le cose da guardare (2 messaggi + 5 iscrizioni)");
prova(in_array('Iscritti e presenze', $et_ap, true) && in_array('Scanner presenze', $et_ap, true) && in_array('Messaggi', $et_ap, true) && in_array('Nuovo evento', $et_ap, true) && in_array('Nuovo progetto', $et_ap, true), "il mio lavoro: la tessera dell'area ha iscritti, presenze, messaggi e la scorciatoia per creare eventi e progetti");
$ar2_ap = PostazioniLavoro::elenco(['aree' => true], [], [['id' => 8, 'titolo' => 'Solo un evento', 'intera' => false, 'eventi' => 1]]);
prova(!in_array('Nuovo evento', array_column($ar2_ap[0]['altri'], 0), true) && !in_array('Nuovo progetto', array_column($ar2_ap[0]['altri'], 0), true), "il mio lavoro: chi gestisce solo alcune attività non vede «Nuovo evento» né «Nuovo progetto»");
$ar3_ap = PostazioniLavoro::elenco(['aree' => true], [], [['id' => 9, 'titolo' => 'Aule', 'calendario' => true, 'risorse' => 4, 'prossime' => 6, 'da_approvare' => 2]]);
prova($ar3_ap[0]['novita'] === 2 && array_column($ar3_ap[0]['altri'], 0) === ['Prenotazioni', 'Risorse e orari'], "il mio lavoro: un'area di aule e laboratori ha prenotazioni da approvare e risorse");
$ok_ap = true;
foreach (array_merge($ar_ap, $ar3_ap) as $v_a) foreach ($v_a['altri'] as $a_a) $ok_ap = $ok_ap && is_file(__DIR__ . '/../../../' . strtok($a_a[1], '?'));
prova($ok_ap, "il mio lavoro: ogni pulsante delle aree porta a una pagina che esiste");

// Icone di servizio in alto, righe di lavoro con i numeri sui pulsanti, «Il mio lavoro» solo a chi gestisce qualcosa
use App\Portale\Area\CatalogoRiquadri as CatRiq;
$icone_ap = array_keys(array_filter(CatRiq::sistema(), static fn (array $v): bool => ($v['forma'] ?? '') === CatRiq::ICONA));
prova(!array_diff(['assistenza', 'messaggi', 'calendario', 'eventi', 'disponibilita', 'risorse_responsabile', 'profilo', 'guida', 'cerca'], $icone_ap), "area personale: assistenza, messaggi, calendario, eventi, disponibilità, risorse, profilo, guida e ricerca sono icone di servizio");
prova(!CatRiq::eIcona('lavoro') && !CatRiq::eIcona('da_fare') && CatRiq::eIcona('messaggi'), "area personale: «Il mio lavoro» e «Da fare per te» restano tessere grandi");
foreach (['messaggi', 'cerca'] as $k_ic) prova(is_file(__DIR__ . "/../../../inc/area/$k_ic.php"), "esiste inc/area/$k_ic.php");
$pr_ap = array_values(array_filter(PostazioniLavoro::elenco(['didattica_tutto' => true, 'didattica' => true], ['smistare' => 3, 'smistare_giorni' => 9, 'verbali' => 2]), static fn (array $x): bool => $x['chiave'] === 'pratiche'))[0];
$da_ass_ap = array_values(array_filter($pr_ap['altri'], static fn (array $b): bool => $b[0] === 'Da assegnare'))[0];
prova(($da_ass_ap[4] ?? 0) === 3 && $da_ass_ap[3] === true, "il mio lavoro: il pulsante «Da assegnare» porta il numero (3) e l'evidenza");
$se_ap = array_values(array_filter(PostazioniLavoro::elenco(['didattica' => true], ['verbali' => 2]), static fn (array $x): bool => $x['chiave'] === 'sedute'))[0];
prova(in_array('Verbali da firmare', array_column($se_ap['altri'], 0), true), "il mio lavoro: con verbali in firma compare il pulsante «Verbali da firmare»");
prova(PostazioniLavoro::elenco([], []) === [], "il mio lavoro: a chi non gestisce niente non esce nessuna riga");

