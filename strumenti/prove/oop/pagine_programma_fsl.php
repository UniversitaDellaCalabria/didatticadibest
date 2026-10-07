<?php
// Prove via HTTP del programma FSL (ambiente locale acceso): «Aggiungi al programma» dalla scheda del progetto, domanda «prenota ora o scegli altre»,
// icona con il contatore, programma_fsl.php (riepilogo, togli, conferma), esito con i documenti precompilati. Possono usare $http, $jar (amministratore),
// $jar2 (docente 4), $loc (database locale). Dati di esempio di strumenti/locale: utente 4, progetto 20 edizione 2 = turno 201, scuola di prova ZZPR00000P.
sezione('Modulo FSL: programma delle attività (pagine)');

$loc->query("INSERT IGNORE INTO scuole (codice, denominazione, comune, provincia, regione, tipo) VALUES ('ZZPR00000P', 'SCUOLA DELLE PROVE AUTOMATICHE', 'COSENZA', 'COSENZA', 'CALABRIA', 'LICEO')");
$loc->query("DELETE FROM convenzioni_scuole WHERE scuola_codice = 'ZZPR00000P'");
$pulisci_prg = function () use ($loc) {
    $ids = [];
    $ris = $loc->query("SELECT id FROM prenotazioni WHERE turno_id = 201 AND email = 'luca.insegnante@example.org'");
    while ($ris && ($x = $ris->fetch_assoc())) $ids[] = (int)$x['id'];
    if ($ids) {
        $loc->query("DELETE FROM convenzioni_compilate WHERE prenotazione_id IN (" . implode(',', $ids) . ")");
        $loc->query("DELETE FROM prenotazioni WHERE id IN (" . implode(',', $ids) . ")");
    }
};
$pulisci_prg();
$jar_prg = tempnam(sys_get_temp_dir(), 'jar');
$http('/__accesso?u=4', null, $jar_prg);
$csrf_prg = function (string $url) use ($http, $jar_prg): string { $r = $http($url, null, $jar_prg); return preg_match('/name="csrf_token" value="([^"]+)"/', $r['corpo'], $m) ? $m[1] : ''; };
$senza_errori = fn(string $corpo): bool => !preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught /', $corpo);

// La scheda del progetto: il pulsante diventa «Aggiungi al programma» e il modulo non chiede più i dati della scuola
$r = $http('/eventi/fsl.php?progetto=20', null, $jar_prg);
prova($r['codice'] === 200 && $senza_errori($r['corpo']) && str_contains($r['corpo'], 'name="aggiungi_programma"') && str_contains($r['corpo'], 'Aggiungi al programma') && str_contains($r['corpo'], 'name="turno_id" value="201"'),
    "scheda del progetto FSL: «Aggiungi al programma» al posto della prenotazione diretta");
prova(!str_contains($r['corpo'], 'id="pgConvSi"') && !str_contains($r['corpo'], 'class="form-check-input conv-radio"') && !str_contains($r['corpo'], 'name="accetta_privacy"') && !preg_match('/id="modPrenota201".*?name="nome"/s', $r['corpo']),
    "modulo dell'attività FSL: niente anagrafica, convenzione e privacy (si chiedono una volta sola alla conferma)");
prova(!str_contains($r['corpo'], 'class="programma-fsl-n"'), "icona del programma: non compare se il programma è vuoto");

// Aggiunta: token sbagliato respinto, poi aggiunta e domanda «prenota ora o scegli altre»
$campi = ['turno_id' => 201, 'aggiungi_programma' => 1, 'custom_numero_partecipanti' => 15];
$rr = $http('/eventi/fsl.php?progetto=20', ['csrf_token' => 'sbagliato'] + $campi, $jar_prg);
prova($rr['codice'] === 403, "aggiungi al programma: token CSRF sbagliato respinto", (string)$rr['codice']);
$rr = $http('/eventi/fsl.php?progetto=20', ['csrf_token' => $csrf_prg('/eventi/fsl.php?progetto=20')] + $campi, $jar_prg);
prova(in_array($rr['codice'], [302, 303], true) && str_contains($rr['dove'], 'fsl.php?progetto=20') && str_contains($rr['dove'], 'programma=ok'), "aggiungi al programma: si torna alla scheda", $rr['codice'] . ' ' . $rr['dove']);
$r = $http('/eventi/fsl.php?progetto=20&programma=ok', null, $jar_prg);
prova($r['codice'] === 200 && $senza_errori($r['corpo']) && str_contains($r['corpo'], 'id="modProgrammaAggiunta"') && str_contains($r['corpo'], 'Vai al programma e prenota') && str_contains($r['corpo'], 'Scegli altre attività')
    && str_contains($r['corpo'], 'class="programma-fsl-n"') && str_contains($r['corpo'], 'Nel programma: modifica'), "dopo l'aggiunta: domanda «prenota ora o scegli altre», icona con il contatore, pulsante «Nel programma»");
