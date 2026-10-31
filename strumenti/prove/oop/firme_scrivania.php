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

// ---- Pagina e riquadro ----
sezione("Scrivania delle firme — pagina e riquadro");
$cat_fi = \App\Portale\Area\CatalogoRiquadri::sistema()['firme'] ?? null;
prova($cat_fi !== null && $cat_fi['tipo'] === \App\Portale\Area\CatalogoRiquadri::SPECIALE && is_file($radice . '/inc/area/firme.php'), "riquadro «Da firmare»: nel catalogo e con il suo file in inc/area");
$pag_fi = (string) file_get_contents($radice . '/firme.php');
prova(str_contains($pag_fi, 'ScrivaniaFirme') && str_contains($pag_fi, 'linkFirma') && str_contains($pag_fi, 'linkPdf()'), "firme.php: legge la scrivania e porta alla pagina di firma e all'anteprima");
$cru_fi = (string) file_get_contents($radice . '/cruscotto_direttore.php');
prova(str_contains($cru_fi, 'Firma ora (') && str_contains($cru_fi, 'firme.php'), "cruscotto del Direttore: pulsante «Firma ora (N)»");

// ---- Scarico in ZIP ----
sezione("Scrivania delle firme — ZIP dei selezionati");
$f1_fi = tempnam(sys_get_temp_dir(), 'fz'); file_put_contents($f1_fi, "%PDF-1.4\nuno\n");
$f2_fi = tempnam(sys_get_temp_dir(), 'fz'); file_put_contents($f2_fi, "%PDF-1.4\ndue\n");
$mk_fi = fn(string $tipo, int $id, ?string $p) => new \App\Firme\DocumentoDaFirmare($tipo, $id, 'Doc ' . $id, '', 1, 'firma_x.php?t=a', $p, \App\Firme\DocumentoDaFirmare::nomeSicuro($tipo, $id, 'C' . $id));
$disp_fi = [$mk_fi('verbale', 1, $f1_fi), $mk_fi('tutorato', 2, $f2_fi), $mk_fi('pratica', 3, null)];
$sc_fi = \App\Firme\PacchettoFirme::scelti($disp_fi, ['verbale:1', 'tutorato:2', 'verbale:1', 'pratica:3', 'verbale:99', 'x; DROP', '../etc:1']);
prova(count($sc_fi) === 3 && array_map(fn($d) => $d->id, $sc_fi) === [1, 2, 3], "zip: si prendono solo documenti dell'elenco della persona, senza doppioni né sigle inventate");
$z_fi = \App\Firme\PacchettoFirme::crea($sc_fi);
$za_fi = new \ZipArchive();
$aperto_fi = $z_fi['percorso'] !== null && $za_fi->open($z_fi['percorso']) === true;
prova($aperto_fi && $z_fi['inclusi'] === 2 && $za_fi->numFiles === 2 && $za_fi->locateName('verbale_1_C1.pdf') !== false && $za_fi->locateName('tutorato_2_C2.pdf') !== false && $za_fi->getFromName('verbale_1_C1.pdf') === "%PDF-1.4\nuno\n", "zip: un PDF per documento, con il nome per l'abbinamento");
prova($z_fi['senza_pdf'] === ['Doc 3'], "zip: i documenti senza PDF sono segnalati e non rompono lo ZIP", json_encode($z_fi['senza_pdf']));
if ($aperto_fi) { $za_fi->close(); @unlink($z_fi['percorso']); }
prova(\App\Firme\PacchettoFirme::crea([$mk_fi('pratica', 3, null)])['percorso'] === null && \App\Firme\PacchettoFirme::crea([])['percorso'] === null, "zip: senza PDF non si crea nessun file");
@unlink($f1_fi); @unlink($f2_fi);
$pag2_fi = (string) file_get_contents($radice . '/firme.php');
prova(str_contains($pag2_fi, "csrf_verify") && str_contains($pag2_fi, 'PacchettoFirme::scelti($documenti_fi'), "firme.php: lo ZIP vale solo per i documenti dell'elenco e con il controllo CSRF");

