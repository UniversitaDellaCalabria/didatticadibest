<?php
// Prove via HTTP delle pagine del modulo Iscrizioni (ambiente locale acceso): aree pubbliche, Area personale, ricevuta, check-in
// e la prova di concorrenza sull'ultimo posto (prenotazioni inviate nello stesso istante da sessioni diverse).
sezione("Modulo Iscrizioni: pagine");
$senza_errori_isc = fn(array $r) => $r['codice'] === 200 && !preg_match('/<b>(Fatal error|Parse error|Warning)<\/b>|Uncaught |Fatal error:/', $r['corpo']);

foreach (['openlab.php', 'seminari.php', 'fsl.php', 'openlab.php?status=dup', 'seminari.php?status=success&code=ABC-1234&st_tipo=attesa', 'fsl.php?progetto=20', 'openlab.php?evento=10', 'seminari.php?evento=21'] as $pag) {
    prova($senza_errori_isc($http("/eventi/$pag")), "$pag senza accesso: 200 senza errori PHP");
}
$r = $http('/eventi/seminari.php');
prova(preg_match('/Quanto fa \d \+ \d\?/', $r['corpo']) === 1 && str_contains($r['corpo'], 'name="captcha_id"') && str_contains($r['corpo'], 'name="sito_web"'), "modulo di prenotazione pubblico con domanda di controllo e trappola per i robot");
$r2 = $http('/eventi/seminari.php', null, $jar2);
prova(!str_contains($r2['corpo'], 'name="captcha_id"') && str_contains($r2['corpo'], 'name="invia_prenotazione"') || str_contains($r2['corpo'], 'modPrenota'), "con l'accesso la domanda di controllo non serve");

// Area personale, ricevuta e check-in
foreach (['area_personale.php', 'area_personale.php?conferma_posto=99999', 'scanner_studente.php'] as $pag) {
    prova($senza_errori_isc($http("/eventi/$pag", null, $jar2)), "$pag da docente: 200 senza errori PHP");
}
prova($http('/eventi/area_personale.php')['codice'] === 302, "area_personale.php senza accesso: rimando al login");
prova($http('/eventi/stampa_ricevuta.php')['corpo'] === 'Parametri non validi.' && $http('/eventi/stampa_ricevuta.php?code=NON-ESISTE')['corpo'] === 'Ricevuta non trovata nel sistema.', "stampa_ricevuta.php: parametri mancanti o codice sconosciuto");
$codice_op = (string)($loc->query("SELECT codice_prenotazione FROM prenotazioni WHERE codice_prenotazione = 'OP-PROVA001'")->fetch_assoc()['codice_prenotazione'] ?? '');
if ($codice_op !== '') {
    $r = $http('/eventi/stampa_ricevuta.php?code=' . $codice_op);
    prova($r['codice'] === 200 && str_contains($r['corpo'], 'RICEVUTA DI PRENOTAZIONE') && str_contains($r['corpo'], $codice_op), "stampa_ricevuta.php: ricevuta con il codice");
    prova(str_contains($http('/eventi/stampa_ricevuta.php?id=1')['corpo'], 'Accesso non autorizzato'), "stampa_ricevuta.php per id: serve essere il proprietario o un gestore");
}
prova(str_contains($http('/eventi/self_checkin.php?t=1&k=x')['corpo'], 'saml_login.php'), "self_checkin.php senza accesso: rimando al login");
prova(str_contains($http('/eventi/self_checkin.php?t=0&k=', null, $jar2)['corpo'], 'Dati del QR Code mancanti'), "self_checkin.php: QR incompleto");
prova(str_contains($http('/eventi/self_checkin.php?t=1&k=sbagliato', null, $jar2)['corpo'], 'QR Code non valido o scaduto'), "self_checkin.php: codice di sicurezza sbagliato");
prova($http('/eventi/checkin.php', null, $jar)['dove'] !== '' && str_contains($http('/eventi/checkin.php?code=ZZZ-NON-ESISTE', null, $jar)['corpo'], 'Biglietto non trovato'), "checkin.php: senza codice porta allo scanner, codice sconosciuto respinto");
prova(!str_contains($http('/eventi/checkin.php?code=ZZZ', null, $jar2)['corpo'], 'Biglietto non trovato'), "checkin.php: il docente non gestore non entra");
prova(str_contains($http('/eventi/admin/iscritti.php?p_id=24&ajax_campi_turno=204', null, $jar)['corpo'], '') && $senza_errori_isc($http('/eventi/admin/iscritti.php?p_id=24', null, $jar)), "pannello iscritti: campi del modulo e elenco");

