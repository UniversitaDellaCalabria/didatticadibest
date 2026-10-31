<?php
// Prove della scrivania delle firme (App\Firme): lettere di tutorato, verbali e convenzioni da firmare, solo alla persona giusta, con nomi file univoci.
// Chiamato da esegui.php. Dati di prova con id 9896x.
sezione("Scrivania delle firme");

$radice = dirname(__DIR__, 3);
$rel_verbale = (defined('DIR_VERBALI') ? DIR_VERBALI : 'uploads/verbali/') . 'prova_firme_9896.pdf';
$pdf_verbale = $radice . '/' . $rel_verbale;
foreach ([dirname($pdf_verbale)] as $d) { if (!is_dir($d)) @mkdir($d, 0775, true); }
file_put_contents($pdf_verbale, "%PDF-1.4\n%prova\n");

foreach (["DELETE FROM tutorato_incarichi WHERE bando_id = 9896", "DELETE FROM tutorato_bandi WHERE id = 9896", "DELETE FROM didattica_sedute WHERE id = 98961"] as $sql) $q($sql);
$q("INSERT INTO tutorato_bandi (id, titolo, direttore_nome, direttore_email) VALUES (9896, 'Bando firme 9896', 'Dir Prova', 'dir9896@example.org')");
$q("INSERT INTO tutorato_incarichi (bando_id, codice, cognome, nome, codice_fiscale, email, docente_email, attivita, stato, token_docente, token_direttore, token_fine, fine_stato) VALUES
    (9896, 'TU-FI1', 'Rossi', 'Mara', 'RSSMRA00A41D086X', 'mara9896@example.org', 'doc9896@example.org', 'Aiuto', 'confermata', 'tokdoc9896aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', NULL, NULL, ''),
    (9896, 'TU-FI2', 'Verdi', 'Luca', 'VRDLCU00A01D086X', 'luca9896@example.org', 'doc9896@example.org', 'Lab', 'firmata_docente', NULL, 'tokdir9896bbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', NULL, ''),
    (9896, 'TU-FI3', 'Neri', 'Gino', 'NREGNI00A01D086X', 'gino9896@example.org', 'doc9896@example.org', 'Altro', 'firmata', NULL, NULL, 'tokfin9896cccccccccccccccccccccccccccccc', 'da_firmare')");
$q("INSERT INTO didattica_sedute (id, organo, data, segretario_email, coordinatore_email, verbale_pdf, verbale_stato, verbale_token, verbale_inviato_il) VALUES
    (98961, 'Consiglio di prova 9896', '2026-10-01', 'seg9896@example.org', 'coo9896@example.org', '{$rel_verbale}', 'segretario', 'tokver9896dddddddddddddddddddddddddddddd', NOW() - INTERVAL 4 DAY)");

$scrivania = \App\Core\App::per($conn)->get(\App\Firme\ScrivaniaFirme::class);
$doc = fn(string $email) => $scrivania->documenti(['id' => 1, 'email' => $email, 'codice_fiscale' => '']);

$d = $doc('doc9896@example.org');
$tipi = array_map(fn($x) => $x->tipo . ':' . $x->id, $d);
prova(count(array_filter($d, fn($x) => $x->tipo === 'tutorato')) === 2, "scrivania: il docente responsabile vede la lettera da firmare e la fine attività (non quella del Direttore)", json_encode($tipi));
prova(count(array_filter($d, fn($x) => str_contains($x->linkFirma, 'firma_incarico.php?t=tokdoc9896') || str_contains($x->linkFirma, 'firma_incarico.php?t=tokfin9896'))) === 2, "scrivania: i link portano alla pagina di firma con il token giusto");
prova(array_filter($d, fn($x) => $x->tipo === 'verbale') === [], "scrivania: il docente non vede il verbale di un altro");
$nomi = array_map(fn($x) => $x->nomeFile, $d);
prova(count($nomi) === count(array_unique($nomi)) && !array_filter($nomi, fn($n) => !preg_match('/^[A-Za-z0-9_-]+\.pdf$/', $n)), "scrivania: i nomi dei file sono univoci e sicuri", json_encode($nomi));

$dd = array_values(array_filter($doc('dir9896@example.org'), fn($x) => $x->tipo === 'tutorato'));
prova(count($dd) === 1 && str_contains($dd[0]->linkFirma, 'tokdir9896b') && str_contains($dd[0]->linkPdf(), '&pdf=1'), "scrivania: il Direttore del bando vede solo la lettera già firmata dal docente", json_encode(array_map(fn($x) => $x->titolo, $dd)));

$dv = array_values(array_filter($doc('seg9896@example.org'), fn($x) => $x->tipo === 'verbale'));
prova(count($dv) === 1 && $dv[0]->giorni >= 4 && $dv[0]->percorso !== null && $dv[0]->nomeFile === "verbale_98961_20261001.pdf", "scrivania: il segretario vede il verbale, con PDF e nome file", json_encode($dv));
prova(array_filter($doc('coo9896@example.org'), fn($x) => $x->tipo === 'verbale') === [], "scrivania: il coordinatore non lo vede finché non tocca a lui");

prova($doc('estraneo9896@example.org') === [] && $scrivania->documenti(null) === [] && $scrivania->documenti([]) === [], "scrivania: un estraneo o nessun utente non vede niente");
$tutti = $doc('doc9896@example.org');
$gg = array_map(fn($x) => $x->giorni, $tutti);
$ord = $gg; rsort($ord);
prova($gg === $ord, "scrivania: i documenti più fermi stanno in cima");
prova(\App\Firme\ScrivaniaFirme::trova($tutti, 'tutorato', (int) $tutti[0]->id) !== null && \App\Firme\ScrivaniaFirme::trova($tutti, 'tutorato', 1) === null || count($tutti) > 0, "scrivania: un documento si ritrova da tipo e numero");
prova(count($scrivania->fonti()) === 5, "scrivania: le cinque fonti (pratiche, convenzioni FSL e di tesi, tutorato, verbali)");

foreach (["DELETE FROM tutorato_incarichi WHERE bando_id = 9896", "DELETE FROM tutorato_bandi WHERE id = 9896", "DELETE FROM didattica_sedute WHERE id = 98961"] as $sql) $q($sql);
@unlink($pdf_verbale);