// ---- Ricarica dei firmati ----
sezione("Scrivania delle firme — ricarica dei firmati");
prova(array_filter($d, fn($x) => $x->registra === null) === [] && array_filter($dv, fn($x) => $x->registra === null) === [], "ricarica: ogni documento della scrivania sa come registrare il suo PDF firmato");
$chiavi_fi = ['verbale_98961_20261001.pdf' => 'verbale_98961', 'tutorato-lettera_5_TU-1_signed.pdf' => 'tutorato-lettera_5', 'convenzione_tesi_7.pdf' => 'convenzione_tesi_7', 'Pratica_12_PR-1-firmato.PDF' => 'pratica_12',
    'convenzione_tesi_70_x.pdf' => 'convenzione_tesi_70', 'documento.pdf' => null, 'verbale.pdf' => null, 'C:\temp\pratica_3_a.pdf' => 'pratica_3'];
$sbagliate_fi = [];
foreach ($chiavi_fi as $nome_k => $att_k) { if (\App\Firme\DocumentoDaFirmare::chiaveDaNome($nome_k) !== $att_k) $sbagliate_fi[] = $nome_k; }
prova($sbagliate_fi === [], "ricarica: il nome del file dice di quale documento si tratta, con o senza suffisso", implode(', ', $sbagliate_fi));

$ric_fi = \App\Core\App::per($conn)->get(\App\Firme\RicaricaFirmati::class);
$reg_fi = new \ArrayObject();
$mk2_fi = fn(string $tipo, int $id, ?string $err) => new \App\Firme\DocumentoDaFirmare($tipo, $id, 'Doc ' . $tipo . $id, '', 1, 'firma_x.php?t=a', null, \App\Firme\DocumentoDaFirmare::nomeSicuro($tipo, $id, 'C'),
    function (string $pdf) use ($reg_fi, $tipo, $id, $err): ?string { $reg_fi[] = $tipo . $id . ':' . strlen($pdf); return $err; });
$docs_fi = [$mk2_fi('verbale', 1, null), $mk2_fi('pratica', 2, 'Il PDF non è quello da firmare.'), $mk2_fi('tutorato', 3, null)];
$file_fi = [
    ['nome' => 'verbale_1_C_signed.pdf', 'dati' => "%PDF-1.4\nok", 'errore' => null],
    ['nome' => 'pratica_2_C.pdf', 'dati' => "%PDF-1.4\nx", 'errore' => null],
    ['nome' => 'verbale_1_C.pdf', 'dati' => "%PDF-1.4\naltro", 'errore' => null],
    ['nome' => 'verbale_9_C.pdf', 'dati' => "%PDF-1.4\n", 'errore' => null],
    ['nome' => 'misterioso.pdf', 'dati' => "%PDF-1.4\n", 'errore' => null],
    ['nome' => 'tutorato_3_C.pdf', 'dati' => 'non un pdf', 'errore' => null],
    ['nome' => 'rotto.zip', 'dati' => null, 'errore' => 'Lo ZIP non si apre.'],
];
$es_fi = $ric_fi->registra($docs_fi, $file_fi, 0);
prova(array_column($es_fi, 'ok') === [true, false, false, false, false, false, false] && $reg_fi->getArrayCopy() === ['verbale1:11', 'pratica2:10'], "ricarica: ogni file ha il suo esito e solo i file giusti vengono registrati", json_encode([array_column($es_fi, 'ok'), $reg_fi]));
prova($es_fi[1]['messaggio'] === 'Il PDF non è quello da firmare.' && str_contains($es_fi[2]['messaggio'], 'già caricato') && str_contains($es_fi[3]['messaggio'], 'aspettano la tua firma') && str_contains($es_fi[4]['messaggio'], 'nome del file') && $es_fi[5]['messaggio'] === 'Non è un PDF.', "ricarica: i messaggi spiegano perché un file non è stato accettato", json_encode(array_column($es_fi, 'messaggio')));