$r = $http('/eventi/fsl.php?progetto=20&programma=ok', null, $jar_prg);
prova(!str_contains($r['corpo'], 'id="modProgrammaAggiunta"'), "la domanda compare una volta sola");
$r = $http('/eventi/', null, $jar_prg);
prova($r['codice'] === 200 && str_contains($r['corpo'], 'href="programma_fsl.php"') && str_contains($r['corpo'], 'class="programma-fsl-n"'), "l'icona del programma compare in tutte le pagine");

// Area personale: il programma in preparazione si vede e si riapre
$r = $http('/eventi/area_personale.php', null, $jar_prg);
prova($r['codice'] === 200 && $senza_errori($r['corpo']) && str_contains($r['corpo'], 'id="pills-fsl"') && str_contains($r['corpo'], 'Il programma in preparazione (1)') && str_contains($r['corpo'], 'href="programma_fsl.php"'), "Area personale: scheda «Programma FSL» con il programma in preparazione");

// Aggiunta non valida: attività non FSL (un turno qualsiasi di un'area non FSL) → messaggio
$non_fsl = $loc->query("SELECT t.id FROM turni t JOIN eventi e ON e.id = t.evento_id LEFT JOIN progetti_dettagli pd ON pd.evento_id = e.id WHERE IFNULL(pd.convenzione, 0) = 0 AND e.archiviato = 0 LIMIT 1")->fetch_assoc();
if ($non_fsl) {
    $rr = $http('/eventi/fsl.php', ['csrf_token' => $csrf_prg('/eventi/fsl.php?progetto=20'), 'turno_id' => $non_fsl['id'], 'aggiungi_programma' => 1], $jar_prg);
    $dove = $rr['dove'];
    $r = $http(str_starts_with($dove, 'http') ? $dove : '/eventi/' . ltrim($dove, '/'), null, $jar_prg);
    prova(in_array($rr['codice'], [302, 303], true) && str_contains($r['corpo'], 'Non aggiunta al programma'), "aggiungi al programma: un'attività non FSL è rifiutata con il motivo", $rr['codice'] . ' ' . $dove);
}

// La pagina del programma
$r = $http('/eventi/programma_fsl.php', null, $jar_prg);
prova($r['codice'] === 200 && $senza_errori($r['corpo']) && str_contains($r['corpo'], 'Il programma FSL della scuola') && str_contains($r['corpo'], 'Attività scelte (1)') && str_contains($r['corpo'], 'name="conferma_programma"')
    && str_contains($r['corpo'], 'name="docente[20]"') && str_contains($r['corpo'], 'name="convenzione"') && str_contains($r['corpo'], 'name="accetta_privacy"') && str_contains($r['corpo'], 'class="scuola-campo'), "programma_fsl.php: attività scelte, docente referente, scuola, convenzione, privacy");
prova(str_contains($r['corpo'], 'value="Luca"') && str_contains($r['corpo'], 'readonly') && !str_contains($r['corpo'], 'name="captcha_risposta"') && !str_contains($r['corpo'], 'name="email_conferma"'), "programma_fsl.php: connesso, nome ed email già noti e niente controllo anti-robot");
prova($http('/eventi/programma_fsl.php')['codice'] === 200 && str_contains($http('/eventi/programma_fsl.php')['corpo'], 'Il programma è vuoto'), "programma_fsl.php: senza accesso e senza attività, programma vuoto");

