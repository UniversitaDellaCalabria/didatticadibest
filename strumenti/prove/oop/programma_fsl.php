<?php
// Prove del programma FSL (App\Fsl\ProgrammaFsl, ServizioProgrammaFsl, AllegatoAPdf): più attività scelte e prenotate insieme, lista d'attesa,
// attività non prenotabili che restano nel programma, un'unica email, compilazione della convenzione online con tutte le prenotazioni,
// Allegato A in PDF con la scheda di ogni attività, Convenzione Word senza Allegato A. Chiamato da esegui.php: stesse variabili
// ($conn, $q, $giorni, $EMAIL) e funzioni (prova, sezione). Dati di prova con id 989xx e la scuola ZZPG98900X.
sezione('Modulo FSL: programma delle attività (prenotazione di più attività insieme)');

use App\Core\App as AppPrg;
use App\Fsl\ProgrammaFsl;
use App\Fsl\RichiestaProgramma;
use App\Fsl\ServizioProgrammaFsl;

foreach (["DELETE FROM convenzioni_compilate WHERE email LIKE '%@prg989.example.org'", "DELETE FROM prenotazioni WHERE turno_id BETWEEN 98911 AND 98919", "DELETE FROM turni WHERE id BETWEEN 98911 AND 98919",
          "DELETE FROM progetti_dettagli WHERE evento_id BETWEEN 98901 AND 98909", "DELETE FROM eventi WHERE id BETWEEN 98901 AND 98909", "DELETE FROM campi_form WHERE pagina_id = 98900",
          "DELETE FROM pagine_eventi WHERE id = 98900", "DELETE FROM scuole WHERE codice = 'ZZPG98900X'"] as $sql) $q($sql);
$q("INSERT INTO pagine_eventi (id, titolo, slug, tipo_area, visibile, colore_primario) VALUES (98900, 'FSL programma 989', 'fsl_programma_989', 'fsl', 1, '#445566')");
$q("INSERT INTO scuole (codice, denominazione, istituto_codice, istituto_denominazione, tipo, comune, provincia, regione, indirizzo, cap) VALUES ('ZZPG98900X', 'LICEO PROGRAMMA 989', NULL, NULL, 'LICEO', 'RENDE', 'COSENZA', 'CALABRIA', 'VIA PROGRAMMA 989', '87036')");
$q("INSERT INTO campi_form (pagina_id, evento_id, nome_campo, etichetta, tipo_campo, obbligatorio, ordine) VALUES (98900, NULL, 'scuola', 'Scuola', 'scuola', 0, 1), (98900, NULL, 'numero_partecipanti', 'Numero di studenti', 'number', 0, 2)");

