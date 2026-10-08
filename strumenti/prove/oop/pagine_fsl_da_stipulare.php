<?php
// Prove via HTTP della scheda «Da stipulare» del pannello FSL e dei documenti precompilati (ambiente locale acceso).
// Possono usare $http, $jar (amministratore), $jar2 (docente 4), $loc (database locale). Dati di prova con id 989xx nell'area FSL (id 2).
sezione("Modulo FSL: convenzioni da stipulare (pagine)");

$pulisci_ds = ["DELETE FROM prenotazioni WHERE id = 98903", "DELETE FROM turni WHERE id = 98902", "DELETE FROM progetti_dettagli WHERE evento_id = 98901", "DELETE FROM eventi WHERE id = 98901", "DELETE FROM scuole WHERE codice = 'ZZDS98900A'"];
foreach ($pulisci_ds as $sql) $loc->query($sql);
$loc->query("INSERT INTO scuole (codice, denominazione, istituto_codice, istituto_denominazione, tipo, comune, provincia, regione, indirizzo, cap) VALUES ('ZZDS98900A', 'LICEO PROVA PAGINE', NULL, NULL, 'LICEO', 'RENDE', 'COSENZA', 'CALABRIA', 'VIA PAGINE 1', '87036')");
$loc->query("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve, archiviato) VALUES (98901, 2, 'Progetto FSL pagine da stipulare', 'progetto', 'Prova.', 0)");
$loc->query("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati, data_inizio, data_fine, ore_totali) VALUES (98901, 1, 1, 0, '" . date('Y-m-d', strtotime('+20 days')) . "', '" . date('Y-m-d', strtotime('+50 days')) . "', 25)");
$loc->query("INSERT INTO turni (id, evento_id, nome_turno, max_posti, richiede_approvazione) VALUES (98902, 98901, 'Edizione pagine', 5, 0)");
$loc->query("INSERT INTO prenotazioni (id, turno_id, utente_id, codice_prenotazione, stato, num_posti, nome, cognome, email, scuola_codice, convenzione, data_prenotazione, dati_custom_json)
             VALUES (98903, 98902, 4, 'FS-DSPAG', 'confermata', 1, 'Luca', 'Insegnante', 'luca.insegnante@example.org', 'ZZDS98900A', 'no', NOW(), '{\"numero_partecipanti\":\"14\"}')");
$errore_php_ds = fn(array $r) => (bool)preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $r['corpo']);

$r = $http('/eventi/admin/fsl.php?p_id=2&tab=stipulare', null, $jar);
prova($r['codice'] === 200 && !$errore_php_ds($r) && str_contains($r['corpo'], 'Convenzioni da stipulare') && str_contains($r['corpo'], 'Liceo Prova Pagine') && str_contains($r['corpo'], 'Allegato A precompilato (PDF)')
    && str_contains($r['corpo'], 'Registra la convenzione ricevuta') && str_contains($r['corpo'], 'FS-DSPAG'), "scheda «Da stipulare»: la scuola con la sua prenotazione (già confermata) e i pulsanti dei documenti");
$r_tutte = $http('/eventi/admin/fsl.php?p_id=2&tab=stipulare&mostra=tutte', null, $jar);
prova($r_tutte['codice'] === 200 && !$errore_php_ds($r_tutte) && str_contains($r_tutte['corpo'], 'Liceo Prova Pagine'), "scheda «Da stipulare»: vista di tutte le scuole con prenotazioni");
prova(str_contains($http('/eventi/admin/fsl.php?p_id=2', null, $jar)['corpo'], 'tab=stipulare'), "la scheda compare nel menu del pannello FSL");

// Download dei documenti dalla scheda
preg_match('#href="(allegato_a\.php\?k=ZZDS98900A&amp;doc=allegato_pdf)"#', $r['corpo'], $m_pdf);
$url_pdf = '/eventi/admin/' . html_entity_decode($m_pdf[1] ?? 'allegato_a.php?k=ZZDS98900A&doc=allegato_pdf');
$rd = $http($url_pdf, null, $jar);
prova($rd['codice'] === 200 && str_starts_with($rd['corpo'], '%PDF'), "Allegato A in PDF precompilato scaricabile dalla scheda");
$rd = $http('/eventi/admin/allegato_a.php?k=ZZDS98900A&doc=allegato', null, $jar);
prova($rd['codice'] === 200 && str_starts_with($rd['corpo'], 'PK'), "Allegato A in Word precompilato scaricabile");
$rd = $http('/eventi/admin/allegato_a.php?k=ZZDS98900A&doc=convenzione', null, $jar);
prova($rd['codice'] === 200 && str_starts_with($rd['corpo'], 'PK'), "Convenzione in Word precompilata scaricabile");
$rd = $http('/eventi/admin/allegato_a.php?pr=98903&doc=allegato_pdf', null, $jar);
prova($rd['codice'] === 200 && str_starts_with($rd['corpo'], '%PDF'), "Allegato A dalla riga di una prenotazione già confermata");
prova($http('/eventi/admin/allegato_a.php?k=NONESISTE0&doc=allegato_pdf', null, $jar)['codice'] === 404, "scuola senza prenotazioni: documento non disponibile");
prova($http('/eventi/admin/allegato_a.php?k=ZZDS98900A&doc=allegato_pdf')['codice'] !== 200 && $http('/eventi/admin/allegato_a.php?k=ZZDS98900A&doc=allegato_pdf', null, $jar2)['codice'] !== 200
    && $http('/eventi/admin/fsl.php?p_id=2&tab=stipulare', null, $jar2)['codice'] !== 200, "documenti e scheda riservati a chi gestisce la FSL (non agli anonimi né ai docenti)");

// Iscrizioni: pulsante sulla riga della prenotazione FSL
$ri = $http('/eventi/admin/iscritti.php?p_id=2&f_cerca=FS-DSPAG', null, $jar);
prova($ri['codice'] === 200 && !$errore_php_ds($ri) && str_contains($ri['corpo'], 'allegato_a.php?pr=98903&amp;doc=allegato_pdf'), "Iscrizioni: Allegato A già compilato dalla riga della prenotazione");

foreach ($pulisci_ds as $sql) $loc->query($sql);