// Togli e svuota
$tk = $csrf_prg('/eventi/programma_fsl.php');
$rr = $http('/eventi/programma_fsl.php', ['csrf_token' => 'sbagliato', 'togli' => 20], $jar_prg);
prova($rr['codice'] === 403, "togli: token CSRF sbagliato respinto");
$rr = $http('/eventi/programma_fsl.php', ['csrf_token' => $tk, 'togli' => 20], $jar_prg);
$r = $http('/eventi/programma_fsl.php', null, $jar_prg);
prova(in_array($rr['codice'], [302, 303], true) && str_contains($r['corpo'], 'Il programma è vuoto') && !str_contains($r['corpo'], 'class="programma-fsl-n"'), "togli: l'attività esce dal programma e l'icona sparisce");

// Conferma: prima con dati mancanti (non prenota nulla), poi completa
$http('/eventi/fsl.php?progetto=20', ['csrf_token' => $csrf_prg('/eventi/fsl.php?progetto=20')] + $campi, $jar_prg);
$dati_conf_piatti = function (array $dati): array { $out = []; foreach ($dati as $k => $v) { if (is_array($v)) foreach ($v as $k2 => $v2) $out[$k . '[' . $k2 . ']'] = $v2; else $out[$k] = $v; } return $out; };
$dati_conf = ['conferma_programma' => 1, 'custom_scuola' => 'Liceo', 'scuola_codice' => ['scuola' => 'ZZPR00000P'], 'convenzione' => 'no', 'accetta_privacy' => 'on',
              'cf' => '123', 'pec' => 'scuola.prova@pec.example.org', 'dirigente' => '', 'docente' => [20 => 'Prof. Verdi Prova']];
$rr = $http('/eventi/programma_fsl.php', ['csrf_token' => $csrf_prg('/eventi/programma_fsl.php')] + $dati_conf, $jar_prg);
$r = $http('/eventi/programma_fsl.php', null, $jar_prg);
$n_pr = (int)$loc->query("SELECT COUNT(*) c FROM prenotazioni WHERE turno_id = 201 AND email = 'luca.insegnante@example.org'")->fetch_assoc()['c'];
prova(in_array($rr['codice'], [302, 303], true) && $n_pr === 0 && str_contains($r['corpo'], 'codice fiscale dell&#039;istituto') && str_contains($r['corpo'], 'Attività scelte (1)') && str_contains($r['corpo'], 'value="123"'),
    "conferma: dati della convenzione non validi, nulla prenotato e il modulo conserva quanto scritto", "prenotazioni $n_pr");
$rr = $http('/eventi/programma_fsl.php', ['csrf_token' => 'sbagliato'] + $dati_conf, $jar_prg);
prova($rr['codice'] === 403, "conferma: token CSRF sbagliato respinto");
$dati_conf['dirigente'] = 'Dott.ssa Anna Preside';
$dati_conf['cf'] = '12345678901';
// Il logo della scuola è obbligatorio (serve per l'Allegato A)
$rr = $http('/eventi/programma_fsl.php', ['csrf_token' => $csrf_prg('/eventi/programma_fsl.php')] + $dati_conf, $jar_prg);
$r = $http('/eventi/programma_fsl.php', null, $jar_prg);
$n_pr = (int)$loc->query("SELECT COUNT(*) c FROM prenotazioni WHERE turno_id = 201 AND email = 'luca.insegnante@example.org'")->fetch_assoc()['c'];
prova(in_array($rr['codice'], [302, 303], true) && $n_pr === 0 && str_contains($r['corpo'], 'Carica il logo della scuola') && str_contains($r['corpo'], 'id="pgLogo" name="logo" accept=".png,.jpg,.jpeg" required'), "conferma: senza il logo della scuola non si prenota nulla", "prenotazioni $n_pr");
$png_prg = tempnam(sys_get_temp_dir(), 'lg') . '.png';
$im_prg = imagecreatetruecolor(120, 40); imagefill($im_prg, 0, 0, imagecolorallocate($im_prg, 30, 90, 160)); imagepng($im_prg, $png_prg);
$ch = curl_init($BASE . '/eventi/programma_fsl.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_POST => true, CURLOPT_COOKIEJAR => $jar_prg, CURLOPT_COOKIEFILE => $jar_prg,
    CURLOPT_POSTFIELDS => array_merge(['csrf_token' => $csrf_prg('/eventi/programma_fsl.php')], $dati_conf_piatti($dati_conf), ['logo' => new CURLFile($png_prg, 'image/png', 'logo.png')])]);