// 98901 progetto per le scuole con due edizioni (98911, 98912); 98902 evento FSL con un posto (98913) occupato e lista d'attesa;
// 98903 evento FSL con un posto (98914) occupato e senza lista d'attesa; 98904 evento NON FSL (98915); 98905 progetto FSL concluso (98916)
$q("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve, descrizione, luogo) VALUES
    (98901, 98900, 'Biodiversita989', 'progetto', 'Breve 989.', '<p>Descrizione <strong>lunga</strong> 989.</p><ul><li>Primo punto</li><li>Secondo punto</li></ul>', 'Laboratorio 989'),
    (98902, 98900, 'Geologia989', 'evento', 'Geologia breve.', '<p>Escursione geologica.</p>', 'Cantiere 989'),
    (98903, 98900, 'Chimica989', 'evento', 'Chimica breve.', '<p>Laboratorio di chimica.</p>', 'Aula 989'),
    (98904, 98900, 'Seminario989', 'evento', 'Non FSL.', '<p>Seminario.</p>', 'Aula magna'),
    (98905, 98900, 'Passato989', 'progetto', 'Concluso.', '<p>Concluso.</p>', 'Altrove')");
$q("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati, data_inizio, data_fine, ore_totali, modalita, destinatari, incontri_previsti, min_studenti, max_studenti, struttura, obiettivi, conoscenze, competenze, moduli_json, referenti_json, info_extra_json) VALUES
    (98901, 1, 1, 0, '" . $giorni(40) . "', '" . $giorni(70) . "', 24, 'In presenza', 'Classi quarte e quinte', 6, 5, 30, 'Corso di laurea Prova 989', '<p>Obiettivo uno 989</p>', '<p>Conoscenza 989</p>', '<p>Competenza 989</p>',
        '[{\"titolo\":\"Modulo Alfa989\",\"ore\":8,\"modalita\":\"Laboratorio\",\"quando\":\"Novembre\",\"sede\":\"DiBEST\",\"descrizione\":\"Descrizione modulo alfa\"}]',
        '[{\"nome\":\"Prof. Mario Referente989\",\"ruolo\":\"Responsabile\",\"email\":\"referente989@example.org\"}]', '[{\"etichetta\":\"Pranzo\",\"valore\":\"Al sacco\"}]'),
    (98902, 1, 0, 0, '" . $giorni(50) . "', '" . $giorni(50) . "', 4, '', '', NULL, NULL, NULL, '', '', '', '', NULL, NULL, NULL),
    (98903, 1, 0, 0, '" . $giorni(51) . "', '" . $giorni(51) . "', 4, '', '', NULL, NULL, NULL, '', '', '', '', NULL, NULL, NULL),
    (98904, 0, 0, 0, NULL, NULL, NULL, '', '', NULL, NULL, NULL, '', '', '', '', NULL, NULL, NULL),
    (98905, 1, 1, 0, '" . $giorni(-60) . "', '" . $giorni(-30) . "', 10, '', '', NULL, NULL, NULL, '', '', '', '', NULL, NULL, NULL)");
$q("INSERT INTO turni (id, evento_id, nome_turno, data_turno, max_posti, richiede_approvazione, abilita_lista_attesa) VALUES
    (98911, 98901, 'Edizione A989', NULL, 1, 0, 1),
    (98912, 98901, 'Edizione B989', NULL, 1, 0, 1),
    (98913, 98902, 'Giornata', '" . $giorni(50) . "', 1, 0, 1),
    (98914, 98903, 'Giornata', '" . $giorni(51) . "', 1, 0, 0),
    (98915, 98904, 'Seminario', '" . $giorni(20) . "', 10, 0, 0),
    (98916, 98905, 'Passato', NULL, 1, 0, 0)");
// I posti di 98913 e 98914 sono già occupati da altri
$q("INSERT INTO prenotazioni (turno_id, codice_prenotazione, stato, num_posti, nome, cognome, email, convenzione) VALUES
    (98913, 'FS-PRG9891', 'confermata', 1, 'Altra', 'Scuola', 'altra1@other989.example.org', 'ricevuta'), (98914, 'FS-PRG9892', 'confermata', 1, 'Altra', 'Scuola', 'altra2@other989.example.org', 'ricevuta')");

$prg = AppPrg::per($conn)->get(ServizioProgrammaFsl::class);
$cart = AppPrg::get(ProgrammaFsl::class);
$_SESSION = [];
$rid = static fn(string $nome, string $cognome, string $email, array $post, ?int $uid = null): RichiestaProgramma => new RichiestaProgramma(
    $nome, $cognome, $email, $uid, 5, [], $post, null, '10.98.9.' . random_int(1, 250), 'https://dibest2.unical.it/eventi');
$base_post = fn(array $extra = []) => $extra + ['accetta_privacy' => 'on', 'email_conferma' => 'dir989@prg989.example.org', 'sito_web' => ''];

// ── Aggiunta ──
$r = $prg->aggiungi(98915, ['custom_numero_partecipanti' => '10'], false, 5, []);
prova(!$r['ok'] && str_contains((string)$r['errore'], 'non fa parte della Formazione Scuola Lavoro') && $prg->conta() === 0, "programma: un'attività non FSL non si aggiunge");
$r = $prg->aggiungi(98916, ['custom_numero_partecipanti' => '10'], false, 5, []);
prova(!$r['ok'] && str_contains((string)$r['errore'], 'conclusa') && $prg->conta() === 0, "programma: un'attività conclusa non si aggiunge");
$r = $prg->aggiungi(99999, [], false, 5, []);
prova(!$r['ok'] && $prg->conta() === 0, "programma: un turno che non esiste non si aggiunge");
$r = $prg->aggiungi(98911, [], false, 5, []);
prova(!$r['ok'] && str_contains((string)$r['errore'], 'numero di studenti') && $prg->conta() === 0, "programma: il progetto per le scuole chiede il numero di studenti", (string)$r['errore']);
$r = $prg->aggiungi(98911, ['custom_numero_partecipanti' => '3'], false, 5, []);
prova(!$r['ok'] && str_contains((string)$r['errore'], 'compreso tra 5 e 30') && $prg->conta() === 0, "programma: numero di studenti dentro i limiti del progetto", (string)$r['errore']);
$r = $prg->aggiungi(98911, ['custom_numero_partecipanti' => '12', 'custom_scuola' => 'Scritta a mano', 'custom_altro' => ['a', 'b']], false, 5, []);
prova($r['ok'] && !$r['sostituita'] && $r['titolo'] === 'Biodiversita989' && $prg->conta() === 1 && $prg->contiene(98901), "programma: aggiunta di un'edizione del progetto");
prova(!isset($cart->voci()[98901]['custom']['scuola']) && ($cart->voci()[98901]['custom']['altro'] ?? '') === 'a, b' && $cart->voci()[98901]['custom']['numero_partecipanti'] === '12', "programma: la scuola non si tiene per attività, gli elenchi diventano testo");
$r = $prg->aggiungi(98912, ['custom_numero_partecipanti' => '15'], false, 5, []);
prova($r['ok'] && $r['sostituita'] && $prg->conta() === 1 && $cart->turnoDi(98901) === 98912, "programma: un'altra edizione della stessa attività sostituisce la prima");
$prg->aggiungi(98913, [], false, 5, []);
$prg->aggiungi(98914, [], false, 5, []);
prova($prg->conta() === 3, "programma: tre attività (progetto, evento in lista d'attesa, evento esaurito)");

// ── Stato ──
$stati = array_column($prg->elenco(), 'stato', 'evento_id');
prova(($stati[98901] ?? '') === 'ok' && ($stati[98902] ?? '') === 'attesa' && ($stati[98903] ?? '') === 'piena', "elenco: stati delle attività (libera, lista d'attesa, esaurita)", json_encode($stati));

// ── Conferma: dati mancanti, non si prenota nulla ──
$n_pren = fn() => (int)$conn->query("SELECT COUNT(*) c FROM prenotazioni WHERE turno_id BETWEEN 98911 AND 98919 AND codice_prenotazione NOT LIKE 'FS-PRG989%'")->fetch_assoc()['c'];
$e = $prg->conferma($rid('Anna', 'Docente', 'docente989@prg989.example.org', $base_post(['email_conferma' => 'docente989@prg989.example.org', 'custom_scuola' => 'Istituto Fuori Elenco 989', 'convenzione' => 'no', 'cf' => '123'])));
prova($e->errori && !$e->voci && $n_pren() === 0 && $prg->conta() === 3 && str_contains(implode(' ', $e->errori), 'codice fiscale'), "conferma: dati della convenzione non validi → errore, nulla prenotato", implode(' | ', $e->errori));
$e = $prg->conferma($rid('Anna', 'Docente', 'docente989@prg989.example.org', ['custom_scuola' => 'X', 'convenzione' => 'si', 'email_conferma' => 'docente989@prg989.example.org']));
prova($e->errori && str_contains(implode(' ', $e->errori), 'informativa') && $n_pren() === 0, "conferma: senza la presa visione dell'informativa non si prenota");
$e = $prg->conferma($rid('Anna', 'Docente', 'docente989@prg989.example.org', $base_post(['custom_scuola' => 'X', 'convenzione' => 'si', 'email_conferma' => 'altra@prg989.example.org'])));
prova($e->errori && str_contains(implode(' ', $e->errori), 'email non coincidono') && $n_pren() === 0, "conferma: le due email devono coincidere");
$e = $prg->conferma($rid('Anna', 'Docente', 'docente989@prg989.example.org', $base_post(['email_conferma' => 'docente989@prg989.example.org', 'convenzione' => 'si'])));
prova($e->errori && str_contains(implode(' ', $e->errori), 'Indica la scuola') && $n_pren() === 0, "conferma: la scuola è obbligatoria");
$e = $prg->conferma($rid('Anna', 'Docente', 'docente989@prg989.example.org', $base_post(['email_conferma' => 'docente989@prg989.example.org', 'custom_scuola' => 'X'])));
prova($e->errori && str_contains(implode(' ', $e->errori), 'convenzione') && $n_pren() === 0, "conferma: la risposta sulla convenzione è obbligatoria");
$e = $prg->conferma($rid('Anna', 'Docente', 'docente989@prg989.example.org', $base_post(['email_conferma' => 'docente989@prg989.example.org', 'custom_scuola' => 'X', 'convenzione' => 'si', 'sito_web' => 'http://spam'])));
prova($e->errori && $n_pren() === 0, "conferma: il campo trappola dei robot blocca la richiesta");
$e = $prg->conferma($rid('Anna', 'Docente', 'docente989@prg989.example.org', $base_post(['email_conferma' => 'docente989@prg989.example.org', 'custom_scuola' => 'X', 'convenzione' => 'si'])));
prova($e->errori && $n_pren() === 0, "conferma: senza accesso serve il controllo anti-robot");

// ── Conferma riuscita: scuola fuori elenco, convenzione da stipulare ──
$_SESSION['captcha_pren']['c1'] = ['r' => 7, 't' => time() - 10];
$n_email = count($EMAIL);
$post_ok = $base_post([
    'email_conferma' => 'docente989@prg989.example.org', 'custom_scuola' => 'Istituto Fuori Elenco 989', 'convenzione' => 'no', 'captcha_id' => 'c1', 'captcha_risposta' => '7',
    'dirigente' => 'Dott.ssa Maria Dirigente989', 'cf' => '12345678901', 'pec' => 'scuola989@pec.example.org', 'comune' => 'Cosenza (CS)', 'indirizzo' => 'Via Fuori 989',
    'email' => 'referente@prg989.example.org', 'docente' => [98901 => 'Prof. Luisa Tutor989', 98902 => ''],
]);
$e = $prg->conferma($rid('Anna', 'Docente', 'docente989@prg989.example.org', $post_ok));
$per_ev = [];
foreach ($e->voci as $v) $per_ev[$v['evento_id']] = $v;
prova(!$e->errori && count($e->voci) === 3 && $e->prenotato(), "conferma: tre attività elaborate", json_encode($e->errori));
prova(($per_ev[98901]['esito'] ?? '') === 'convenzione' && !empty($per_ev[98901]['codice']), "conferma: il progetto è prenotato, in attesa della convenzione", json_encode($per_ev[98901] ?? null));
prova(($per_ev[98902]['esito'] ?? '') === 'attesa' && !empty($per_ev[98902]['codice']) && $e->inListaAttesa() === 1, "conferma: l'attività piena con lista d'attesa va in lista d'attesa", json_encode($per_ev[98902] ?? null));
prova(($per_ev[98903]['esito'] ?? '') === 'errore' && str_contains((string)($per_ev[98903]['messaggio'] ?? ''), 'esauriti') && $per_ev[98903]['codice'] === null, "conferma: l'attività esaurita senza lista d'attesa non viene prenotata e dice perché", json_encode($per_ev[98903] ?? null));
prova($prg->conta() === 1 && $cart->contiene(98903) && !$cart->contiene(98901) && !$cart->contiene(98902) && count($e->inErrore()) === 1, "conferma: restano nel programma solo le attività non prenotate");
$righe_pr = $conn->query("SELECT pr.*, t.evento_id FROM prenotazioni pr JOIN turni t ON t.id = pr.turno_id WHERE pr.email = 'docente989@prg989.example.org' ORDER BY t.evento_id")->fetch_all(MYSQLI_ASSOC);
prova(count($righe_pr) === 2 && $righe_pr[0]['stato'] === 'da_approvare' && $righe_pr[0]['convenzione'] === 'no' && $righe_pr[1]['stato'] === 'in_attesa', "prenotazioni: una per attività, con lo stato giusto");
$custom_pr = json_decode((string)$righe_pr[0]['dati_custom_json'], true);
prova(($custom_pr['numero_partecipanti'] ?? '') === '15' && ($custom_pr['docente_riferimento'] ?? '') === 'Prof. Luisa Tutor989' && ($custom_pr['scuola'] ?? '') === 'Istituto Fuori Elenco 989', "prenotazione: numero di studenti, docente referente e scuola dal programma", json_encode($custom_pr));
$custom_pr2 = json_decode((string)$righe_pr[1]['dati_custom_json'], true);
prova(($custom_pr2['docente_riferimento'] ?? '') === 'Anna Docente', "prenotazione: senza un altro docente referente vale chi prenota");

// Un'unica email a chi ha prenotato
$a_docente = array_values(array_filter(array_slice($EMAIL, $n_email), fn($m) => $m['a'] === 'docente989@prg989.example.org'));
prova(count($a_docente) === 1 && str_contains($a_docente[0]['oggetto'], 'riepilogo') && str_contains($a_docente[0]['corpo'], 'Biodiversita989') && str_contains($a_docente[0]['corpo'], 'Geologia989')
    && str_contains($a_docente[0]['corpo'], 'Chimica989') && str_contains($a_docente[0]['corpo'], "Lista d&#039;attesa") && str_contains($a_docente[0]['corpo'], 'convenzione_online.php?t=' . $e->token) && str_contains($a_docente[0]['corpo'], 'Non prenotata'),
    "email: un solo riepilogo con le tre attività, la lista d'attesa e il link ai documenti", count($a_docente) . ' email');
prova(count(array_filter(array_slice($EMAIL, $n_email), fn($m) => str_contains($m['oggetto'], 'Prenotazione') && $m['a'] === 'docente989@prg989.example.org')) === 0, "email: nessuna conferma separata per ogni attività");

// Compilazione della convenzione con tutte le prenotazioni
$conv = AppPrg::per($conn)->get(\App\Fsl\ServizioConvenzioneOnline::class);
$cc = $conv->perToken((string)$e->token);
$dati_cc = json_decode((string)($cc['dati_json'] ?? ''), true) ?: [];
prova($cc && $e->convenzione === 'no' && ($dati_cc['origine'] ?? '') === 'programma' && count($dati_cc['attivita'] ?? []) === 2 && $dati_cc['scuola']['denominazione'] === 'Istituto Fuori Elenco 989'
    && $dati_cc['scuola']['dirigente'] === 'Dott.ssa Maria Dirigente989' && $dati_cc['scuola']['email'] === 'referente@prg989.example.org' && ($cc['scuola_codice'] ?? null) === null, "convenzione online: creata con scuola, Dirigente e le due prenotazioni", json_encode($dati_cc));
prova(($dati_cc['attivita'][0]['studenti'] ?? 0) === 15 && ($dati_cc['attivita'][0]['tutor'] ?? '') === 'Prof. Luisa Tutor989' && ($dati_cc['attivita'][1]['tutor'] ?? '') === 'Anna Docente', "convenzione online: studenti e docente referente di ogni attività");
$ctx = $conv->contesto($cc);
prova($ctx && count($ctx['prenotate']) === 2, "convenzione online: anche per una scuola fuori elenco compaiono le prenotazioni del programma", $ctx ? (string)count($ctx['prenotate']) : 'nessun contesto');
prova($conv->tokenDaCodice((string)$per_ev[98902]['codice']) === $e->token && $conv->tokenDaCodice((string)$per_ev[98901]['codice']) === $e->token, "convenzione online: il codice di ogni prenotazione porta alla stessa compilazione");

// Area personale: la persona ritrova sempre i documenti delle sue prenotazioni FSL
$mie = $conv->dellePrenotazioni([(int)$righe_pr[1]['id']]);
prova(count($mie) === 1 && $mie[0]['token'] === $e->token && $mie[0]['convenzione'] === 'no' && $mie[0]['scuola'] === 'Istituto Fuori Elenco 989' && count($mie[0]['attivita']) === 2
    && $mie[0]['attivita'][0]['mia'] === false && $mie[0]['attivita'][1]['mia'] === true && $mie[0]['attivita'][1]['stato'] === 'in_attesa' && $mie[0]['attivita'][1]['titolo'] === 'Geologia989',
    "Area personale: la compilazione si trova anche da una sola delle prenotazioni, con le attività e il loro stato", json_encode($mie));
prova($conv->dellePrenotazioni([]) === [] && $conv->dellePrenotazioni([1, 2, 3]) === [], "Area personale: nessuna compilazione per chi non ne ha");

// Allegato A in PDF: scheda completa di ogni attività
$testo_pdf = function (string $pdf): string {
    preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $mm);
    $t = '';
    foreach ($mm[1] as $s) { $d = @gzuncompress($s); if ($d !== false) $t .= preg_replace('/\) Tj|\(/', '', $d) . "\n"; }
    return $t;
};
$doc = $conv->scarica('allegato_pdf', $cc, $ctx);
$pdf = $doc ? (string)file_get_contents($doc['file']) : '';
$tp = $testo_pdf($pdf);
prova($doc && str_starts_with($pdf, '%PDF-') && str_ends_with($doc['nome'], '.pdf') && str_contains($doc['nome'], 'Allegato_A_FSL_'), "Allegato A: PDF generato", $doc['nome'] ?? 'nessun documento');
prova(str_contains($tp, 'Istituto') && str_contains($tp, 'Fuori') && str_contains($tp, 'Dott.ssa') && str_contains($tp, 'Dirigente989') && str_contains($tp, 'Via') && str_contains($tp, '12345678901'), "Allegato A: i dati dell'istituto e del Dirigente");
prova(str_contains($tp, 'Biodiversita989') && str_contains($tp, 'Geologia989') && !str_contains($tp, 'Chimica989'), "Allegato A: le attività prenotate (non quella rimasta nel programma)");
prova(str_contains($tp, 'Modulo') && str_contains($tp, 'Alfa989') && str_contains($tp, 'Obiettivo') && str_contains($tp, '989') && str_contains($tp, 'Conoscenza') && str_contains($tp, 'Competenza') && str_contains($tp, 'Primo') && str_contains($tp, 'punto'),
    "Allegato A: percorso, obiettivi, conoscenze, competenze e descrizione con gli elenchi");
prova(str_contains($tp, 'Referente989') && str_contains($tp, 'referente989@example.org') && str_contains($tp, 'Tutor989') && str_contains($tp, 'Laboratorio') && str_contains($tp, 'Pranzo') && str_contains($tp, 'Classi') && str_contains($tp, 'quinte'),
    "Allegato A: referenti del Dipartimento, docente referente della scuola, sede, altre informazioni, destinatari");
prova(str_contains($tp, '15') && str_contains($tp, 'laurea') && str_contains($tp, 'PAdES'), "Allegato A: studenti, corso di studio e indicazione della firma PAdES");
@unlink($doc['file'] ?? '');

// Convenzione Word senza l'Allegato A
$leggi_docx = function (string $file): string { $z = new ZipArchive(); $z->open($file); $x = (string)$z->getFromName('word/document.xml'); $z->close(); return html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $x)), ENT_QUOTES | ENT_XML1, 'UTF-8'); };
$doc_sola = $conv->scarica('convenzione_sola', $cc, $ctx);
$doc_con = $conv->scarica('convenzione', $cc, $ctx);
$t_sola = $doc_sola ? $leggi_docx($doc_sola['file']) : '';
$t_con = $doc_con ? $leggi_docx($doc_con['file']) : '';
prova($doc_sola && str_ends_with($doc_sola['nome'], '.docx') && str_contains($t_sola, 'Istituto Fuori Elenco 989') && str_contains($t_sola, 'Dott.ssa Maria Dirigente989') && str_contains($t_sola, 'Art.7')
    && !str_contains($t_sola, 'Allegato_A') && !str_contains($t_sola, 'Titolo corso') && !str_contains($t_sola, 'Biodiversita989'), "Convenzione Word: precompilata e senza la pagina dell'Allegato A", substr($t_sola, -300));
