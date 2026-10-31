<?php
// Prove del registro del tutorato: più giorni in una volta, «Ripeti l'ultima» e correzione di una riga (ServizioRegistroTutorato). Dati con id 9898xx.
// Chiamato da esegui.php: stesse variabili ($conn, $q) e funzioni (prova, sezione).
sezione("Tutorato: registro più rapido (più giorni, ripeti, correggi)");

$pulisci_rr = ["DELETE FROM tutorato_registro WHERE incarico_id = 989800", "DELETE FROM tutorato_incarichi WHERE id = 989800", "DELETE FROM tutorato_bandi WHERE id = 989800"];
foreach ($pulisci_rr as $sql) $q($sql);
$q("INSERT INTO tutorato_bandi (id, titolo) VALUES (989800, 'Bando prova 9898')");
$q("INSERT INTO tutorato_incarichi (id, bando_id, codice, cognome, nome, codice_fiscale, email, docente_email, stato, ore) VALUES (989800, 989800, 'RR-1', 'Rossi', 'Tutor', 'RSSTTR00A01D086Z', 'rr9898@example.org', 'doc9898@example.org', 'firmata', 10)");
$s_rr = \App\Core\App::per($conn)->get(\App\Tutorato\ServizioRegistroTutorato::class);
$righe_rr = fn () => $conn->query("SELECT id, data, ore, attivita, stato, nota_docente FROM tutorato_registro WHERE incarico_id = 989800 ORDER BY data, id")->fetch_all(MYSQLI_ASSOC);

prova($s_rr->ripetiUltima(989800) !== null, "ripeti: senza righe non c'è niente da ripetere");
prova($s_rr->aggiungiDate(989800, ['2026-09-01', '2026-09-02', '2026-09-02', ' '], 2, 'Esercitazione') === null && count($righe_rr()) === 2, "più giorni: una riga per giorno, senza doppioni né date vuote");
prova($s_rr->aggiungiDate(989800, ['2026-09-03', '2026-09-04', '2026-09-05'], 4, 'Troppe') !== null && count($righe_rr()) === 2, "più giorni: se le ore totali superano l'incarico non si segna nessun giorno");
prova($s_rr->aggiungiDate(989800, ['2026-09-03', '2099-01-01'], 1, 'Futuro') !== null && count($righe_rr()) === 2, "più giorni: una data nel futuro ferma tutto");

prova($s_rr->ripetiUltima(989800) === null, "ripeti: stessa attività e ore con la data di oggi");
$ult = array_slice($righe_rr(), -1)[0];
prova($ult['attivita'] === 'Esercitazione' && (float) $ult['ore'] === 2.0 && $ult['data'] === date('Y-m-d'), "ripeti: la riga nuova è quella di oggi", json_encode($ult));

$r1 = $righe_rr()[0]['id'];
$q("UPDATE tutorato_registro SET stato = 'respinta', nota_docente = 'Ore sbagliate' WHERE id = $r1");
prova($s_rr->modifica(989800, (int) $r1, '2026-09-01', 3, 'Esercitazione corretta') === null, "modifica: una riga respinta si può correggere");
$r = $conn->query("SELECT * FROM tutorato_registro WHERE id = $r1")->fetch_assoc();
prova($r['stato'] === 'inviata' && $r['nota_docente'] === '' && (float) $r['ore'] === 3.0 && $r['attivita'] === 'Esercitazione corretta', "modifica: torna «da approvare», senza la nota del docente", json_encode($r));
prova($s_rr->modifica(989800, (int) $r1, '2026-09-01', 11, 'x') !== null, "modifica: gli stessi controlli di aggiungi (ore oltre il limite)");
$q("UPDATE tutorato_registro SET stato = 'approvata' WHERE id = $r1");
prova($s_rr->modifica(989800, (int) $r1, '2026-09-01', 1, 'x') !== null && $s_rr->modifica(989800, 1, '2026-09-01', 1, 'x') !== null, "modifica: una riga approvata o inesistente non si cambia");

$pag_rr = file_get_contents(dirname(__DIR__, 3) . '/registro_tutorato.php');
prova(str_contains($pag_rr, "name=\"data[]\"") && str_contains($pag_rr, "'ripeti'") && str_contains($pag_rr, 'salva_riga') && str_contains($pag_rr, '&amp;modifica='), "registro_tutorato.php: più giorni, «Ripeti l'ultima» e la matita di correzione ci sono");
foreach ($pulisci_rr as $sql) $q($sql);

// Riepilogo mensile in PDF
$rig_pdf = [
    ['id' => 3, 'data' => '2026-09-10', 'ore' => '2.0', 'attivita' => 'B', 'stato' => 'inviata'],
    ['id' => 1, 'data' => '2026-09-02', 'ore' => '3.0', 'attivita' => 'A', 'stato' => 'approvata'],
    ['id' => 2, 'data' => '2026-10-01', 'ore' => '4.0', 'attivita' => 'C', 'stato' => 'approvata'],
    ['id' => 4, 'data' => '2026-09-11', 'ore' => '1.0', 'attivita' => 'D', 'stato' => 'respinta'],
];
use App\Tutorato\RiepilogoRegistroPdf as RRP;
$mese_pdf = RRP::righeDelMese($rig_pdf, '2026-09');
$tt_pdf = RRP::totali($mese_pdf);
prova(array_column($mese_pdf, 'id') === [1, 3, 4] && $tt_pdf['approvata'] === 3.0 && $tt_pdf['inviata'] === 2.0 && $tt_pdf['totale'] === 5.0, "riepilogo: solo le righe del mese in ordine; le respinte non contano nelle ore", json_encode($tt_pdf));
prova(RRP::nomeMese('2026-09') === 'settembre 2026' && RRP::nomeMese('boh') === 'boh', "riepilogo: nome del mese in italiano");
$inc_pdf = ['nome' => 'Tutor', 'cognome' => 'Rossi', 'codice' => 'RR-1', 'bando_titolo' => 'Bando', 'docente_titolo' => 'Prof.', 'docente_nome' => 'Doc', 'docente_cognome' => 'Nove', 'ore' => '10'];
$pdf_pdf = \App\Core\App::per($conn)->get(RRP::class)->genera($inc_pdf, $rig_pdf, '2026-09');
prova(str_starts_with($pdf_pdf, '%PDF') && strlen($pdf_pdf) > 1000, "riepilogo: il PDF si genera", (string) strlen($pdf_pdf));
$testo_pdf = (new \App\Infrastructure\Pdf\TestoPdf())->estrai($pdf_pdf);
prova(str_contains($testo_pdf, 'settembre 2026') && str_contains($testo_pdf, 'Rossi'), "riepilogo: il PDF contiene il mese e il nome del tutor", substr($testo_pdf, 0, 120));
prova(str_contains($pag_rr, 'RiepilogoRegistroPdf') && str_contains($pag_rr, 'riepilogo='), "registro_tutorato.php: il riepilogo si scarica per mese");