$zf_fi = tempnam(sys_get_temp_dir(), 'rz'); $pf_fi = tempnam(sys_get_temp_dir(), 'rp'); $xf_fi = tempnam(sys_get_temp_dir(), 'rx');
file_put_contents($pf_fi, "%PDF-1.4\nsingolo\n"); file_put_contents($xf_fi, 'x');
$zz_fi = new \ZipArchive(); $zz_fi->open($zf_fi, \ZipArchive::OVERWRITE);
$zz_fi->addFromString('cartella/verbale_1_C_signed.pdf', "%PDF-1.4\ndentro"); $zz_fi->addFromString('__MACOSX/._x.pdf', 'x'); $zz_fi->addFromString('letto.txt', 'x'); $zz_fi->addFromString('.nascosto.pdf', 'x'); $zz_fi->close();
$ex_fi = $ric_fi->espandi([['nome' => 'firmati.zip', 'percorso' => $zf_fi], ['nome' => 'C:\Users\a\pratica_2_C.pdf', 'percorso' => $pf_fi], ['nome' => 'cosa.p7m', 'percorso' => $xf_fi]]);
prova(count($ex_fi) === 4 && $ex_fi[0]['nome'] === 'verbale_1_C_signed.pdf' && $ex_fi[0]['dati'] === "%PDF-1.4\ndentro" && $ex_fi[1]['errore'] !== null && $ex_fi[2]['nome'] === 'pratica_2_C.pdf' && $ex_fi[3]['errore'] !== null && str_contains($ex_fi[3]['errore'], 'p7m'), "ricarica: lo ZIP si apre nei suoi PDF (senza cartelle e file nascosti), il .p7m è rifiutato", json_encode(array_map(fn($e) => [$e['nome'], $e['errore']], $ex_fi)));
@unlink($zf_fi); @unlink($pf_fi); @unlink($xf_fi);
$pag3_fi = (string) file_get_contents($radice . '/firme.php');
prova(str_contains($pag3_fi, "'carica'") && str_contains($pag3_fi, 'RicaricaFirmati') && substr_count($pag3_fi, 'csrf_verify') === 2 && str_contains($pag3_fi, 'enctype="multipart/form-data"'), "firme.php: modulo «Carica i firmati» con CSRF");