prova($doc_con && str_contains($t_con, 'Allegato_A') && str_contains($t_con, 'Biodiversita989') && str_contains($t_con, 'Geologia989'), "Convenzione Word «unica»: l'Allegato A in fondo resta disponibile come prima");
@unlink($doc_sola['file'] ?? ''); @unlink($doc_con['file'] ?? '');
$doc_all = $conv->scarica('allegato', $cc, $ctx);
prova($doc_all && str_contains($leggi_docx($doc_all['file']), 'Biodiversita989'), "Allegato A in Word: resta disponibile");
@unlink($doc_all['file'] ?? '');

// Salvataggio dal modulo della convenzione: i dati propri del programma non si perdono
$ris = $conv->salva($cc, $ctx, ['denominazione' => 'Istituto Fuori Elenco 989', 'dirigente' => 'Dott.ssa Maria Dirigente989', 'att' => ['pr' . $dati_cc['attivita'][0]['pr'] => ['scelta' => '1', 'studenti' => '16', 'tutor' => 'Prof. Nuovo']]], null);
$dati_dopo = json_decode((string)($conv->perToken((string)$e->token)['dati_json'] ?? ''), true) ?: [];
prova(!$ris['errori'] && ($dati_dopo['origine'] ?? '') === 'programma' && ($dati_dopo['convenzione'] ?? '') === 'no' && count($dati_dopo['attivita']) === 1 && $dati_dopo['attivita'][0]['studenti'] === 16, "convenzione online: correggendo i dati restano le informazioni del programma", json_encode($ris['errori']));