// Concorrenza sull'ultimo posto: un server con più processi riceve le prenotazioni nello stesso istante
$porta_isc = (function () { $s = @stream_socket_server('tcp://127.0.0.1:0'); if (!$s) return 0; $n = (int)substr(strrchr((string)stream_socket_get_name($s, false), ':'), 1); fclose($s); return $n; })();
$php_isc = ['-d', 'extension=zip', '-d', 'extension=gd', '-d', 'extension=intl', '-d', 'display_errors=0', '-d', 'log_errors=0'];
$srv_isc = null;
if (PHP_OS_FAMILY === 'Linux' && $porta_isc > 0 && function_exists('proc_open')) {
    $srv_isc = proc_open(array_merge([PHP_BINARY], $php_isc, ['-S', "127.0.0.1:$porta_isc", '-t', "$SITO/strumenti/locale/www", "$SITO/strumenti/locale/router.php"]),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipe_isc, $SITO, ['PHP_CLI_SERVER_WORKERS' => '8'] + $_ENV + ['PATH' => (string)getenv('PATH')]);
}
$pronto_isc = false;
if (is_resource($srv_isc)) {
    for ($i = 0; $i < 40 && !$pronto_isc; $i++) { $pronto_isc = @file_get_contents("http://127.0.0.1:$porta_isc/eventi/") !== false; if (!$pronto_isc) usleep(250000); }
}
if (!$pronto_isc) {
    echo "  (concorrenza: server con più processi non avviabile qui, prova saltata)\n";
} else {
    $area_isc = (int)$loc->query("SELECT id FROM pagine_eventi WHERE slug = 'seminari'")->fetch_assoc()['id'];
    $loc->query("DELETE FROM prenotazioni WHERE turno_id IN (98011, 98012)");
    $loc->query("DELETE FROM turni WHERE evento_id = 9801");
    $loc->query("DELETE FROM eventi WHERE id = 9801");
    $loc->query("INSERT INTO eventi (id, pagina_id, titolo, luogo, tipo, ruolo_accesso_id, archiviato, richiede_prenotazione) VALUES (9801, $area_isc, 'Concorrenza 98', 'Aula', 'evento', 0, 0, 1)");
    $loc->query("INSERT INTO turni (id, evento_id, nome_turno, max_posti, abilita_lista_attesa) VALUES (98011, 9801, 'Ultimo posto', 1, 0), (98012, 9801, 'Due posti e attesa', 2, 1)");
    $prenota_insieme = function (int $turno, int $quante) use ($porta_isc) {
        $base = "http://127.0.0.1:$porta_isc";
        $jars = []; $tokens = [];
        for ($i = 0; $i < $quante; $i++) {
            $jars[$i] = tempnam(sys_get_temp_dir(), 'jar');
            foreach (["$base/__accesso?u=4", "$base/eventi/seminari.php"] as $k => $u) {
                $ch = curl_init($u);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $jars[$i], CURLOPT_COOKIEFILE => $jars[$i]]);
                $corpo = (string)curl_exec($ch); curl_close($ch);
                if ($k === 1) { preg_match('/name="csrf_token" value="([^"]+)"/', $corpo, $m); $tokens[$i] = $m[1] ?? ''; }
            }
        }
        $multi = curl_multi_init(); $chs = []; $loc_hdr = [];
        for ($i = 0; $i < $quante; $i++) {
            $ch = curl_init("$base/eventi/seminari.php");
            $loc_hdr[$i] = '';
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $jars[$i], CURLOPT_COOKIEFILE => $jars[$i], CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $tokens[$i], 'invia_prenotazione' => 1, 'turno_id' => $turno, 'nome' => "Prova$i", 'cognome' => 'Concorrenza', 'email' => "conc98_$i@prova.it", 'email_conferma' => "conc98_$i@prova.it", 'matricola' => '']),
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$loc_hdr, $i) { if (stripos($h, 'Location:') === 0) $loc_hdr[$i] = trim(substr($h, 9)); return strlen($h); }]);
            curl_multi_add_handle($multi, $ch); $chs[$i] = $ch;
        }
        do { curl_multi_exec($multi, $attivi); if ($attivi) curl_multi_select($multi, 1); } while ($attivi);
        foreach ($chs as $ch) { curl_multi_remove_handle($multi, $ch); curl_close($ch); }
        curl_multi_close($multi);
        foreach ($jars as $j) @unlink($j);
        return $loc_hdr;
    };
    $esiti = $prenota_insieme(98011, 8);
    $ok = count(array_filter($esiti, fn($l) => str_contains($l, 'status=success')));
    $pieni = count(array_filter($esiti, fn($l) => str_contains($l, 'status=full')));
    $r = $loc->query("SELECT COUNT(*) n, COALESCE(SUM(num_posti), 0) posti FROM prenotazioni WHERE turno_id = 98011 AND stato = 'confermata'")->fetch_assoc();
    prova($ok === 1 && $pieni === 7 && (int)$r['n'] === 1 && (int)$r['posti'] === 1, "concorrenza: 8 prenotazioni insieme sull'ultimo posto, una sola confermata", json_encode(['esiti' => $esiti, 'db' => $r]));
    $esiti = $prenota_insieme(98012, 6);
    $conf = count(array_filter($esiti, fn($l) => str_contains($l, 'status=success') && !str_contains($l, 'st_tipo=attesa')));
    $att = count(array_filter($esiti, fn($l) => str_contains($l, 'st_tipo=attesa')));
    $r = $loc->query("SELECT SUM(stato = 'confermata') c, SUM(stato = 'in_attesa') a FROM prenotazioni WHERE turno_id = 98012")->fetch_assoc();
    prova($conf === 2 && $att === 4 && (int)$r['c'] === 2 && (int)$r['a'] === 4, "concorrenza: due posti e lista d'attesa, 6 richieste insieme: 2 confermate e 4 in coda", json_encode(['esiti' => $esiti, 'db' => $r]));
    $loc->query("DELETE FROM prenotazioni WHERE turno_id IN (98011, 98012)");
    $loc->query("DELETE FROM turni WHERE evento_id = 9801");
    $loc->query("DELETE FROM eventi WHERE id = 9801");
    $loc->query("DELETE FROM log_email WHERE destinatario LIKE 'conc98_%'");
}
if (is_resource($srv_isc)) { proc_terminate($srv_isc); proc_close($srv_isc); }
