<?php
// Prove via HTTP dei documenti prima dell'attività FSL (ambiente locale acceso): elenco_studenti.php con il caricamento dell'autorizzazione
// (PDF), autorizzazione_scuola.php (download riservato), cartella bloccata al web. Possono usare $http, $jar (amministratore), $jar2 (docente 4).
// Dati di prova con id 988xx nell'area FSL (id 2); il docente di prova è l'utente 4 (luca.insegnante@example.org).
sezione("Modulo FSL: documenti prima dell'attività (pagine)");

foreach (["DELETE FROM prenotazioni WHERE id = 98803", "DELETE FROM turni WHERE id = 98802", "DELETE FROM progetti_dettagli WHERE evento_id = 98801", "DELETE FROM eventi WHERE id = 98801"] as $sql) $loc->query($sql);
$loc->query("INSERT INTO eventi (id, pagina_id, titolo, tipo, descrizione_breve, archiviato) VALUES (98801, 2, 'Progetto FSL documenti (prova)', 'progetto', 'Prova.', 0)");
$loc->query("INSERT INTO progetti_dettagli (evento_id, convenzione, per_scuole, attestati, data_inizio, data_fine, ore_totali) VALUES (98801, 1, 1, 0, '" . date('Y-m-d', strtotime('+40 days')) . "', '" . date('Y-m-d', strtotime('+70 days')) . "', 20)");
$loc->query("INSERT INTO turni (id, evento_id, nome_turno, max_posti, richiede_approvazione) VALUES (98802, 98801, 'Edizione prova', 5, 0)");
$loc->query("INSERT INTO prenotazioni (id, turno_id, utente_id, codice_prenotazione, stato, num_posti, nome, cognome, email, convenzione, data_prenotazione)
             VALUES (98803, 98802, 4, 'FS-DOCPAG', 'da_approvare', 1, 'Luca', 'Insegnante', 'luca.insegnante@example.org', 'no', NOW())");
$url_doc = '/eventi/elenco_studenti.php?code=FS-DOCPAG';
$stato_aut = fn() => $loc->query("SELECT autorizzazione_file FROM prenotazioni WHERE id = 98803")->fetch_assoc()['autorizzazione_file'] ?? null;

// POST con file (il metodo $http invia solo campi semplici)
$http_file = function (string $url, array $campi, ?string $file, string $jar) use ($BASE) {
    $ch = curl_init($BASE . $url);
    if ($file !== null) $campi['file_autorizzazione'] = new CURLFile($file, 'application/pdf', 'autorizzazione_scuola.pdf');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $campi, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $corpo = (string)curl_exec($ch);
    $r = ['codice' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'corpo' => $corpo, 'dove' => (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
    curl_close($ch);
    return $r;
};
$pdf_ok = tempnam(sys_get_temp_dir(), 'aut'); file_put_contents($pdf_ok, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
$pdf_finto = tempnam(sys_get_temp_dir(), 'aut'); file_put_contents($pdf_finto, "questo non è un PDF");
$token = function (string $jar) use ($http, $url_doc) { $r = $http($url_doc, null, $jar); return preg_match('/name="csrf_token" value="([^"]+)"/', $r['corpo'], $m) ? $m[1] : ''; };

$r = $http($url_doc, null, $jar2);
prova($r['codice'] === 200 && str_contains($r['corpo'], "Documenti da consegnare prima dell'attività") && str_contains($r['corpo'], 'name="file_autorizzazione"') && str_contains($r['corpo'], 'name="stud_cognome[]"')
    && str_contains($r['corpo'], 'Autorizzazione della scuola mancante') && str_contains($r['corpo'], 'Da consegnare entro il ' . date('d/m/Y', strtotime('+33 days'))), "elenco_studenti.php: prenotazione FSL in attesa di convenzione, elenco e autorizzazione da consegnare");
$r = $http('/eventi/fsl.php?progetto=20', null, $jar2);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Dopo la prenotazione dovrai caricare due documenti') && str_contains($r['corpo'], 'name="turno_id" value="201"'), "modulo di iscrizione FSL: riquadro evidente con elenco e autorizzazione da caricare");
prova($http($url_doc)['codice'] === 302, "elenco_studenti.php: senza accesso rimanda al login");

$r = $http_file($url_doc, ['csrf_token' => 'sbagliato', 'code' => 'FS-DOCPAG', 'carica_autorizzazione' => '1'], $pdf_ok, $jar2);
prova($r['codice'] === 403 && $stato_aut() === null, "autorizzazione: token CSRF sbagliato respinto");
$r = $http_file($url_doc, ['csrf_token' => $token($jar2), 'code' => 'FS-DOCPAG', 'carica_autorizzazione' => '1'], $pdf_finto, $jar2);
prova(in_array($r['codice'], [302, 303], true) && $stato_aut() === null && str_contains($http($url_doc, null, $jar2)['corpo'], 'deve essere un file PDF'), "autorizzazione: un file che non è un PDF viene rifiutato");
$r = $http_file($url_doc, ['csrf_token' => $token($jar2), 'code' => 'FS-DOCPAG', 'carica_autorizzazione' => '1'], $pdf_ok, $jar2);
$file_aut = $stato_aut();
prova(in_array($r['codice'], [302, 303], true) && $file_aut !== null && preg_match('#^uploads/autorizzazioni/[a-f0-9]{32}\.pdf$#', $file_aut) && is_file($SITO . '/' . $file_aut), "autorizzazione: PDF caricato in una cartella dedicata con nome casuale");
$pagina = $http($url_doc, null, $jar2)['corpo'];
prova(str_contains($pagina, 'Autorizzazione della scuola caricata') && str_contains($pagina, 'autorizzazione_scuola.pdf') && str_contains($pagina, 'autorizzazione_scuola.php?code=FS-DOCPAG'), "elenco_studenti.php: mostra il file caricato con il link per aprirlo");

// Download riservato
$r = $http('/eventi/autorizzazione_scuola.php?code=FS-DOCPAG', null, $jar2);
prova($r['codice'] === 200 && str_starts_with($r['corpo'], '%PDF-'), "autorizzazione_scuola.php: la scuola che ha prenotato scarica il PDF");
$r = $http('/eventi/autorizzazione_scuola.php?code=FS-DOCPAG', null, $jar);
prova($r['codice'] === 200 && str_starts_with($r['corpo'], '%PDF-'), "autorizzazione_scuola.php: l'amministratore scarica il PDF");
prova($http('/eventi/autorizzazione_scuola.php?code=FS-DOCPAG')['codice'] === 302, "autorizzazione_scuola.php: senza accesso rimanda al login");
prova($http('/eventi/autorizzazione_scuola.php?code=NONESISTE', null, $jar)['codice'] === 403 && $http('/eventi/autorizzazione_scuola.php', null, $jar)['codice'] === 400, "autorizzazione_scuola.php: codice sconosciuto o mancante");
prova($http('/eventi/' . $file_aut)['codice'] === 403 && $http('/eventi/uploads/autorizzazioni/')['codice'] === 403, "i PDF delle autorizzazioni non sono raggiungibili dal web");

// Il gestore vede lo stato nel pannello Iscrizioni
$r = $http('/eventi/admin/iscritti.php?p_id=2', null, $jar);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Autorizzazione ricevuta') && str_contains($r['corpo'], 'autorizzazione_scuola.php?code=FS-DOCPAG') && !preg_match('/<b>(Fatal error|Warning)<\/b>/', $r['corpo']), "Iscrizioni: badge dei documenti e link all'autorizzazione");
$r = $http('/eventi/area_personale.php', null, $jar2);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'Carica elenco') && !preg_match('/<b>(Fatal error|Warning)<\/b>/', $r['corpo']), "Area personale: pulsante per completare i documenti");

// Elenco degli studenti prima dell'attività, poi la scuola toglie l'autorizzazione
$r = $http($url_doc, ['csrf_token' => $token($jar2), 'code' => 'FS-DOCPAG', 'salva_elenco' => '1', 'stud_cognome' => ['Rossi', 'Bianchi'], 'stud_nome' => ['Mario', 'Anna']], $jar2);
prova(in_array($r['codice'], [302, 303], true) && (int)$loc->query("SELECT COUNT(*) n FROM partecipanti_prenotazione WHERE prenotazione_id = 98803")->fetch_assoc()['n'] === 2
    && str_contains($http($url_doc, null, $jar2)['corpo'], 'Documenti completi'), "elenco degli studenti salvato prima dell'attività: documenti completi");
$r = $http($url_doc, ['csrf_token' => $token($jar2), 'code' => 'FS-DOCPAG', 'togli_autorizzazione' => '1'], $jar2);
clearstatcache(); // il file lo elimina il server: PHP non deve rileggere il risultato di is_file() di prima
prova(in_array($r['codice'], [302, 303], true) && $stato_aut() === null && !is_file($SITO . '/' . $file_aut), "autorizzazione tolta: il file viene eliminato");

// Prenotazione annullata: niente documenti
$loc->query("UPDATE prenotazioni SET stato = 'annullata' WHERE id = 98803");
prova(str_contains($http($url_doc, null, $jar2)['corpo'], 'annullata o rifiutata'), "prenotazione annullata: l'elenco e l'autorizzazione non si caricano");

foreach (["DELETE FROM partecipanti_prenotazione WHERE prenotazione_id = 98803", "DELETE FROM prenotazioni WHERE id = 98803", "DELETE FROM turni WHERE id = 98802", "DELETE FROM progetti_dettagli WHERE evento_id = 98801", "DELETE FROM eventi WHERE id = 98801"] as $sql) $loc->query($sql);
@unlink($pdf_ok); @unlink($pdf_finto);