// ── Convenzione già stipulata: scuola dell'anagrafe con convenzione valida ──
$q("INSERT INTO convenzioni_scuole (scuola_codice, data_stipula, scadenza, protocollo) VALUES ('ZZPG98900X', '" . $giorni(-100) . "', '" . $giorni(300) . "', 'Prot. 989')");
$prg->aggiungi(98912, ['custom_numero_partecipanti' => '8'], true, 5, []);
$prg->aggiungi(98914, [], true, 5, []);
$q("DELETE FROM prenotazioni WHERE turno_id IN (98912, 98914) AND codice_prenotazione NOT LIKE 'FS-PRG989%'");
$q("DELETE FROM prenotazioni WHERE codice_prenotazione = 'FS-PRG9892'");   // libera il posto di 98914
$n_email = count($EMAIL);
$e2 = $prg->conferma($rid('Bruno', 'Scuola', 'bruno989@prg989.example.org', [
    'accetta_privacy' => 'on', 'custom_scuola' => 'qualcosa', 'scuola_codice' => ['scuola' => 'ZZPG98900X'], 'convenzione' => 'si',
], 4));
$per_ev2 = [];
foreach ($e2->voci as $v) $per_ev2[$v['evento_id']] = $v;
prova(!$e2->errori && count($e2->voci) === 2 && ($per_ev2[98901]['esito'] ?? '') === 'confermata' && ($per_ev2[98903]['esito'] ?? '') === 'confermata' && $prg->conta() === 0, "conferma con convenzione valida: attività confermate e programma svuotato", json_encode($e2->errori) . json_encode($per_ev2));
$cc2 = $conv->perToken((string)$e2->token);
$dati2 = json_decode((string)($cc2['dati_json'] ?? ''), true) ?: [];
prova($e2->convenzione === 'si' && ($cc2['scuola_codice'] ?? '') === 'ZZPG98900X' && ($dati2['scuola']['denominazione'] ?? '') === 'Liceo Programma 989' && ($dati2['scuola']['comune'] ?? '') === 'Rende (Cosenza)' && ($dati2['scuola']['codice'] ?? '') === 'ZZPG98900X',
    "conferma: scuola dell'anagrafe, convenzione già valida (serve solo l'Allegato A) e dati compilati dall'anagrafe", json_encode($dati2['scuola'] ?? null));