curl_exec($ch);
$rr = ['codice' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'dove' => (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
curl_close($ch);
@unlink($png_prg);
prova(in_array($rr['codice'], [302, 303], true) && str_contains($rr['dove'], 'programma_fsl.php?esito=1'), "conferma: richiesta registrata", $rr['codice'] . ' ' . $rr['dove']);
$pr = $loc->query("SELECT id, stato, convenzione, scuola_codice, dati_custom_json FROM prenotazioni WHERE turno_id = 201 AND email = 'luca.insegnante@example.org' ORDER BY id DESC LIMIT 1")->fetch_assoc();
prova(($pr['stato'] ?? '') === 'da_approvare' && ($pr['convenzione'] ?? '') === 'no' && ($pr['scuola_codice'] ?? '') === 'ZZPR00000P' && str_contains((string)($pr['dati_custom_json'] ?? ''), 'Verdi Prova'), "prenotazione salvata da approvare con docente referente e scuola", json_encode($pr));
$r = $http('/eventi/programma_fsl.php?esito=1', null, $jar_prg);
preg_match('/convenzione_online\.php\?t=([a-f0-9]{32})&amp;scarica=allegato_pdf/', $r['corpo'], $mt);
prova($r['codice'] === 200 && $senza_errori($r['corpo']) && str_contains($r['corpo'], 'richiesta registrata') && str_contains($r['corpo'], 'In attesa della convenzione') && !empty($mt[1])
    && str_contains($r['corpo'], "Scarica l'Allegato A (PDF)") && str_contains($r['corpo'], 'Scarica la Convenzione (Word)') && str_contains($r['corpo'], 'PAdES'), "esito: attività, stato e documenti da scaricare");
$r = $http('/eventi/programma_fsl.php?esito=1', null, $jar_prg);
prova(str_contains($r['corpo'], 'Il programma FSL della scuola') && !str_contains($r['corpo'], 'richiesta registrata'), "l'esito si vede una volta sola");
$r = $http('/eventi/programma_fsl.php', null, $jar_prg);
prova(str_contains($r['corpo'], 'Il programma è vuoto') && !str_contains($r['corpo'], 'class="programma-fsl-n"'), "dopo la conferma il programma è vuoto");
$r = $http('/eventi/area_personale.php', null, $jar_prg);
prova($r['codice'] === 200 && $senza_errori($r['corpo']) && str_contains($r['corpo'], 'id="pills-fsl"') && !str_contains($r['corpo'], 'Il programma in preparazione') && preg_match('/convenzione_online\\.php\\?t=[a-f0-9]{32}&amp;scarica=allegato_pdf/', $r['corpo']) && str_contains($r['corpo'], 'scarica=convenzione_sola') && str_contains($r['corpo'], 'Geologia sul campo'),
    "Area personale: dopo la conferma, Allegato A e Convenzione sempre disponibili");


// Documenti: Allegato A (PDF) e Convenzione (Word), dal link personale
$tok = $mt[1] ?? '';
$r = $http("/eventi/convenzione_online.php?t=$tok&scarica=allegato_pdf");
prova($r['codice'] === 200 && str_starts_with($r['corpo'], '%PDF-'), "Allegato A: download del PDF dal link personale");
$r = $http("/eventi/convenzione_online.php?t=$tok&scarica=convenzione_sola");
prova($r['codice'] === 200 && str_starts_with($r['corpo'], 'PK'), "Convenzione: download del Word dal link personale");
$r = $http("/eventi/convenzione_online.php?t=$tok");
prova($r['codice'] === 200 && $senza_errori($r['corpo']) && str_contains($r['corpo'], 'scarica=allegato_pdf') && str_contains($r['corpo'], 'scarica=convenzione_sola') && str_contains($r['corpo'], 'Anna Preside'), "convenzione online: pagina con i dati e i due download");
$r = $http('/eventi/convenzione_online.php?t=' . str_repeat('0', 32) . '&scarica=allegato_pdf');
prova($r['codice'] === 404, "download con un link personale sbagliato: negato");

$pulisci_prg();