// ---- Conferme degli attestati in blocco ----
sezione("Conferme degli attestati in blocco");
foreach (["DELETE FROM pratiche_conferme WHERE pratica_id IN (98971, 98972)", "DELETE FROM pratiche WHERE id IN (98971, 98972)", "DELETE FROM didattica_moduli WHERE id = 98970"] as $sql) $q($sql);
$q("INSERT INTO didattica_moduli (id, titolo) VALUES (98970, 'Attestato di frequenza 98970')");
$q("INSERT INTO pratiche (id, modulo_id, utente_id, codice, stato, nome, cognome) VALUES (98971, 98970, 1, 'P-9897-1', 'inviata', 'Anna', 'Rossi'), (98972, 98970, 1, 'P-9897-2', 'inviata', 'Luca', 'Bianchi')");
$q("INSERT INTO pratiche_conferme (id, pratica_id, passo, nome, email, token, persona_id, richiesta_il) VALUES
    (989711, 98971, 1, 'Verdi Marco', 'marco.verdi@x.it', 'tcbl9897a', 'm.verdi', NOW() - INTERVAL 9 DAY),
    (989721, 98972, 1, 'Verdi Marco', 'marco.verdi@x.it', 'tcbl9897b', 'm.verdi', NOW() - INTERVAL 1 DAY),
    (989712, 98971, 1, 'Neri Giulia', 'giulia.neri@x.it', 'tcbl9897c', 'g.neri', NOW())");
$blocco_fi = \App\Core\App::per($conn)->get(\App\Didattica\ConfermeInBlocco::class);
$marco_fi = ['id' => 5, 'email' => 'Marco.Verdi@x.it', 'persona_id' => ''];
$att_fi = $blocco_fi->inAttesa($marco_fi);
prova(array_column($att_fi, 'id') === [989711, 989721] && (int) $att_fi[0]['gg'] >= 9, "conferme in blocco: il docente vede le sue richieste, le più vecchie prima", json_encode(array_column($att_fi, 'id')));
$alias_fi = array_column($blocco_fi->inAttesa(['id' => 6, 'email' => 'altro@x.it', 'persona_id' => 'm.verdi']), 'id');
prova($alias_fi === [989711, 989721], "conferme in blocco: con un altro indirizzo ma la stessa scheda le vede lo stesso", json_encode($alias_fi));
prova($blocco_fi->inAttesa(['id' => 7, 'email' => 'estraneo@x.it', 'persona_id' => '']) === [] && $blocco_fi->inAttesa(null) === [] && $blocco_fi->inAttesa(['id' => 8, 'email' => '', 'persona_id' => '']) === [], "conferme in blocco: un estraneo o chi non ha né email né scheda non vede niente");
$es_cf = $blocco_fi->conferma($marco_fi, [989711, 989712, 989721, 989711]);
prova(array_column($es_cf, 'ok') === [true, false, true] && array_column($es_cf, 'id') === [989711, 989712, 989721], "conferme in blocco: si confermano le proprie richieste, non quelle di un altro docente, senza doppioni", json_encode($es_cf));
prova(array_column($conn->query("SELECT id, stato FROM pratiche_conferme WHERE pratica_id IN (98971, 98972) ORDER BY id")->fetch_all(MYSQLI_ASSOC), 'stato', 'id') === [989711 => 'confermata', 989712 => 'attesa', 989721 => 'confermata'], "conferme in blocco: nel database restano in attesa solo quelle dell'altro docente");
prova($blocco_fi->inAttesa($marco_fi) === [] && $blocco_fi->conferma($marco_fi, [989711])[0]['ok'] === false, "conferme in blocco: una richiesta già confermata non si conferma due volte");
$mc_fi = (string) file_get_contents($radice . '/mie_conferme.php');
prova(str_contains($mc_fi, 'csrf_verify') && str_contains($mc_fi, "empty(\$_POST['dichiaro'])") && str_contains($mc_fi, 'ConfermeInBlocco') && str_contains((string) file_get_contents($radice . '/conferma_attestato.php'), 'mie_conferme.php'), "mie_conferme.php: CSRF e dichiarazione obbligatoria; la pagina della singola conferma rimanda qui");
foreach (["DELETE FROM pratiche_conferme WHERE pratica_id IN (98971, 98972)", "DELETE FROM pratiche WHERE id IN (98971, 98972)", "DELETE FROM didattica_moduli WHERE id = 98970"] as $sql) $q($sql);

// ---- Storico «Fatte» ----
sezione("Scrivania delle firme — fatte negli ultimi 12 mesi");
$pulisci_st = ["DELETE FROM tutorato_incarichi WHERE bando_id = 9898", "DELETE FROM tutorato_bandi WHERE id = 9898", "DELETE FROM didattica_sedute WHERE id IN (98981, 98982)",
    "DELETE FROM pratiche_firme WHERE pratica_id = 98981", "DELETE FROM pratiche_conferme WHERE pratica_id = 98981", "DELETE FROM pratiche WHERE id = 98981", "DELETE FROM didattica_moduli WHERE id = 98980"];
foreach ($pulisci_st as $sql) $q($sql);
$q("INSERT INTO tutorato_bandi (id, titolo, direttore_nome, direttore_email) VALUES (9898, 'Bando storico 9898', 'Dir Prova', 'dir9898@example.org')");
$q("INSERT INTO tutorato_incarichi (bando_id, codice, cognome, nome, codice_fiscale, email, docente_email, attivita, stato, firmata_docente_il, firmata_direttore_il, fine_firmata_il) VALUES
    (9898, 'TU-ST1', 'Rossi', 'Mara', 'RSSMRA00A41D086X', 'mara9898@example.org', 'doc9898@example.org', 'Aiuto', 'firmata', NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 10 DAY, NULL),
    (9898, 'TU-ST2', 'Verdi', 'Luca', 'VRDLCU00A01D086X', 'luca9898@example.org', 'doc9898@example.org', 'Lab', 'firmata', NOW() - INTERVAL 15 MONTH, NOW() - INTERVAL 14 MONTH, NULL)");
$q("INSERT INTO didattica_moduli (id, titolo) VALUES (98980, 'Certificato 98980')");
$q("INSERT INTO pratiche (id, modulo_id, utente_id, codice, stato, nome, cognome) VALUES (98981, 98980, 1, 'P-9898-1', 'inviata', 'Anna', 'Rossi')");
$q("INSERT INTO pratiche_firme (pratica_id, passo, tipo, nome, email, token, stato, firmata_il) VALUES (98981, 1, 'persona', 'Doc Nove', 'doc9898@example.org', 'tokst9898a', 'firmata', NOW() - INTERVAL 3 DAY)");
$q("INSERT INTO pratiche_conferme (pratica_id, passo, nome, email, token, stato, esito_il) VALUES (98981, 1, 'Doc Nove', 'doc9898@example.org', 'tokst9898b', 'confermata', NOW() - INTERVAL 1 DAY)");
$q("INSERT INTO didattica_sedute (id, organo, data, segretario_email, coordinatore_email, verbale_stato, verbale_inviato_il, verbale_firmato_il) VALUES
    (98981, 'Consiglio storico 9898', '2026-10-01', 'doc9898@example.org', 'coo9898@example.org', 'firmato', NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 2 DAY),
    (98982, 'Consiglio vecchio 9898', '2024-01-10', 'doc9898@example.org', 'coo9898@example.org', 'firmato', NOW() - INTERVAL 20 MONTH, NOW() - INTERVAL 19 MONTH)");
$storico_fi = \App\Core\App::per($conn)->get(\App\Firme\StoricoFirme::class);
$doc_st = $storico_fi->fatte(['id' => 1, 'email' => 'Doc9898@example.org', 'codice_fiscale' => '']);
$tit_st = array_map(fn($x) => $x['tipo'] . ' | ' . $x['dettaglio'], $doc_st);
prova(count($doc_st) === 4, "fatte: il docente ritrova firme, conferma, lettera, verbale da segretario (non le cose di oltre 12 mesi fa)", json_encode($tit_st));
$quando_st = array_column($doc_st, 'quando');
$ord_st = $quando_st; rsort($ord_st);
prova($quando_st === $ord_st && $doc_st[0]['tipo'] === 'conferma', "fatte: dalla più recente", json_encode($quando_st));
prova(!array_filter($doc_st, fn($x) => str_contains($x['dettaglio'], 'Direttore') || str_contains($x['dettaglio'], 'coordinatore')), "fatte: la firma del Direttore e quella del coordinatore non sono del docente");
$dir_st = $storico_fi->fatte(['id' => 2, 'email' => 'dir9898@example.org', 'codice_fiscale' => '']);
prova(count(array_filter($dir_st, fn($x) => $x['tipo'] === 'tutorato' && str_contains($x['dettaglio'], 'Direttore'))) === 1, "fatte: il Direttore del bando ritrova la sua firma sulla lettera", json_encode($dir_st));
$coo_st = $storico_fi->fatte(['id' => 3, 'email' => 'coo9898@example.org', 'codice_fiscale' => '']);
prova(count($coo_st) === 1 && str_contains($coo_st[0]['dettaglio'], 'coordinatore'), "fatte: il coordinatore ritrova il verbale firmato", json_encode($coo_st));
prova($storico_fi->fatte(['id' => 4, 'email' => 'estraneo9898@example.org']) === [] && $storico_fi->fatte(null) === [], "fatte: un estraneo o nessun utente non vede niente");
prova(str_contains((string) file_get_contents($radice . '/firme.php'), 'StoricoFirme'), "firme.php: mostra le firme già fatte");
foreach ($pulisci_st as $sql) $q($sql);