$r_pr2 = $conn->query("SELECT stato, convenzione, utente_id, scuola_codice FROM prenotazioni WHERE email = 'bruno989@prg989.example.org'")->fetch_all(MYSQLI_ASSOC);
prova(count($r_pr2) === 2 && $r_pr2[0]['convenzione'] === 'ricevuta' && $r_pr2[0]['scuola_codice'] === 'ZZPG98900X' && (int)$r_pr2[0]['utente_id'] === 4, "prenotazioni: convenzione ricevuta, scuola e utente collegati");
$a_bruno = array_values(array_filter(array_slice($EMAIL, $n_email), fn($m) => $m['a'] === 'bruno989@prg989.example.org'));
prova(count($a_bruno) === 1 && !str_contains($a_bruno[0]['corpo'], 'e la <strong>Convenzione</strong>') && str_contains($a_bruno[0]['corpo'], 'Allegato A'), "email: con la convenzione già valida si parla solo dell'Allegato A");

// ── Convenzione «No» senza nessun dato facoltativo: si prenota lo stesso e la convenzione resta da completare a mano ──
$prg->aggiungi(98912, ['custom_numero_partecipanti' => '9'], false, 5, []);
$q("DELETE FROM prenotazioni WHERE turno_id = 98912 AND codice_prenotazione NOT LIKE 'FS-PRG989%'");
$_SESSION['captcha_pren']['c3'] = ['r' => 5, 't' => time() - 10];
$e4 = $prg->conferma($rid('Carla', 'Docente', 'carla989@prg989.example.org', $base_post(['email_conferma' => 'carla989@prg989.example.org', 'custom_scuola' => 'Scuola Senza Dati 989', 'convenzione' => 'no', 'captcha_id' => 'c3', 'captcha_risposta' => '5'])));
prova(!$e4->errori && $e4->prenotato() && $e4->convenzione === 'no' && $e4->token !== null, "conferma: la convenzione «No» senza Dirigente né altri dati non blocca la prenotazione", json_encode($e4->errori) . json_encode($e4->voci));
$cc4 = $conv->perToken((string)$e4->token);
$ctx4 = $conv->contesto($cc4);
$doc4 = $conv->scarica('convenzione_sola', $cc4, $ctx4);
$t4 = $doc4 ? $leggi_docx($doc4['file']) : '';
$z4 = new ZipArchive(); $z4->open($doc4['file'] ?? ''); $x4 = (string)$z4->getFromName('word/document.xml'); $z4->close();
prova($doc4 && str_contains($t4, 'Scuola Senza Dati 989') && str_contains($x4, '<w:highlight'), "Convenzione senza dati facoltativi: precompilata con quanto c'è e i campi vuoti restano evidenziati da completare");
@unlink($doc4['file'] ?? '');
$pdf4 = $conv->scarica('allegato_pdf', $cc4, $ctx4);
prova($pdf4 && str_starts_with((string)file_get_contents($pdf4['file']), '%PDF-'), "Allegato A in PDF anche senza i dati facoltativi");
@unlink($pdf4['file'] ?? '');

// ── Logo obbligatorio (come lo chiede la pagina) ──
$prg->aggiungi(98912, ['custom_numero_partecipanti' => '9'], false, 5, []);
$richiesta_logo = new RichiestaProgramma('Dino', 'Docente', 'dino989@prg989.example.org', 4, 5, [], ['accetta_privacy' => 'on', 'custom_scuola' => 'X', 'convenzione' => 'si'], null, '10.98.9.1', 'https://dibest2.unical.it/eventi', true);
$e5 = $prg->conferma($richiesta_logo);
prova($e5->errori && str_contains(implode(' ', $e5->errori), 'logo') && !$e5->voci && $prg->conta() === 1, "conferma: senza il logo della scuola non si prenota nulla", implode(' | ', $e5->errori));
$prg->svuota();

// ── Programma vuoto, svuota ──
$e3 = $prg->conferma($rid('Anna', 'Docente', 'docente989@prg989.example.org', $base_post(['custom_scuola' => 'X', 'convenzione' => 'si'])));
prova($e3->errori && str_contains($e3->errori[0], 'vuoto') && !$e3->prenotato(), "conferma: programma vuoto");
$prg->aggiungi(98912, ['custom_numero_partecipanti' => '9'], false, 5, []);
$prg->svuota();
prova($prg->conta() === 0, "programma: svuota");
$_SESSION = [];
$q("UPDATE utenti SET scuola_codice = NULL WHERE id = 4");

// ── PDF: logo PNG della scuola convertito in JPEG ──
$png = tempnam(sys_get_temp_dir(), 'lg') . '.png';
$im = imagecreatetruecolor(120, 40); imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30)); imagepng($im, $png);
$all = AppPrg::per($conn)->get(\App\Fsl\AllegatoAPdf::class);
$pdf_logo = $all->genera(['denominazione' => 'Scuola con logo'], [], $png);
prova(str_starts_with($pdf_logo, '%PDF-') && str_contains($pdf_logo, '/Logo2') && str_contains($pdf_logo, 'DCTDecode'), "Allegato A: logo PNG della scuola convertito e inserito a destra");
$pdf_senza = $all->genera(['denominazione' => 'Scuola senza logo'], []);
prova(str_starts_with($pdf_senza, '%PDF-') && !str_contains($pdf_senza, '/Logo2'), "Allegato A: senza il logo della scuola il PDF si crea lo stesso");
@unlink($png);

foreach (["DELETE FROM convenzioni_compilate WHERE email LIKE '%@prg989.example.org'", "DELETE FROM convenzioni_scuole WHERE scuola_codice = 'ZZPG98900X'", "DELETE FROM prenotazioni WHERE turno_id BETWEEN 98911 AND 98919",
          "DELETE FROM turni WHERE id BETWEEN 98911 AND 98919", "DELETE FROM progetti_dettagli WHERE evento_id BETWEEN 98901 AND 98909", "DELETE FROM eventi WHERE id BETWEEN 98901 AND 98909",
          "DELETE FROM campi_form WHERE pagina_id = 98900", "DELETE FROM pagine_eventi WHERE id = 98900", "DELETE FROM scuole WHERE codice = 'ZZPG98900X'"] as $sql) $q($sql);
